<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Calendar.php';
require_once __DIR__ . '/../src/CheckinService.php';
require_once __DIR__ . '/../src/Geocoder.php';

$config  = require __DIR__ . '/../config.php';
$version = is_file(__DIR__ . '/../version.php') ? require __DIR__ . '/../version.php' : null;

// Language resolution: explicit choice (?lang=) > cookie > Accept-Language > 'fr'
// To add a language: create lang/{code}.php and add the code to $supportedLangs.
$supportedLangs = ['fr', 'en'];
if (isset($_GET['lang']) && in_array($_GET['lang'], $supportedLangs, true)) {
    $lang = $_GET['lang'];
    setcookie('jrv_lang', $lang, ['expires' => time() + 60 * 60 * 24 * 365, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
} elseif (isset($_COOKIE['jrv_lang']) && in_array($_COOKIE['jrv_lang'], $supportedLangs, true)) {
    $lang = $_COOKIE['jrv_lang'];
} else {
    preg_match('/^([a-z]{2})/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'fr', $m);
    $lang = in_array(strtolower($m[1] ?? 'fr'), $supportedLangs, true) ? strtolower($m[1]) : 'fr';
}
$langUrlFor = fn(string $code): string => '?' . http_build_query(['lang' => $code]);
$langFlag   = ['fr' => '🇫🇷', 'en' => '🇬🇧'];
$langName   = ['fr' => 'Français', 'en' => 'English'];

/** @var array<string, string> $t */
$t = require __DIR__ . '/../lang/' . $lang . '.php';

// Load sessions server-side
$calendar = new Calendar(
    $config['calendar_url'],
    $config['cache_path'],
    filter:      $config['event_filter']         ?? [],
    labelFormat: $config['session_label_format'] ?? '{date} — {title}',
);
try {
    $sessions = $calendar->getSessions($lang);
} catch (RuntimeException) {
    $sessions = [];
}

$showLocation = $config['show_location'] ?? 'with_map';
$showVenue    = $showLocation !== false;
$showLink     = in_array($showLocation, ['only_link', 'with_map'], true);
$showMap      = $showLocation === 'with_map';

$sessionCoords = [];
if ($showLink) {
    $geocacheFile = dirname($config['cache_path']) . '/geocode.json';
    foreach ($sessions as $s) {
        $sessionCoords[$s['uid']] = geocode($s['location'] ?? '', $geocacheFile);
    }
}

$associationName = $config['association_name'];
$title           = str_replace('{name}', $associationName, $t['title']);
$iconUrl         = $config['icon_url'] ?? '/assets/icon.svg';
$customCssUrl    = $config['custom_css_url'] ?? null;
$safeUrl         = fn(?string $url): string => ($url && preg_match('#^(https?://|/)#', $url)) ? $url : '#';
$safeCssUrl      = fn(?string $url): ?string => ($url && preg_match('#^(https?://|/)#', $url)) ? $url : null;
$siteUrl         = $config['site_url']  ?? null;
$allNavItems     = [];
if ($siteUrl) $allNavItems[] = ['label' => '← ' . $associationName, 'url' => $siteUrl];
foreach ($config['nav_links'] ?? [] as $link) $allNavItems[] = $link;

const COOKIE_NAME = 'jrv_nickname';
const COOKIE_TTL  = 60 * 60 * 24 * 365; // 1 year

// Read saved nickname from cookie
$savedNickname = $_COOKIE[COOKIE_NAME] ?? '';

// Handle no-JS form POST (PRG pattern)
$feedback = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sessionUid = trim($_POST['session_uid'] ?? '');
    $nickname   = trim($_POST['nickname'] ?? '');
    $remember   = isset($_POST['remember']);

    $action = $_POST['action'] ?? 'checkin';

    if (!$sessionUid || !$nickname) {
        $feedback = ['type' => 'danger', 'msg' => $t['fill_nickname']];
    } elseif ($action === 'cancel') {
        try {
            (new CheckinService(Database::get()))->cancel($sessionUid, $nickname);
            header('Location: /?cancel=ok&name=' . urlencode($nickname) . '&lang=' . $lang);
            exit;
        } catch (RuntimeException $e) {
            $msg      = $e->getCode() === 404 ? $t['not_checked_in'] : $t['err_generic'];
            $feedback = ['type' => 'danger', 'msg' => $msg];
        }
    } else {
        try {
            (new CheckinService(Database::get()))->checkin($sessionUid, $nickname);
            if ($remember) {
                setcookie(COOKIE_NAME, $nickname, ['expires' => time() + COOKIE_TTL, 'path' => '/', 'secure' => true, 'httponly' => false, 'samesite' => 'Strict']);
            } elseif ($savedNickname !== '') {
                setcookie(COOKIE_NAME, '', time() - 1, '/');
            }
            header('Location: /?checkin=ok&name=' . urlencode($nickname) . '&lang=' . $lang);
            exit;
        } catch (RuntimeException $e) {
            $msg      = $e->getCode() === 409 ? $t['already'] : $t['err_generic'];
            $feedback = ['type' => 'danger', 'msg' => $msg];
        }
    }
}

// Feedback from PRG redirect
if (isset($_GET['checkin']) && $_GET['checkin'] === 'ok') {
    $name     = htmlspecialchars($_GET['name'] ?? '', ENT_QUOTES);
    $feedback = ['type' => 'success', 'msg' => str_replace('{name}', $name, $t['checked_in'])];
}
if (isset($_GET['cancel']) && $_GET['cancel'] === 'ok') {
    $name     = htmlspecialchars($_GET['name'] ?? '', ENT_QUOTES);
    $feedback = ['type' => 'success', 'msg' => str_replace('{name}', $name, $t['cancelled'])];
}

// Sessions already checked in by the current user
$checkedUids = [];
if ($savedNickname) {
    $stmt = Database::get()->prepare("
        SELECT c.session_uid FROM checkins c
        JOIN attendees a ON a.id = c.attendee_id
        WHERE a.nickname = ?
    ");
    $stmt->execute([$savedNickname]);
    $checkedUids = array_column($stmt->fetchAll(), 'session_uid');
}

// Current session uid for pre-selection
$currentUid = '';
foreach ($sessions as $s) {
    if ($s['is_current']) { $currentUid = $s['uid']; break; }
}
if (!$currentUid && !empty($sessions)) {
    // Pre-select the first upcoming session (sessions are sorted newest-first, so last entry is soonest)
    $upcoming = array_filter($sessions, fn($s) => $s['start'] >= date('c'));
    if ($upcoming) $currentUid = end($upcoming)['uid'];
}

$osmLink          = '';
$currentVenueName = '';
if ($showLink) {
    $c = $sessionCoords[$currentUid] ?? ['lat' => null, 'lon' => null];
    if ($c['lat'] !== null) {
        $osmLink = 'https://www.openstreetmap.org/?mlat=' . $c['lat'] . '&mlon=' . $c['lon']
                 . '#map=15/' . $c['lat'] . '/' . $c['lon'];
        $loc = array_column($sessions, 'location', 'uid')[$currentUid] ?? '';
        $currentVenueName = trim(str_replace(['\\,', '\\\\'], [',', '\\'], explode('\\n', $loc)[0]));
    }
}

?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#ffffff">
  <title><?= htmlspecialchars($title) ?> — SPS</title>
  <link rel="icon" href="<?= htmlspecialchars($iconUrl) ?>">
  <link rel="apple-touch-icon" href="<?= htmlspecialchars($iconUrl) ?>">
  <link rel="manifest" href="/manifest.json">
  <link rel="stylesheet" href="/assets/bootstrap.min.css">
  <?php if ($safeCssUrl($customCssUrl)): ?><link rel="stylesheet" href="<?= htmlspecialchars($customCssUrl) ?>"><?php endif ?>
  <?php if ($showMap): ?><link rel="stylesheet" href="/assets/leaflet.min.css"><?php endif ?>
</head>
<body class="bg-light d-flex flex-column min-vh-100"
  data-lang="<?= $lang ?>"
  data-association-name="<?= htmlspecialchars($associationName) ?>"
  data-checked-uids="<?= htmlspecialchars(json_encode($checkedUids), ENT_QUOTES) ?>"
  data-saved-nickname="<?= htmlspecialchars(json_encode($savedNickname), ENT_QUOTES) ?>"
  data-show-location="<?= htmlspecialchars(json_encode($showLocation)) ?>"
  data-session-coords="<?= htmlspecialchars(json_encode($sessionCoords), ENT_QUOTES) ?>"
  data-i18n="<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>">
<header class="border-bottom bg-white px-3" style="padding-top: calc(0.5rem + env(safe-area-inset-top)); padding-bottom: 0.5rem">
  <div class="d-flex align-items-center gap-2" style="min-height:44px">
    <img src="<?= htmlspecialchars($iconUrl) ?>" alt="" width="24" height="24">
    <h1 class="fw-semibold fs-6 mb-0"><?= htmlspecialchars($title) ?></h1>
    <?php if (count($allNavItems) === 1): ?>
    <a href="<?= htmlspecialchars($safeUrl($allNavItems[0]['url'] ?? '')) ?>" class="ms-auto text-secondary text-decoration-none small"><?= htmlspecialchars($allNavItems[0]['label'] ?? '') ?></a>
    <?php elseif (count($allNavItems) > 1): ?>
    <details class="ms-auto position-relative">
      <summary class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center" aria-label="<?= htmlspecialchars($t['nav_menu_label']) ?>" style="min-height:44px;min-width:44px;cursor:pointer"><span aria-hidden="true">☰</span></summary>
      <div class="position-absolute end-0 top-100 bg-white border rounded shadow-sm py-1 mt-1" style="min-width:160px;z-index:1000">
        <?php foreach ($allNavItems as $item): ?>
        <a href="<?= htmlspecialchars($safeUrl($item['url'] ?? '')) ?>" class="d-block px-3 py-2 text-secondary text-decoration-none small"><?= htmlspecialchars($item['label'] ?? '') ?></a>
        <?php endforeach ?>
      </div>
    </details>
    <?php endif ?>
  </div>
</header>
<main class="flex-grow-1 py-4 px-3 d-flex justify-content-center align-items-start">
<div class="card w-100" style="max-width:420px">
  <div class="card-body">
    <div id="feedback" role="alert" aria-live="assertive" aria-atomic="true"
         class="<?= $feedback ? 'alert alert-' . $feedback['type'] : 'visually-hidden' ?>">
      <?= $feedback ? htmlspecialchars($feedback['msg']) : '' ?>
    </div>

    <form method="POST" action="" id="checkin-form">
      <div class="mb-3">
        <label for="session" class="form-label"><?= $t['session_label'] ?></label>
        <select id="session" name="session_uid" class="form-select" required>
          <?php foreach ($sessions as $s):
            $venue = trim(str_replace(['\\,', '\\\\'], [',', '\\'], explode('\\n', $s['location'])[0]));
          ?>
          <option value="<?= htmlspecialchars($s['uid']) ?>"
            data-location="<?= htmlspecialchars($venue) ?>"
            <?= $s['uid'] === $currentUid ? 'selected' : '' ?>>
            <?= htmlspecialchars((in_array($s['uid'], $checkedUids) ? '✅ ' : '') . $s['label']) ?>
          </option>
          <?php endforeach ?>
        </select>
        <?php if ($showVenue): ?>
        <?= $showLink ? '<a' : '<span' ?> id="session-location" class="d-none mt-1 small text-muted text-decoration-none d-block"<?= $showLink ? ' href="#" tabindex="-1"' : '' ?>>
          <span id="venue-name"></span>
          <?php if ($showMap): ?>
          <small id="map-notice" class="d-none d-block" style="font-size:.75em">
            <?= htmlspecialchars($t['map_notice']) ?>
          </small>
          <?php endif ?>
        <?= $showLink ? '</a>' : '</span>' ?>
        <?php if ($osmLink): ?>
        <noscript>
          <a href="<?= htmlspecialchars($osmLink) ?>" target="_blank" rel="noopener"
            class="d-block mt-1 small text-muted">📍 <?= htmlspecialchars($currentVenueName) ?></a>
        </noscript>
        <?php endif ?>
        <?php if ($showMap): ?><div id="map" class="d-none rounded mt-2" style="height:220px" role="region" aria-label="<?= htmlspecialchars($t['map_label']) ?>"></div><?php endif ?>
        <div id="session-status" aria-live="polite" class="visually-hidden"></div>
        <?php endif ?>
      </div>

      <div class="mb-3">
        <label for="nickname" class="form-label"><?= $t['nickname_label'] ?></label>
        <input id="nickname" name="nickname" type="text" class="form-control"
               autocomplete="off" placeholder="<?= $t['nickname_ph'] ?>"
               list="suggestions" value="<?= htmlspecialchars($savedNickname) ?>" required>
        <datalist id="suggestions"></datalist>
      </div>

      <div class="form-check mb-3">
        <input id="remember" name="remember" type="checkbox" class="form-check-input"
               <?= $savedNickname !== '' ? 'checked' : '' ?>>
        <label for="remember" class="form-check-label"><?= $t['remember'] ?></label>
      </div>

      <div class="d-grid gap-2">
        <button type="submit" name="action" value="checkin" class="btn btn-primary" id="btn-checkin"><?= $t['btn_checkin'] ?></button>
        <button type="submit" name="action" value="cancel" class="btn btn-outline-danger" id="btn-cancel"><?= $t['btn_cancel'] ?></button>
      </div>
    </form>

  </div>
</div>
</main>

<footer class="border-top bg-white px-3" style="padding-top: 0.5rem; padding-bottom: calc(0.5rem + env(safe-area-inset-bottom))">
  <div class="d-flex flex-wrap justify-content-center align-items-center gap-3">
    <span class="d-flex align-items-center gap-3">
      <nav aria-label="<?= htmlspecialchars($t['lang_switcher_label']) ?>">
        <?php foreach ($supportedLangs as $code): ?>
          <?php if ($code === $lang): ?>
            <span aria-label="<?= htmlspecialchars($langName[$code]) ?>" aria-current="true" style="opacity:.4;cursor:default"><?= $langFlag[$code] ?></span>
          <?php else: ?>
            <a href="<?= htmlspecialchars($langUrlFor($code)) ?>" aria-label="<?= htmlspecialchars($langName[$code]) ?>" class="text-decoration-none"><?= $langFlag[$code] ?></a>
          <?php endif ?>
        <?php endforeach ?>
      </nav>
      <a href="/admin/" class="text-secondary small"><?= $t['admin_link'] ?></a>
    </span>
    <span class="d-flex align-items-center gap-3">
      <a href="https://github.com/holyhope/attendance-tracker?tab=License-1-ov-file" target="_blank" rel="noopener" class="text-secondary small">© <?= date('Y') ?> holyhope</a>
      <?php if ($version): ?><span class="text-muted small"><?= htmlspecialchars($version) ?></span><?php endif ?>
    </span>
  </div>
</footer>

<?php if ($showMap): ?><script src="/assets/leaflet.min.js"></script><?php endif ?>
<script src="/assets/app.js"></script>

</body>
</html>

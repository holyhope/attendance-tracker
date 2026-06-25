<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Calendar.php';
require_once __DIR__ . '/../src/CheckinService.php';
require_once __DIR__ . '/../src/Geocoder.php';

$config = require __DIR__ . '/../config.php';

// Server-side i18n from Accept-Language header
preg_match('/^([a-z]{2})/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'fr', $m);
$lang = in_array(strtolower($m[1] ?? 'fr'), ['en']) ? 'en' : 'fr';

$i18n = [
    'fr' => [
        'title'           => fn($n) => "Pointage $n",
        'session_label'   => 'Séance',
        'nickname_label'  => 'Pseudonyme',
        'nickname_ph'     => 'Pseudo',
        'remember'        => 'Mémoriser mon pseudonyme',
        'btn_checkin'     => 'Pointer la présence',
        'btn_cancel'      => 'Annuler le pointage',
        'checked_in'      => fn($n) => "Présence enregistrée pour $n.",
        'cancelled'       => fn($n) => "Pointage annulé pour $n.",
        'fill_nickname'   => 'Entrez un pseudonyme.',
        'already'         => 'Déjà pointé pour cette séance.',
        'not_checked_in'  => 'Aucun pointage trouvé pour cette séance.',
        'err_generic'     => 'Une erreur est survenue.',
        'admin_link'      => 'Administration',
    ],
    'en' => [
        'title'           => fn($n) => "$n Attendance",
        'session_label'   => 'Session',
        'nickname_label'  => 'Nickname',
        'nickname_ph'     => 'Nickname',
        'remember'        => 'Remember my nickname',
        'btn_checkin'     => 'Check in',
        'btn_cancel'      => 'Cancel check-in',
        'checked_in'      => fn($n) => "Checked in: $n.",
        'cancelled'       => fn($n) => "Check-in cancelled for $n.",
        'fill_nickname'   => 'Enter a nickname.',
        'already'         => 'Already checked in for this session.',
        'not_checked_in'  => 'No check-in found for this session.',
        'err_generic'     => 'An error occurred.',
        'admin_link'      => 'Administration',
    ],
];

$t = $i18n[$lang];

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
$title           = ($t['title'])($associationName);

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
    $feedback = ['type' => 'success', 'msg' => ($t['checked_in'])($name)];
}
if (isset($_GET['cancel']) && $_GET['cancel'] === 'ok') {
    $name     = htmlspecialchars($_GET['name'] ?? '', ENT_QUOTES);
    $feedback = ['type' => 'success', 'msg' => ($t['cancelled'])($name)];
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
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title) ?> — SPS</title>
  <link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/bootstrap.min.css">
  <?php if ($showMap): ?><link rel="stylesheet" href="/assets/leaflet.min.css"><?php endif ?>
</head>
<body class="bg-light py-4 px-3"
  data-lang="<?= $lang ?>"
  data-association-name="<?= htmlspecialchars($associationName) ?>"
  data-checked-uids="<?= htmlspecialchars(json_encode($checkedUids), ENT_QUOTES) ?>"
  data-saved-nickname="<?= htmlspecialchars(json_encode($savedNickname), ENT_QUOTES) ?>"
  data-show-location="<?= htmlspecialchars(json_encode($showLocation)) ?>"
  data-session-coords="<?= htmlspecialchars(json_encode($sessionCoords), ENT_QUOTES) ?>">
<main class="card mx-auto" style="max-width:420px">
  <div class="card-body">
    <h1 class="h4 mb-4 d-flex align-items-center gap-2">
      <img src="/assets/icon.svg" alt="" width="28" height="28">
      <?= htmlspecialchars($title) ?>
    </h1>

    <?php if ($feedback): ?>
    <div class="alert alert-<?= $feedback['type'] ?>" role="alert">
      <?= $feedback['msg'] ?>
    </div>
    <?php endif ?>

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
        <?= $showLink ? '<a' : '<span' ?> id="session-location" class="d-none mt-1 small text-muted text-decoration-none d-block"<?= $showLink ? ' href="#"' : '' ?>>
          <span id="venue-name"></span>
          <?php if ($showMap): ?>
          <small id="map-notice" class="d-none d-block" style="font-size:.75em">
            En cliquant, des données de localisation seront chargées depuis openstreetmap.org.
          </small>
          <?php endif ?>
        <?= $showLink ? '</a>' : '</span>' ?>
        <?php if ($osmLink): ?>
        <noscript>
          <a href="<?= htmlspecialchars($osmLink) ?>" target="_blank" rel="noopener"
            class="d-block mt-1 small text-muted">📍 <?= htmlspecialchars($currentVenueName) ?></a>
        </noscript>
        <?php endif ?>
        <?php if ($showMap): ?><div id="map" class="d-none rounded mt-2" style="height:220px"></div><?php endif ?>
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

    <div class="text-center mt-3 d-flex justify-content-center align-items-center gap-3">
      <a href="/admin/" class="text-secondary small"><?= $t['admin_link'] ?></a>
    </div>
  </div>
</main>

<div class="text-center mt-3">
  <a href="https://github.com/sponsors/holyhope" target="_blank" rel="noopener" class="text-secondary small">© <?= date('Y') ?> holyhope</a>
</div>

<?php if ($showMap): ?><script src="/assets/leaflet.min.js"></script><?php endif ?>
<script src="/assets/app.js"></script>

</body>
</html>

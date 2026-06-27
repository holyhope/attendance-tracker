<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/Calendar.php';
require_once __DIR__ . '/../../src/Geocoder.php';

$config  = require __DIR__ . '/../../config.php';
$version = is_file(__DIR__ . '/../../version.php') ? require __DIR__ . '/../../version.php' : null;

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
$langUrlFor = function(string $code): string {
    $params = array_filter(['lang' => $code, 'session_uid' => $_GET['session_uid'] ?? null]);
    return '?' . http_build_query($params);
};
$langFlag = ['fr' => '🇫🇷', 'en' => '🇬🇧'];

/** @var array<string, string> $t */
$t = require __DIR__ . '/../../lang/' . $lang . '.php';

// Disk usage
$fmtBytes = function(int|false $bytes): string {
    if ($bytes === false) return '?';
    foreach (['o', 'Ko', 'Mo', 'Go'] as $unit) {
        if ($bytes < 1024) return round($bytes) . ' ' . $unit;
        $bytes /= 1024;
    }
    return round($bytes) . ' To';
};
$dbPath        = dirname($config['db_dsn'] === '' ? '' : str_replace('sqlite:', '', $config['db_dsn']));
$dbSize        = file_exists(str_replace('sqlite:', '', $config['db_dsn'])) ? filesize(str_replace('sqlite:', '', $config['db_dsn'])) : false;
$cacheSize     = file_exists($config['cache_path']) ? filesize($config['cache_path']) : false;

// Handle delete (POST + PRG)
$feedback = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $checkinId  = trim($_POST['checkin_id']  ?? '');
    $sessionUid = trim($_POST['session_uid'] ?? '');
    $deleted = false;
    if ($checkinId && $sessionUid) {
        $stmt = Database::get()->prepare('DELETE FROM checkins WHERE id = ? AND session_uid = ?');
        $stmt->execute([$checkinId, $sessionUid]);
        $deleted = $stmt->rowCount() > 0;
    }
    header('Location: /admin/?session_uid=' . urlencode($sessionUid) . ($deleted ? '&deleted=1' : ''));
    exit;
}

if (isset($_GET['deleted'])) {
    $feedback = ['type' => 'success', 'msg' => $t['deleted']];
}

// Load sessions
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

// Counts per session
$countRows = Database::get()
    ->query('SELECT session_uid, COUNT(*) AS cnt FROM checkins GROUP BY session_uid')
    ->fetchAll();
$counts = array_column($countRows, 'cnt', 'session_uid');


// Selected session — GET param, fallback to current or most recent
$sessionUid = trim($_GET['session_uid'] ?? '');
if (!$sessionUid) {
    foreach ($sessions as $s) {
        if ($s['is_current']) { $sessionUid = $s['uid']; break; }
    }
    if (!$sessionUid && !empty($sessions)) {
        $sessionUid = $sessions[0]['uid'];
    }
}

$osmLink          = '';
$currentVenueName = '';
if ($showLink) {
    $c = $sessionCoords[$sessionUid] ?? ['lat' => null, 'lon' => null];
    if ($c['lat'] !== null) {
        $osmLink = 'https://www.openstreetmap.org/?mlat=' . $c['lat'] . '&mlon=' . $c['lon']
                 . '#map=15/' . $c['lat'] . '/' . $c['lon'];
        $loc = array_column($sessions, 'location', 'uid')[$sessionUid] ?? '';
        $currentVenueName = trim(str_replace(['\\,', '\\\\'], [',', '\\'], explode('\\n', $loc)[0]));
    }
}

// Checkins for selected session
$checkins = [];
if ($sessionUid) {
    $stmt = Database::get()->prepare('
        SELECT c.id, c.created_at, a.nickname
        FROM checkins c
        JOIN attendees a ON a.id = c.attendee_id
        WHERE c.session_uid = ?
        ORDER BY c.created_at ASC
    ');
    $stmt->execute([$sessionUid]);
    $checkins = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin — <?= htmlspecialchars($config['association_name']) ?> — SPS</title>
  <link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/bootstrap.min.css">
  <?php if ($showMap): ?><link rel="stylesheet" href="/assets/leaflet.min.css"><?php endif ?>
</head>
<body class="bg-light d-flex flex-column min-vh-100"
  data-session-uid="<?= htmlspecialchars($sessionUid) ?>"
  data-lang="<?= $lang ?>"
  data-show-location="<?= htmlspecialchars(json_encode($showLocation)) ?>"
  data-session-coords="<?= htmlspecialchars(json_encode($sessionCoords), ENT_QUOTES) ?>"
  data-i18n="<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>">
<header class="border-bottom bg-white py-2 px-3">
  <div class="d-flex align-items-center gap-2">
    <a href="/" class="btn btn-outline-secondary btn-sm" aria-label="<?= htmlspecialchars($t['back_home']) ?>">←</a>
    <img src="/assets/icon.svg" alt="" width="24" height="24" class="ms-1">
    <span class="fw-semibold"><?= htmlspecialchars($t['admin_title']) ?> — <?= htmlspecialchars($config['association_name']) ?></span>
  </div>
</header>
<main class="flex-grow-1 py-4 px-3 mx-auto w-100" style="max-width:680px">

  <div class="card mb-3">
    <div class="card-body">
      <?php if ($feedback): ?>
      <div class="alert alert-<?= $feedback['type'] ?>" id="feedback" role="alert">
        <?= htmlspecialchars($feedback['msg']) ?>
      </div>
      <?php else: ?>
      <div class="alert d-none" id="feedback" role="alert"></div>
      <?php endif ?>

      <form method="GET" action="/admin/" id="session-form">
        <label for="session" class="form-label"><?= htmlspecialchars($t['session_label']) ?></label>
        <div class="d-flex gap-2 flex-wrap align-items-start">
          <select name="session_uid" id="session" class="form-select">
            <?php foreach ($sessions as $s):
              $venue = trim(str_replace(['\\,', '\\\\'], [',', '\\'], explode('\\n', $s['location'])[0]));
              $cnt   = (int) ($counts[$s['uid']] ?? 0);
            ?>
            <option value="<?= htmlspecialchars($s['uid']) ?>"
              data-location="<?= htmlspecialchars($venue) ?>"
              <?= $s['uid'] === $sessionUid ? 'selected' : '' ?>>
              <?= htmlspecialchars($s['label']) ?> (<?= $cnt ?>)
            </option>
            <?php endforeach ?>
          </select>
          <button type="submit" class="btn btn-outline-secondary" id="btn-voir"><?= htmlspecialchars($t['view']) ?></button>
          <form method="GET" action="/api/admin/checkins.php" class="d-flex gap-1 ms-auto">
            <select name="format" class="form-select" style="width:auto">
              <option value="grist">Grist</option>
              <option value="csv">CSV</option>
            </select>
            <button type="submit" class="btn btn-outline-secondary"><?= htmlspecialchars($t['export']) ?></button>
          </form>
        </div>
        <?php if ($showVenue): ?>
        <?= $showLink ? '<a' : '<span' ?> id="session-location" class="d-none mt-1 small text-muted text-decoration-none d-block"<?= $showLink ? ' href="#"' : '' ?>>
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
        <?php if ($showMap): ?><div id="map" class="d-none rounded mt-2" style="height:220px"></div><?php endif ?>
        <?php endif ?>
      </form>
    </div>
  </div>

<div class="card">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th><?= htmlspecialchars($t['nickname_col']) ?></th>
          <th><?= htmlspecialchars($t['date_col']) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody id="tbody">
        <?php foreach ($checkins as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['nickname']) ?></td>
          <td><?= htmlspecialchars((new DateTimeImmutable($c['created_at']))->format('d/m/Y')) ?></td>
          <td class="text-end">
            <form method="POST" action="/admin/" style="display:inline">
              <input type="hidden" name="checkin_id" value="<?= htmlspecialchars($c['id']) ?>">
              <input type="hidden" name="session_uid" value="<?= htmlspecialchars($sessionUid) ?>">
              <button type="submit" class="btn btn-outline-danger btn-sm"><?= htmlspecialchars($t['delete']) ?></button>
            </form>
          </td>
        </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>

</main>

<footer class="border-top bg-white py-2 px-3">
  <div class="d-flex flex-wrap justify-content-center align-items-center column-gap-3 row-gap-1">
    <span>
      <?php foreach ($supportedLangs as $code): ?>
        <?php if ($code === $lang): ?>
          <span title="<?= strtoupper($code) ?>" style="opacity:.4;cursor:default"><?= $langFlag[$code] ?></span>
        <?php else: ?>
          <a href="<?= htmlspecialchars($langUrlFor($code)) ?>" title="<?= strtoupper($code) ?>" style="text-decoration:none"><?= $langFlag[$code] ?></a>
        <?php endif ?>
      <?php endforeach ?>
    </span>
    <a href="https://github.com/sponsors/holyhope" target="_blank" rel="noopener" class="text-secondary small">♥ Soutenir ce projet</a>
    <span class="text-muted small d-none d-sm-inline"><?= $fmtBytes($dbSize) ?> · <?= $fmtBytes($cacheSize) ?></span>
    <?php if ($version): ?><span class="text-muted small d-none d-sm-inline"><?= htmlspecialchars($version) ?></span><?php endif ?>
  </div>
</footer>

<?php if ($showMap): ?><script src="/assets/leaflet.min.js"></script><?php endif ?>
<script src="/assets/admin.js"></script>
</body>
</html>

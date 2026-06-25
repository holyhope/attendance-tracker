<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/Calendar.php';
require_once __DIR__ . '/../../src/Geocoder.php';

$config = require __DIR__ . '/../../config.php';

preg_match('/^([a-z]{2})/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'fr', $m);
$lang = in_array(strtolower($m[1] ?? 'fr'), ['en']) ? 'en' : 'fr';

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
    $feedback = ['type' => 'success', 'msg' => 'Entrée supprimée.'];
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
<body class="bg-light py-4 px-3"
  data-session-uid="<?= htmlspecialchars($sessionUid) ?>"
  data-show-location="<?= htmlspecialchars(json_encode($showLocation)) ?>"
  data-session-coords="<?= htmlspecialchars(json_encode($sessionCoords), ENT_QUOTES) ?>">
<main class="mx-auto" style="max-width:680px">

  <a href="/" class="btn btn-outline-secondary btn-sm mb-3">← Accueil</a>

  <div class="card mb-3">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0 d-flex align-items-center gap-2">
          <img src="/assets/icon.svg" alt="" width="28" height="28">
          Administration — <?= htmlspecialchars($config['association_name']) ?>
        </h1>
        <form method="GET" action="/api/admin/checkins.php" class="d-flex gap-1 ms-auto">
          <select name="format" class="form-select form-select-sm" style="width:auto">
            <option value="grist">Grist</option>
            <option value="csv">CSV</option>
          </select>
          <button type="submit" class="btn btn-outline-secondary btn-sm">Exporter</button>
        </form>
      </div>

      <?php if ($feedback): ?>
      <div class="alert alert-<?= $feedback['type'] ?>" id="feedback" role="alert">
        <?= htmlspecialchars($feedback['msg']) ?>
      </div>
      <?php else: ?>
      <div class="alert d-none" id="feedback" role="alert"></div>
      <?php endif ?>

      <form method="GET" action="/admin/" id="session-form">
        <label for="session" class="form-label">Séance</label>
        <div class="d-flex gap-2 flex-wrap">
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
          <button type="submit" class="btn btn-outline-secondary" id="btn-voir">Voir</button>
        </div>
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
      </form>
    </div>
  </div>

<div class="card">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Pseudonyme</th>
          <th>Date</th>
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
              <button type="submit" class="btn btn-outline-danger btn-sm">Supprimer</button>
            </form>
          </td>
        </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>

</main>

<?php if ($showMap): ?><script src="/assets/leaflet.min.js"></script><?php endif ?>
<script src="/assets/admin.js"></script>
</body>
</html>

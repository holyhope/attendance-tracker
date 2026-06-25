<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/Calendar.php';

$config = require __DIR__ . '/../../config.php';

preg_match('/^([a-z]{2})/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'fr', $m);
$lang = in_array(strtolower($m[1] ?? 'fr'), ['en']) ? 'en' : 'fr';

// Handle delete (POST + PRG)
$feedback = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $checkinId  = trim($_POST['checkin_id']  ?? '');
    $sessionUid = trim($_POST['session_uid'] ?? '');
    if ($checkinId) {
        $stmt = Database::get()->prepare('DELETE FROM checkins WHERE id = ?');
        $stmt->execute([$checkinId]);
    }
    header('Location: /admin/?session_uid=' . urlencode($sessionUid) . '&deleted=1');
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
</head>
<body class="bg-light py-4 px-3">
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
            <?php foreach ($sessions as $s): ?>
            <?php $cnt = (int) ($counts[$s['uid']] ?? 0) ?>
            <option value="<?= htmlspecialchars($s['uid']) ?>"
              <?= $s['uid'] === $sessionUid ? 'selected' : '' ?>>
              <?= htmlspecialchars($s['label']) ?> (<?= $cnt ?>)
            </option>
            <?php endforeach ?>
          </select>
          <button type="submit" class="btn btn-outline-secondary" id="btn-voir">Voir</button>
        </div>
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

<script>
  let sessionUid  = <?= json_encode($sessionUid) ?>;

  function showFeedback(msg, type) {
    const el = document.getElementById('feedback');
    el.textContent = msg;
    el.className = `alert alert-${type === 'success' ? 'success' : 'danger'}`;
    setTimeout(() => el.classList.add('d-none'), 4000);
  }

  function renderCheckins(checkins) {
    const tbody = document.getElementById('tbody');
    tbody.innerHTML = '';
    checkins.forEach(c => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${c.nickname}</td>
        <td>${new Date(c.created_at).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' })}</td>
        <td class="text-end">
          <form method="POST" action="/admin/" style="display:inline">
            <input type="hidden" name="checkin_id" value="${c.id}">
            <input type="hidden" name="session_uid" value="${sessionUid}">
            <button type="submit" class="btn btn-outline-danger btn-sm">Supprimer</button>
          </form>
        </td>`;
      tbody.appendChild(tr);
    });
  }

  // Progressive enhancement
  document.getElementById('btn-voir').classList.add('d-none');

  // Session change — fetch checkins without reload
  document.getElementById('session').addEventListener('change', ({ target }) => {
    sessionUid = target.value;
    history.pushState({ sessionUid }, '', `/admin/?session_uid=${encodeURIComponent(sessionUid)}`);
    fetch(`/api/admin/checkins.php?session_uid=${encodeURIComponent(sessionUid)}`)
      .then(r => r.json())
      .then(({ checkins = [] }) => renderCheckins(checkins));
  });

  window.addEventListener('popstate', ({ state }) => {
    if (!state?.sessionUid) return;
    sessionUid = state.sessionUid;
    document.getElementById('session').value = sessionUid;
    fetch(`/api/admin/checkins.php?session_uid=${encodeURIComponent(sessionUid)}`)
      .then(r => r.json())
      .then(({ checkins = [] }) => renderCheckins(checkins));
  });

  // Delete — intercept form submit in tbody
  document.getElementById('tbody').addEventListener('submit', async e => {
    e.preventDefault();
    if (!confirm('Supprimer cette entrée ?')) return;
    const checkinId = e.target.querySelector('[name="checkin_id"]').value;
    const res = await fetch('/api/admin/delete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ checkin_id: checkinId }),
    }).then(r => r.json());
    if (res.ok) {
      e.target.closest('tr').remove();
      const sel = document.getElementById('session');
      sel.options[sel.selectedIndex].text =
        sel.options[sel.selectedIndex].text.replace(/\((\d+)\)/, (_, n) => `(${Math.max(0, n - 1)})`)
    } else {
      showFeedback(res.error || 'Erreur.', 'error');
    }
  });

</script>
</body>
</html>

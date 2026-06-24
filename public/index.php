<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Calendar.php';
require_once __DIR__ . '/../src/CheckinService.php';

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
    labelFormat: $config['session_label_format'] ?? '{datetime} — {title}',
);
try {
    $sessions = $calendar->getSessions($lang);
} catch (RuntimeException) {
    $sessions = [];
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
                setcookie(COOKIE_NAME, $nickname, time() + COOKIE_TTL, '/', '', false, false);
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

?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title) ?></title>
  <link rel="stylesheet" href="/assets/bootstrap.min.css">
</head>
<body class="bg-light py-4 px-3">
<main class="card mx-auto" style="max-width:420px">
  <div class="card-body">
    <h1 class="h4 mb-4"><?= htmlspecialchars($title) ?></h1>

    <?php if ($feedback): ?>
    <div class="alert alert-<?= $feedback['type'] ?>" role="alert">
      <?= $feedback['msg'] ?>
    </div>
    <?php endif ?>

    <form method="POST" action="" id="checkin-form">
      <div class="mb-3">
        <label for="session" class="form-label"><?= $t['session_label'] ?></label>
        <select id="session" name="session_uid" class="form-select" required>
          <?php foreach ($sessions as $s): ?>
          <option value="<?= htmlspecialchars($s['uid']) ?>"
            <?= $s['uid'] === $currentUid ? 'selected' : '' ?>>
            <?= htmlspecialchars((in_array($s['uid'], $checkedUids) ? '✅ ' : '') . $s['label']) ?>
          </option>
          <?php endforeach ?>
        </select>
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

    <div class="text-center mt-3">
      <a href="/admin/" class="text-secondary small"><?= $t['admin_link'] ?></a>
    </div>
  </div>
</main>

<script>
  // JS translations (mirrors PHP $i18n)
  const translations = {
    fr: {
      title:           name => `Pointage ${name}`,
      session_label:   'Séance',
      nickname_label:  'Pseudonyme',
      nickname_ph:     'Pseudo',
      remember:        'Mémoriser mon pseudonyme',
      btn_checkin:     'Pointer la présence',
      btn_cancel:      'Annuler le pointage',
      checked_in:      name => `Présence enregistrée pour ${name}.`,
      cancelled:       name => `Pointage annulé pour ${name}.`,
      fill_nickname:   'Entrez un pseudonyme.',
      already:         'Déjà pointé pour cette séance.',
      not_checked_in:  'Aucun pointage trouvé pour cette séance.',
      err_generic:     'Une erreur est survenue.',
    },
    en: {
      title:           name => `${'<?= $associationName ?>'} Attendance`,
      session_label:   'Session',
      nickname_label:  'Nickname',
      nickname_ph:     'Nickname',
      remember:        'Remember my nickname',
      btn_checkin:     'Check in',
      btn_cancel:      'Cancel check-in',
      checked_in:      name => `Checked in: ${name}.`,
      cancelled:       name => `Check-in cancelled for ${name}.`,
      fill_nickname:   'Enter a nickname.',
      already:         'Already checked in for this session.',
      not_checked_in:  'No check-in found for this session.',
      err_generic:     'An error occurred.',
    },
  };

  const lang             = '<?= $lang ?>';
  const t                = translations[lang] ?? translations.fr;
  const checkedUids      = <?= json_encode($checkedUids) ?>;
  const initialChecked   = [...checkedUids];
  const savedNickname    = <?= json_encode($savedNickname) ?>;

  function updateButtons(sessionUid) {
    const checked = checkedUids.includes(sessionUid);
    document.getElementById('btn-checkin').classList.toggle('d-none', checked);
    document.getElementById('btn-cancel').classList.toggle('d-none', !checked);
  }

  updateButtons(document.getElementById('session').value);
  document.getElementById('session').addEventListener('change', ({ target }) => updateButtons(target.value));

  const post = (path, data) => fetch(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  }).then(r => r.json());

  function showFeedback(msg, type) {
    let el = document.querySelector('.alert');
    if (!el) {
      el = document.createElement('div');
      el.setAttribute('role', 'alert');
      document.querySelector('.card-body').prepend(el);
    }
    el.textContent = msg;
    el.className = `alert alert-${type === 'success' ? 'success' : 'danger'}`;
    setTimeout(() => el.remove(), 4000);
  }

  // Cookie helpers
  const COOKIE_NAME = 'jrv_nickname';
  const getCookie   = () => document.cookie.split('; ').find(r => r.startsWith(COOKIE_NAME + '='))?.split('=')[1] ?? '';
  const setCookie   = v  => document.cookie = `${COOKIE_NAME}=${encodeURIComponent(v)}; max-age=31536000; path=/; SameSite=Strict`;
  const deleteCookie= () => document.cookie = `${COOKIE_NAME}=; max-age=0; path=/`;

  function syncCheckedState(nickname) {
    const uids = nickname === savedNickname ? initialChecked : [];
    checkedUids.length = 0;
    uids.forEach(u => checkedUids.push(u));
    const sel = document.getElementById('session');
    for (const opt of sel.options) {
      const has    = opt.text.startsWith('✅ ');
      const should = checkedUids.includes(opt.value);
      if (has && !should) opt.text = opt.text.slice(2);
      if (!has && should) opt.text = '✅ ' + opt.text;
    }
    updateButtons(sel.value);
  }

  // Sync checkbox + checked state with cookie value as user types
  document.getElementById('nickname').addEventListener('input', ({ target }) => {
    const val      = target.value.trim();
    const checkbox = document.getElementById('remember');
    const matches  = val === decodeURIComponent(getCookie());
    if (checkbox.checked !== matches) checkbox.checked = matches;
    syncCheckedState(val);
  });

  // Autocomplete
  let timer;
  document.getElementById('nickname').addEventListener('input', ({ target }) => {
    clearTimeout(timer);
    if (target.value.trim().length < 2) return;
    timer = setTimeout(() => {
      fetch(`/api/attendees.php?q=${encodeURIComponent(target.value.trim())}`)
        .then(r => r.json())
        .then(({ attendees = [] }) => {
          const dl = document.getElementById('suggestions');
          dl.innerHTML = '';
          attendees.forEach(({ nickname }) => {
            const opt = document.createElement('option');
            opt.value = nickname;
            dl.appendChild(opt);
          });
        });
    }, 200);
  });

  // Intercept form submit — use fetch instead of full reload
  document.getElementById('checkin-form').addEventListener('submit', async e => {
    e.preventDefault();
    const action     = e.submitter?.value ?? 'checkin';
    const nickname   = document.getElementById('nickname').value.trim();
    const sel        = document.getElementById('session');
    const sessionUid = sel.value;

    if (!nickname) { showFeedback(t.fill_nickname, 'error'); return; }

    if (action === 'cancel') {
      const res = await post('/api/cancel.php', { session_uid: sessionUid, nickname });
      if (res.ok) {
        showFeedback(t.cancelled(res.nickname), 'success');
        const idx = checkedUids.indexOf(sessionUid);
        if (idx !== -1) checkedUids.splice(idx, 1);
        if (sel.options[sel.selectedIndex].text.startsWith('✅ ')) {
          sel.options[sel.selectedIndex].text = sel.options[sel.selectedIndex].text.slice(2);
        }
        updateButtons(sessionUid);
      } else if (res.error?.includes('No check-in')) {
        showFeedback(t.not_checked_in, 'error');
      } else {
        showFeedback(t.err_generic, 'error');
      }
      return;
    }

    const res = await post('/api/checkin.php', { session_uid: sessionUid, nickname });
    if (res.ok) {
      if (document.getElementById('remember').checked) setCookie(nickname); else deleteCookie();
      showFeedback(t.checked_in(res.nickname), 'success');
      document.getElementById('nickname').value = '';
      checkedUids.push(sessionUid);
      if (!sel.options[sel.selectedIndex].text.startsWith('✅ ')) {
        sel.options[sel.selectedIndex].text = '✅ ' + sel.options[sel.selectedIndex].text;
      }
      updateButtons(sessionUid);
    } else if (res.error?.includes('Already')) {
      showFeedback(t.already, 'error');
    } else {
      showFeedback(t.err_generic, 'error');
    }
  });
</script>
</body>
</html>

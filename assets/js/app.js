const body             = document.body;
const lang             = body.dataset.lang || 'fr';
const checkedUids      = JSON.parse(body.dataset.checkedUids || '[]');
const initialChecked   = [...checkedUids];
const savedNickname    = JSON.parse(body.dataset.savedNickname || '""');
const sessionCoords    = JSON.parse(body.dataset.sessionCoords || '{}');
const associationName  = body.dataset.associationName || '';
const showLocation = JSON.parse(body.dataset.showLocation || '"with_map"');
const showVenue    = showLocation !== false;
const showLink     = showLocation === 'only_link' || showLocation === 'with_map';
const showMap      = showLocation === 'with_map';

const translations = {
  fr: {
    title:          name => `Pointage ${name}`,
    session_label:  'Séance',
    nickname_label: 'Pseudonyme',
    nickname_ph:    'Pseudo',
    remember:       'Mémoriser mon pseudonyme',
    btn_checkin:    'Pointer la présence',
    btn_cancel:     'Annuler le pointage',
    checked_in:     name => `Présence enregistrée pour ${name}.`,
    cancelled:      name => `Pointage annulé pour ${name}.`,
    fill_nickname:  'Entrez un pseudonyme.',
    already:        'Déjà pointé pour cette séance.',
    not_checked_in: 'Aucun pointage trouvé pour cette séance.',
    err_generic:    'Une erreur est survenue.',
  },
  en: {
    title:          name => `${associationName} ${name} Attendance`,
    session_label:  'Session',
    nickname_label: 'Nickname',
    nickname_ph:    'Nickname',
    remember:       'Remember my nickname',
    btn_checkin:    'Check in',
    btn_cancel:     'Cancel check-in',
    checked_in:     name => `Checked in: ${name}.`,
    cancelled:      name => `Check-in cancelled for ${name}.`,
    fill_nickname:  'Enter a nickname.',
    already:        'Already checked in for this session.',
    not_checked_in: 'No check-in found for this session.',
    err_generic:    'An error occurred.',
  },
};

const t          = translations[lang] ?? translations.fr;
const sessionSel = document.getElementById('session');

function updateButtons(sessionUid) {
  const checked = checkedUids.includes(sessionUid);
  document.getElementById('btn-checkin').classList.toggle('d-none', checked);
  document.getElementById('btn-cancel').classList.toggle('d-none', !checked);
}

updateButtons(sessionSel.value);
sessionSel.addEventListener('change', ({ target }) => updateButtons(target.value));

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

const COOKIE_NAME  = 'jrv_nickname';
const getCookie    = () => document.cookie.split('; ').find(r => r.startsWith(COOKIE_NAME + '='))?.split('=')[1] ?? '';
const setCookie    = v => { document.cookie = `${COOKIE_NAME}=${encodeURIComponent(v)}; max-age=31536000; path=/; SameSite=Strict`; };
const deleteCookie = () => { document.cookie = `${COOKIE_NAME}=; max-age=0; path=/`; };

function syncCheckedState(nickname) {
  const uids = nickname === savedNickname ? initialChecked : [];
  checkedUids.length = 0;
  uids.forEach(u => checkedUids.push(u));
  const sel = document.getElementById('session');
  for (const opt of sel.options) {
    const has    = opt.text.startsWith('✅ ');
    const should = checkedUids.includes(opt.value);
    if (has && !should) opt.text = opt.text.slice(2);
    if (!has && should)  opt.text = '✅ ' + opt.text;
  }
  updateButtons(sel.value);
}

let timer;
document.getElementById('nickname').addEventListener('input', ({ target }) => {
  const val      = target.value.trim();
  const checkbox = document.getElementById('remember');
  const matches  = val === decodeURIComponent(getCookie());
  if (checkbox.checked !== matches) checkbox.checked = matches;
  syncCheckedState(val);

  clearTimeout(timer);
  if (val.length < 2) return;
  timer = setTimeout(() => {
    fetch(`/api/attendees.php?q=${encodeURIComponent(val)}`)
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

// ── Map ──────────────────────────────────────────────────────────────────────
let map = null, marker = null;

function initMap(coords) {
  document.getElementById('map-notice').classList.add('d-none');
  const mapEl = document.getElementById('map');
  mapEl.classList.remove('d-none');
  requestAnimationFrame(() => {
    if (!map) {
      map = L.map('map', { zoomControl: true, attributionControl: true });
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19,
      }).addTo(map);
      marker = L.marker([coords.lat, coords.lon]).addTo(map);
      map.setView([coords.lat, coords.lon], 15);
    } else {
      marker.setLatLng([coords.lat, coords.lon]);
      map.setView([coords.lat, coords.lon], 15);
      map.invalidateSize();
    }
  });
}

function updateMap(uid) {
  const coords = sessionCoords[uid];
  const mapEl  = document.getElementById('map');
  if (!coords || coords.lat == null) { mapEl.classList.add('d-none'); return; }
  if (map) initMap(coords);
}

function updateLocation(sel, uid) {
  const venue  = sel.options[sel.selectedIndex]?.dataset.location ?? '';
  const coords = sessionCoords[uid];
  const el     = document.getElementById('session-location');
  if (!venue) { el.classList.add('d-none'); return; }

  document.getElementById('venue-name').textContent = '📍 ' + venue;
  el.classList.remove('d-none');
  el.onclick = null;
  el.style.cursor = '';

  if (showMap) {
    const notice = document.getElementById('map-notice');
    if (coords?.lat != null) {
      el.onclick = e => { e.preventDefault(); initMap(coords); };
      el.style.cursor = 'pointer';
      if (!map) notice.classList.remove('d-none');
    } else {
      notice.classList.add('d-none');
    }
  } else if (showLink) {
    if (coords?.lat != null) {
      el.href = `https://www.openstreetmap.org/?mlat=${coords.lat}&mlon=${coords.lon}#map=15/${coords.lat}/${coords.lon}`;
      el.target = '_blank';
      el.rel = 'noopener';
    } else {
      el.removeAttribute('href');
    }
  }
}

if (showVenue) updateLocation(sessionSel, sessionSel.value);
if (showMap)   updateMap(sessionSel.value);
sessionSel.addEventListener('change', ({ target }) => {
  if (showVenue) updateLocation(target, target.value);
  if (showMap)   updateMap(target.value);
});

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

const body          = document.body;
const lang          = body.dataset.lang || 'fr';
const t             = JSON.parse(body.dataset.i18n || '{}');
let sessionUid      = body.dataset.sessionUid || '';
const sessionCoords = JSON.parse(body.dataset.sessionCoords || '{}');
const showLocation = JSON.parse(body.dataset.showLocation || '"with_map"');
const showVenue    = showLocation !== false;
const showLink     = showLocation === 'only_link' || showLocation === 'with_map';
const showMap      = showLocation === 'with_map';

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
      el.tabIndex = 0;
    } else {
      el.removeAttribute('href');
      el.tabIndex = -1;
    }
  }
}

const sessionSel = document.getElementById('session');
if (showVenue) updateLocation(sessionSel, sessionUid);
if (showMap)   updateMap(sessionUid);

function showFeedback(msg, type) {
  const el = document.getElementById('feedback');
  el.textContent = msg;
  el.className = `alert alert-${type === 'success' ? 'success' : 'danger'}`;
  clearTimeout(el._timer);
  el._timer = setTimeout(() => {
    el.className = 'visually-hidden';
    el.textContent = '';
  }, 4000);
}

function makeCheckinRow(c, highlight = false) {
  const date = new Date(c.created_at).toLocaleDateString(lang, { day: '2-digit', month: '2-digit', year: 'numeric' });

  const form = document.createElement('form');
  form.method = 'POST';
  form.action = '/admin/';
  form.style.display = 'inline';
  const inId  = document.createElement('input'); inId.type  = 'hidden'; inId.name  = 'checkin_id';  inId.value = c.id;
  const inSid = document.createElement('input'); inSid.type = 'hidden'; inSid.name = 'session_uid'; inSid.value = sessionUid;
  const btn   = document.createElement('button'); btn.type = 'button'; btn.className = 'btn btn-outline-danger btn-sm';
  btn.dataset.deleteId = c.id;
  btn.textContent = t.delete || 'Supprimer';
  form.append(inId, inSid, btn);

  const tr = document.createElement('tr');
  if (highlight) {
    tr.className = 'row-highlight';
    tr.addEventListener('animationend', () => tr.classList.remove('row-highlight'), { once: true });
  }
  const tdName = document.createElement('td'); tdName.textContent = c.nickname;
  const tdDate = document.createElement('td'); tdDate.textContent = date;
  const tdAct  = document.createElement('td'); tdAct.className = 'text-end'; tdAct.appendChild(form);
  tr.append(tdName, tdDate, tdAct);
  return tr;
}

// Upgrade server-rendered delete buttons (type=submit → type=button + data-delete-id)
// so the two-step confirmation handler applies uniformly to all rows.
document.querySelectorAll('#tbody [name="checkin_id"]').forEach(input => {
  const btn = input.closest('form')?.querySelector('[type="submit"]');
  if (!btn) return;
  btn.type = 'button';
  btn.dataset.deleteId = input.value;
});

function renderCheckins(checkins) {
  const tbody = document.getElementById('tbody');
  tbody.innerHTML = '';
  checkins.forEach(c => tbody.appendChild(makeCheckinRow(c)));
}

document.getElementById('btn-voir').classList.add('d-none');

document.getElementById('session').addEventListener('change', ({ target }) => {
  sessionUid = target.value;
  const sidInput = document.querySelector('#checkin-form [name="session_uid"]');
  if (sidInput) sidInput.value = sessionUid;
  const nicknameInput = document.getElementById('checkin-nickname');
  if (nicknameInput) nicknameInput.disabled = !sessionUid;
  history.pushState({ sessionUid }, '', `/admin/?session_uid=${encodeURIComponent(sessionUid)}`);
  fetch(`/api/admin/checkins.php?session_uid=${encodeURIComponent(sessionUid)}`)
    .then(r => r.json())
    .then(({ checkins = [] }) => renderCheckins(checkins));
  if (showMap)   updateMap(sessionUid);
  if (showVenue) updateLocation(sessionSel, sessionUid);
});

window.addEventListener('popstate', ({ state }) => {
  if (!state?.sessionUid) return;
  sessionUid = state.sessionUid;
  document.getElementById('session').value = sessionUid;
  fetch(`/api/admin/checkins.php?session_uid=${encodeURIComponent(sessionUid)}`)
    .then(r => r.json())
    .then(({ checkins = [] }) => renderCheckins(checkins));
});

document.getElementById('checkin-form').addEventListener('submit', async e => {
  e.preventDefault();
  const input    = document.getElementById('checkin-nickname');
  const nickname = input.value.trim();
  if (!nickname) return;

  const res = await fetch('/api/admin/checkin.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ session_uid: sessionUid, nickname }),
  }).then(r => r.json());

  if (res.ok) {
    input.value = '';
    document.getElementById('tbody').appendChild(makeCheckinRow(res.checkin, true));
    const sel = document.getElementById('session');
    sel.options[sel.selectedIndex].text =
      sel.options[sel.selectedIndex].text.replace(/\((\d+)\)/, (_, n) => `(${+n + 1})`);
    showFeedback((t.checked_in || '{name}').replace('{name}', res.checkin.nickname), 'success');
  } else {
    const msg = res.error?.includes('Already') ? (t.already || res.error) : (t.err_generic || res.error);
    showFeedback(msg, 'error');
  }
});

document.getElementById('tbody').addEventListener('click', async e => {
  const btn = e.target.closest('[data-delete-id], [data-confirm-delete]');
  if (!btn) return;

  if (btn.dataset.confirmDelete) {
    // Second click — proceed with delete
    const checkinId = btn.dataset.confirmDelete;
    const res = await fetch('/api/admin/delete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ checkin_id: checkinId }),
    }).then(r => r.json());
    if (res.ok) {
      btn.closest('tr').remove();
      const sel = document.getElementById('session');
      sel.options[sel.selectedIndex].text =
        sel.options[sel.selectedIndex].text.replace(/\((\d+)\)/, (_, n) => `(${Math.max(0, n - 1)})`);
    } else {
      showFeedback(res.error || t.err_generic || 'Erreur.', 'error');
    }
    return;
  }

  // First click — enter confirmation state
  const checkinId = btn.dataset.deleteId;
  btn.removeAttribute('data-delete-id');
  btn.dataset.confirmDelete = checkinId;
  btn.textContent = t.confirm_delete || 'Confirmer ?';
  btn.classList.replace('btn-outline-danger', 'btn-danger');

  const cancelBtn = document.createElement('button');
  cancelBtn.type = 'button';
  cancelBtn.className = 'btn btn-outline-secondary btn-sm ms-1';
  cancelBtn.textContent = t.cancel_action || 'Annuler';
  cancelBtn.onclick = () => {
    btn.removeAttribute('data-confirm-delete');
    btn.dataset.deleteId = checkinId;
    btn.textContent = t.delete || 'Supprimer';
    btn.classList.replace('btn-danger', 'btn-outline-danger');
    cancelBtn.remove();
  };
  btn.after(cancelBtn);
});

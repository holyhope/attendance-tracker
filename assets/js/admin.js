const body          = document.body;
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
    } else {
      el.removeAttribute('href');
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

document.getElementById('btn-voir').classList.add('d-none');

document.getElementById('session').addEventListener('change', ({ target }) => {
  sessionUid = target.value;
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
      sel.options[sel.selectedIndex].text.replace(/\((\d+)\)/, (_, n) => `(${Math.max(0, n - 1)})`);
  } else {
    showFeedback(res.error || 'Erreur.', 'error');
  }
});

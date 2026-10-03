<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/master_data.php';

startSecureSession();
requireLogin();
if (!isAdminUser()) {
    header('Location: dashboard.php?forbidden=route_master');
    exit;
}

$conn  = getDBConnection();
ensureTHCSchema($conn);
$csrf  = generateCSRFToken();

$routes = getRouteMasters($conn, false); // all incl inactive
$cities = $conn->query(
    "SELECT id, city_name, state_code FROM cities ORDER BY city_name LIMIT 2000"
)->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(appBrandTitle('Route Master')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
<style>
.rm-wrap{max-width:1300px;margin:16px auto;padding:0 16px}
.rm-hero{background:linear-gradient(120deg,#0c2a45,#134e6b);border-radius:14px;padding:18px 22px;color:#fff;display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
.rm-hero h2{margin:0;font-size:1.2rem}
.rm-hero p{margin:4px 0 0;font-size:.78rem;color:#b6d4e8}
.rm-card{background:#fff;border:1px solid #dce4ea;border-radius:12px;padding:16px;margin-bottom:14px}
.routes-table{width:100%;border-collapse:collapse}
.routes-table th{background:#f1f5f9;color:#475569;font-size:.7rem;font-weight:700;text-transform:uppercase;padding:8px 10px;border-bottom:2px solid #e2e8f0}
.routes-table td{padding:9px 10px;border-bottom:1px solid #f1f5f9;font-size:.82rem;vertical-align:middle}
.routes-table tr:hover td{background:#f8fafc}
.badge-mode{font-size:.68rem;padding:3px 8px;border-radius:20px;font-weight:600}
.badge-mode.Surface{background:#dbeafe;color:#1d4ed8}
.badge-mode.Air{background:#fce7f3;color:#be185d}
.badge-mode.Rail{background:#fef9c3;color:#854d0e}
.badge-mode.FTL{background:#dcfce7;color:#15803d}
.badge-mode.Co-loader{background:#f3e8ff;color:#6d28d9}
.badge-mode.Data\ Movement{background:#ffedd5;color:#c2410c}
.pts-badge{display:inline-flex;align-items:center;gap:4px;flex-wrap:wrap}
.pts-badge span{background:#e0f2fe;color:#0369a1;font-size:.65rem;padding:2px 7px;border-radius:10px;white-space:nowrap}
.pts-badge .arrow{color:#94a3b8;font-size:.7rem}
/* Point builder */
.pt-row{display:grid;grid-template-columns:32px 1fr 1fr auto;gap:8px;align-items:center;margin-bottom:6px;padding:6px 8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px}
.pt-seq{width:26px;height:26px;background:#0c2a45;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;flex-shrink:0}
.status-active{color:#16a34a;font-weight:600;font-size:.75rem}
.status-inactive{color:#dc2626;font-weight:600;font-size:.75rem}
</style>
</head>
<body>
<div class="app-shell">
<aside class="app-sidebar">
  <?php renderAppBrand(); ?>
  <nav class="sidebar-nav"><?php renderSidebarNav(); ?></nav>
  <?php renderSidebarFooter(); ?>
</aside>
<div class="app-main">
<?php renderAppHeader('Route Master', '🗺️'); ?>
<main class="app-body">
<div class="rm-wrap">

<div class="rm-hero">
  <div>
    <h2>🗺️ Route Master</h2>
    <p>Define routes with origin, touching points and destination. Used in THC creation.</p>
  </div>
  <button class="btn btn-light btn-sm fw-bold" onclick="openModal()">＋ Add Route</button>
</div>

<div class="rm-card">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <b style="font-size:.85rem">All Routes (<?= count($routes) ?>)</b>
    <input class="form-control form-control-sm" style="width:220px" id="rmSearch" placeholder="Search routes…" oninput="filterRoutes()">
  </div>
  <div style="overflow:auto">
  <table class="routes-table" id="routesTable">
    <thead>
      <tr>
        <th>#</th>
        <th>Route Code</th>
        <th>Route Name</th>
        <th>Mode</th>
        <th>Route Sequence</th>
        <th>Distance (km)</th>
        <th>Status</th>
        <th style="text-align:center">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($routes)): ?>
      <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:24px">No routes yet. Click "Add Route" to create one.</td></tr>
    <?php else: ?>
      <?php foreach ($routes as $i => $r):
        $pts = getRoutePoints($conn, (int)$r['id']);
        // Build sequence: Origin → pt1 → pt2 → Destination
        $seq = [['name' => htmlspecialchars($r['origin_name'] ?? '—'), 'type' => 'origin']];
        foreach ($pts as $pt) {
            // skip if same as origin or dest (they're included explicitly)
            $seq[] = ['name' => htmlspecialchars($pt['city_name'] ?? ''), 'type' => 'touch'];
        }
        $seq[] = ['name' => htmlspecialchars($r['destination_name'] ?? '—'), 'type' => 'dest'];
      ?>
      <tr data-search="<?= strtolower(htmlspecialchars($r['route_code'].' '.$r['route_name'].' '.($r['origin_name']??'').' '.($r['destination_name']??''))) ?>">
        <td style="color:#94a3b8;font-size:.72rem"><?= $i+1 ?></td>
        <td><code style="font-size:.75rem"><?= htmlspecialchars($r['route_code']) ?></code></td>
        <td><b><?= htmlspecialchars($r['route_name']) ?></b></td>
        <td><span class="badge-mode <?= htmlspecialchars($r['transport_mode']) ?>"><?= htmlspecialchars($r['transport_mode']) ?></span></td>
        <td>
          <div class="pts-badge">
            <?php foreach ($seq as $si => $s): ?>
              <?php if ($si > 0): ?><span class="arrow">→</span><?php endif; ?>
              <span style="<?= $s['type']==='origin'?'background:#dcfce7;color:#15803d':($s['type']==='dest'?'background:#fee2e2;color:#b91c1c':'') ?>"><?= $s['name'] ?></span>
            <?php endforeach; ?>
          </div>
        </td>
        <td style="color:#64748b"><?= $r['total_distance_km'] ? number_format((float)$r['total_distance_km'],1) . ' km' : '—' ?></td>
        <td><span class="status-<?= strtolower($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
        <?php
          $editData = json_encode([
            'id'                  => $r['id'],
            'route_name'          => $r['route_name'],
            'origin_city_id'      => $r['origin_city_id'],
            'destination_city_id' => $r['destination_city_id'],
            'transport_mode'      => $r['transport_mode'],
            'total_distance_km'   => $r['total_distance_km'],
            'status'              => $r['status'],
            'points'              => $pts,
          ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        ?>
        <td style="text-align:center;white-space:nowrap">
          <button class="btn btn-outline-primary btn-sm" style="font-size:.7rem" onclick='editRoute(<?= $editData ?>)'>✏️ Edit</button>
          <button class="btn btn-outline-danger btn-sm" style="font-size:.7rem;margin-left:4px" onclick="deleteRoute(<?= (int)$r['id'] ?>, '<?= htmlspecialchars($r['route_name'], ENT_QUOTES) ?>')">🗑️</button>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

</div><!-- /.rm-wrap -->
</main>
</div><!-- /.app-main -->
</div><!-- /.app-shell -->

<!-- ─── Add / Edit Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="routeModal" tabindex="-1" aria-labelledby="routeModalLabel" aria-modal="true">
<div class="modal-dialog modal-lg modal-dialog-scrollable">
<div class="modal-content">
<div class="modal-header" style="background:#0c2a45;color:#fff">
  <h5 class="modal-title" id="routeModalLabel">Add Route</h5>
  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
  <div id="formErr" class="alert alert-danger d-none"></div>
  <input type="hidden" id="fRouteId" value="0">

  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <label class="form-label fw-bold" style="font-size:.78rem">Route Name <span class="text-danger">*</span></label>
      <input class="form-control" id="fRouteName" placeholder="e.g. Bangalore → Gurgaon Surface">
    </div>
    <div class="col-md-6">
      <label class="form-label fw-bold" style="font-size:.78rem">Transport Mode <span class="text-danger">*</span></label>
      <select class="form-select" id="fMode">
        <option value="Surface">Surface</option>
        <option value="Air">Air</option>
        <option value="Rail">Rail</option>
        <option value="FTL">FTL</option>
        <option value="Co-loader">Co-loader</option>
        <option value="Data Movement">Data Movement</option>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-bold" style="font-size:.78rem">Origin City <span class="text-danger">*</span></label>
      <select class="form-select" id="fOrigin">
        <option value="">— Select —</option>
        <?php foreach ($cities as $c): ?>
        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['city_name'] . ' (' . $c['state_code'] . ')') ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label fw-bold" style="font-size:.78rem">Destination City <span class="text-danger">*</span></label>
      <select class="form-select" id="fDest">
        <option value="">— Select —</option>
        <?php foreach ($cities as $c): ?>
        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['city_name'] . ' (' . $c['state_code'] . ')') ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label fw-bold" style="font-size:.78rem">Total Distance (km)</label>
      <input class="form-control" type="number" min="0" step="0.1" id="fDistance" placeholder="Optional">
    </div>
    <div class="col-md-4">
      <label class="form-label fw-bold" style="font-size:.78rem">Status</label>
      <select class="form-select" id="fStatus">
        <option value="Active">Active</option>
        <option value="Inactive">Inactive</option>
      </select>
    </div>
  </div>

  <!-- Touching Points Builder -->
  <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <b style="font-size:.8rem">Touching Points <small style="font-weight:400;color:#64748b">(between origin and destination, in sequence)</small></b>
      <button class="btn btn-sm btn-outline-primary" type="button" onclick="addPoint()">＋ Add Point</button>
    </div>
    <div id="ptList"></div>
    <div style="font-size:.7rem;color:#94a3b8;margin-top:6px">
      Route preview: <span id="routePreview" style="font-weight:600;color:#0c2a45">—</span>
    </div>
  </div>
</div>
<div class="modal-footer">
  <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
  <button class="btn btn-primary btn-sm" id="saveRouteBtn" onclick="saveRoute()">💾 Save Route</button>
</div>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const csrf = <?= json_encode($csrf) ?>;
const cities = <?= json_encode(array_map(function($c) { return ['id'=>(int)$c['id'],'name'=>$c['city_name'].' ('.$c['state_code'].')']; }, $cities)) ?>;
const cityMap = {};
cities.forEach(c => cityMap[c.id] = c.name);

let bsModal;
document.addEventListener('DOMContentLoaded', () => {
  bsModal = new bootstrap.Modal(document.getElementById('routeModal'));
  ['fOrigin','fDest'].forEach(id => document.getElementById(id).addEventListener('change', updatePreview));
});

function filterRoutes() {
  const q = document.getElementById('rmSearch').value.toLowerCase();
  document.querySelectorAll('#routesTable tbody tr[data-search]').forEach(tr => {
    tr.style.display = !q || tr.dataset.search.includes(q) ? '' : 'none';
  });
}

// ── Modal open/reset ────────────────────────────────────────────────
function openModal(data) {
  document.getElementById('formErr').classList.add('d-none');
  document.getElementById('fRouteId').value  = data?.id || 0;
  document.getElementById('fRouteName').value= data?.route_name || '';
  document.getElementById('fMode').value     = data?.transport_mode || 'Surface';
  document.getElementById('fOrigin').value   = data?.origin_city_id || '';
  document.getElementById('fDest').value     = data?.destination_city_id || '';
  document.getElementById('fDistance').value = data?.total_distance_km || '';
  document.getElementById('fStatus').value   = data?.status || 'Active';
  document.getElementById('routeModalLabel').textContent = data?.id > 0 ? 'Edit Route' : 'Add Route';

  // Build points
  document.getElementById('ptList').innerHTML = '';
  if (data?.points?.length) {
    data.points.forEach(pt => addPoint(pt.city_id, pt.point_label));
  }
  updatePreview();
  bsModal.show();
}

function editRoute(data) { openModal(data); }

// ── Touching point rows ─────────────────────────────────────────────
let ptIndex = 0;
function addPoint(cityId, label) {
  const idx = ++ptIndex;
  const opts = cities.map(c =>
    `<option value="${c.id}" ${String(c.id)===String(cityId)?'selected':''}>${c.name}</option>`
  ).join('');
  const div = document.createElement('div');
  div.className = 'pt-row';
  div.dataset.idx = idx;
  div.innerHTML = `
    <div class="pt-seq" id="seq_${idx}">?</div>
    <select class="form-select form-select-sm" id="ptCity_${idx}" onchange="updatePreview()">
      <option value="">— City —</option>${opts}
    </select>
    <input class="form-control form-control-sm" id="ptLabel_${idx}" placeholder="Label (e.g. SBC)" value="${label||''}">
    <button class="btn btn-sm btn-outline-danger" onclick="removePoint(${idx})">✕</button>`;
  document.getElementById('ptList').appendChild(div);
  updatePreview();
}

function removePoint(idx) {
  document.querySelector(`[data-idx="${idx}"]`)?.remove();
  updatePreview();
}

function updatePreview() {
  const origin = cityMap[document.getElementById('fOrigin').value] || '?';
  const dest   = cityMap[document.getElementById('fDest').value] || '?';
  const rows   = document.querySelectorAll('#ptList .pt-row');
  let parts = [`<span style="color:#15803d;font-weight:700">${origin}</span>`];
  rows.forEach((row, i) => {
    row.querySelector('[id^=seq_]').textContent = i + 1;
    const sel = row.querySelector('[id^=ptCity_]');
    const cName = cityMap[sel?.value] || '…';
    parts.push(`<span>${cName}</span>`);
  });
  parts.push(`<span style="color:#b91c1c;font-weight:700">${dest}</span>`);
  document.getElementById('routePreview').innerHTML = parts.join(' <span style="color:#94a3b8">→</span> ');
}

// ── Save Route ──────────────────────────────────────────────────────
async function saveRoute() {
  const btn = document.getElementById('saveRouteBtn');
  btn.disabled = true; btn.textContent = 'Saving…';
  const errEl = document.getElementById('formErr');
  errEl.classList.add('d-none');

  // Build points array
  const rows = document.querySelectorAll('#ptList .pt-row');
  const points = [];
  let valid = true;
  rows.forEach((row, i) => {
    const cid = row.querySelector('[id^=ptCity_]')?.value;
    if (!cid) { valid = false; return; }
    points.push({ city_id: parseInt(cid), point_sequence: i + 1, point_label: row.querySelector('[id^=ptLabel_]')?.value || '' });
  });
  if (!valid) {
    errEl.textContent = 'Select a city for each touching point.';
    errEl.classList.remove('d-none');
    btn.disabled = false; btn.textContent = '💾 Save Route';
    return;
  }

  const f = new FormData();
  f.append('csrf_token', csrf);
  f.append('action', 'save');
  f.append('route_id', document.getElementById('fRouteId').value);
  f.append('route_name', document.getElementById('fRouteName').value);
  f.append('transport_mode', document.getElementById('fMode').value);
  f.append('origin_city_id', document.getElementById('fOrigin').value);
  f.append('destination_city_id', document.getElementById('fDest').value);
  f.append('total_distance_km', document.getElementById('fDistance').value);
  f.append('status', document.getElementById('fStatus').value);
  f.append('route_points', JSON.stringify(points));

  try {
    const r = await fetch('api/save_route_master.php', { method: 'POST', body: f });
    const d = await r.json();
    if (d.success) {
      bsModal.hide();
      location.reload();
    } else {
      errEl.textContent = d.message || 'Save failed';
      errEl.classList.remove('d-none');
    }
  } catch(e) {
    errEl.textContent = 'Network error';
    errEl.classList.remove('d-none');
  }
  btn.disabled = false; btn.textContent = '💾 Save Route';
}

// ── Delete Route ────────────────────────────────────────────────────
async function deleteRoute(id, name) {
  if (!confirm(`Delete route "${name}"?\nThis cannot be undone if no THC references it.`)) return;
  const f = new FormData();
  f.append('csrf_token', csrf);
  f.append('action', 'delete');
  f.append('route_id', id);
  const r = await fetch('api/save_route_master.php', { method: 'POST', body: f });
  const d = await r.json();
  alert(d.message);
  if (d.success) location.reload();
}
</script>
</body>
</html>

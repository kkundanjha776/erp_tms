<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/master_data.php';

startSecureSession();
requireLogin();
requireModule('thc');

$conn  = getDBConnection();
ensureTHCSchema($conn);
$csrf  = generateCSRFToken();

$thcId = (int)($_GET['thc_id'] ?? 0);
if ($thcId <= 0) { header('Location: thc_list.php'); exit; }

$thc = getTHCById($conn, $thcId);
if (!$thc) { header('Location: thc_list.php?err=notfound'); exit; }

// Route points
$routePoints = [];
if (!empty($thc['route_id'])) {
    $routePoints = getRoutePoints($conn, (int)$thc['route_id']);
}

// Existing manifests for this THC
$mnfStmt = $conn->prepare(
    "SELECT m.*, dc.city_name AS dest_name,
            COUNT(mi.id) AS item_count
     FROM thc_manifests m
     LEFT JOIN cities dc ON dc.id = m.destination_city_id
     LEFT JOIN thc_manifest_items mi ON mi.manifest_id = m.id
     WHERE m.thc_id = ?
     GROUP BY m.id
     ORDER BY m.id ASC"
);
$mnfStmt->bind_param('i', $thcId);
$mnfStmt->execute();
$manifests = $mnfStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$mnfStmt->close();

// Dockets in LT (available for manual manifest)
$ltDkts = $conn->prepare(
    "SELECT lti.consignment_id, c.consignment_note, c.booking_date,
            c.no_of_pieces, c.actual_weight, c.charged_weight,
            oc.city_name AS origin_name, dc.city_name AS dest_name,
            c.destination_city_id
     FROM loading_tally_items lti
     JOIN consignments c ON c.id = lti.consignment_id
     LEFT JOIN cities oc ON oc.id = c.origin_city_id
     LEFT JOIN cities dc ON dc.id = c.destination_city_id
     WHERE lti.thc_id = ?
     ORDER BY c.destination_city_id, c.consignment_note"
);
$ltDkts->bind_param('i', $thcId);
$ltDkts->execute();
$ltDockets = $ltDkts->get_result()->fetch_all(MYSQLI_ASSOC);
$ltDkts->close();

// All cities for manual destination select
$cities = $conn->query(
    "SELECT id, city_name, state_code FROM cities ORDER BY city_name LIMIT 2000"
)->fetch_all(MYSQLI_ASSOC);

// Manifest items (for expand view)
$mnfItems = [];
foreach ($manifests as $m) {
    $iStmt = $conn->prepare(
        "SELECT mi.consignment_id, c.consignment_note, c.no_of_pieces,
                c.actual_weight, oc.city_name AS origin_name, dc.city_name AS dest_name
         FROM thc_manifest_items mi
         JOIN consignments c ON c.id = mi.consignment_id
         LEFT JOIN cities oc ON oc.id = c.origin_city_id
         LEFT JOIN cities dc ON dc.id = c.destination_city_id
         WHERE mi.manifest_id = ?
         ORDER BY c.consignment_note"
    );
    $mId = (int)$m['id'];
    $iStmt->bind_param('i', $mId);
    $iStmt->execute();
    $mnfItems[$m['id']] = $iStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $iStmt->close();
}

$statusColors = [
    'Draft'      => ['bg'=>'#f1f5f9','text'=>'#475569'],
    'Saved'      => ['bg'=>'#dbeafe','text'=>'#1d4ed8'],
    'Dispatched' => ['bg'=>'#dcfce7','text'=>'#15803d'],
    'Delivered'  => ['bg'=>'#f0fdf4','text'=>'#166534'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(appBrandTitle('Manifest')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
<style>
.mnf-wrap{max-width:1300px;margin:14px auto;padding:0 14px}
.mnf-hero{background:linear-gradient(120deg,#083b5c,#1e3a5f);border-radius:13px;padding:14px 20px;color:#fff;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:12px}
.mnf-hero h2{margin:0;font-size:1.05rem;font-weight:700}
.mnf-hero p{margin:3px 0 0;font-size:.72rem;color:#93c5fd}
.meta-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px}
.meta-row span{font-size:.71rem;background:#ffffff22;padding:3px 10px;border-radius:8px}
.card-section{background:#fff;border:1px solid #dce4ea;border-radius:11px;padding:16px;margin-bottom:12px}
.card-section h3{font-size:.8rem;font-weight:700;color:#0c2a45;text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;border-bottom:1px solid #e2e8f0;padding-bottom:8px}
.mnf-card{border:1px solid #dce4ea;border-radius:9px;margin-bottom:10px;overflow:hidden}
.mnf-card-head{padding:10px 14px;display:flex;justify-content:space-between;align-items:center;background:#f8fafc;border-bottom:1px solid #e2e8f0;flex-wrap:wrap;gap:8px}
.mnf-card-head .mnf-no{font-size:.82rem;font-weight:700;color:#0c2a45}
.mnf-card-head .dest{font-size:.75rem;color:#1d4ed8;font-weight:600}
.status-pill{font-size:.67rem;padding:2px 9px;border-radius:14px;font-weight:600}
.mnf-items-table{width:100%;border-collapse:collapse;font-size:.74rem}
.mnf-items-table th{background:#f1f5f9;color:#64748b;font-size:.65rem;font-weight:700;text-transform:uppercase;padding:6px 9px}
.mnf-items-table td{padding:6px 9px;border-bottom:1px solid #f0f4f8}
.route-seq-preview{display:flex;align-items:center;gap:6px;flex-wrap:wrap;padding:8px 12px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;margin-bottom:12px;font-size:.74rem}
.route-seq-preview span{padding:3px 10px;border-radius:12px;font-size:.71rem;font-weight:600}
.dkt-check-table{width:100%;border-collapse:collapse;font-size:.75rem}
.dkt-check-table th{background:#f8fafc;color:#64748b;font-size:.65rem;font-weight:700;text-transform:uppercase;padding:6px 9px;border-bottom:1px solid #e2e8f0}
.dkt-check-table td{padding:6px 9px;border-bottom:1px solid #f0f4f8}
.dkt-check-table tr:hover td{background:#f8fafc}
#manualMsg{display:none;padding:8px 12px;border-radius:8px;font-size:.78rem;margin-top:8px}
#manualMsg.ok{background:#f0fdf4;border:1px solid #86efac;color:#15803d}
#manualMsg.err{background:#fff1f2;border:1px solid #fca5a5;color:#b91c1c}
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
<?php renderAppHeader('Manifest', '📄'); ?>
<main class="app-body">
<div class="mnf-wrap">

<!-- Hero -->
<div class="mnf-hero">
  <div>
    <h2>📄 Manifest — <?= htmlspecialchars($thc['thc_no']) ?></h2>
    <p>Auto-manifest groups dockets by destination. Manual allows custom assignment.</p>
    <div class="meta-row">
      <span>🚛 <?= htmlspecialchars($thc['transport_mode']) ?><?= $thc['vehicle_no'] ? ' / ' . htmlspecialchars($thc['vehicle_no']) : '' ?></span>
      <span>🗺️ <?= htmlspecialchars($thc['origin_name'] ?? '—') ?> → <?= htmlspecialchars($thc['destination_name'] ?? '—') ?></span>
      <span><?= count($manifests) ?> manifest(s) | <?= count($ltDockets) ?> dockets in LT</span>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="thc_loading_tally.php?thc_id=<?= $thcId ?>" class="btn btn-light btn-sm">← Loading Tally</a>
    <a href="thc_dispatch.php?thc_id=<?= $thcId ?>" class="btn btn-outline-light btn-sm">🚀 Dispatch</a>
  </div>
</div>

<!-- Route Sequence -->
<?php if (!empty($routePoints)): ?>
<div class="route-seq-preview">
  <b style="color:#0c2a45;font-size:.72rem">Route:</b>
  <span style="background:#dcfce7;color:#15803d"><?= htmlspecialchars($thc['origin_name'] ?? '—') ?></span>
  <?php foreach ($routePoints as $pt): ?>
  <span style="color:#94a3b8">→</span>
  <span style="background:#e0f2fe;color:#0369a1"><?= htmlspecialchars($pt['city_name']) ?></span>
  <?php endforeach; ?>
  <span style="color:#94a3b8">→</span>
  <span style="background:#fee2e2;color:#b91c1c"><?= htmlspecialchars($thc['destination_name'] ?? '—') ?></span>
</div>
<?php endif; ?>

<!-- Existing Manifests -->
<div class="card-section">
  <h3>📋 Existing Manifests (<?= count($manifests) ?>)</h3>
  <?php if (empty($manifests)): ?>
    <p style="color:#94a3b8;font-size:.8rem;text-align:center;padding:20px">No manifests yet. Generate auto-manifest or create manual below.</p>
  <?php else: ?>
    <?php foreach ($manifests as $m):
      $sc = $statusColors[$m['status']] ?? $statusColors['Saved'];
      $items = $mnfItems[$m['id']] ?? [];
    ?>
    <div class="mnf-card">
      <div class="mnf-card-head">
        <div>
          <span class="mnf-no"><?= htmlspecialchars($m['manifest_no']) ?></span>
          <span style="font-size:.7rem;color:#64748b;margin-left:8px"><?= date('d/m/Y', strtotime($m['manifest_date'])) ?></span>
          <span style="font-size:.7rem;color:#64748b;margin-left:8px">[<?= $m['manifest_type'] ?>]</span>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="dest">→ <?= htmlspecialchars($m['dest_name'] ?? '—') ?></span>
          <span style="font-size:.7rem;background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:8px"><?= $m['item_count'] ?> dockets</span>
          <span class="status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>"><?= $m['status'] ?></span>
          <?php if (!in_array($m['status'], ['Dispatched','Delivered'], true)): ?>
          <button class="btn btn-outline-danger btn-sm" style="font-size:.68rem" onclick="deleteManifest(<?= $m['id'] ?>, '<?= htmlspecialchars($m['manifest_no'], ENT_QUOTES) ?>')">🗑️ Delete</button>
          <?php endif; ?>
          <button class="btn btn-outline-secondary btn-sm" style="font-size:.68rem" onclick="toggleItems(<?= $m['id'] ?>)">▼ Items</button>
        </div>
      </div>
      <div id="items_<?= $m['id'] ?>" style="display:none;padding:10px 14px">
        <?php if (empty($items)): ?>
          <p style="color:#94a3b8;font-size:.75rem">No items.</p>
        <?php else: ?>
        <table class="mnf-items-table">
          <thead><tr><th>#</th><th>Docket No</th><th>From</th><th>To</th><th style="text-align:right">Pkgs</th><th style="text-align:right">Actual Wt</th></tr></thead>
          <tbody>
          <?php foreach ($items as $j => $it): ?>
          <tr>
            <td style="color:#94a3b8"><?= $j+1 ?></td>
            <td><b><?= htmlspecialchars($it['consignment_note']) ?></b></td>
            <td><?= htmlspecialchars($it['origin_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($it['dest_name'] ?? '—') ?></td>
            <td style="text-align:right"><?= (int)$it['no_of_pieces'] ?></td>
            <td style="text-align:right"><?= number_format((float)$it['actual_weight'],2) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Auto Generate -->
<div class="card-section">
  <h3>⚡ Auto-Generate Manifests</h3>
  <p style="font-size:.78rem;color:#475569;margin-bottom:12px">Groups all LT dockets by destination city and creates one manifest per destination automatically.</p>
  <?php if (empty($ltDockets)): ?>
    <div style="background:#fff7ed;border:1px solid #fdba74;border-radius:8px;padding:12px;font-size:.78rem;color:#c2410c">
      ⚠️ No dockets in Loading Tally. <a href="thc_loading_tally.php?thc_id=<?= $thcId ?>">Prepare Loading Tally first →</a>
    </div>
  <?php else: ?>
    <button class="btn btn-primary btn-sm" onclick="generateAutoManifest()">⚡ Generate Auto Manifests for All LT Dockets</button>
    <span style="font-size:.72rem;color:#64748b;margin-left:10px"><?= count($ltDockets) ?> dockets available</span>
    <div id="autoMsg" style="display:none;margin-top:8px;padding:8px 12px;border-radius:8px;font-size:.78rem"></div>
  <?php endif; ?>
</div>

<!-- Manual Manifest -->
<div class="card-section">
  <h3>✏️ Create Manual Manifest</h3>
  <p style="font-size:.78rem;color:#475569;margin-bottom:12px">Select specific dockets and assign to a particular touching point or destination.</p>
  <div id="manualMsg"></div>
  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <label style="font-size:.72rem;font-weight:700;color:#4b6475;text-transform:uppercase;display:block;margin-bottom:4px">Destination / Touching Point <span class="text-danger">*</span></label>
      <select class="form-select form-select-sm" id="manualDest">
        <option value="">— Select destination —</option>
        <?php if (!empty($routePoints)): ?>
        <optgroup label="Route Touching Points">
          <?php foreach ($routePoints as $pt): ?>
          <option value="<?= $pt['city_id'] ?>">
            [Seq <?= $pt['point_sequence'] ?>] <?= htmlspecialchars($pt['city_name']) ?>
          </option>
          <?php endforeach; ?>
          <option value="<?= $thc['destination_city_id'] ?>">[Final] <?= htmlspecialchars($thc['destination_name'] ?? '') ?></option>
        </optgroup>
        <?php endif; ?>
        <optgroup label="All Cities">
          <?php foreach ($cities as $c): ?>
          <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['city_name'] . ' (' . $c['state_code'] . ')') ?></option>
          <?php endforeach; ?>
        </optgroup>
      </select>
    </div>
    <div class="col-md-3">
      <label style="font-size:.72rem;font-weight:700;color:#4b6475;text-transform:uppercase;display:block;margin-bottom:4px">Manifest Date</label>
      <input class="form-control form-control-sm" type="date" id="manualDate" value="<?= date('Y-m-d') ?>">
    </div>
    <div class="col-md-5">
      <label style="font-size:.72rem;font-weight:700;color:#4b6475;text-transform:uppercase;display:block;margin-bottom:4px">Remarks</label>
      <input class="form-control form-control-sm" id="manualRemarks" placeholder="Optional remarks">
    </div>
  </div>

  <!-- Docket selection for manual -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
    <b style="font-size:.75rem">Select Dockets from Loading Tally</b>
    <label style="font-size:.72rem"><input type="checkbox" id="manualSelAll" onchange="toggleManualAll(this)"> Select All</label>
  </div>
  <?php if (empty($ltDockets)): ?>
    <p style="color:#94a3b8;font-size:.78rem">No dockets in LT.</p>
  <?php else: ?>
  <div style="max-height:280px;overflow-y:auto">
  <table class="dkt-check-table">
    <thead>
      <tr><th><input type="checkbox" onchange="toggleManualAll(this)"></th><th>Docket No</th><th>Date</th><th>From</th><th>To</th><th style="text-align:right">Pkgs</th><th style="text-align:right">Actual Wt</th></tr>
    </thead>
    <tbody>
    <?php foreach ($ltDockets as $i => $d): ?>
    <tr>
      <td><input type="checkbox" class="manual-chk" value="<?= $d['consignment_id'] ?>"></td>
      <td><b style="font-size:.74rem"><?= htmlspecialchars($d['consignment_note']) ?></b></td>
      <td style="font-size:.72rem;white-space:nowrap"><?= date('d/m/Y', strtotime($d['booking_date'])) ?></td>
      <td style="font-size:.72rem"><?= htmlspecialchars($d['origin_name'] ?? '—') ?></td>
      <td style="font-size:.72rem"><?= htmlspecialchars($d['dest_name'] ?? '—') ?></td>
      <td style="text-align:right;font-size:.72rem"><?= (int)$d['no_of_pieces'] ?></td>
      <td style="text-align:right;font-size:.72rem"><?= number_format((float)$d['actual_weight'],2) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div class="mt-3">
    <button class="btn btn-success btn-sm" onclick="saveManual()">💾 Create Manual Manifest</button>
  </div>
  <?php endif; ?>
</div>

</div><!-- /.mnf-wrap -->
</main>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const csrf  = <?= json_encode($csrf) ?>;
const thcId = <?= $thcId ?>;

function toggleItems(id) {
  const el = document.getElementById('items_' + id);
  el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

function toggleManualAll(src) {
  document.querySelectorAll('.manual-chk').forEach(cb => cb.checked = src.checked);
}

async function generateAutoManifest() {
  if (!confirm('Generate auto manifests for all Loading Tally dockets?')) return;
  const f = new FormData();
  f.append('csrf_token', csrf);
  f.append('action', 'generate_auto');
  f.append('thc_id', thcId);
  const r = await fetch('api/save_manifest.php', { method: 'POST', body: f });
  const d = await r.json();
  const el = document.getElementById('autoMsg');
  el.textContent = d.message;
  el.style.display = 'block';
  el.style.background = d.success ? '#f0fdf4' : '#fff1f2';
  el.style.border = d.success ? '1px solid #86efac' : '1px solid #fca5a5';
  el.style.color  = d.success ? '#15803d' : '#b91c1c';
  if (d.success) setTimeout(() => location.reload(), 1200);
}

async function saveManual() {
  const destId = document.getElementById('manualDest').value;
  const ids = [...document.querySelectorAll('.manual-chk:checked')].map(c => c.value);
  const msgEl = document.getElementById('manualMsg');
  msgEl.style.display = 'none';
  if (!destId) { showManualMsg('Select a destination.', false); return; }
  if (!ids.length) { showManualMsg('Select at least one docket.', false); return; }

  const f = new FormData();
  f.append('csrf_token', csrf);
  f.append('action', 'save_manual');
  f.append('thc_id', thcId);
  f.append('manifest_date', document.getElementById('manualDate').value);
  f.append('destination_city_id', destId);
  f.append('remarks', document.getElementById('manualRemarks').value);
  ids.forEach(id => f.append('docket_ids[]', id));

  const r = await fetch('api/save_manifest.php', { method: 'POST', body: f });
  const d = await r.json();
  showManualMsg(d.message, d.success);
  if (d.success) setTimeout(() => location.reload(), 1000);
}

function showManualMsg(msg, ok) {
  const el = document.getElementById('manualMsg');
  el.textContent = msg;
  el.className = ok ? 'ok' : 'err';
  el.style.display = 'block';
}

async function deleteManifest(id, no) {
  if (!confirm(`Delete manifest "${no}"?`)) return;
  const f = new FormData();
  f.append('csrf_token', csrf);
  f.append('action', 'delete');
  f.append('manifest_id', id);
  const r = await fetch('api/save_manifest.php', { method: 'POST', body: f });
  const d = await r.json();
  alert(d.message);
  if (d.success) location.reload();
}
</script>
</body>
</html>

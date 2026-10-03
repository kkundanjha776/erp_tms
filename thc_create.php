<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/master_data.php';

startSecureSession();
requireLogin();
requireModule('thc');

$conn = getDBConnection();
ensureTHCSchema($conn);
$csrf = generateCSRFToken();

$thcId  = (int)($_GET['thc_id'] ?? 0);
$thc    = $thcId > 0 ? getTHCById($conn, $thcId) : null;
$isEdit = $thc !== null;

// Auto THC No for display
$nextThcNo = $isEdit ? $thc['thc_no'] : getNextTHCNo($conn);

$routes = getRouteMasters($conn, true);
$cities = $conn->query(
    "SELECT id, city_name, state_code FROM cities ORDER BY city_name LIMIT 2000"
)->fetch_all(MYSQLI_ASSOC);

// Route points for pre-selected route
$existingRoutePoints = [];
if ($isEdit && !empty($thc['route_id'])) {
    $existingRoutePoints = getRoutePoints($conn, (int)$thc['route_id']);
}

$transportModes = ['Surface','Air','Rail','FTL','Co-loader','Data Movement'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(appBrandTitle($isEdit ? 'Edit THC' : 'Create THC')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
<style>
.thc-form-wrap{max-width:1100px;margin:16px auto;padding:0 16px}
.form-hero{background:linear-gradient(120deg,#083b5c,#0f5c3a);border-radius:14px;padding:16px 22px;color:#fff;display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px}
.form-hero h2{margin:0;font-size:1.1rem}
.form-hero p{margin:3px 0 0;font-size:.75rem;color:#a7f3d0}
.form-card{background:#fff;border:1px solid #dce4ea;border-radius:12px;padding:18px 20px;margin-bottom:12px}
.form-card h3{font-size:.82rem;font-weight:700;color:#0c2a45;text-transform:uppercase;letter-spacing:.05em;margin-bottom:14px;padding-bottom:8px;border-bottom:2px solid #e0f2fe}
.form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
.form-grid-4{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px}
label.fl{font-size:.72rem;font-weight:700;color:#4b6475;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:5px}
.thc-no-box{background:#f0fdf4;border:2px solid #86efac;border-radius:8px;padding:10px 14px;font-size:1rem;font-weight:800;color:#15803d;letter-spacing:.03em}
.amount-row{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}
.amt-field{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;text-align:center}
.amt-field label{font-size:.65rem;color:#64748b;text-transform:uppercase;font-weight:700;display:block;margin-bottom:4px}
.amt-field input{border:none;background:transparent;text-align:center;font-size:1rem;font-weight:700;width:100%;outline:none;color:#0c2a45}
.amt-field.total{background:#e0f2fe;border-color:#7dd3fc}
.amt-field.balance{background:#dcfce7;border-color:#86efac}
.amt-field.balance input{color:#15803d}
.route-preview-box{background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;font-size:.78rem;color:#0369a1;margin-top:8px}
.route-preview-box .rp-seq{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.rp-seq span{padding:3px 10px;border-radius:14px;font-size:.72rem;font-weight:600}
.rp-seq .arrow{color:#94a3b8}
.btn-bar{position:sticky;bottom:0;background:#fff;border-top:1px solid #e2e8f0;padding:12px 20px;display:flex;gap:10px;align-items:center;margin-top:14px;z-index:10}
#formMsg{display:none;padding:10px 14px;border-radius:8px;font-size:.8rem;margin-bottom:10px}
#formMsg.ok{background:#f0fdf4;border:1px solid #86efac;color:#15803d}
#formMsg.err{background:#fff1f2;border:1px solid #fca5a5;color:#b91c1c}
@media(max-width:768px){
  .form-grid-2,.form-grid-3,.form-grid-4,.amount-row{grid-template-columns:1fr 1fr}
}
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
<?php renderAppHeader($isEdit ? 'Edit THC' : 'Create THC', '🚚'); ?>
<main class="app-body">
<div class="thc-form-wrap">

<div class="form-hero">
  <div>
    <h2><?= $isEdit ? '✏️ Edit Trip Hire Contract' : '＋ New Trip Hire Contract' ?></h2>
    <p><?= $isEdit ? 'Modify trip details. Dispatched THCs cannot be deleted.' : 'Fill in trip details. THC No is auto-generated.' ?></p>
  </div>
  <a href="thc_list.php" class="btn btn-light btn-sm">← Back to List</a>
</div>

<div id="formMsg"></div>

<!-- ── Section 1: Basic Info ─────────────────────────────────── -->
<div class="form-card">
  <h3>Basic Information</h3>
  <div class="form-grid-3">
    <div>
      <label class="fl">THC No</label>
      <div class="thc-no-box"><?= htmlspecialchars($nextThcNo) ?></div>
    </div>
    <div>
      <label class="fl">THC Date <span class="text-danger">*</span></label>
      <input class="form-control" type="date" id="thcDate"
             value="<?= htmlspecialchars($isEdit ? $thc['thc_date'] : date('Y-m-d')) ?>"
             max="<?= date('Y-m-d') ?>">
    </div>
    <div>
      <label class="fl">Transport Mode <span class="text-danger">*</span></label>
      <select class="form-select" id="transMode" onchange="onModeChange()">
        <?php foreach ($transportModes as $m): ?>
        <option value="<?= $m ?>" <?= ($isEdit && $thc['transport_mode']===$m)?'selected':(!$isEdit&&$m==='Surface'?'selected':'') ?>>
          <?= $m ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="form-grid-2 mt-3">
    <div>
      <label class="fl">Origin City <span class="text-danger">*</span></label>
      <select class="form-select" id="originCity" onchange="updateRoutePreview()">
        <option value="">— Select Origin —</option>
        <?php foreach ($cities as $c): ?>
        <option value="<?= $c['id'] ?>"
          <?= ($isEdit && (int)$thc['origin_city_id']===(int)$c['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['city_name'] . ' (' . $c['state_code'] . ')') ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="fl">Destination City <span class="text-danger">*</span></label>
      <select class="form-select" id="destCity" onchange="updateRoutePreview()">
        <option value="">— Select Destination —</option>
        <?php foreach ($cities as $c): ?>
        <option value="<?= $c['id'] ?>"
          <?= ($isEdit && (int)$thc['destination_city_id']===(int)$c['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['city_name'] . ' (' . $c['state_code'] . ')') ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</div>

<!-- ── Section 2: Route ──────────────────────────────────────── -->
<div class="form-card">
  <h3>Route Selection</h3>
  <div class="form-grid-2">
    <div>
      <label class="fl">Select Default Route</label>
      <select class="form-select" id="routeId" onchange="onRouteChange()">
        <option value="">— No route (manual) —</option>
        <?php foreach ($routes as $r): ?>
        <option value="<?= $r['id'] ?>"
                data-origin="<?= $r['origin_city_id'] ?>"
                data-dest="<?= $r['destination_city_id'] ?>"
                data-mode="<?= htmlspecialchars($r['transport_mode']) ?>"
                data-points='<?= htmlspecialchars(json_encode(getRoutePoints($conn, (int)$r['id'])), ENT_QUOTES) ?>'
                <?= ($isEdit && (int)$thc['route_id']===(int)$r['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($r['route_code'] . ' — ' . $r['route_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div id="routePreviewWrap">
      <label class="fl">Route Sequence Preview</label>
      <div class="route-preview-box">
        <div class="rp-seq" id="routePreviewSeq">
          <span style="color:#94a3b8">Select a route to see sequence</span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Section 3: Vehicle & Driver ──────────────────────────── -->
<div class="form-card">
  <h3>Vehicle &amp; Driver Details</h3>
  <div class="form-grid-3">
    <div>
      <label class="fl">Vendor / Transporter Name</label>
      <input class="form-control" id="vendorName" placeholder="Vendor or owner name"
             value="<?= htmlspecialchars($isEdit ? ($thc['vendor_name'] ?? '') : '') ?>">
    </div>
    <div>
      <label class="fl">Vehicle / Flight / Train No</label>
      <input class="form-control" id="vehicleNo" placeholder="e.g. WB03B3287" style="text-transform:uppercase"
             oninput="this.value=this.value.toUpperCase()"
             value="<?= htmlspecialchars($isEdit ? ($thc['vehicle_no'] ?? '') : '') ?>">
    </div>
  </div>
  <div class="form-grid-4 mt-3">
    <div>
      <label class="fl">Driver 1 Name</label>
      <input class="form-control" id="driver1Name" placeholder="Driver name"
             value="<?= htmlspecialchars($isEdit ? ($thc['driver1_name'] ?? '') : '') ?>">
    </div>
    <div>
      <label class="fl">Driver 1 Phone</label>
      <input class="form-control" id="driver1Phone" type="tel" maxlength="15" placeholder="10-digit"
             value="<?= htmlspecialchars($isEdit ? ($thc['driver1_phone'] ?? '') : '') ?>">
    </div>
    <div>
      <label class="fl">Driver 2 Name</label>
      <input class="form-control" id="driver2Name" placeholder="Driver name"
             value="<?= htmlspecialchars($isEdit ? ($thc['driver2_name'] ?? '') : '') ?>">
    </div>
    <div>
      <label class="fl">Driver 2 Phone</label>
      <input class="form-control" id="driver2Phone" type="tel" maxlength="15" placeholder="10-digit"
             value="<?= htmlspecialchars($isEdit ? ($thc['driver2_phone'] ?? '') : '') ?>">
    </div>
  </div>
</div>

<!-- ── Section 4: Contract Amounts ──────────────────────────── -->
<div class="form-card">
  <h3>Contract &amp; Amount</h3>
  <div class="amount-row">
    <div class="amt-field">
      <label>Contract Amount (₹)</label>
      <input type="number" id="contractAmt" min="0" step="0.01" placeholder="0.00"
             value="<?= $isEdit ? number_format((float)$thc['contract_amount'],2,'.','') : '' ?>"
             oninput="recalcAmounts()">
    </div>
    <div class="amt-field">
      <label>Other Charges (₹)</label>
      <input type="number" id="otherAmt" min="0" step="0.01" placeholder="0.00"
             value="<?= $isEdit ? number_format((float)$thc['other_amount'],2,'.','') : '' ?>"
             oninput="recalcAmounts()">
    </div>
    <div class="amt-field total">
      <label>Total Amount (₹)</label>
      <input type="number" id="totalAmt" readonly placeholder="0.00"
             value="<?= $isEdit ? number_format((float)$thc['total_amount'],2,'.','') : '' ?>">
    </div>
    <div class="amt-field">
      <label>Advance Paid (₹)</label>
      <input type="number" id="advanceAmt" min="0" step="0.01" placeholder="0.00"
             value="<?= $isEdit ? number_format((float)$thc['advance_amount'],2,'.','') : '' ?>"
             oninput="recalcAmounts()">
    </div>
    <div class="amt-field">
      <label>Deduction (₹)</label>
      <input type="number" id="deductionAmt" min="0" step="0.01" placeholder="0.00"
             value="<?= $isEdit ? number_format((float)$thc['deduction_amount'],2,'.','') : '' ?>"
             oninput="recalcAmounts()">
    </div>
  </div>
  <div class="amount-row mt-3">
    <div class="amt-field balance" style="grid-column:span 2">
      <label>Balance Amount (₹)</label>
      <input type="number" id="balanceAmt" readonly placeholder="0.00"
             value="<?= $isEdit ? number_format((float)$thc['balance_amount'],2,'.','') : '' ?>">
    </div>
  </div>

  <div class="mt-3">
    <label class="fl">Remarks</label>
    <textarea class="form-control" id="remarks" rows="2" placeholder="Optional remarks…"><?= htmlspecialchars($isEdit ? ($thc['remarks'] ?? '') : '') ?></textarea>
  </div>
</div>

<!-- ── Action Bar ─────────────────────────────────────────────── -->
<div class="btn-bar">
  <button class="btn btn-primary" onclick="saveTHC('Draft')">💾 Save as Draft</button>
  <button class="btn btn-success" onclick="saveTHC('Active')">✅ Save &amp; Activate</button>
  <?php if ($isEdit && in_array($thc['status'], ['Active'], true)): ?>
  <a href="thc_loading_tally.php?thc_id=<?= $thcId ?>" class="btn btn-outline-success">📋 Prepare Loading Tally</a>
  <?php endif; ?>
  <a href="thc_list.php" class="btn btn-outline-secondary ms-auto">Cancel</a>
</div>

</div><!-- /.thc-form-wrap -->
</main>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const csrf   = <?= json_encode($csrf) ?>;
const thcId  = <?= $thcId ?>;
const cityMap = {};
<?php foreach ($cities as $c): ?>
cityMap[<?= (int)$c['id'] ?>] = <?= json_encode($c['city_name'] . ' (' . $c['state_code'] . ')') ?>;
<?php endforeach; ?>

// ── Amount recalculation ───────────────────────────────────────
function recalcAmounts() {
  const contract  = parseFloat(document.getElementById('contractAmt').value) || 0;
  const other     = parseFloat(document.getElementById('otherAmt').value)    || 0;
  const advance   = parseFloat(document.getElementById('advanceAmt').value)  || 0;
  const deduction = parseFloat(document.getElementById('deductionAmt').value)|| 0;
  const total     = contract + other;
  const balance   = total - advance - deduction;
  document.getElementById('totalAmt').value   = total.toFixed(2);
  document.getElementById('balanceAmt').value = balance.toFixed(2);
  document.getElementById('balanceAmt').style.color = balance < 0 ? '#b91c1c' : '#15803d';
}
recalcAmounts();

// ── Route selection ────────────────────────────────────────────
function onRouteChange() {
  const sel = document.getElementById('routeId');
  const opt = sel.selectedOptions[0];
  if (!opt || !opt.value) {
    updateRoutePreview(); return;
  }
  // Auto-fill origin/dest and mode from route
  const originId = opt.dataset.origin;
  const destId   = opt.dataset.dest;
  const mode     = opt.dataset.mode;
  if (originId) document.getElementById('originCity').value = originId;
  if (destId)   document.getElementById('destCity').value   = destId;
  if (mode)     document.getElementById('transMode').value  = mode;

  // Show route point sequence
  let pts = [];
  try { pts = JSON.parse(opt.dataset.points || '[]'); } catch(e) {}
  renderRoutePreview(originId, destId, pts);
}

function onModeChange() { /* no auto-action needed */ }

function updateRoutePreview() {
  const sel = document.getElementById('routeId');
  const opt = sel.selectedOptions[0];
  if (opt && opt.value) { onRouteChange(); return; }
  // Manual: just show origin → dest
  const originId = document.getElementById('originCity').value;
  const destId   = document.getElementById('destCity').value;
  renderRoutePreview(originId, destId, []);
}

function renderRoutePreview(originId, destId, points) {
  const seq = document.getElementById('routePreviewSeq');
  const parts = [];
  if (originId) parts.push(`<span style="background:#dcfce7;color:#15803d">${cityMap[originId]||'?'}</span>`);
  points.forEach(pt => {
    parts.push('<span class="arrow">→</span>');
    parts.push(`<span>${cityMap[pt.city_id]||pt.city_name||'?'}</span>`);
  });
  if (destId) {
    parts.push('<span class="arrow">→</span>');
    parts.push(`<span style="background:#fee2e2;color:#b91c1c">${cityMap[destId]||'?'}</span>`);
  }
  seq.innerHTML = parts.length ? parts.join('') : '<span style="color:#94a3b8">Select origin and destination</span>';
}

// Init preview on edit
<?php if ($isEdit && !empty($thc['route_id'])): ?>
(function(){
  const pts = <?= json_encode($existingRoutePoints) ?>;
  renderRoutePreview(<?= (int)$thc['origin_city_id'] ?>, <?= (int)$thc['destination_city_id'] ?>, pts);
})();
<?php elseif ($isEdit): ?>
updateRoutePreview();
<?php endif; ?>

// ── Save THC ───────────────────────────────────────────────────
async function saveTHC(status) {
  const msgEl = document.getElementById('formMsg');
  msgEl.style.display = 'none';

  const originId = document.getElementById('originCity').value;
  const destId   = document.getElementById('destCity').value;
  const thcDate  = document.getElementById('thcDate').value;

  if (!thcDate)  { showMsg('THC Date is required.', false); return; }
  if (!originId) { showMsg('Origin City is required.', false); return; }
  if (!destId)   { showMsg('Destination City is required.', false); return; }

  const f = new FormData();
  f.append('csrf_token', csrf);
  f.append('action', 'save');
  f.append('thc_id', thcId);
  f.append('thc_date', thcDate);
  f.append('origin_city_id', originId);
  f.append('destination_city_id', destId);
  f.append('transport_mode', document.getElementById('transMode').value);
  f.append('route_id', document.getElementById('routeId').value);
  f.append('vendor_name', document.getElementById('vendorName').value);
  f.append('vehicle_no', document.getElementById('vehicleNo').value);
  f.append('driver1_name', document.getElementById('driver1Name').value);
  f.append('driver1_phone', document.getElementById('driver1Phone').value);
  f.append('driver2_name', document.getElementById('driver2Name').value);
  f.append('driver2_phone', document.getElementById('driver2Phone').value);
  f.append('contract_amount', document.getElementById('contractAmt').value || 0);
  f.append('other_amount', document.getElementById('otherAmt').value || 0);
  f.append('advance_amount', document.getElementById('advanceAmt').value || 0);
  f.append('deduction_amount', document.getElementById('deductionAmt').value || 0);
  f.append('remarks', document.getElementById('remarks').value);
  f.append('status', status);

  const btn = event.target;
  btn.disabled = true;
  try {
    const r = await fetch('api/save_thc.php', { method: 'POST', body: f });
    const d = await r.json();
    if (d.success) {
      showMsg(d.message + (d.thc_no ? ' — ' + d.thc_no : ''), true);
      setTimeout(() => {
        window.location.href = 'thc_list.php';
      }, 1200);
    } else {
      showMsg(d.message || 'Save failed', false);
    }
  } catch(e) {
    showMsg('Network error. Please try again.', false);
  }
  btn.disabled = false;
}

function showMsg(msg, ok) {
  const el = document.getElementById('formMsg');
  el.textContent = msg;
  el.className = ok ? 'ok' : 'err';
  el.style.display = 'block';
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
</script>
</body>
</html>

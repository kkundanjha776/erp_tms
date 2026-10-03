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

$ltId = (int)($_GET['lt_id'] ?? 0);

// All LTs for this THC
$ltList = getLTsByTHC($conn, $thcId);

// Active/selected LT
$activeLT = null;
if ($ltId > 0) {
    foreach ($ltList as $lt) {
        if ((int)$lt['id'] === $ltId) { $activeLT = $lt; break; }
    }
}
if (!$activeLT && !empty($ltList)) {
    $activeLT = $ltList[count($ltList)-1];
    $ltId = (int)$activeLT['id'];
}

// Dockets already in this LT (if any LT exists)
$ltDockets = [];
if ($ltId > 0) {
    $stmt = $conn->prepare(
        "SELECT lti.id AS lti_id, lti.consignment_id, lti.lt_id,
                c.consignment_note, c.booking_date, c.no_of_pieces, c.actual_weight,
                c.charged_weight, lti.short_reason,
                oc.city_name AS origin_name, dc.city_name AS dest_name,
                bt.type_name AS service_type, c.truck_type_id,
                tt.type_name AS mode_name
         FROM loading_tally_items lti
         JOIN consignments c ON c.id = lti.consignment_id
         LEFT JOIN cities oc ON oc.id = c.origin_city_id
         LEFT JOIN cities dc ON dc.id = c.destination_city_id
         LEFT JOIN booking_types bt ON bt.id = c.booking_type_id
         LEFT JOIN truck_types tt ON tt.id = c.truck_type_id
         WHERE lti.lt_id = ?
         ORDER BY lti.id ASC"
    );
    $stmt->bind_param('i', $ltId);
    $stmt->execute();
    $ltDockets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Route city IDs for priority
$routeCityIds = [];
if (!empty($thc['route_id'])) {
    $pts = getRoutePoints($conn, (int)$thc['route_id']);
    $routeCityIds = array_column($pts, 'city_id');
}
$routeCityIds[] = $thc['origin_city_id'];
$routeCityIds[] = $thc['destination_city_id'];
$routeCityIds   = array_unique(array_map('intval', $routeCityIds));

// Build waiting dockets — NOT yet assigned to any loading tally
// Show route-matching first, then others
$assignedIds = $conn->query("SELECT consignment_id FROM loading_tally_items WHERE thc_id={$thcId}");
$alreadyIds  = [];
if ($assignedIds) while ($r = $assignedIds->fetch_assoc()) $alreadyIds[] = (int)$r['consignment_id'];

$waitingDockets = [];
$notInClause    = '';
if (!empty($alreadyIds)) {
    $notInClause = 'AND c.id NOT IN (' . implode(',', $alreadyIds) . ')';
}
$waitSQL = "SELECT c.id AS consignment_id, c.consignment_note, c.booking_date,
                   c.no_of_pieces, c.actual_weight, c.charged_weight,
                   oc.city_name AS origin_name, dc.city_name AS dest_name,
                   c.origin_city_id, c.destination_city_id,
                   bt.type_name AS service_type, tt.type_name AS mode_name,
                   c.party_invoice_no AS fr_no
            FROM consignments c
            LEFT JOIN cities oc ON oc.id = c.origin_city_id
            LEFT JOIN cities dc ON dc.id = c.destination_city_id
            LEFT JOIN booking_types bt ON bt.id = c.booking_type_id
            LEFT JOIN truck_types tt ON tt.id = c.truck_type_id
            WHERE c.status IN ('Submitted','Approved')
              AND c.docket_tracking_status IN ('In Transit','Connecting to Next Destination')
              {$notInClause}
            ORDER BY c.booking_date DESC, c.id DESC
            LIMIT 500";
$wr = $conn->query($waitSQL);
if ($wr) $waitingDockets = $wr->fetch_all(MYSQLI_ASSOC);

// Split: route-matching vs others
$matchDockets = [];
$otherDockets = [];
foreach ($waitingDockets as $d) {
    if (in_array((int)$d['destination_city_id'], $routeCityIds, true) ||
        in_array((int)$d['origin_city_id'], $routeCityIds, true)) {
        $matchDockets[] = $d;
    } else {
        $otherDockets[] = $d;
    }
}
$waitingDockets = array_merge($matchDockets, $otherDockets);

// Totals for LT section
$ltTotalPcs  = array_sum(array_column($ltDockets, 'no_of_pieces'));
$ltTotalAWt  = array_sum(array_column($ltDockets, 'actual_weight'));
$ltTotalCWt  = array_sum(array_column($ltDockets, 'charged_weight'));

$thcRouteLabel = '';
if ($thc['route_name']) $thcRouteLabel = $thc['route_code'] . ' — ' . $thc['route_name'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(appBrandTitle('Loading Tally')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
<style>
.lt-wrap{max-width:1500px;margin:14px auto;padding:0 14px}
.lt-hero{background:linear-gradient(120deg,#083b5c,#134e4e);border-radius:13px;padding:14px 20px;color:#fff;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:12px}
.lt-hero h2{margin:0;font-size:1.05rem;font-weight:700}
.lt-hero p{margin:3px 0 0;font-size:.72rem;color:#a7f3d0}
.lt-hero-meta{display:flex;gap:14px;flex-wrap:wrap;margin-top:6px}
.lt-hero-meta span{font-size:.72rem;background:#ffffff22;padding:3px 10px;border-radius:8px}
.section-card{background:#fff;border:1px solid #dce4ea;border-radius:11px;overflow:hidden;margin-bottom:12px}
.section-head{background:#f1f5f9;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;flex-wrap:wrap;gap:8px}
.section-head h3{margin:0;font-size:.82rem;font-weight:700;color:#0c2a45}
.section-head .badge-count{background:#0c2a45;color:#fff;font-size:.68rem;padding:2px 8px;border-radius:10px}
.dkt-table{width:100%;border-collapse:collapse;font-size:.76rem}
.dkt-table th{background:#f8fafc;color:#64748b;font-size:.65rem;font-weight:700;text-transform:uppercase;padding:7px 9px;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:2;white-space:nowrap}
.dkt-table td{padding:7px 9px;border-bottom:1px solid #f0f4f8;vertical-align:middle}
.dkt-table tr:hover td{background:#f8fafc}
.dkt-table tr.route-match td:first-child{border-left:3px solid #22c55e}
.dkt-table tr.other-dkt td:first-child{border-left:3px solid #e2e8f0}
.dkt-table tfoot td{background:#f1f5f9;font-weight:700;font-size:.75rem;padding:8px 9px}
.tbl-wrap{max-height:340px;overflow-y:auto}
.filter-bar{padding:8px 14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;border-bottom:1px solid #e2e8f0}
.filter-bar input{max-width:200px}
.filter-bar label{font-size:.7rem;font-weight:600;color:#475569;white-space:nowrap}
.action-bar{padding:10px 14px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.lt-tab-bar{display:flex;gap:6px;padding:10px 14px;border-bottom:1px solid #e2e8f0;flex-wrap:wrap;background:#f8fafc}
.lt-tab{font-size:.72rem;padding:4px 12px;border-radius:14px;border:1px solid #dce4ea;background:#fff;cursor:pointer;text-decoration:none;color:#475569;font-weight:600}
.lt-tab.active{background:#0c2a45;color:#fff;border-color:#0c2a45}
.sticky-summary{position:sticky;bottom:0;background:#0c2a45;color:#fff;border-radius:0 0 11px 11px;padding:10px 16px;display:flex;gap:20px;align-items:center;flex-wrap:wrap}
.sticky-summary span{font-size:.72rem}
.sticky-summary b{font-size:.9rem}
#ltSaveMsg{display:none;padding:8px 14px;border-radius:8px;font-size:.78rem;margin:8px 14px}
#ltSaveMsg.ok{background:#f0fdf4;border:1px solid #86efac;color:#15803d}
#ltSaveMsg.err{background:#fff1f2;border:1px solid #fca5a5;color:#b91c1c}
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
<?php renderAppHeader('Loading Tally', '📋'); ?>
<main class="app-body">
<div class="lt-wrap">

<!-- Hero -->
<div class="lt-hero">
  <div>
    <h2>📋 Loading Tally — <?= htmlspecialchars($thc['thc_no']) ?></h2>
    <p>Select dockets from waiting list and add to Loading Tally. Save or print when ready.</p>
    <div class="lt-hero-meta">
      <span>🚛 <?= htmlspecialchars($thc['transport_mode']) ?><?= $thc['vehicle_no'] ? ' / ' . htmlspecialchars($thc['vehicle_no']) : '' ?></span>
      <span>🗺️ <?= htmlspecialchars($thc['origin_name'] ?? '—') ?> → <?= htmlspecialchars($thc['destination_name'] ?? '—') ?></span>
      <?php if ($thcRouteLabel): ?><span>📍 <?= htmlspecialchars($thcRouteLabel) ?></span><?php endif; ?>
      <span style="background:<?= $thc['status']==='Dispatched'?'#dcfce7':'#dbeafe' ?>;color:<?= $thc['status']==='Dispatched'?'#15803d':'#1d4ed8' ?>">
        <?= htmlspecialchars($thc['status']) ?>
      </span>
    </div>
  </div>
  <div class="d-flex gap-2 align-items-start flex-wrap">
    <a href="thc_list.php" class="btn btn-light btn-sm">← THC List</a>
    <a href="thc_create.php?thc_id=<?= $thcId ?>" class="btn btn-outline-light btn-sm">✏️ Edit THC</a>
    <?php if (in_array($thc['status'],['Active','Dispatched'],true)): ?>
    <a href="thc_manifest.php?thc_id=<?= $thcId ?>" class="btn btn-outline-light btn-sm">📄 Manifest</a>
    <?php endif; ?>
  </div>
</div>

<!-- LT Tabs -->
<div class="section-card">
  <div class="lt-tab-bar">
    <span style="font-size:.72rem;font-weight:700;color:#64748b;align-self:center">Loading Tallies:</span>
    <?php foreach ($ltList as $lt): ?>
    <a href="?thc_id=<?= $thcId ?>&lt_id=<?= $lt['id'] ?>"
       class="lt-tab <?= ((int)$lt['id']===$ltId)?'active':'' ?>">
      <?= htmlspecialchars($lt['lt_no']) ?>
      <span style="margin-left:4px;font-size:.65rem;opacity:.8">(<?= $lt['item_count'] ?> dkts)</span>
    </a>
    <?php endforeach; ?>
    <button class="lt-tab" style="background:#f0fdf4;border-color:#86efac;color:#15803d" onclick="createNewLT()">＋ New LT</button>
  </div>

  <div id="ltSaveMsg"></div>

<!-- ── Section 1: Loading Tally (selected dockets) ──────────── -->
  <div class="section-head">
    <div class="d-flex align-items-center gap-2">
      <h3>Loading Tally — <?= $activeLT ? htmlspecialchars($activeLT['lt_no']) : 'No LT selected' ?></h3>
      <span class="badge-count" id="ltCount"><?= count($ltDockets) ?></span>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <?php if ($activeLT): ?>
      <span style="font-size:.7rem;color:#64748b">Date: <?= date('d/m/Y', strtotime($activeLT['lt_date'])) ?></span>
      <span style="font-size:.7rem;padding:2px 8px;border-radius:8px;background:#dbeafe;color:#1d4ed8"><?= htmlspecialchars($activeLT['status']) ?></span>
      <?php endif; ?>
      <button class="btn btn-sm btn-outline-danger" id="btnRemoveSelected" onclick="removeSelected()" disabled>✕ Remove Selected</button>
    </div>
  </div>
  <div class="filter-bar">
    <label>Filter:</label>
    <input class="form-control form-control-sm" id="ltSearch" placeholder="Docket No / From / To…" oninput="filterLT()">
    <label style="margin-left:8px"><input type="checkbox" id="ltSelAll" onchange="toggleLTAll(this)"> Select All</label>
  </div>
  <div class="tbl-wrap">
  <table class="dkt-table" id="ltTable">
    <thead>
      <tr>
        <th><input type="checkbox" id="ltSelAllHead" onchange="toggleLTAll(this)"></th>
        <th>#</th>
        <th>Docket No</th>
        <th>FR No / Inv</th>
        <th>Date</th>
        <th>From</th>
        <th>To</th>
        <th>Service</th>
        <th>Mode</th>
        <th style="text-align:right">Pkgs</th>
        <th style="text-align:right">Orig Pkgs</th>
        <th>Short Reason</th>
        <th style="text-align:right">Actual Wt</th>
        <th style="text-align:right">Charged Wt</th>
      </tr>
    </thead>
    <tbody id="ltBody">
    <?php if (empty($ltDockets)): ?>
      <tr id="ltEmptyRow"><td colspan="14" style="text-align:center;color:#94a3b8;padding:20px">No dockets in this Loading Tally. Select from Waiting Dockets below.</td></tr>
    <?php else: ?>
      <?php foreach ($ltDockets as $i => $d): ?>
      <tr data-cid="<?= $d['consignment_id'] ?>"
          data-search="<?= strtolower(htmlspecialchars($d['consignment_note'].' '.($d['origin_name']??'').' '.($d['dest_name']??'').' '.($d['fr_no']??''))) ?>">
        <td><input type="checkbox" class="lt-chk" value="<?= $d['consignment_id'] ?>" onchange="ltCheckChange()"></td>
        <td style="color:#94a3b8"><?= $i+1 ?></td>
        <td><b><?= htmlspecialchars($d['consignment_note']) ?></b></td>
        <td style="color:#64748b;font-size:.7rem"><?= htmlspecialchars($d['fr_no'] ?? '—') ?></td>
        <td style="white-space:nowrap"><?= date('d/m/Y', strtotime($d['booking_date'])) ?></td>
        <td><?= htmlspecialchars($d['origin_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($d['dest_name'] ?? '—') ?></td>
        <td style="font-size:.7rem"><?= htmlspecialchars($d['service_type'] ?? '—') ?></td>
        <td style="font-size:.7rem"><?= htmlspecialchars($d['mode_name'] ?? '—') ?></td>
        <td style="text-align:right"><?= (int)$d['no_of_pieces'] ?></td>
        <td style="text-align:right"><?= (int)$d['no_of_pieces'] ?></td>
        <td><input class="form-control form-control-sm" style="min-width:100px;font-size:.7rem" placeholder="reason…" value="<?= htmlspecialchars($d['short_reason'] ?? '') ?>"></td>
        <td style="text-align:right"><?= number_format((float)$d['actual_weight'],2) ?></td>
        <td style="text-align:right"><?= number_format((float)$d['charged_weight'],2) ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="9" style="text-align:right">Totals →</td>
        <td id="ltTotalPcs" style="text-align:right"><?= $ltTotalPcs ?></td>
        <td id="ltTotalOrigPcs" style="text-align:right"><?= $ltTotalPcs ?></td>
        <td></td>
        <td id="ltTotalAWt" style="text-align:right"><?= number_format($ltTotalAWt,2) ?></td>
        <td id="ltTotalCWt" style="text-align:right"><?= number_format($ltTotalCWt,2) ?></td>
      </tr>
    </tfoot>
  </table>
  </div>
  <div class="action-bar">
    <?php if ($activeLT && $activeLT['status'] !== 'Dispatched'): ?>
    <button class="btn btn-primary btn-sm" onclick="saveLT()">💾 Save LT</button>
    <button class="btn btn-outline-success btn-sm" onclick="saveLT(true)">💾 Save &amp; Download PDF</button>
    <?php endif; ?>
    <span style="font-size:.72rem;color:#64748b;margin-left:auto">
      LT: <?= $activeLT ? htmlspecialchars($activeLT['lt_no']) : '—' ?> |
      <?= count($ltDockets) ?> dockets
    </span>
  </div>

<!-- ── Section 2: Waiting Dockets ────────────────────────────── -->
  <div class="section-head" style="margin-top:0;border-top:2px solid #e2e8f0">
    <div class="d-flex align-items-center gap-2">
      <h3>⏳ Waiting Dockets</h3>
      <span class="badge-count" id="waitCount"><?= count($waitingDockets) ?></span>
      <span style="font-size:.68rem;background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:8px">
        <?= count($matchDockets) ?> route-matching
      </span>
      <span style="font-size:.68rem;background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:8px">
        <?= count($otherDockets) ?> others
      </span>
    </div>
    <button class="btn btn-sm btn-success" id="btnAddSelected" onclick="addSelectedToLT()" disabled>
      ＋ Add Selected to LT
    </button>
  </div>
  <div class="filter-bar">
    <label>Filter:</label>
    <input class="form-control form-control-sm" id="waitSearch" placeholder="Docket No / From / To / FR…" oninput="filterWait()">
    <label style="margin-left:8px"><input type="checkbox" id="waitSelAll" onchange="toggleWaitAll(this)"> Select All Visible</label>
    <span style="margin-left:auto;font-size:.7rem;color:#64748b">
      🟢 Green border = route-matching destination
    </span>
  </div>
  <div class="tbl-wrap">
  <table class="dkt-table" id="waitTable">
    <thead>
      <tr>
        <th><input type="checkbox" onchange="toggleWaitAll(this)"></th>
        <th>#</th>
        <th>Docket No</th>
        <th>FR No / Inv</th>
        <th>Date</th>
        <th>From</th>
        <th>To</th>
        <th>Service</th>
        <th>Mode</th>
        <th style="text-align:right">Pkgs</th>
        <th style="text-align:right">Actual Wt</th>
        <th style="text-align:right">Chg Wt</th>
      </tr>
    </thead>
    <tbody id="waitBody">
    <?php if (empty($waitingDockets)): ?>
      <tr><td colspan="12" style="text-align:center;color:#94a3b8;padding:20px">No waiting dockets available.</td></tr>
    <?php else: ?>
      <?php
      $routeCityIdsJson = json_encode($routeCityIds);
      foreach ($waitingDockets as $i => $d):
        $isMatch = in_array((int)$d['destination_city_id'], $routeCityIds, true) ||
                   in_array((int)$d['origin_city_id'], $routeCityIds, true);
      ?>
      <tr class="<?= $isMatch ? 'route-match' : 'other-dkt' ?>"
          data-cid="<?= $d['consignment_id'] ?>"
          data-search="<?= strtolower(htmlspecialchars($d['consignment_note'].' '.($d['origin_name']??'').' '.($d['dest_name']??'').' '.($d['fr_no']??''))) ?>">
        <td><input type="checkbox" class="wait-chk" value="<?= $d['consignment_id'] ?>" onchange="waitCheckChange()"></td>
        <td style="color:#94a3b8;font-size:.7rem"><?= $i+1 ?></td>
        <td>
          <b><?= htmlspecialchars($d['consignment_note']) ?></b>
          <?php if ($isMatch): ?><span style="font-size:.62rem;background:#dcfce7;color:#15803d;padding:1px 5px;border-radius:6px;margin-left:4px">✓ route</span><?php endif; ?>
        </td>
        <td style="color:#64748b;font-size:.7rem"><?= htmlspecialchars($d['fr_no'] ?? '—') ?></td>
        <td style="white-space:nowrap"><?= date('d/m/Y', strtotime($d['booking_date'])) ?></td>
        <td><?= htmlspecialchars($d['origin_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($d['dest_name'] ?? '—') ?></td>
        <td style="font-size:.7rem"><?= htmlspecialchars($d['service_type'] ?? '—') ?></td>
        <td style="font-size:.7rem"><?= htmlspecialchars($d['mode_name'] ?? '—') ?></td>
        <td style="text-align:right"><?= (int)$d['no_of_pieces'] ?></td>
        <td style="text-align:right"><?= number_format((float)$d['actual_weight'],2) ?></td>
        <td style="text-align:right"><?= number_format((float)$d['charged_weight'],2) ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
  <div class="sticky-summary">
    <div><b id="sumSelected">0</b><br><span>Selected (waiting)</span></div>
    <div><b id="sumLTCount"><?= count($ltDockets) ?></b><br><span>In LT</span></div>
    <div><b id="sumLTPcs"><?= $ltTotalPcs ?></b><br><span>Total Packages</span></div>
    <div><b id="sumLTAWt"><?= number_format($ltTotalAWt,2) ?></b><br><span>Actual Wt (kg)</span></div>
    <div><b id="sumLTCWt"><?= number_format($ltTotalCWt,2) ?></b><br><span>Charged Wt (kg)</span></div>
  </div>
</div><!-- /.section-card -->
</div><!-- /.lt-wrap -->
</main>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const csrf   = <?= json_encode($csrf) ?>;
const thcId  = <?= $thcId ?>;
let   ltId   = <?= $ltId ?: 'null' ?>;

// ── Filter helpers ─────────────────────────────────────────────
function filterLT() {
  const q = document.getElementById('ltSearch').value.toLowerCase();
  document.querySelectorAll('#ltBody tr[data-search]').forEach(tr => {
    tr.style.display = !q || tr.dataset.search.includes(q) ? '' : 'none';
  });
}
function filterWait() {
  const q = document.getElementById('waitSearch').value.toLowerCase();
  document.querySelectorAll('#waitBody tr[data-search]').forEach(tr => {
    tr.style.display = !q || tr.dataset.search.includes(q) ? '' : 'none';
  });
}

// ── Checkbox helpers ───────────────────────────────────────────
function toggleLTAll(src) {
  document.querySelectorAll('.lt-chk').forEach(cb => {
    if (cb.closest('tr').style.display !== 'none') cb.checked = src.checked;
  });
  ltCheckChange();
}
function toggleWaitAll(src) {
  document.querySelectorAll('.wait-chk').forEach(cb => {
    if (cb.closest('tr').style.display !== 'none') cb.checked = src.checked;
  });
  waitCheckChange();
}
function ltCheckChange() {
  const anyChecked = [...document.querySelectorAll('.lt-chk')].some(c => c.checked);
  document.getElementById('btnRemoveSelected').disabled = !anyChecked;
}
function waitCheckChange() {
  const cnt = [...document.querySelectorAll('.wait-chk')].filter(c => c.checked).length;
  document.getElementById('btnAddSelected').disabled = cnt === 0;
  document.getElementById('sumSelected').textContent = cnt;
}

// ── Show message ───────────────────────────────────────────────
function showMsg(msg, ok) {
  const el = document.getElementById('ltSaveMsg');
  el.textContent = msg;
  el.className = ok ? 'ok' : 'err';
  el.style.display = 'block';
  setTimeout(() => el.style.display = 'none', 4000);
}

// ── Create new LT ──────────────────────────────────────────────
async function createNewLT() {
  const f = new FormData();
  f.append('csrf_token', csrf);
  f.append('action', 'save');
  f.append('thc_id', thcId);
  f.append('lt_id', 0);
  f.append('lt_date', new Date().toISOString().slice(0,10));
  const r = await fetch('api/save_loading_tally.php', { method:'POST', body:f });
  const d = await r.json();
  if (d.success) {
    window.location.href = `thc_loading_tally.php?thc_id=${thcId}&lt_id=${d.lt_id}`;
  } else {
    showMsg(d.message, false);
  }
}

// ── Add selected waiting dockets to LT ────────────────────────
async function addSelectedToLT() {
  const ids = [...document.querySelectorAll('.wait-chk:checked')].map(c => c.value);
  if (!ids.length) return;

  if (!ltId) {
    // Auto-create LT first
    const fc = new FormData();
    fc.append('csrf_token', csrf);
    fc.append('action', 'save');
    fc.append('thc_id', thcId);
    fc.append('lt_id', 0);
    fc.append('lt_date', new Date().toISOString().slice(0,10));
    const rc = await fetch('api/save_loading_tally.php', { method:'POST', body:fc });
    const dc = await rc.json();
    if (!dc.success) { showMsg(dc.message, false); return; }
    ltId = dc.lt_id;
  }

  const f = new FormData();
  f.append('csrf_token', csrf);
  f.append('action', 'save');
  f.append('thc_id', thcId);
  f.append('lt_id', ltId);
  f.append('lt_date', new Date().toISOString().slice(0,10));
  ids.forEach(id => f.append('add_consignment_ids[]', id));

  const btn = document.getElementById('btnAddSelected');
  btn.disabled = true; btn.textContent = 'Adding…';
  const r = await fetch('api/save_loading_tally.php', { method:'POST', body:f });
  const d = await r.json();
  showMsg(d.message, d.success);
  if (d.success) setTimeout(() => location.reload(), 900);
  else { btn.disabled = false; btn.textContent = '＋ Add Selected to LT'; }
}

// ── Remove selected from LT ────────────────────────────────────
async function removeSelected() {
  const ids = [...document.querySelectorAll('.lt-chk:checked')].map(c => c.value);
  if (!ids.length || !ltId) return;
  if (!confirm(`Remove ${ids.length} docket(s) from this Loading Tally?`)) return;

  let removed = 0;
  for (const cid of ids) {
    const f = new FormData();
    f.append('csrf_token', csrf);
    f.append('action', 'remove_docket');
    f.append('lt_id', ltId);
    f.append('consignment_id', cid);
    const r = await fetch('api/save_loading_tally.php', { method:'POST', body:f });
    const d = await r.json();
    if (d.success) removed++;
  }
  showMsg(`${removed} docket(s) removed.`, true);
  setTimeout(() => location.reload(), 900);
}

// ── Save LT header ─────────────────────────────────────────────
async function saveLT(download) {
  if (!ltId) { showMsg('No Loading Tally to save.', false); return; }
  const f = new FormData();
  f.append('csrf_token', csrf);
  f.append('action', 'save');
  f.append('thc_id', thcId);
  f.append('lt_id', ltId);
  f.append('lt_date', new Date().toISOString().slice(0,10));
  const r = await fetch('api/save_loading_tally.php', { method:'POST', body:f });
  const d = await r.json();
  showMsg(d.message, d.success);
  if (d.success && download) {
    // Open print view
    window.open(`thc_loading_tally.php?thc_id=${thcId}&lt_id=${ltId}&print=1`, '_blank');
  }
}

// ── Print mode ─────────────────────────────────────────────────
if (new URLSearchParams(window.location.search).get('print') === '1') {
  window.addEventListener('load', () => window.print());
}
</script>
</body>
</html>

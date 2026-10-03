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

// Touching points in sequence
$tpStmt = $conn->prepare(
    "SELECT ttp.*, c.city_name
     FROM thc_touching_points ttp
     LEFT JOIN cities c ON c.id = ttp.city_id
     WHERE ttp.thc_id = ?
     ORDER BY ttp.point_sequence ASC"
);
$tpStmt->bind_param('i', $thcId);
$tpStmt->execute();
$touchingPoints = $tpStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$tpStmt->close();

// LT dockets for unloading tally
$ltDkts = $conn->prepare(
    "SELECT lti.consignment_id, c.consignment_note, c.no_of_pieces,
            c.actual_weight, c.charged_weight, c.booking_date,
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

// Existing unloading tallies per touching point
$utByTP = [];
$utStmt = $conn->prepare(
    "SELECT * FROM thc_unloading_tally WHERE thc_id = ? ORDER BY touching_point_id, id"
);
$utStmt->bind_param('i', $thcId);
$utStmt->execute();
foreach ($utStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $ut) {
    $utByTP[(int)$ut['touching_point_id']][] = $ut;
}
$utStmt->close();

// Manifests
$mnfStmt = $conn->prepare(
    "SELECT m.*, dc.city_name AS dest_name, COUNT(mi.id) AS item_count
     FROM thc_manifests m
     LEFT JOIN cities dc ON dc.id = m.destination_city_id
     LEFT JOIN thc_manifest_items mi ON mi.manifest_id = m.id
     WHERE m.thc_id = ?
     GROUP BY m.id ORDER BY m.id"
);
$mnfStmt->bind_param('i', $thcId);
$mnfStmt->execute();
$manifests = $mnfStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$mnfStmt->close();

$ltCount = (int)($conn->query("SELECT COUNT(*) AS n FROM loading_tally_items WHERE thc_id={$thcId}")->fetch_assoc()['n'] ?? 0);

$tpStatusColors = [
    'Pending'    => ['bg'=>'#f1f5f9','text'=>'#64748b','border'=>'#e2e8f0'],
    'Arrived'    => ['bg'=>'#fef9c3','text'=>'#854d0e','border'=>'#fde047'],
    'Unloaded'   => ['bg'=>'#dbeafe','text'=>'#1d4ed8','border'=>'#93c5fd'],
    'Dispatched' => ['bg'=>'#dcfce7','text'=>'#15803d','border'=>'#86efac'],
];
$thcStatusColors = [
    'Draft'      => '#94a3b8', 'Active' => '#1d4ed8',
    'Dispatched' => '#15803d', 'Completed' => '#166534', 'Cancelled' => '#b91c1c',
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(appBrandTitle('Dispatch & Track')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
<style>
.dsp-wrap{max-width:1300px;margin:14px auto;padding:0 14px}
.dsp-hero{background:linear-gradient(120deg,#083b5c,#14532d);border-radius:13px;padding:14px 20px;color:#fff;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:12px}
.dsp-hero h2{margin:0;font-size:1.05rem;font-weight:700}
.dsp-hero p{margin:3px 0 0;font-size:.72rem;color:#86efac}
.meta-row{display:flex;gap:9px;flex-wrap:wrap;margin-top:6px}
.meta-row span{font-size:.7rem;background:#ffffff22;padding:3px 9px;border-radius:8px}
/* Dispatch Action bar */
.dispatch-bar{background:#fff;border:1px solid #dce4ea;border-radius:11px;padding:14px 18px;margin-bottom:12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.dispatch-bar h3{margin:0;font-size:.82rem;font-weight:700;color:#0c2a45}
/* Workflow stepper */
.workflow-stepper{background:#fff;border:1px solid #dce4ea;border-radius:11px;padding:16px;margin-bottom:12px}
.workflow-stepper h3{font-size:.8rem;font-weight:700;color:#0c2a45;text-transform:uppercase;letter-spacing:.05em;margin-bottom:14px;border-bottom:1px solid #e2e8f0;padding-bottom:8px}
.stepper-flow{display:flex;align-items:flex-start;gap:0;flex-wrap:wrap}
.step-node{display:flex;flex-direction:column;align-items:center;flex:1;min-width:120px;position:relative}
.step-node::before{content:'';position:absolute;top:18px;right:-50%;width:100%;height:2px;background:#e2e8f0;z-index:0}
.step-node:last-child::before{display:none}
.step-circle{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.82rem;font-weight:700;border:2px solid;position:relative;z-index:1;cursor:default}
.step-label{font-size:.65rem;font-weight:600;text-align:center;margin-top:5px;color:#475569;max-width:90px;word-break:break-word}
.step-status{font-size:.6rem;text-align:center;margin-top:2px;font-weight:600}
/* Touching point cards */
.tp-card{border:1px solid #e2e8f0;border-radius:10px;margin-bottom:10px;overflow:hidden}
.tp-card-head{padding:11px 15px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;border-bottom:1px solid #e2e8f0}
.tp-name{font-size:.85rem;font-weight:700;color:#0c2a45}
.tp-seq{font-size:.7rem;background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:8px;font-weight:600}
.tp-card-body{padding:14px}
.tp-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.field-group label{font-size:.68rem;font-weight:700;color:#4b6475;text-transform:uppercase;display:block;margin-bottom:4px}
.ut-table{width:100%;border-collapse:collapse;font-size:.74rem;margin-top:8px}
.ut-table th{background:#f8fafc;color:#64748b;font-size:.63rem;font-weight:700;text-transform:uppercase;padding:6px 8px;border-bottom:1px solid #e2e8f0}
.ut-table td{padding:6px 8px;border-bottom:1px solid #f0f4f8}
.ut-table input{width:60px;text-align:center;font-size:.75rem;padding:2px 4px}
.card-section{background:#fff;border:1px solid #dce4ea;border-radius:11px;padding:14px;margin-bottom:12px}
.card-section h3{font-size:.8rem;font-weight:700;color:#0c2a45;text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;border-bottom:1px solid #e2e8f0;padding-bottom:8px}
.mnf-pill{display:inline-flex;align-items:center;gap:6px;background:#f1f5f9;border:1px solid #e2e8f0;padding:5px 10px;border-radius:8px;font-size:.72rem;margin:3px}
.notice{padding:10px 14px;border-radius:8px;font-size:.78rem;margin-bottom:10px}
.notice.warn{background:#fff7ed;border:1px solid #fdba74;color:#c2410c}
.notice.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8}
.notice.ok{background:#f0fdf4;border:1px solid #86efac;color:#15803d}
#globalMsg{display:none;padding:10px 14px;border-radius:8px;font-size:.78rem;margin-bottom:10px}
#globalMsg.ok{background:#f0fdf4;border:1px solid #86efac;color:#15803d}
#globalMsg.err{background:#fff1f2;border:1px solid #fca5a5;color:#b91c1c}
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
<?php renderAppHeader('Dispatch & Track', '🚀'); ?>
<main class="app-body">
<div class="dsp-wrap">

<!-- Hero -->
<div class="dsp-hero">
  <div>
    <h2>🚀 Dispatch &amp; Tracking — <?= htmlspecialchars($thc['thc_no']) ?></h2>
    <p>Dispatch THC, record arrivals at touching points, save unloading tallies, and track progress.</p>
    <div class="meta-row">
      <span>🚛 <?= htmlspecialchars($thc['transport_mode']) ?><?= $thc['vehicle_no'] ? ' / ' . htmlspecialchars($thc['vehicle_no']) : '' ?></span>
      <?php if ($thc['vendor_name']): ?><span>👤 <?= htmlspecialchars($thc['vendor_name']) ?></span><?php endif; ?>
      <span>📦 <?= $ltCount ?> dockets in LT</span>
      <span style="background:<?= ($thcStatusColors[$thc['status']] ?? '#94a3b8') ?>33;color:<?= $thcStatusColors[$thc['status']] ?? '#94a3b8' ?>;font-weight:700">
        <?= htmlspecialchars($thc['status']) ?>
      </span>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="thc_loading_tally.php?thc_id=<?= $thcId ?>" class="btn btn-light btn-sm">← LT</a>
    <a href="thc_manifest.php?thc_id=<?= $thcId ?>" class="btn btn-outline-light btn-sm">📄 Manifest</a>
    <a href="thc_list.php" class="btn btn-outline-light btn-sm">📋 THC List</a>
  </div>
</div>

<div id="globalMsg"></div>

<!-- ── Dispatch Action Bar ─────────────────────────────────────── -->
<div class="dispatch-bar">
  <h3>🚀 Dispatch THC</h3>
  <?php if ($thc['status'] === 'Draft'): ?>
    <div class="notice warn" style="margin:0;flex:1">THC is in Draft. <a href="thc_create.php?thc_id=<?= $thcId ?>">Activate it first</a> before dispatching.</div>
  <?php elseif ($thc['status'] === 'Active'): ?>
    <?php if ($ltCount === 0): ?>
      <div class="notice warn" style="margin:0;flex:1">⚠️ No dockets in Loading Tally. <a href="thc_loading_tally.php?thc_id=<?= $thcId ?>">Prepare LT first</a>.</div>
    <?php else: ?>
      <div class="notice info" style="margin:0;flex:1">Ready to dispatch. <?= $ltCount ?> docket(s) in LT, <?= count($manifests) ?> manifest(s) created.</div>
      <button class="btn btn-success" onclick="dispatchTHC()">🚀 Dispatch THC Now</button>
    <?php endif; ?>
  <?php elseif ($thc['status'] === 'Dispatched'): ?>
    <div class="notice ok" style="margin:0;flex:1">✅ THC is Dispatched on <?= $thc['dispatched_at'] ? date('d/m/Y H:i', strtotime($thc['dispatched_at'])) : '—' ?>. Track progress below.</div>
    <button class="btn btn-outline-success btn-sm" onclick="completeTHC()">✔ Mark as Completed</button>
  <?php elseif ($thc['status'] === 'Completed'): ?>
    <div class="notice ok" style="margin:0;flex:1">🏁 THC Completed on <?= $thc['completed_at'] ? date('d/m/Y H:i', strtotime($thc['completed_at'])) : '—' ?>.</div>
  <?php else: ?>
    <span style="font-size:.78rem;color:#94a3b8">Status: <?= htmlspecialchars($thc['status']) ?></span>
  <?php endif; ?>
</div>

<!-- ── Workflow Stepper ────────────────────────────────────────── -->
<?php if (!empty($touchingPoints)): ?>
<div class="workflow-stepper">
  <h3>📍 Route Progress</h3>
  <div class="stepper-flow">
    <?php
    // Origin node
    $sc0 = $thc['status'] === 'Dispatched' || $thc['status'] === 'Completed'
        ? $tpStatusColors['Dispatched'] : $tpStatusColors['Pending'];
    ?>
    <div class="step-node">
      <div class="step-circle" style="background:<?= $sc0['bg'] ?>;color:<?= $sc0['text'] ?>;border-color:<?= $sc0['border'] ?>">🏭</div>
      <div class="step-label"><?= htmlspecialchars($thc['origin_name'] ?? '—') ?></div>
      <div class="step-status" style="color:<?= $sc0['text'] ?>">Origin</div>
    </div>
    <?php foreach ($touchingPoints as $tp):
      $sc = $tpStatusColors[$tp['status']] ?? $tpStatusColors['Pending'];
      $icons = ['Pending'=>'⏳','Arrived'=>'🛬','Unloaded'=>'📦','Dispatched'=>'🚀'];
    ?>
    <div class="step-node">
      <div class="step-circle" style="background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>;border-color:<?= $sc['border'] ?>">
        <?= $icons[$tp['status']] ?? '⏳' ?>
      </div>
      <div class="step-label"><?= htmlspecialchars($tp['city_name'] ?? '—') ?></div>
      <div class="step-status" style="color:<?= $sc['text'] ?>"><?= htmlspecialchars($tp['status']) ?></div>
    </div>
    <?php endforeach; ?>
    <?php
    $scF = $thc['status'] === 'Completed' ? $tpStatusColors['Dispatched'] : $tpStatusColors['Pending'];
    ?>
    <div class="step-node">
      <div class="step-circle" style="background:<?= $scF['bg'] ?>;color:<?= $scF['text'] ?>;border-color:<?= $scF['border'] ?>">🏁</div>
      <div class="step-label"><?= htmlspecialchars($thc['destination_name'] ?? '—') ?></div>
      <div class="step-status" style="color:<?= $scF['text'] ?>">Destination</div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Touching Point Cards ──────────────────────────────────── -->
<?php if (empty($touchingPoints)): ?>
<div class="card-section">
  <div class="notice info">No touching points defined for this THC's route. <?= $thc['route_id'] ? '' : '<a href="thc_create.php?thc_id='.$thcId.'">Assign a route with touching points</a>.' ?></div>
</div>
<?php else: ?>
<?php foreach ($touchingPoints as $tp):
  $sc       = $tpStatusColors[$tp['status']] ?? $tpStatusColors['Pending'];
  $tpIdVal  = (int)$tp['id'];
  $utItems  = $utByTP[$tpIdVal] ?? [];
  $canArrive   = $thc['status'] === 'Dispatched' && $tp['status'] === 'Pending';
  $canUnload   = in_array($tp['status'], ['Arrived'], true);
  $canDispatch = in_array($tp['status'], ['Arrived','Unloaded'], true);
?>
<div class="tp-card" id="tp_<?= $tpIdVal ?>">
  <div class="tp-card-head" style="background:<?= $sc['bg'] ?>22;border-left:4px solid <?= $sc['border'] ?>">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="tp-seq">Seq <?= (int)$tp['point_sequence'] ?></span>
      <span class="tp-name">📍 <?= htmlspecialchars($tp['city_name'] ?? '—') ?></span>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <?php if ($tp['arrival_datetime']): ?>
        <span style="font-size:.7rem;color:#475569">🛬 Arrived: <?= date('d/m/Y H:i', strtotime($tp['arrival_datetime'])) ?></span>
      <?php endif; ?>
      <?php if ($tp['departure_datetime']): ?>
        <span style="font-size:.7rem;color:#15803d">🚀 Departed: <?= date('d/m/Y H:i', strtotime($tp['departure_datetime'])) ?></span>
      <?php endif; ?>
      <span style="font-size:.72rem;padding:3px 10px;border-radius:12px;font-weight:600;background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>">
        <?= htmlspecialchars($tp['status']) ?>
      </span>
    </div>
  </div>
  <div class="tp-card-body">
    <!-- Arrival -->
    <?php if ($canArrive): ?>
    <div class="tp-grid" style="margin-bottom:10px">
      <div class="field-group">
        <label>Arrival Date &amp; Time</label>
        <input class="form-control form-control-sm" type="datetime-local" id="arrivalDt_<?= $tpIdVal ?>"
               value="<?= date('Y-m-d\TH:i') ?>">
      </div>
      <div style="display:flex;align-items:flex-end">
        <button class="btn btn-warning btn-sm" onclick="recordArrival(<?= $tpIdVal ?>)">🛬 Record Arrival</button>
      </div>
    </div>
    <?php endif; ?>

    <!-- Unloading Tally -->
    <?php if ($tp['status'] !== 'Pending'): ?>
    <div>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <b style="font-size:.75rem">📦 Unloading Tally — <?= count($ltDockets) ?> dockets</b>
        <?php if ($canUnload || $canDispatch): ?>
        <button class="btn btn-outline-primary btn-sm" style="font-size:.7rem" onclick="saveUnloading(<?= $tpIdVal ?>)">
          💾 Save Unloading Tally
        </button>
        <?php endif; ?>
      </div>
      <div style="max-height:280px;overflow-y:auto">
      <table class="ut-table" id="utTable_<?= $tpIdVal ?>">
        <thead>
          <tr>
            <th>#</th><th>Docket No</th><th>From</th><th>To</th>
            <th style="text-align:right">Orig Pkgs</th>
            <th style="text-align:center">Received</th>
            <th style="text-align:center">Short</th>
            <th style="text-align:center">Damaged</th>
            <th>Short Reason</th>
            <th>Damage Reason</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($ltDockets as $i => $d):
          // Prefill from existing unloading tally
          $existing = null;
          foreach ($utItems as $u) {
              if ((int)$u['consignment_id'] === (int)$d['consignment_id']) { $existing = $u; break; }
          }
          $recPkg  = $existing['received_packages'] ?? $d['no_of_pieces'];
          $short   = $existing['short_count']       ?? 0;
          $damaged = $existing['damaged_count']      ?? 0;
          $sReason = $existing['short_reason']       ?? '';
          $dReason = $existing['damage_reason']      ?? '';
        ?>
        <tr data-cid="<?= $d['consignment_id'] ?>">
          <td style="color:#94a3b8;font-size:.7rem"><?= $i+1 ?></td>
          <td><b style="font-size:.73rem"><?= htmlspecialchars($d['consignment_note']) ?></b></td>
          <td style="font-size:.71rem"><?= htmlspecialchars($d['origin_name'] ?? '—') ?></td>
          <td style="font-size:.71rem"><?= htmlspecialchars($d['dest_name'] ?? '—') ?></td>
          <td style="text-align:right;font-size:.72rem"><?= (int)$d['no_of_pieces'] ?></td>
          <td><input class="form-control form-control-sm ut-received" type="number" min="0" value="<?= (int)$recPkg ?>" oninput="calcShort(this)"></td>
          <td><input class="form-control form-control-sm ut-short" type="number" min="0" value="<?= (int)$short ?>" readonly style="background:#fff7ed"></td>
          <td><input class="form-control form-control-sm ut-damaged" type="number" min="0" value="<?= (int)$damaged ?>"></td>
          <td><input class="form-control form-control-sm" style="min-width:90px;font-size:.7rem" placeholder="reason…" value="<?= htmlspecialchars($sReason) ?>" data-type="short_reason"></td>
          <td><input class="form-control form-control-sm" style="min-width:90px;font-size:.7rem" placeholder="reason…" value="<?= htmlspecialchars($dReason) ?>" data-type="damage_reason"></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Dispatch from TP -->
    <?php if ($canDispatch): ?>
    <div class="d-flex align-items-center gap-2 mt-3 flex-wrap" style="border-top:1px solid #e2e8f0;padding-top:10px">
      <b style="font-size:.75rem">Dispatch from <?= htmlspecialchars($tp['city_name'] ?? '') ?>:</b>
      <input class="form-control form-control-sm" type="datetime-local" id="depDt_<?= $tpIdVal ?>"
             value="<?= date('Y-m-d\TH:i') ?>" style="max-width:200px">
      <button class="btn btn-success btn-sm" onclick="dispatchFromTP(<?= $tpIdVal ?>)">
        🚀 Dispatch to Next Point
      </button>
    </div>
    <?php endif; ?>

    <?php if ($tp['status'] === 'Dispatched'): ?>
    <div class="notice ok" style="margin-top:8px;margin-bottom:0">✅ Dispatched from this point on <?= date('d/m/Y H:i', strtotime($tp['departure_datetime'])) ?>.</div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ── Manifests Summary ───────────────────────────────────────── -->
<div class="card-section">
  <h3>📄 Manifest Summary (<?= count($manifests) ?>)</h3>
  <?php if (empty($manifests)): ?>
    <div class="notice warn">No manifests. <a href="thc_manifest.php?thc_id=<?= $thcId ?>">Create manifests →</a></div>
  <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
    <?php foreach ($manifests as $m):
      $sc = ['Draft'=>'#f1f5f9','Saved'=>'#dbeafe','Dispatched'=>'#dcfce7','Delivered'=>'#f0fdf4'];
      $tc = ['Draft'=>'#475569','Saved'=>'#1d4ed8','Dispatched'=>'#15803d','Delivered'=>'#166534'];
    ?>
    <div class="mnf-pill" style="background:<?= $sc[$m['status']] ?? '#f1f5f9' ?>;border-color:<?= $tc[$m['status']] ?? '#94a3b8' ?>22">
      <span style="font-weight:700;color:#0c2a45"><?= htmlspecialchars($m['manifest_no']) ?></span>
      <span style="color:#475569">→ <?= htmlspecialchars($m['dest_name'] ?? '—') ?></span>
      <span style="background:<?= $sc[$m['status']] ?? '#f1f5f9' ?>;color:<?= $tc[$m['status']] ?? '#475569' ?>;padding:1px 6px;border-radius:8px;font-size:.65rem">
        <?= $m['status'] ?> · <?= $m['item_count'] ?> dkts
      </span>
    </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ── Additional Loading (post-dispatch) ───────────────────── -->
<?php if ($thc['status'] === 'Dispatched'): ?>
<div class="card-section">
  <h3>➕ Additional Loading (Same THC)</h3>
  <p style="font-size:.78rem;color:#475569;margin-bottom:8px">
    You can add more dockets to this dispatched THC by preparing another Loading Tally.
  </p>
  <a href="thc_loading_tally.php?thc_id=<?= $thcId ?>" class="btn btn-outline-primary btn-sm">
    📋 Add More Dockets (New LT)
  </a>
</div>
<?php endif; ?>

</div><!-- /.dsp-wrap -->
</main>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const csrf  = <?= json_encode($csrf) ?>;
const thcId = <?= $thcId ?>;

function showMsg(msg, ok) {
  const el = document.getElementById('globalMsg');
  el.textContent = msg;
  el.className = ok ? 'ok' : 'err';
  el.style.display = 'block';
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  setTimeout(() => el.style.display = 'none', 5000);
}

// ── Dispatch THC ──────────────────────────────────────────────
async function dispatchTHC() {
  if (!confirm('Dispatch this THC? All Loading Tallies will be marked as Dispatched.')) return;
  const f = new FormData();
  f.append('csrf_token', csrf);
  f.append('action', 'dispatch_thc');
  f.append('thc_id', thcId);
  const r = await fetch('api/save_thc_dispatch.php', { method: 'POST', body: f });
  const d = await r.json();
  showMsg(d.message, d.success);
  if (d.success) setTimeout(() => location.reload(), 1000);
}

// ── Complete THC ──────────────────────────────────────────────
async function completeTHC() {
  if (!confirm('Mark this THC as Completed? This indicates final destination delivery.')) return;
  const f = new FormData();
  f.append('csrf_token', csrf);
  f.append('action', 'complete_thc');
  f.append('thc_id', thcId);
  const r = await fetch('api/save_thc_dispatch.php', { method: 'POST', body: f });
  const d = await r.json();
  showMsg(d.message, d.success);
  if (d.success) setTimeout(() => location.reload(), 1000);
}

// ── Record Arrival ─────────────────────────────────────────────
async function recordArrival(tpId) {
  const dt = document.getElementById('arrivalDt_' + tpId)?.value;
  if (!dt) { showMsg('Select arrival date & time.', false); return; }
  const f = new FormData();
  f.append('csrf_token', csrf);
  f.append('action', 'arrive_tp');
  f.append('thc_id', thcId);
  f.append('tp_id', tpId);
  f.append('arrival_datetime', dt.replace('T', ' '));
  const r = await fetch('api/save_thc_dispatch.php', { method: 'POST', body: f });
  const d = await r.json();
  showMsg(d.message, d.success);
  if (d.success) setTimeout(() => location.reload(), 800);
}

// ── Short count auto-calc ─────────────────────────────────────
function calcShort(inp) {
  const row     = inp.closest('tr');
  const origPkg = parseInt(row.querySelector('td:nth-child(5)').textContent.trim()) || 0;
  const received= parseInt(inp.value) || 0;
  const shortEl = row.querySelector('.ut-short');
  const diff    = origPkg - received;
  shortEl.value = Math.max(0, diff);
}

// ── Save Unloading Tally ──────────────────────────────────────
async function saveUnloading(tpId) {
  const tbody = document.querySelectorAll(`#utTable_${tpId} tbody tr`);
  const items = [];
  tbody.forEach(row => {
    const inputs = row.querySelectorAll('input');
    const cid = parseInt(row.dataset.cid);
    if (!cid) return;
    const tds   = row.querySelectorAll('td');
    const origPkg = parseInt(tds[4].textContent) || 0;
    items.push({
      consignment_id:    cid,
      original_packages: origPkg,
      received_packages: parseInt(inputs[0].value) || 0,
      short_count:       parseInt(inputs[1].value) || 0,
      damaged_count:     parseInt(inputs[2].value) || 0,
      short_reason:      inputs[3].value,
      damage_reason:     inputs[4].value,
      remarks:           '',
    });
  });

  const f = new FormData();
  f.append('csrf_token', csrf);
  f.append('action', 'save_unloading');
  f.append('thc_id', thcId);
  f.append('tp_id', tpId);
  f.append('items', JSON.stringify(items));
  const r = await fetch('api/save_thc_dispatch.php', { method: 'POST', body: f });
  const d = await r.json();
  showMsg(d.message, d.success);
}

// ── Dispatch from Touching Point ──────────────────────────────
async function dispatchFromTP(tpId) {
  const dt = document.getElementById('depDt_' + tpId)?.value;
  if (!dt) { showMsg('Select departure date & time.', false); return; }
  if (!confirm('Dispatch from this touching point to next?')) return;
  const f = new FormData();
  f.append('csrf_token', csrf);
  f.append('action', 'dispatch_from_tp');
  f.append('thc_id', thcId);
  f.append('tp_id', tpId);
  f.append('departure_datetime', dt.replace('T', ' '));
  const r = await fetch('api/save_thc_dispatch.php', { method: 'POST', body: f });
  const d = await r.json();
  showMsg(d.message, d.success);
  if (d.success) setTimeout(() => location.reload(), 800);
}
</script>
</body>
</html>

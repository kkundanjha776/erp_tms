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

// ── Filters ───────────────────────────────────────────────────────
$fStatus = trim($_GET['status'] ?? '');
$fFrom   = trim($_GET['from']   ?? date('Y-m-01'));
$fTo     = trim($_GET['to']     ?? date('Y-m-d'));
$fSearch = trim($_GET['q']      ?? '');

$where  = ['1=1'];
$types  = '';
$params = [];

if ($fFrom !== '') { $where[] = 't.thc_date >= ?'; $types .= 's'; $params[] = $fFrom; }
if ($fTo   !== '') { $where[] = 't.thc_date <= ?'; $types .= 's'; $params[] = $fTo; }
if ($fStatus !== '') { $where[] = 't.status = ?'; $types .= 's'; $params[] = $fStatus; }
if ($fSearch !== '') {
    $where[] = '(t.thc_no LIKE ? OR t.vehicle_no LIKE ? OR t.vendor_name LIKE ?)';
    $types  .= 'sss';
    $like    = '%' . $fSearch . '%';
    $params  = array_merge($params, [$like, $like, $like]);
}

$sql = "SELECT t.id, t.thc_no, t.thc_date, t.transport_mode, t.vehicle_no,
               t.vendor_name, t.status, t.total_amount,
               oc.city_name AS origin_name, dc.city_name AS destination_name,
               rm.route_name, rm.route_code,
               (SELECT COUNT(*) FROM loading_tally_items lti WHERE lti.thc_id = t.id) AS docket_count,
               (SELECT COUNT(*) FROM loading_tally lt WHERE lt.thc_id = t.id) AS lt_count
        FROM thc t
        LEFT JOIN cities oc ON oc.id = t.origin_city_id
        LEFT JOIN cities dc ON dc.id = t.destination_city_id
        LEFT JOIN route_masters rm ON rm.id = t.route_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.thc_date DESC, t.id DESC
        LIMIT 300";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$thcList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Summary counts ────────────────────────────────────────────────
$counts = [];
$cRes = $conn->query("SELECT status, COUNT(*) AS n FROM thc GROUP BY status");
if ($cRes) while ($r = $cRes->fetch_assoc()) $counts[$r['status']] = (int)$r['n'];

$statusColors = [
    'Draft'      => ['bg' => '#f1f5f9', 'text' => '#475569'],
    'Active'     => ['bg' => '#dbeafe', 'text' => '#1d4ed8'],
    'Dispatched' => ['bg' => '#dcfce7', 'text' => '#15803d'],
    'Completed'  => ['bg' => '#f0fdf4', 'text' => '#166534'],
    'Cancelled'  => ['bg' => '#fee2e2', 'text' => '#b91c1c'],
];
$modeIcons = [
    'Surface' => '🚛', 'Air' => '✈️', 'Rail' => '🚂',
    'FTL' => '🚚', 'Co-loader' => '📦', 'Data Movement' => '💾',
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(appBrandTitle('Trip Hire Contract')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
<style>
.thc-wrap{max-width:1400px;margin:16px auto;padding:0 16px}
.thc-hero{background:linear-gradient(120deg,#083b5c,#0f5c3a);border-radius:14px;padding:18px 24px;color:#fff;display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:12px}
.thc-hero h2{margin:0;font-size:1.2rem}
.thc-hero p{margin:4px 0 0;font-size:.78rem;color:#a7f3d0}
.kpi-row{display:flex;gap:10px;flex-wrap:wrap}
.kpi-box{background:#ffffff22;border:1px solid #ffffff33;padding:8px 14px;border-radius:10px;text-align:center;min-width:80px}
.kpi-box b{display:block;font-size:1.1rem}
.kpi-box small{font-size:.65rem;color:#c7f9e3}
.filter-card{background:#fff;border:1px solid #dce4ea;border-radius:12px;padding:14px 16px;margin-bottom:14px}
.filter-row{display:grid;grid-template-columns:1fr 1fr 1.5fr 1fr auto;gap:10px;align-items:end}
.filter-row label{font-size:.7rem;font-weight:700;color:#4b6475;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px}
.thc-table{width:100%;border-collapse:separate;border-spacing:0 5px}
.thc-table th{color:#64748b;font-size:.68rem;font-weight:700;text-transform:uppercase;padding:6px 10px;background:transparent}
.thc-table td{background:#fff;padding:9px 10px;font-size:.8rem;border-top:1px solid #e8eef2;border-bottom:1px solid #e8eef2;vertical-align:middle}
.thc-table td:first-child{border-left:1px solid #e8eef2;border-radius:8px 0 0 8px}
.thc-table td:last-child{border-right:1px solid #e8eef2;border-radius:0 8px 8px 0}
.thc-table tr:hover td{background:#f8fafc}
.status-pill{font-size:.68rem;padding:3px 9px;border-radius:20px;font-weight:600;white-space:nowrap}
.route-seq{display:flex;align-items:center;gap:4px;flex-wrap:wrap;font-size:.72rem}
.route-seq span{background:#e0f2fe;color:#0369a1;padding:2px 7px;border-radius:8px}
.route-seq .arrow{color:#94a3b8}
.action-btns{display:flex;gap:5px;flex-wrap:nowrap}
.action-btns .btn{font-size:.68rem;padding:4px 8px;white-space:nowrap}
.no-data{text-align:center;padding:36px;color:#94a3b8}
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
<?php renderAppHeader('Trip Hire Contract', '🚚'); ?>
<main class="app-body">
<div class="thc-wrap">

<!-- Hero -->
<div class="thc-hero">
  <div>
    <h2>🚚 Trip Hire Contract (THC)</h2>
    <p>Manage vehicle trips — loading tally, manifest, dispatch and touching points.</p>
  </div>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <div class="kpi-row">
      <?php foreach (['Active','Dispatched','Completed','Draft'] as $s): ?>
      <div class="kpi-box">
        <b><?= $counts[$s] ?? 0 ?></b>
        <small><?= $s ?></small>
      </div>
      <?php endforeach; ?>
    </div>
    <a href="thc_create.php" class="btn btn-light btn-sm fw-bold ms-2">＋ New THC</a>
  </div>
</div>

<!-- Filters -->
<form class="filter-card filter-row" method="get">
  <div>
    <label>From Date</label>
    <input class="form-control form-control-sm" type="date" name="from" value="<?= htmlspecialchars($fFrom) ?>">
  </div>
  <div>
    <label>To Date</label>
    <input class="form-control form-control-sm" type="date" name="to" value="<?= htmlspecialchars($fTo) ?>">
  </div>
  <div>
    <label>Search (THC No / Vehicle / Vendor)</label>
    <input class="form-control form-control-sm" name="q" placeholder="THC/2026/0001 or WB03B…" value="<?= htmlspecialchars($fSearch) ?>">
  </div>
  <div>
    <label>Status</label>
    <select class="form-select form-select-sm" name="status">
      <option value="">All</option>
      <?php foreach (array_keys($statusColors) as $s): ?>
      <option value="<?= $s ?>" <?= $fStatus===$s?'selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>&nbsp;</label>
    <button class="btn btn-primary btn-sm w-100" type="submit">Search</button>
  </div>
</form>

<!-- Table -->
<div style="background:#fff;border:1px solid #dce4ea;border-radius:12px;padding:14px">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <b style="font-size:.82rem">THC Records — <?= count($thcList) ?> found</b>
  </div>
  <div style="overflow-x:auto">
  <table class="thc-table">
    <thead>
      <tr>
        <th>#</th>
        <th>THC No</th>
        <th>Date</th>
        <th>Mode / Vehicle</th>
        <th>Route</th>
        <th>Origin → Destination</th>
        <th>Vendor</th>
        <th style="text-align:right">Amount (₹)</th>
        <th>Dockets / LT</th>
        <th>Status</th>
        <th style="text-align:center">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($thcList)): ?>
      <tr><td colspan="11" class="no-data">No THC records found. <a href="thc_create.php">Create first THC →</a></td></tr>
    <?php else: ?>
      <?php foreach ($thcList as $i => $t):
        $sc = $statusColors[$t['status']] ?? $statusColors['Draft'];
        $icon = $modeIcons[$t['transport_mode']] ?? '🚛';
      ?>
      <tr>
        <td style="color:#94a3b8;font-size:.7rem"><?= $i+1 ?></td>
        <td>
          <a href="thc_create.php?thc_id=<?= $t['id'] ?>" style="font-weight:700;color:#0c2a45;font-size:.78rem;text-decoration:none">
            <?= htmlspecialchars($t['thc_no']) ?>
          </a>
        </td>
        <td style="white-space:nowrap;color:#475569"><?= date('d/m/Y', strtotime($t['thc_date'])) ?></td>
        <td>
          <div style="font-size:.78rem"><?= $icon ?> <?= htmlspecialchars($t['transport_mode']) ?></div>
          <?php if ($t['vehicle_no']): ?>
          <div style="font-size:.7rem;color:#64748b;font-weight:600"><?= htmlspecialchars($t['vehicle_no']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($t['route_name']): ?>
          <div style="font-size:.72rem;font-weight:600;color:#0369a1"><?= htmlspecialchars($t['route_code']) ?></div>
          <div style="font-size:.7rem;color:#64748b"><?= htmlspecialchars($t['route_name']) ?></div>
          <?php else: ?>
          <span style="color:#94a3b8;font-size:.7rem">No route</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="route-seq">
            <span style="background:#dcfce7;color:#15803d"><?= htmlspecialchars($t['origin_name'] ?? '—') ?></span>
            <span class="arrow">→</span>
            <span style="background:#fee2e2;color:#b91c1c"><?= htmlspecialchars($t['destination_name'] ?? '—') ?></span>
          </div>
        </td>
        <td style="font-size:.75rem;color:#475569"><?= htmlspecialchars($t['vendor_name'] ?: '—') ?></td>
        <td style="text-align:right;font-weight:700;color:#0f766e;white-space:nowrap">
          ₹<?= number_format((float)$t['total_amount'], 2) ?>
        </td>
        <td style="text-align:center">
          <span style="font-size:.72rem;color:#475569">
            <?= (int)$t['docket_count'] ?> dkt / <?= (int)$t['lt_count'] ?> LT
          </span>
        </td>
        <td>
          <span class="status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>">
            <?= htmlspecialchars($t['status']) ?>
          </span>
        </td>
        <td>
          <div class="action-btns">
            <!-- Edit -->
            <a href="thc_create.php?thc_id=<?= $t['id'] ?>" class="btn btn-outline-primary btn-sm" title="Edit THC">✏️</a>
            <!-- Prepare LT -->
            <a href="thc_loading_tally.php?thc_id=<?= $t['id'] ?>" class="btn btn-outline-success btn-sm" title="Prepare Loading Tally">📋 LT</a>
            <!-- Manifest -->
            <?php if (in_array($t['status'], ['Active','Dispatched'], true)): ?>
            <a href="thc_manifest.php?thc_id=<?= $t['id'] ?>" class="btn btn-outline-info btn-sm" title="Manifest">📄</a>
            <?php endif; ?>
            <!-- Dispatch / Track -->
            <?php if (in_array($t['status'], ['Active','Dispatched'], true)): ?>
            <a href="thc_dispatch.php?thc_id=<?= $t['id'] ?>" class="btn btn-outline-warning btn-sm" title="Dispatch / Track">🚀</a>
            <?php endif; ?>
            <!-- Delete -->
            <?php if (in_array($t['status'], ['Draft','Active'], true)): ?>
            <button class="btn btn-outline-danger btn-sm" title="Delete THC"
              onclick="deleteTHC(<?= $t['id'] ?>, '<?= htmlspecialchars($t['thc_no'], ENT_QUOTES) ?>')">🗑️</button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

</div><!-- /.thc-wrap -->
</main>
</div>
</div>

<div id="toast" style="display:none;position:fixed;bottom:20px;right:20px;background:#0c2a45;color:#fff;padding:12px 20px;border-radius:10px;font-size:.82rem;z-index:9999;box-shadow:0 4px 16px #0003"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const csrf = <?= json_encode($csrf) ?>;
function toast(msg, ok=true) {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.style.background = ok ? '#0c2a45' : '#b91c1c';
  el.style.display = 'block';
  setTimeout(() => el.style.display = 'none', 3000);
}
async function deleteTHC(id, no) {
  if (!confirm(`Delete THC "${no}"? This cannot be undone.`)) return;
  const f = new FormData();
  f.append('csrf_token', csrf);
  f.append('action', 'delete');
  f.append('thc_id', id);
  const r = await fetch('api/save_thc.php', { method: 'POST', body: f });
  const d = await r.json();
  toast(d.message, d.success);
  if (d.success) setTimeout(() => location.reload(), 800);
}
</script>
</body>
</html>

<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/master_data.php';

startSecureSession();
requireLogin();

$conn = getDBConnection();

$today = date('Y-m-d');
$stats = [
    'total_dockets' => 0,
    'today_dockets' => 0,
    'in_transit' => 0,
    'delivered' => 0,
    'pending_pod' => 0,
    'total_clients' => 0,
    'total_revenue' => 0.0,
];

function safeCount(mysqli $conn, string $sql): int {
    try { $r = $conn->query($sql); if ($r) { $row = $r->fetch_assoc(); $r->free(); return (int)($row['n'] ?? 0); } } catch (Throwable $e) {} return 0;
}
function safeScalar(mysqli $conn, string $sql, string $key = 'total'): float {
    try { $r = $conn->query($sql); if ($r) { $row = $r->fetch_assoc(); $r->free(); return (float)($row[$key] ?? 0); } } catch (Throwable $e) {} return 0.0;
}

$stats['total_dockets'] = safeCount($conn, 'SELECT COUNT(*) AS n FROM consignments');
$stats['today_dockets'] = safeCount($conn, "SELECT COUNT(*) AS n FROM consignments WHERE booking_date = '" . $conn->real_escape_string($today) . "'");
$stats['in_transit'] = safeCount($conn, "SELECT COUNT(*) AS n FROM consignments WHERE docket_tracking_status IN ('In Transit','Connecting to Next Destination','Out for Delivery','Hold','Return')");
$stats['delivered'] = safeCount($conn, "SELECT COUNT(*) AS n FROM consignments WHERE docket_tracking_status = 'Delivered'");
$stats['pending_pod'] = safeCount($conn, 'SELECT COUNT(*) AS n FROM consignments c WHERE NOT EXISTS (SELECT 1 FROM pod_files p WHERE p.consignment_id = c.id)');
$clientCount = safeCount($conn, "SELECT COUNT(*) AS n FROM client_masters WHERE status = 'Active'");
if ($clientCount === 0) { $clientCount = safeCount($conn, 'SELECT COUNT(*) AS n FROM client_masters'); }
$stats['total_clients'] = $clientCount;
$stats['total_revenue'] = safeScalar($conn, 'SELECT COALESCE(SUM(grand_total),0) AS total FROM consignments', 'total');

$modules = [
    ['icon' => '📝', 'title' => 'Create Docket Entry', 'desc' => 'New consignment / docket booking', 'url' => 'index.php', 'color' => '#2563eb'],
    ['icon' => '🧾', 'title' => 'E-Way Bill Entry', 'desc' => 'Fetch & save E-Way Bill docket details', 'url' => 'ewaybill_docket_entry.php', 'color' => '#0d9488'],
    ['icon' => '🔎', 'title' => 'Docket Search / Edit', 'desc' => 'Find, view and edit dockets', 'url' => 'ops_search.php', 'color' => '#0891b2'],
    ['icon' => '👥', 'title' => 'Client Master', 'desc' => 'Clients, contracts and lane rates', 'url' => 'client_master.php', 'color' => '#7c3aed'],
    ['icon' => '📄', 'title' => 'POD Upload', 'desc' => 'Pending proof-of-delivery uploads', 'url' => 'pod_upload.php', 'color' => '#ea580c'],
    ['icon' => '✅', 'title' => 'POD Uploaded', 'desc' => 'Uploaded proof-of-delivery list', 'url' => 'pod_upload.php?view=uploaded', 'color' => '#16a34a'],
    ['icon' => '📍', 'title' => 'Docket Status', 'desc' => 'Quickly update docket status', 'url' => 'docket_status.php', 'color' => '#db2777'],
    ['icon' => '🚛', 'title' => 'Docket Tracking', 'desc' => 'Shipment tracking view', 'url' => 'docket_tracking.php', 'color' => '#0f766e'],
    ['icon' => '📊', 'title' => 'Detailed Report', 'desc' => 'Consignment report with Excel export', 'url' => 'report.php', 'color' => '#4f46e5'],
    ['icon' => '🧾', 'title' => 'Billing & Invoices', 'desc' => 'Create GST invoices from eligible dockets', 'url' => 'billing.php', 'color' => '#0f766e'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard - ERP TMS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=dash1">
<style>
.dash-wrap{padding:14px;display:flex;flex-direction:column;gap:14px;height:100%;overflow:auto}
.dash-greeting{background:#fff;border:1px solid var(--border);border-radius:8px;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.dash-greeting h2{margin:0;font-size:1.05rem;font-weight:700;color:var(--primary)}
.dash-greeting p{margin:2px 0 0;font-size:.72rem;color:#64748b}
.dash-today{font-size:.7rem;color:#475569;background:#eef2ff;border:1px solid #e0e7ff;padding:4px 10px;border-radius:10px;font-weight:600}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}
.stat-card{background:#fff;border:1px solid var(--border);border-radius:8px;padding:12px;display:flex;align-items:center;gap:12px;transition:transform .15s,box-shadow .15s;position:relative;overflow:hidden}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 6px 14px rgba(15,76,129,.08)}
.stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
.stat-body{flex:1;min-width:0}
.stat-label{font-size:.62rem;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.3px}
.stat-value{font-size:1.15rem;font-weight:800;color:#0f172a;margin-top:2px;line-height:1.1}
.stat-sub{font-size:.6rem;color:#94a3b8;margin-top:2px}
.stat-card.alt::before{content:"";position:absolute;right:-20px;top:-20px;width:90px;height:90px;border-radius:50%;opacity:.08}
.modules-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px}
.module-card{background:#fff;border:1px solid var(--border);border-radius:8px;padding:14px;text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:8px;transition:transform .15s,box-shadow .15s,border-color .15s;position:relative;overflow:hidden}
.module-card:hover{transform:translateY(-3px);box-shadow:0 10px 22px rgba(15,76,129,.1);border-color:#93c5fd;text-decoration:none;color:inherit}
.module-card .mc-head{display:flex;align-items:center;gap:10px}
.module-card .mc-icon{width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#fff;flex-shrink:0}
.module-card h3{margin:0;font-size:.82rem;font-weight:700;color:#0f172a}
.module-card p{margin:0;font-size:.68rem;color:#64748b;line-height:1.4}
.module-card .mc-arrow{margin-left:auto;font-size:.8rem;color:#94a3b8;transition:color .15s,transform .15s}
.module-card:hover .mc-arrow{color:#2563eb;transform:translateX(3px)}
@media(max-width:900px){.stats-grid{grid-template-columns:repeat(2,1fr)}.modules-grid{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<div class="app-shell">
<aside class="app-sidebar">
    <?php renderAppBrand(); ?>
    <nav class="sidebar-nav">
        <?php renderSidebarNav(); ?>
    </nav>
    <nav class="sidebar-nav" style="display:none">
        <div class="nav-module">
            <div class="nav-module-title">OPS — Operations</div>
            <ul class="nav-list">
                <li><a href="dashboard.php" class="nav-item active" title="Home Dashboard"><span class="nav-icon">🏠</span><span class="nav-label">Dashboard</span></a></li>
                <li><a href="index.php" class="nav-item" title="Create Docket Entry"><span class="nav-icon">📝</span><span class="nav-label">Create Docket Entry</span></a></li>
                <li><a href="ops_search.php" class="nav-item" title="Docket Search / View / Edit"><span class="nav-icon">🔎</span><span class="nav-label">Docket Search / Edit</span></a></li>
                <li><a href="client_master.php" class="nav-item" title="Client Master & Contracts"><span class="nav-icon">👥</span><span class="nav-label">Client Master</span></a></li>
                <li><a href="pod_upload.php" class="nav-item" title="POD Upload Queue"><span class="nav-icon">📄</span><span class="nav-label">POD Upload</span></a></li>
                <li><a href="pod_upload.php?view=uploaded" class="nav-item" title="Uploaded POD List"><span class="nav-icon">✅</span><span class="nav-label">POD Uploaded</span></a></li>
                <li><a href="docket_status.php" class="nav-item" title="Update Docket Status"><span class="nav-icon">📍</span><span class="nav-label">Docket Status</span></a></li>
                <li><a href="docket_tracking.php" class="nav-item" title="Track Shipment"><span class="nav-icon">🚛</span><span class="nav-label">Docket Tracking</span></a></li>
                <li><a href="report.php" class="nav-item" title="Detailed Excel Report"><span class="nav-icon">📊</span><span class="nav-label">Detailed Report</span></a></li>
            </ul>
        </div>
    </nav>
    <div class="sidebar-footer" style="display:none">
        <div class="sidebar-user">
            <div class="sidebar-user-avatar"><?= strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)) ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-username"><?= htmlspecialchars($_SESSION['username']) ?></div>
                <div class="sidebar-role"><?= htmlspecialchars($_SESSION['role']) ?></div>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout" title="Logout">↪ Logout</a>
    </div>
    <?php renderSidebarFooter(); ?>
</aside>

<div class="app-main">
<?php renderAppHeader('Dashboard', '🏠'); ?>

<div class="app-body">
  <div class="dash-wrap">

    <div class="dash-greeting">
      <div>
        <h2>Welcome, <?= htmlspecialchars($_SESSION['username']) ?> 👋</h2>
        <p>ERP TMS — Logistics Management System. Use the cards below to jump to any module.</p>
      </div>
      <div class="dash-today"><?= date('l, d M Y') ?></div>
    </div>

    <div class="stats-grid">
      <div class="stat-card alt">
        <div class="stat-icon" style="background:#dbeafe;color:#1d4ed8">📦</div>
        <div class="stat-body">
          <div class="stat-label">Total Dockets</div>
          <div class="stat-value"><?= number_format($stats['total_dockets']) ?></div>
          <div class="stat-sub">All bookings</div>
        </div>
      </div>
      <div class="stat-card alt">
        <div class="stat-icon" style="background:#dcfce7;color:#166534">🗓️</div>
        <div class="stat-body">
          <div class="stat-label">Today's Dockets</div>
          <div class="stat-value"><?= number_format($stats['today_dockets']) ?></div>
          <div class="stat-sub"><?= htmlspecialchars($today) ?></div>
        </div>
      </div>
      <div class="stat-card alt">
        <div class="stat-icon" style="background:#fef3c7;color:#92400e">🚚</div>
        <div class="stat-body">
          <div class="stat-label">In Transit</div>
          <div class="stat-value"><?= number_format($stats['in_transit']) ?></div>
          <div class="stat-sub">Active shipments</div>
        </div>
      </div>
      <div class="stat-card alt">
        <div class="stat-icon" style="background:#ede9fe;color:#6d28d9">✅</div>
        <div class="stat-body">
          <div class="stat-label">Delivered</div>
          <div class="stat-value"><?= number_format($stats['delivered']) ?></div>
          <div class="stat-sub">Delivered shipments</div>
        </div>
      </div>
      <div class="stat-card alt">
        <div class="stat-icon" style="background:#fee2e2;color:#991b1b">📄</div>
        <div class="stat-body">
          <div class="stat-label">Pending POD</div>
          <div class="stat-value"><?= number_format($stats['pending_pod']) ?></div>
          <div class="stat-sub">Awaiting upload</div>
        </div>
      </div>
      <div class="stat-card alt">
        <div class="stat-icon" style="background:#e0f2fe;color:#075985">👥</div>
        <div class="stat-body">
          <div class="stat-label">Total Clients</div>
          <div class="stat-value"><?= number_format($stats['total_clients']) ?></div>
          <div class="stat-sub">Active clients</div>
        </div>
      </div>
      <div class="stat-card alt" style="grid-column:span 1">
        <div class="stat-icon" style="background:#ccfbf1;color:#115e59">₹</div>
        <div class="stat-body">
          <div class="stat-label">Total Revenue</div>
          <div class="stat-value">₹ <?= number_format($stats['total_revenue'], 2) ?></div>
          <div class="stat-sub">Grand Total sum</div>
        </div>
      </div>
    </div>

    <div>
      <div style="font-weight:700;font-size:.8rem;color:var(--primary);margin:4px 2px 8px;padding-bottom:4px;border-bottom:1px solid #e2e8f0">📂 Quick Module Links</div>
      <div class="modules-grid">
        <?php foreach ($modules as $m): ?>
          <a class="module-card" href="<?= htmlspecialchars($m['url']) ?>">
            <div class="mc-head">
              <div class="mc-icon" style="background:<?= $m['color'] ?>"><?= $m['icon'] ?></div>
              <span class="mc-arrow">→</span>
            </div>
            <h3><?= htmlspecialchars($m['title']) ?></h3>
            <p><?= htmlspecialchars($m['desc']) ?></p>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</div>

</div>
</div>
</body>
</html>

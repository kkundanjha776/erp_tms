<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/master_data.php';

startSecureSession();
requireLogin();

$conn = getDBConnection();

$f_note = isset($_GET['note']) ? trim((string)$_GET['note']) : '';
$f_billing = isset($_GET['billing']) ? trim((string)$_GET['billing']) : '';
$f_consignee = isset($_GET['consignee']) ? trim((string)$_GET['consignee']) : '';
$f_status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$f_date_from = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
$f_date_to = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';

$hasFilters = ($f_note !== '' || $f_billing !== '' || $f_consignee !== '' || $f_status !== '' || $f_date_from !== '' || $f_date_to !== '');
$rows = [];
if ($hasFilters) {
    $rows = getFilteredConsignments($conn, $f_note, $f_billing, $f_consignee, $f_status, $f_date_from, $f_date_to, 500);
} else {
    $rows = getRecentConsignments($conn, 100);
}

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmtNum($n): string {
    $n = (float)$n;
    return number_format($n, 2, '.', ',');
}
function statusClass(string $s): string {
    return $s;
}
$isAdmin = isAdminUser();
$role = currentUserRole();

function canEditRow(?string $status, bool $isAdmin): bool {
    $s = (string)$status;
    if ($s === 'Draft' || $s === '') return true;
    if ($s === 'Submitted') return $isAdmin;
    return false;
}
function lockReason(?string $status, bool $isAdmin, string $role): string {
    $s = (string)$status;
    if ($s === 'Approved') return 'Approved consignment cannot be edited';
    if ($s === 'Submitted' && !$isAdmin) return 'Only Admin can edit Submitted (your role: ' . $role . ')';
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(appBrandTitle('Docket Search / View / Edit')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=opsmenu1">
</head>
<body>
<div class="app-shell">
    <aside class="app-sidebar">
        <?php renderAppBrand(); ?>
        <nav class="sidebar-nav">
            <?php renderSidebarNav(); ?>
        </nav>
        <?php renderSidebarFooter(); ?>
    </aside>

    <div class="app-main">
        <header class="app-header">
            <h1>🔎 Docket Search / View / Edit</h1>
            <div class="header-actions">
                <a href="index.php" class="btn btn-outline-light btn-sm">➕ New Docket</a>
                <?php
                $_br = currentUserBranchName();
                $_brSuffix = '';
                if ($_br && $_br !== '—') { $_bc = currentUserBranchCode(); $_brSuffix = ' · ' . htmlspecialchars(($_bc ? $_bc . ' — ' : '') . $_br); }
                ?><span class="user-info"><?= h($_SESSION['username']) ?> (<?= h($role) ?>)<?= $_brSuffix ?></span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
            </div>
        </header>

        <div class="app-body search-page">
            <form method="get" class="search-filter-card" novalidate>
                <div class="search-filter-title">🔍 Filters</div>
                <div class="search-filter-grid">
                    <div class="form-group">
                        <label>Consignment No.</label>
                        <input type="text" name="note" class="form-control" value="<?= h($f_note) ?>" maxlength="50" placeholder="Search by docket no...">
                    </div>
                    <div class="form-group">
                        <label>Billing Party</label>
                        <input type="text" name="billing" class="form-control" value="<?= h($f_billing) ?>" maxlength="200" placeholder="Billing party name...">
                    </div>
                    <div class="form-group">
                        <label>Consignee</label>
                        <input type="text" name="consignee" class="form-control" value="<?= h($f_consignee) ?>" maxlength="200" placeholder="Consignee name...">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            <option value="Draft" <?= $f_status === 'Draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="Submitted" <?= $f_status === 'Submitted' ? 'selected' : '' ?>>Submitted</option>
                            <option value="Approved" <?= $f_status === 'Approved' ? 'selected' : '' ?>>Approved</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Booking Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?= h($f_date_from) ?>">
                    </div>
                    <div class="form-group">
                        <label>Booking Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?= h($f_date_to) ?>">
                    </div>
                </div>
                <div class="search-filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">🔎 Search</button>
                    <a href="ops_search.php" class="btn btn-outline-secondary btn-sm">❌ Clear</a>
                    <span class="result-count-badge"><?= count($rows) ?> record(s) / रिकॉर्ड</span>
                </div>
            </form>

            <div class="results-card">
                <div class="results-header">
                    <span class="results-title"><?= $hasFilters ? 'Search Results / खोज परिणाम' : 'Recent Dockets / हाल के डॉकेट' ?></span>
                </div>
                <?php if (empty($rows)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📭</div>
                        <div class="empty-title">No records found / कोई रिकॉर्ड नहीं मिला</div>
                        <div class="empty-hint">Try adjusting filters or clear and search again / फ़िल्टर बदलें और पुनः प्रयास करें</div>
                    </div>
                <?php else: ?>
                <div class="results-table-wrap">
                    <table class="results-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Docket No.</th>
                                <th>Status</th>
                                <th>Booking Date</th>
                                <th>Billing Party</th>
                                <th>Consignee</th>
                                <th>Truck No</th>
                                <th class="num">Act Wt</th>
                                <th class="num">Chg Wt</th>
                                <th class="num">Grand Total</th>
                                <th>Created</th>
                                <th class="actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $i => $r):
                                $canEdit = canEditRow($r['status'] ?? null, $isAdmin);
                                $reason = lockReason($r['status'] ?? null, $isAdmin, $role);
                                $editTitle = $canEdit ? 'Edit / संपादन करें' : ('🔒 ' . $reason);
                            ?>
                            <tr class="status-row-<?= statusClass($r['status'] ?? 'Draft') ?>">
                                <td class="muted"><?= $i + 1 ?></td>
                                <td class="mono strong"><?= h($r['consignment_note']) ?></td>
                                <td>
                                    <span class="status-pill status-<?= statusClass($r['status'] ?? 'Draft') ?>"><?= h($r['status'] ?? 'Draft') ?></span>
                                </td>
                                <td><?= !empty($r['booking_date']) ? date('d M Y', strtotime($r['booking_date'])) : '-' ?></td>
                                <td><?= h($r['billing_party_name'] ?? '-') ?></td>
                                <td><?= h($r['consignee_name'] ?? '-') ?></td>
                                <td><?= h($r['truck_no'] ?? '-') ?></td>
                                <td class="num"><?= fmtNum($r['actual_weight'] ?? 0) ?></td>
                                <td class="num"><?= fmtNum($r['charged_weight'] ?? 0) ?></td>
                                <td class="num grand-total-cell">₹ <?= fmtNum($r['grand_total'] ?? 0) ?></td>
                                <td class="muted small"><?= !empty($r['created_at']) ? date('d M y H:i', strtotime($r['created_at'])) : '-' ?></td>
                                <td class="actions">
                                    <a href="index.php?id=<?= (int)$r['id'] ?>" class="btn btn-<?= $canEdit ? 'primary' : 'secondary' ?> btn-xs" title="<?= h($editTitle) ?>" <?= $canEdit ? '' : 'aria-disabled="true" style="pointer-events:none;opacity:0.65"' ?>>
                                        <?= $canEdit ? '✏️ Edit' : '🔒 View' ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<div id="toastContainer" class="toast-container"></div>
</body>
</html>

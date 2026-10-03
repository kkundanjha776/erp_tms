<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ewaybill_service.php';
require_once __DIR__ . '/includes/ewaybill_config.php';

startSecureSession();
requireLogin();
requireModule('ewaybill_docket');

$conn = getDBConnection();
ensureEwaybillSchema($conn);
$recent = getRecentEwaybillDockets($conn, 15);

$csrf = generateCSRFToken();
$assetVersion = (string) (filemtime(__DIR__ . '/js/ewaybill-docket.js') ?: time());
$demoMode = isEwaybillDemoMode();
$apiConfigured = isEwaybillConfigured();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(appBrandTitle('E-Way Bill Docket Entry')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?= $assetVersion ?>">
    <style>
        .ewb-page { padding: 14px; overflow: auto; height: 100%; display: flex; flex-direction: column; gap: 12px; }
        .ewb-panel { background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 14px; }
        .ewb-panel h2 { margin: 0 0 10px; font-size: .9rem; color: var(--primary); font-weight: 700; }
        .ewb-input-section { display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: start; }
        .ewb-bulk-input { min-height: 70px; font-family: monospace; font-size: .78rem; }
        .ewb-input-rows { display: flex; flex-direction: column; gap: 6px; margin-top: 8px; }
        .ewb-input-row { display: grid; grid-template-columns: 1fr 36px; gap: 6px; }
        .ewb-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .ewb-status-bar { display: none; padding: 8px 12px; border-radius: 6px; font-size: .72rem; margin-top: 8px; }
        .ewb-status-bar.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .ewb-status-bar.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .ewb-status-bar.loading { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .ewb-status-bar.info { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .ewb-results-wrap { flex: 1; min-height: 0; overflow: auto; }
        .ewb-empty { text-align: center; color: #64748b; padding: 40px 20px; font-size: .78rem; }
        .ewb-card { border: 1px solid #cbd5e1; border-left: 4px solid #2563eb; border-radius: 8px; padding: 12px; margin-bottom: 10px; background: #fafbfc; }
        .ewb-card-error { border-left-color: #dc2626; }
        .ewb-card-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 10px; }
        .ewb-no-label { font-size: .62rem; color: #64748b; text-transform: uppercase; display: block; }
        .ewb-no-value { font-size: 1rem; color: #0f172a; }
        .ewb-badge { display: inline-block; font-size: .6rem; padding: 2px 8px; border-radius: 10px; margin-left: 6px; font-weight: 600; }
        .ewb-badge.demo { background: #fef3c7; color: #92400e; }
        .ewb-badge.status { background: #dcfce7; color: #166534; }
        .ewb-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
        .ewb-field { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 8px; }
        .ewb-field.span-2 { grid-column: span 2; }
        .ewb-field label { display: block; font-size: .58rem; color: #64748b; text-transform: uppercase; font-weight: 600; margin-bottom: 2px; }
        .ewb-field span { font-size: .72rem; color: #0f172a; line-height: 1.35; }
        .ewb-details { margin-top: 8px; font-size: .72rem; }
        .ewb-details summary { cursor: pointer; color: #2563eb; font-weight: 600; }
        .ewb-details pre { background: #f1f5f9; padding: 8px; border-radius: 6px; font-size: .65rem; overflow: auto; max-height: 200px; }
        .ewb-table { width: 100%; border-collapse: collapse; font-size: .68rem; margin-top: 6px; }
        .ewb-table th, .ewb-table td { border: 1px solid #e2e8f0; padding: 4px 6px; text-align: left; }
        .ewb-table th { background: #f8fafc; }
        .ewb-remarks { margin-top: 8px; }
        .ewb-remarks label { font-size: .62rem; color: #64748b; font-weight: 600; }
        .ewb-error { color: #dc2626; font-size: .72rem; padding: 8px; background: #fef2f2; border-radius: 6px; }
        .ewb-config-note { font-size: .68rem; color: #475569; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; padding: 8px 10px; margin-bottom: 10px; }
        .ewb-recent { font-size: .68rem; }
        .ewb-recent table { width: 100%; border-collapse: collapse; }
        .ewb-recent th, .ewb-recent td { border: 1px solid #e2e8f0; padding: 5px 8px; text-align: left; }
        .ewb-recent th { background: #f1f5f9; font-size: .62rem; text-transform: uppercase; }
        .ewb-top-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .ewb-count-badge { background: #2563eb; color: #fff; font-size: .65rem; padding: 3px 10px; border-radius: 12px; font-weight: 600; }
        @media (max-width: 900px) {
            .ewb-grid { grid-template-columns: 1fr 1fr; }
            .ewb-field.span-2 { grid-column: span 2; }
            .ewb-input-section { grid-template-columns: 1fr; }
        }
    </style>
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
        <?php renderAppHeader('E-Way Bill Docket Entry', '🧾'); ?>
        <main class="app-body">
            <div class="ewb-page">
                <div class="ewb-config-note">
                    <?php if ($apiConfigured): ?>
                        ✓ E-Way Bill API credentials configured (<?= htmlspecialchars(EWAYBILL_ENV) ?> mode). Live lookups will use the NIC GST E-Way Bill system.
                    <?php elseif ($demoMode): ?>
                        ⚠ <strong>Demo mode</strong> — API credentials not configured. Sample data loads for UI testing.
                        Edit <code>includes/ewaybill_config.php</code> and add your portal credentials + public key to enable live lookups.
                    <?php else: ?>
                        ⚠ E-Way Bill API not configured. Add credentials in <code>includes/ewaybill_config.php</code>.
                    <?php endif; ?>
                </div>

                <div class="ewb-panel">
                    <h2>Enter E-Way Bill Number(s)</h2>
                    <p style="font-size:.72rem;color:#64748b;margin:0 0 8px">Enter one or multiple 12-digit E-Way Bill numbers (comma, space, or newline separated). Details auto-fetch on blur or when you click Fetch.</p>
                    <div class="ewb-input-section">
                        <div>
                            <textarea id="ewbNumbersInput" class="form-control ewb-bulk-input" placeholder="e.g. 123456789012&#10;123456789013, 123456789014"></textarea>
                            <div id="ewbInputRows" class="ewb-input-rows"></div>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:6px">
                            <button type="button" id="fetchEwbBtn" class="btn btn-primary btn-sm">Fetch Details</button>
                            <button type="button" id="addEwbRowBtn" class="btn btn-outline-secondary btn-sm">+ Add Row</button>
                        </div>
                    </div>
                    <div id="ewbStatusBar" class="ewb-status-bar"></div>
                </div>

                <div class="ewb-panel ewb-results-wrap">
                    <div class="ewb-top-bar">
                        <h2 style="margin:0">Fetched E-Way Bill Details</h2>
                        <span id="ewbCountBadge" class="ewb-count-badge">0 loaded</span>
                    </div>
                    <div id="ewbResults" class="ewb-results-wrap" style="margin-top:10px"></div>
                    <div class="ewb-actions">
                        <button type="button" id="saveDraftBtn" class="btn btn-outline-primary btn-sm" disabled>Save as Draft</button>
                        <button type="button" id="saveSubmitBtn" class="btn btn-success btn-sm" disabled>Save & Submit</button>
                        <button type="button" id="clearAllBtn" class="btn btn-outline-secondary btn-sm">Clear All</button>
                    </div>
                </div>

                <?php if (!empty($recent)): ?>
                <div class="ewb-panel ewb-recent">
                    <h2>Recently Saved E-Way Bill Dockets</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>E-Way Bill No</th>
                                <th>Consignor</th>
                                <th>Consignee</th>
                                <th>Vehicle</th>
                                <th>Status</th>
                                <th>Valid Until</th>
                                <th>Saved</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent as $r): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($r['ewb_no']) ?></strong></td>
                                <td><?= htmlspecialchars($r['consignor_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['consignee_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['vehicle_no'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['current_status'] ?? '—') ?> <small>(<?= htmlspecialchars($r['status']) ?>)</small></td>
                                <td><?= htmlspecialchars($r['valid_upto'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['created_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>
<script>
    window.EWB_CSRF = <?= json_encode($csrf) ?>;
    window.EWB_DEMO_MODE = <?= $demoMode ? 'true' : 'false' ?>;
</script>
<script src="js/ewaybill-docket.js?v=<?= $assetVersion ?>"></script>
</body>
</html>

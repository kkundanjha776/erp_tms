<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/master_data.php';
require_once __DIR__ . '/includes/city_search_widget.php';

startSecureSession();
requireLogin();

$conn = getDBConnection();
$master = getAllMasterData($conn);
$recentTemplates = getRecentConsignments($conn, 10);

$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$consignment = $editId > 0 ? getConsignmentById($conn, $editId) : null;
$isBilledDocket = false;
if ($consignment) {
    ensureBillingSchema($conn);
    $billLockStmt = $conn->prepare('SELECT id FROM invoice_items WHERE consignment_id=? LIMIT 1');
    $billLockStmt->bind_param('i', $editId);
    $billLockStmt->execute();
    $isBilledDocket = (bool)$billLockStmt->get_result()->fetch_assoc();
    $billLockStmt->close();
}

function fieldVal(?array $data, string $key, string $default = ''): string
{
    return htmlspecialchars($data[$key] ?? $default, ENT_QUOTES, 'UTF-8');
}

function selectedOpt(?array $data, string $key, $optVal): string
{
    return (isset($data[$key]) && $data[$key] == $optVal) ? 'selected' : '';
}

function parseInvoiceRows(?array $consignment): array
{
    $serialized = $consignment['party_invoice_no'] ?? '';
    $declaredValue = $consignment['declared_value'] ?? '';
    $rows = [];

    if (is_string($serialized) && $serialized !== '') {
        $parts = array_filter(array_map('trim', explode('|', $serialized)), static function ($part): bool {
            return $part !== '';
        });

        foreach ($parts as $part) {
            if (strpos($part, ':') !== false) {
                [$invoiceNo, $value] = array_pad(explode(':', $part, 2), 2, '');
                $rows[] = [
                    'invoice_no' => $invoiceNo,
                    'declared_value' => $value,
                ];
            } else {
                $rows[] = [
                    'invoice_no' => $part,
                    'declared_value' => is_numeric($declaredValue) ? (string) $declaredValue : '',
                ];
            }
        }
    }

    if (empty($rows)) {
        $rows[] = [
            'invoice_no' => '',
            'declared_value' => '',
        ];
    }

    return $rows;
}

$csrf = generateCSRFToken();
$assetVersion = (string) max(
    filemtime(__DIR__ . '/css/style.css') ?: 0,
    filemtime(__DIR__ . '/js/form.js') ?: 0,
    filemtime(__DIR__ . '/js/city-search.js') ?: 0
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consignment Entry - ERP TMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=<?= $assetVersion ?>">
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
                    <li>
                        <a href="dashboard.php" class="nav-item" title="Home Dashboard">
                            <span class="nav-icon">🏠</span>
                            <span class="nav-label">Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a href="index.php" class="nav-item active" title="Create Docket Entry">
                            <span class="nav-icon">📝</span>
                            <span class="nav-label">Create Docket Entry</span>
                        </a>
                    </li>
                    <li>
                        <a href="ops_search.php" class="nav-item" title="Docket Search / View / Edit">
                            <span class="nav-icon">🔎</span>
                            <span class="nav-label">Docket Search / Edit</span>
                        </a>
                    </li>
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
        <header class="app-header">
            <h1>📦 Consignment Entry Form</h1>
            <div class="header-actions">
                <div class="search-box">
                    <input type="text" id="searchConsignmentInput" class="form-control search-input" placeholder="Search by Consignment No..." maxlength="50" autocomplete="off">
                    <button type="button" id="searchConsignmentBtn" class="btn btn-outline-light btn-sm search-btn" title="Search Consignment">🔍</button>
                    <div id="searchResultsDropdown" class="search-results-dropdown"></div>
                </div>
                <select id="templateSelect" class="form-select template-select" title="Load previous entry">
                    <option value="">Load Template...</option>
                    <?php foreach ($recentTemplates as $t): ?>
                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['consignment_note']) ?> · <?= !empty($t['booking_date']) ? date('d M', strtotime($t['booking_date'])) : 'No date' ?> · <?= htmlspecialchars($t['billing_party_name']) ?> · <?= htmlspecialchars($t['consignee_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php $headerCompany = getActiveCompanyName($conn); if ($headerCompany !== ''): ?><span class="user-info" style="margin-right:10px">🏢 <?= htmlspecialchars($headerCompany) ?></span><?php endif; ?>
                <span class="user-info"><?= htmlspecialchars($_SESSION['username']) ?> (<?= htmlspecialchars($_SESSION['role']) ?>)</span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
            </div>
        </header>

        <div class="app-body">
        <form id="consignmentForm" novalidate>
            <input type="hidden" id="csrf_token" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" id="consignment_id" name="id" value="<?= fieldVal($consignment, 'id') ?>">
            <input type="hidden" id="billing_locked" value="<?= $isBilledDocket ? '1' : '0' ?>">
            <input type="hidden" id="form_action" name="action" value="draft">
            <input type="hidden" id="form_status" value="<?= fieldVal($consignment, 'status') ?>">
            <input type="hidden" id="amount_words" name="amount_words" value="<?= fieldVal($consignment, 'amount_words') ?>">
            <input type="hidden" id="client_master_id" name="client_master_id" value="<?= fieldVal($consignment, 'client_master_id') ?>">

            <div class="form-grid">
                <!-- COLUMN 1: Booking & Consignment Details -->
                <div class="form-column">
                    <div class="column-header">Booking & Consignment</div>
                    <div class="column-body">
                        <div class="form-section">
                            <div class="section-title">Booking Details ▾</div>
                            <div class="section-content">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Booking Date <span class="req">*</span></label>
                                        <input type="date" id="booking_date" name="booking_date" class="form-control" value="<?= fieldVal($consignment, 'booking_date', date('Y-m-d')) ?>" required>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                    <div class="form-group">
                                        <label>Consignment Note <span class="req">*</span></label>
                                        <input type="text" id="consignment_note" name="consignment_note" class="form-control" value="<?= fieldVal($consignment, 'consignment_note') ?>" required maxlength="50">
                                        <div id="noteStatus" class="note-status"></div>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Handover Date</label>
                                        <input type="date" id="handover_date" name="handover_date" class="form-control" value="<?= fieldVal($consignment, 'handover_date') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Handover Time</label>
                                        <input type="time" id="handover_time" name="handover_time" class="form-control" value="<?= fieldVal($consignment, 'handover_time') ?>">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Truck No</label>
                                        <input type="text" id="truck_no" name="truck_no" class="form-control" value="<?= fieldVal($consignment, 'truck_no') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Booking Type</label>
                                        <select id="booking_type_id" name="booking_type_id" class="form-select">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($master['booking_types'] as $bt): ?>
                                            <option value="<?= $bt['id'] ?>" <?= selectedOpt($consignment, 'booking_type_id', $bt['id']) ?>><?= htmlspecialchars($bt['type_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-row compact-inline">
                                    <div class="form-group">
                                        <label>Co-loader / Courier</label>
                                        <select id="courier_company_id" name="courier_company_id" class="form-select">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($master['courier_companies'] as $cc): ?>
                                            <option value="<?= $cc['id'] ?>" <?= selectedOpt($consignment, 'courier_company_id', $cc['id']) ?>><?= htmlspecialchars($cc['company_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="section-title">Origin - Destination ▾</div>
                            <div class="section-content">
                                <div class="form-row">
                                    <?php renderCitySearchField([
                                        'field_id' => 'origin_city_id',
                                        'label' => 'Origin City',
                                        'required' => true,
                                        'placeholder' => 'Search PIN, area, or district',
                                        'selected_city' => findCityInMaster($master['cities'], (int) ($consignment['origin_city_id'] ?? 0)),
                                    ]); ?>
                                    <?php renderCitySearchField([
                                        'field_id' => 'destination_city_id',
                                        'label' => 'Destination City',
                                        'required' => true,
                                        'placeholder' => 'Search PIN, area, or district',
                                        'selected_city' => findCityInMaster($master['cities'], (int) ($consignment['destination_city_id'] ?? 0)),
                                    ]); ?>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="section-title">Shipment Details ▾</div>
                            <div class="section-content">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>No. of Pieces</label>
                                        <input type="number" id="no_of_pieces" name="no_of_pieces" class="form-control" value="<?= fieldVal($consignment, 'no_of_pieces', '0') ?>" min="0">
                                    </div>
                                    <div class="form-group">
                                        <label>Packing Method</label>
                                        <select id="packing_method_id" name="packing_method_id" class="form-select">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($master['packing_methods'] as $pm): ?>
                                            <option value="<?= $pm['id'] ?>" <?= selectedOpt($consignment, 'packing_method_id', $pm['id']) ?>><?= htmlspecialchars($pm['method_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Actual Wt (kg) <span class="req">*</span></label>
                                        <input type="number" id="actual_weight" name="actual_weight" class="form-control" value="<?= fieldVal($consignment, 'actual_weight', '0') ?>" step="0.01" min="0" required>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                    <div class="form-group">
                                        <label class="weight-label">Charged Wt (kg)
                                            <span class="weight-mode-toggle" role="group" aria-label="Charge weight mode">
                                                <button type="button" class="weight-mode-btn <?= ($consignment['charge_weight_mode'] ?? 'Auto') !== 'Manual' ? 'active' : '' ?>" data-weight-mode="auto" aria-pressed="<?= ($consignment['charge_weight_mode'] ?? 'Auto') !== 'Manual' ? 'true' : 'false' ?>">Auto</button>
                                                <button type="button" class="weight-mode-btn <?= ($consignment['charge_weight_mode'] ?? 'Auto') === 'Manual' ? 'active' : '' ?>" data-weight-mode="manual" aria-pressed="<?= ($consignment['charge_weight_mode'] ?? 'Auto') === 'Manual' ? 'true' : 'false' ?>">Manual</button>
                                            </span>
                                        </label>
                                        <input type="number" id="charged_weight" name="charged_weight" class="form-control" value="<?= fieldVal($consignment, 'charged_weight', '0') ?>" step="0.01" min="0" readonly>
                                        <input type="hidden" id="charge_weight_mode" name="charge_weight_mode" value="<?= ($consignment['charge_weight_mode'] ?? 'Auto') === 'Manual' ? 'Manual' : 'Auto' ?>">
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </div>
                                <div class="dimension-grid">
                                    <div class="dimension-grid-header">
                                        <span>L (in)</span>
                                        <span>W (in)</span>
                                        <span>H (in)</span>
                                        <span>Volume</span>
                                    </div>
                                    <div id="dimensionRows" class="dimension-rows">
                                        <div class="dimension-row">
                                            <input type="number" name="dimension_length[]" class="form-control dimension-input" step="0.1" min="0" placeholder="L">
                                            <input type="number" name="dimension_width[]" class="form-control dimension-input" step="0.1" min="0" placeholder="W">
                                            <input type="number" name="dimension_height[]" class="form-control dimension-input" step="0.1" min="0" placeholder="H">
                                            <div class="dimension-volume-cell">
                                                <input type="text" class="form-control dimension-volume-value" value="<?= fieldVal($consignment, 'volume_lxwxh') ?>" readonly>
                                            </div>
                                            <button type="button" class="btn btn-outline-primary btn-sm add-dimension-row">+</button>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" id="volume_lxwxh" name="volume_lxwxh" value="<?= fieldVal($consignment, 'volume_lxwxh') ?>">
                                <div class="grid-summary">
                                    <span>Total Volume: <strong id="dimensionTotalVolume">0.00</strong></span>
                                    <span>No. of Rows: <strong id="dimensionRowCount">1</strong></span>
                                </div>
                                <div class="form-row full">
                                    <div class="form-group">
                                        <label>Applicable Lane Rates</label>
                                        <div id="laneRatesDisplay" class="lane-rates-display">
                                            <div class="text-muted" style="font-size:0.7rem;">Select client, origin and destination to see applicable rates</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-row full">
                                    <div class="form-group">
                                        <label>Description</label>
                                        <textarea id="description" name="description" class="form-control"><?= fieldVal($consignment, 'description') ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- COLUMN 2: Party Information -->
                <div class="form-column">
                    <div class="column-header">Client / Party Information</div>
                    <div class="column-body">
                        <div class="form-section">
                            <div class="section-title">Billing Party Details ▾</div>
                            <div class="section-content">
                                <div class="form-row full">
                                    <div class="form-group">
                                        <label>Billing Party Name <span class="req">*</span></label>
                                        <div class="input-group compact-input-group">
                                            <input type="text" id="billing_party_name" name="billing_party_name" list="clientMasterList" class="form-control" value="<?= fieldVal($consignment, 'billing_party_name') ?>" required autocomplete="off">
                                        </div>
                                        <select id="billing_client_picker" class="form-select form-select-sm mt-2" aria-label="Select client from Client Master">
                                            <option value="">Select / change client from Client Master</option>
                                            <?php foreach ($master['client_masters'] as $client): ?>
                                                <option value="<?= (int)$client['id'] ?>" <?= ($consignment && (int)($consignment['client_master_id'] ?? 0) === (int)$client['id']) ? 'selected' : '' ?>><?= htmlspecialchars($client['client_name'] . ' · ' . $client['client_code']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </div>
                                <div class="form-row full">
                                    <div class="form-group">
                                        <label>Contract Billing Basis</label>
                                        <div class="row g-2">
                                            <div class="col-6"><select id="contract_billing_basis" class="form-select form-select-sm"><option value="kg">KG</option><option value="pieces">Pieces</option><option value="km">KM</option></select></div>
                                            <div class="col-6"><input type="number" id="contract_quantity" class="form-control form-control-sm" min="0" step="0.01" placeholder="Quantity / KM"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="billing-info-grid">
                                    <div class="billing-info-item">
                                        <label>Address:</label>
                                        <span id="billing_address_display"><?= fieldVal($consignment, 'billing_address') ?: '—' ?></span>
                                        <input type="hidden" id="billing_address" name="billing_address" value="<?= fieldVal($consignment, 'billing_address') ?>">
                                    </div>
                                    <div class="billing-info-item">
                                        <label>State:</label>
                                        <span id="billing_state_display"><?php 
                                            if ($consignment && $consignment['billing_state_id']) {
                                                $stateKey = array_search($consignment['billing_state_id'], array_column($master['states'], 'id'));
                                                echo $stateKey !== false ? htmlspecialchars($master['states'][$stateKey]['state_name']) : '—';
                                            } else {
                                                echo '—';
                                            }
                                        ?></span>
                                        <input type="hidden" id="billing_state_id" name="billing_state_id" value="<?= fieldVal($consignment, 'billing_state_id') ?>">
                                    </div>
                                    <div class="billing-info-item">
                                        <label>City:</label>
                                        <span id="billing_city_display"><?php 
                                            if ($consignment && $consignment['billing_city_id']) {
                                                $cityKey = array_search($consignment['billing_city_id'], array_column($master['cities'], 'id'));
                                                echo $cityKey !== false ? htmlspecialchars($master['cities'][$cityKey]['city_name']) : '—';
                                            } else {
                                                echo '—';
                                            }
                                        ?></span>
                                        <input type="hidden" id="billing_city_id" name="billing_city_id" value="<?= fieldVal($consignment, 'billing_city_id') ?>">
                                    </div>
                                    <div class="billing-info-item">
                                        <label>PIN:</label>
                                        <span id="billing_pin_display"><?= fieldVal($consignment, 'billing_pin') ?: '—' ?></span>
                                        <input type="hidden" id="billing_pin" name="billing_pin" value="<?= fieldVal($consignment, 'billing_pin') ?>">
                                    </div>
                                    <div class="billing-info-item">
                                        <label>Phone:</label>
                                        <span id="billing_phone_display"><?= fieldVal($consignment, 'billing_phone') ?: '—' ?></span>
                                        <input type="hidden" id="billing_phone" name="billing_phone" value="<?= fieldVal($consignment, 'billing_phone') ?>">
                                    </div>
                                    <div class="billing-info-item">
                                        <label>GST No:</label>
                                        <span id="billing_gst_display"><?= fieldVal($consignment, 'billing_gst_no') ?: '—' ?></span>
                                        <input type="hidden" id="billing_gst_no" name="billing_gst_no" value="<?= fieldVal($consignment, 'billing_gst_no') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                               <div class="section-title">Consignor Details ▾</div>
                            <div class="section-content">
                                <div class="form-row full">
                                    <div class="form-group">
                                        <label>Consignor Name <span class="req">*</span></label>
                                        <div class="input-group compact-input-group">
                                            <input type="text" id="consignor_name" name="consignor_name" list="partyMasterList" class="form-control" value="<?= fieldVal($consignment, 'consignor_name') ?>" required autocomplete="off">
                                        </div>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </div>
                                <div class="form-row full">
                                    <div class="form-group">
                                        <label>Address</label>
                                        <textarea id="consignor_address" name="consignor_address" class="form-control address-single-line"><?= fieldVal($consignment, 'consignor_address') ?></textarea>
                                    </div>
                                </div>
                                <div class="form-row compact-inline">
                                    <div class="form-group">
                                        <label>State</label>
                                        <select id="consignor_state_id" name="consignor_state_id" class="form-select">
                                            <option value="">-- State --</option>
                                            <?php foreach ($master['states'] as $st): ?>
                                            <option value="<?= $st['id'] ?>" <?= selectedOpt($consignment, 'consignor_state_id', $st['id']) ?>><?= htmlspecialchars($st['state_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php renderCitySearchField([
                                        'field_id' => 'consignor_city_id',
                                        'label' => 'City',
                                        'state_field_id' => 'consignor_state_id',
                                        'pin_field_id' => 'consignor_pin',
                                        'selected_city' => findCityInMaster($master['cities'], (int) ($consignment['consignor_city_id'] ?? 0)),
                                    ]); ?>
                                    <div class="form-group">
                                        <label>PIN</label>
                                        <input type="text" id="consignor_pin" name="consignor_pin" class="form-control" value="<?= fieldVal($consignment, 'consignor_pin') ?>" maxlength="6">
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </div>
                                <div class="form-row compact-inline">
                                    <div class="form-group">
                                        <label>Phone</label>
                                        <input type="text" id="consignor_phone" name="consignor_phone" class="form-control" value="<?= fieldVal($consignment, 'consignor_phone') ?>" maxlength="10">
                                        <div class="invalid-feedback"></div>
                                    </div>
                                    <div class="form-group">
                                        <label>GST No</label>
                                        <input type="text" id="consignor_gst_no" name="consignor_gst_no" class="form-control" value="<?= fieldVal($consignment, 'consignor_gst_no') ?>" maxlength="15" style="text-transform:uppercase">
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="section-title">Consignee Details ▾</div>
                            <div class="section-content">
                                <div class="form-row full">
                                    <div class="form-group">
                                        <label>Consignee Name <span class="req">*</span></label>
                                        <div class="input-group compact-input-group">
                                            <input type="text" id="consignee_name" name="consignee_name" list="partyMasterList" class="form-control" value="<?= fieldVal($consignment, 'consignee_name') ?>" required autocomplete="off">
                                        </div>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </div>
                                <div class="form-row full">
                                    <div class="form-group">
                                        <label>Address</label>
                                        <textarea id="consignee_address" name="consignee_address" class="form-control address-single-line"><?= fieldVal($consignment, 'consignee_address') ?></textarea>
                                    </div>
                                </div>
                                <div class="form-row compact-inline">
                                    <div class="form-group">
                                        <label>State</label>
                                        <select id="consignee_state_id" name="consignee_state_id" class="form-select">
                                            <option value="">-- State --</option>
                                            <?php foreach ($master['states'] as $st): ?>
                                            <option value="<?= $st['id'] ?>" <?= selectedOpt($consignment, 'consignee_state_id', $st['id']) ?>><?= htmlspecialchars($st['state_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php renderCitySearchField([
                                        'field_id' => 'consignee_city_id',
                                        'label' => 'City',
                                        'state_field_id' => 'consignee_state_id',
                                        'pin_field_id' => 'consignee_pin',
                                        'selected_city' => findCityInMaster($master['cities'], (int) ($consignment['consignee_city_id'] ?? 0)),
                                    ]); ?>
                                    <div class="form-group">
                                        <label>PIN</label>
                                        <input type="text" id="consignee_pin" name="consignee_pin" class="form-control" value="<?= fieldVal($consignment, 'consignee_pin') ?>" maxlength="6">
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </div>
                                <div class="form-row compact-inline">
                                    <div class="form-group">
                                        <label>Phone</label>
                                        <input type="text" id="consignee_phone" name="consignee_phone" class="form-control" value="<?= fieldVal($consignment, 'consignee_phone') ?>" maxlength="10">
                                        <div class="invalid-feedback"></div>
                                    </div>
                                    <div class="form-group">
                                        <label>GST No</label>
                                        <input type="text" id="consignee_gst_no" name="consignee_gst_no" class="form-control" value="<?= fieldVal($consignment, 'consignee_gst_no') ?>" maxlength="15" style="text-transform:uppercase">
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- COLUMN 3: Charges & Final Details -->
                <div class="form-column">
                    <div class="column-header">Charges & Final Details</div>
                    <div class="column-body">
                        <div class="form-section">
                            <div class="section-title">Charges Breakdown ▾</div>
                            <div class="section-content">
                                <div class="charges-grid">
                                    <div class="charge-input">
                                        <label>Freight <span class="req">*</span></label>
                                        <input type="number" id="basic_freight" name="basic_freight" value="<?= fieldVal($consignment, 'basic_freight', '0') ?>" step="0.01" min="0">
                                    </div>
                                    <div class="charge-input">
                                        <label>Fuel</label>
                                        <input type="number" id="fuel_charge" name="fuel_charge" value="<?= fieldVal($consignment, 'fuel_charge', '0') ?>" step="0.01" min="0">
                                    </div>
                                    <div class="charge-input">
                                        <label>DKT</label>
                                        <input type="number" id="dkt_charge" name="dkt_charge" value="<?= fieldVal($consignment, 'dkt_charge', '0') ?>" step="0.01" min="0">
                                    </div>
                                    <div class="charge-input">
                                        <label>Handling</label>
                                        <input type="number" id="handling_charge" name="handling_charge" value="<?= fieldVal($consignment, 'handling_charge', '0') ?>" step="0.01" min="0">
                                    </div>
                                    <div class="charge-input">
                                        <label>ODA</label>
                                        <input type="number" id="oda_charge" name="oda_charge" value="<?= fieldVal($consignment, 'oda_charge', '0') ?>" step="0.01" min="0">
                                    </div>
                                    <div class="charge-input">
                                        <label>Detention</label>
                                        <input type="number" id="detention" name="detention" value="<?= fieldVal($consignment, 'detention', '0') ?>" step="0.01" min="0">
                                    </div>
                                    <div class="charge-input">
                                        <label>Misc</label>
                                        <input type="number" id="misc_charge" name="misc_charge" value="<?= fieldVal($consignment, 'misc_charge', '0') ?>" step="0.01" min="0">
                                    </div>
                                    <div class="charge-input">
                                        <label>Other Charge</label>
                                        <input type="number" id="other_charge" name="other_charge" value="<?= fieldVal($consignment, 'other_charge', '0') ?>" step="0.01" min="0">
                                    </div>
                                    <div class="charge-input">
                                        <label>Risk Charge</label>
                                        <input type="number" id="risk_charge" name="risk_charge" value="<?= fieldVal($consignment, 'risk_charge', '0') ?>" step="0.01" min="0">
                                    </div>
                                </div>
                                <div class="invalid-feedback" id="basic_freight_error"></div>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="section-title">Tax Summary & Grand Total ▾</div>
                            <div class="section-content">
                                <div class="tax-compact-bar">
                                    <label for="gst_rate">GST</label>
                                    <select id="gst_rate" name="gst_rate" class="form-select tax-rate-select" aria-label="GST rate">
                                        <?php foreach ($master['gst_rates'] as $rate): ?>
                                        <option value="<?= $rate['rate'] ?>" data-id="<?= $rate['id'] ?>" <?= (float)$rate['rate'] === 18.0 ? 'selected' : '' ?>><?= rtrim(rtrim($rate['rate'], '0'), '.') ?>%</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" id="editGstMaster" class="tax-master-edit" title="Edit GST master">Edit</button>
                                    <span id="taxTypeLabel" class="tax-type-label">Intra-State</span>
                                    <span class="tax-amounts">CGST <b id="displayCgst">₹ 0.00</b> · SGST <b id="displaySgst">₹ 0.00</b> · IGST <b id="displayIgst">₹ 0.00</b></span>
                                </div>
                                <input type="hidden" id="cgst" name="cgst" value="0.00">
                                <input type="hidden" id="sgst" name="sgst" value="0.00">
                                <input type="hidden" id="igst" name="igst" value="0.00">
                                <div class="grand-total-box tax-total-compact">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="grand-total-label">Grand Total</span>
                                        <span id="displayGrandTotal" class="grand-total-amount">₹ 0.00</span>
                                    </div>
                                    <input type="hidden" id="grand_total" name="grand_total" value="0.00">
                                    <div class="amount-words-box">
                                        <label class="form-label mb-1 text-muted small">Amount in Words</label>
                                        <div id="displayAmountWords" class="amount-words-text">Zero Rupees Only</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="section-title">Additional Info ▾</div>
                            <div class="section-content">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Payment Type</label>
                                        <select id="payment_type_id" name="payment_type_id" class="form-select">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($master['payment_types'] as $pt): ?>
                                            <option value="<?= $pt['id'] ?>" <?= selectedOpt($consignment, 'payment_type_id', $pt['id']) ?>><?= htmlspecialchars($pt['type_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>PO Number</label>
                                        <input type="text" id="party_po_number" name="party_po_number" class="form-control" value="<?= fieldVal($consignment, 'party_po_number') ?>">
                                    </div>
                                </div>
                                <div class="invoice-grid">
                                    <div class="invoice-grid-header">
                                        <span>Party Invoice No</span>
                                        <span>Declared Value</span>
                                    </div>
                                    <div id="invoiceRows" class="invoice-rows">
                                        <?php foreach (parseInvoiceRows($consignment) as $row): ?>
                                            <div class="invoice-row">
                                                <input type="text" name="party_invoice_no[]" class="form-control invoice-input" value="<?= htmlspecialchars($row['invoice_no'], ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="number" name="declared_value[]" class="form-control invoice-input" step="0.01" min="0" value="<?= htmlspecialchars($row['declared_value'], ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="button" class="btn btn-outline-primary btn-sm add-invoice-row">+</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="grid-summary invoice-summary">
                                    <span>Total Value: <strong id="invoiceTotalValue">0.00</strong></span>
                                    <span>No. of Rows: <strong id="invoiceRowCount">1</strong></span>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Eway Bill No</label>
                                        <input type="text" id="eway_bill_no" name="eway_bill_no" class="form-control" value="<?= fieldVal($consignment, 'eway_bill_no') ?>">
                                        <div class="invalid-feedback"></div>
                                    </div>
                                    <div class="form-group">
                                        <label>Validity Date</label>
                                        <input type="date" id="validity_date" name="validity_date" class="form-control" value="<?= fieldVal($consignment, 'validity_date') ?>">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Truck Type</label>
                                        <select id="truck_type_id" name="truck_type_id" class="form-select">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($master['truck_types'] as $tt): ?>
                                            <option value="<?= $tt['id'] ?>" <?= selectedOpt($consignment, 'truck_type_id', $tt['id']) ?>><?= htmlspecialchars($tt['type_name']) ?> (<?= $tt['capacity'] ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Carrier Risk</label>
                                        <select id="carrier_risk_type" name="carrier_risk_type" class="form-select">
                                            <option value="">-- Select --</option>
                                            <option value="Owner" <?= ($consignment['carrier_risk_type'] ?? '') === 'Owner' ? 'selected' : '' ?>>Owner Risk</option>
                                            <option value="Carrier" <?= ($consignment['carrier_risk_type'] ?? '') === 'Carrier' ? 'selected' : '' ?>>Carrier Risk</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Received By</label>
                                        <input type="text" id="received_by_name" name="received_by_name" class="form-control" value="<?= fieldVal($consignment, 'received_by_name') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Pickup Time</label>
                                        <input type="time" id="pickup_time" name="pickup_time" class="form-control" value="<?= fieldVal($consignment, 'pickup_time') ?>">
                                    </div>
                                </div>
                                <div class="form-row full">
                                    <div class="form-group">
                                        <label>Booking Incharge</label>
                                        <input type="text" id="booking_incharge" name="booking_incharge" class="form-control" value="<?= fieldVal($consignment, 'booking_incharge', $_SESSION['username']) ?>">
                                    </div>
                                </div>
                                <div class="form-row full">
                                    <div class="form-group">
                                        <label>Remarks</label>
                                        <textarea id="remarks" name="remarks" class="form-control"><?= fieldVal($consignment, 'remarks') ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <footer class="app-footer">
        <div>
            <button type="button" id="btnReset" class="btn btn-outline-secondary">Clear / रीसेट</button>
        </div>
        <div class="d-flex gap-2">
            <button type="button" id="btnPrint" class="btn btn-outline-primary">Print / PDF</button>
            <button type="button" id="btnSaveDraft" class="btn btn-warning">Save Draft / ड्राफ्ट</button>
            <button type="button" id="btnSubmit" class="btn btn-success">Submit / जमा करें</button>
        </div>
    </footer>

    <div id="toastContainer" class="toast-container"></div>
        </div> <!-- /app-main -->
    </div> <!-- /app-shell -->

    <datalist id="partyMasterList">
        <?php if (!empty($master['billing_parties'])): ?>
        <?php foreach ($master['billing_parties'] as $party): ?>
        <option value="<?= htmlspecialchars($party['party_name']) ?>" 
                data-address="<?= htmlspecialchars($party['address'] ?? '') ?>" 
                data-state-id="<?= $party['state_id'] ?? '' ?>" 
                data-city-id="<?= $party['city_id'] ?? '' ?>" 
                data-pin="<?= htmlspecialchars($party['pin'] ?? '') ?>" 
                data-phone="<?= htmlspecialchars($party['phone'] ?? '') ?>" 
                data-gst="<?= htmlspecialchars($party['gst_no'] ?? '') ?>"></option>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if (!empty($master['client_masters'])): ?>
        <?php foreach ($master['client_masters'] as $client): ?>
        <option value="<?= htmlspecialchars($client['client_name']) ?>" 
                data-is-client="true" 
                data-client-id="<?= $client['id'] ?>" 
                data-address="<?= htmlspecialchars($client['address'] ?? '') ?>" 
                data-state-id="<?= $client['state_id'] ?? '' ?>" 
                data-city-id="<?= $client['city_id'] ?? '' ?>" 
                data-pin="<?= htmlspecialchars($client['pin'] ?? '') ?>" 
                data-phone="<?= htmlspecialchars($client['phone'] ?? '') ?>" 
                data-gst="<?= htmlspecialchars($client['gst_no'] ?? '') ?>" 
                data-docket-charge="<?= $client['docket_charge'] ?>" 
                data-oda-charge="<?= $client['oda_charge'] ?>" 
                data-fuel-charge="<?= $client['fuel_charge'] ?>"></option>
        <?php endforeach; ?>
        <?php endif; ?>
    </datalist>
    <datalist id="clientMasterList">
        <?php foreach ($master['client_masters'] as $client): ?>
        <option value="<?= htmlspecialchars($client['client_name']) ?>"
                data-is-client="true"
                data-client-id="<?= $client['id'] ?>"
                data-address="<?= htmlspecialchars($client['address'] ?? '') ?>"
                data-state-id="<?= $client['state_id'] ?? '' ?>"
                data-city-id="<?= $client['city_id'] ?? '' ?>"
                data-pin="<?= htmlspecialchars($client['pin'] ?? '') ?>"
                data-phone="<?= htmlspecialchars($client['phone'] ?? '') ?>"
                data-gst="<?= htmlspecialchars($client['gst_no'] ?? '') ?>"
                data-docket-charge="<?= $client['docket_charge'] ?>"
                data-oda-charge="<?= $client['oda_charge'] ?>"
                data-fuel-charge="<?= $client['fuel_charge'] ?>"></option>
        <?php endforeach; ?>
    </datalist>
    <script type="application/json" id="citiesData"><?= json_encode($master['cities']) ?></script>
    <script type="application/json" id="statesData"><?= json_encode($master['states']) ?></script>
    <script type="application/json" id="partyMastersData"><?= json_encode($master['billing_parties']) ?></script>
    <script type="application/json" id="contractClientsData"><?= json_encode($master['client_masters']) ?></script>
    <script type="application/json" id="currentUser"><?= json_encode([
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'username' => $_SESSION['username'] ?? '',
        'role' => $_SESSION['role'] ?? 'Operator',
        'is_admin' => isAdminUser(),
    ]) ?></script>
    <script src="js/city-search.js?v=<?= $assetVersion ?>"></script>
    <script src="js/form.js?v=<?= $assetVersion ?>"></script>
</body>
</html>

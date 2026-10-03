<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/master_data.php';

startSecureSession();
requireLogin();
requireModule('billing');

$conn = getDBConnection();
ensureBillingSchema($conn);
$csrf = generateCSRFToken();
$message = '';
$messageType = 'success';

$invoiceId = (int)($_GET['id'] ?? 0);
if ($invoiceId <= 0) {
    header('Location: invoice_management.php');
    exit;
}

// Get invoice details
$stmt = $conn->prepare('SELECT i.*, c.legal_name, c.trade_name FROM invoices i LEFT JOIN companies c ON i.company_id = c.id WHERE i.id = ?');
$stmt->bind_param('i', $invoiceId);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    header('Location: invoice_management.php');
    exit;
}

// Check if invoice can be edited (only pending and temp hold invoices)
if (!in_array($invoice['submission_status'], ['Pending', 'Temp Hold'])) {
    $message = 'Only pending and temp hold invoices can be edited.'; $messageType = 'danger';
}

// Get invoice items (current dockets)
$stmt = $conn->prepare('SELECT ii.consignment_id FROM invoice_items ii WHERE ii.invoice_id = ?');
$stmt->bind_param('i', $invoiceId);
$stmt->execute();
$currentDocketIds = [];
$result = $stmt->get_result();
while($row = $result->fetch_assoc()) {
    $currentDocketIds[] = $row['consignment_id'];
}
$stmt->close();

// Track unchecked dockets from current (for available section)
$uncheckedDocketIds = [];
if (isset($_GET['unchecked']) && trim($_GET['unchecked']) !== '') {
    $uncheckedDocketIds = array_map('intval', explode(',', trim($_GET['unchecked'])));
}

// Handle success message from redirect
if (isset($_GET['msg'])) {
    $message = urldecode($_GET['msg']);
    $messageType = 'success';
}

// Search parameters (same as billing.php)
$from = trim($_GET['from'] ?? date('Y-m-01'));
$to = trim($_GET['to'] ?? date('Y-m-d'));
$party = trim($_GET['party'] ?? '');
// If party is empty, use invoice billing party as default
if (empty($party)) {
    $party = $invoice['billing_party_name'];
}
$podOnly = ($_GET['pod_only'] ?? '') === '1';
$hasSearch = isset($_GET['search']) || !empty($uncheckedDocketIds);

// Build query for available dockets (excluding current invoice dockets, but including unchecked ones)
$where = ["c.basic_freight > 0"];
$types = ''; $params = [];

if ($from !== '') { $where[]='c.booking_date >= ?'; $types.='s'; $params[]=$from; }
if ($to !== '') { $where[]='c.booking_date <= ?'; $types.='s'; $params[]=$to; }
if ($party !== '') {
    $where[]='(c.billing_party_name = ? OR c.client_master_id IN (SELECT id FROM client_masters WHERE client_name = ?) OR c.client_master_id IN (SELECT id FROM client_masters WHERE client_code = ?))';
    $types.='sss'; $params[]=$party; $params[]=$party; $params[]=$party;
}
if ($podOnly) $where[]='EXISTS (SELECT 1 FROM pod_files pf WHERE pf.consignment_id=c.id)';

// Exclude current invoice dockets from search (excluding unchecked ones)
$excludedDocketIds = array_diff($currentDocketIds, $uncheckedDocketIds);
if (!empty($excludedDocketIds)) {
    $placeholders = implode(',', array_fill(0, count($excludedDocketIds), '?'));
    $where[] = "c.id NOT IN ($placeholders)";
    $types .= str_repeat('i', count($excludedDocketIds));
    $params = array_merge($params, $excludedDocketIds);
}

$dockets = [];
if ($hasSearch) { 
    $sql = "SELECT c.id,c.consignment_note,c.booking_date,c.no_of_pieces,c.charged_weight,c.basic_freight,c.grand_total, 
            EXISTS(SELECT 1 FROM pod_files p WHERE p.consignment_id=c.id) pod_ready
            FROM consignments c 
            WHERE " . implode(' AND ', $where) . "
            ORDER BY c.booking_date DESC, c.id DESC";
    $stmt=$conn->prepare($sql); 
    if($params) $stmt->bind_param($types,...$params); 
    $stmt->execute(); 
    $dockets=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); 
    $stmt->close(); 
    
    // Add unchecked dockets from current invoice
    if (!empty($uncheckedDocketIds)) {
        $placeholders = implode(',', array_fill(0, count($uncheckedDocketIds), '?'));
        $types = str_repeat('i', count($uncheckedDocketIds));
        $uncheckedStmt = $conn->prepare("SELECT c.id,c.consignment_note,c.booking_date,c.no_of_pieces,c.charged_weight,c.basic_freight,c.grand_total, 
            EXISTS(SELECT 1 FROM pod_files p WHERE p.consignment_id=c.id) pod_ready
            FROM consignments c 
            WHERE c.id IN ($placeholders)");
        $uncheckedStmt->bind_param($types, ...$uncheckedDocketIds);
        $uncheckedStmt->execute();
        $uncheckedDockets = $uncheckedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $uncheckedStmt->close();
        
        // Merge and deduplicate by ID
        $existingIds = array_column($dockets, 'id');
        foreach($uncheckedDockets as $docket) {
            if(!in_array($docket['id'], $existingIds)) {
                $dockets[] = $docket;
                $existingIds[] = $docket['id'];
            }
        }
    }
}

$activeCompany=getActiveCompanyProfile($conn); $activeCompanyId=(int)($activeCompany['id']??0);
$companies=[]; $companyWhere="c.status='Active' AND g.status='Active'" . ($activeCompanyId ? " AND c.id=".$activeCompanyId : ''); 
$q=$conn->query("SELECT g.id,g.company_id,g.gstin,g.state_code,g.registration_address,g.is_default,COALESCE(NULLIF(c.trade_name,''),c.legal_name) company_name,c.address,c.phone,c.email FROM company_gst_registrations g JOIN companies c ON c.id=g.company_id WHERE $companyWhere ORDER BY g.is_default DESC,g.state_code"); 
if($q)$companies=$q->fetch_all(MYSQLI_ASSOC);
$parties=getClientMasters($conn);
foreach ($parties as &$client) { $client['party_name'] = $client['client_name']; }
unset($client);
$invoiceTerms = getInvoiceTerms($conn);
$defaultTerms = getDefaultInvoiceTerms($conn);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($invoice['submission_status'], ['Pending', 'Temp Hold'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token. Please refresh and try again.'; $messageType = 'danger';
    } elseif (($_POST['action'] ?? '') === 'update') {
        $messageType = 'success'; // Default to success
        $invoiceDate = trim($_POST['invoice_date'] ?? '');
        $gstRate = (float)($_POST['gst_rate'] ?? 18);
        $gstType = $_POST['gst_type'] ?? 'CGST_SGST';
        $remarks = trim($_POST['remarks'] ?? '');
        $tempHold = isset($_POST['temp_hold']) ? 1 : 0;
        $invoiceDate = trim($_POST['invoice_date'] ?? '');
        $gstRate = (float)($_POST['gst_rate'] ?? 18);
        $gstType = $_POST['gst_type'] ?? 'CGST_SGST';
        $remarks = trim($_POST['remarks'] ?? '');
        $tempHold = isset($_POST['temp_hold']) ? 1 : 0;
        
        // Handle docket changes - combine current and newly selected
        $selectedDockets = $_POST['docket_ids'] ?? [];
        
        // Remove duplicates from selected dockets
        $selectedDockets = array_unique($selectedDockets);
        $selectedDockets = array_values($selectedDockets); // Re-index array
        
        if ($invoiceDate === '') {
            $message = 'Invoice date is required.'; $messageType = 'danger';
        } elseif (empty($selectedDockets) && !$tempHold) {
            $message = 'At least one docket must be selected for the invoice, or mark as Temp Hold. You selected ' . count($selectedDockets) . ' dockets.'; $messageType = 'danger';
        } else {
            $conn->begin_transaction();
            try {
                // Remove existing invoice items
                $deleteStmt = $conn->prepare('DELETE FROM invoice_items WHERE invoice_id = ?');
                $deleteStmt->bind_param('i', $invoiceId);
                $deleteStmt->execute();
                $deletedCount = $deleteStmt->affected_rows;
                $deleteStmt->close();
                
                // Get selected dockets details (if any)
                $dockets = [];
                if (!empty($selectedDockets)) {
                    $placeholders = implode(',', array_fill(0, count($selectedDockets), '?'));
                    $types = str_repeat('i', count($selectedDockets));
                    $docketStmt = $conn->prepare("SELECT c.id, c.consignment_note, c.booking_date, c.basic_freight, c.billing_party_name 
                                                       FROM consignments c 
                                                       WHERE c.id IN ($placeholders)");
                    $docketStmt->bind_param($types, ...$selectedDockets);
                    $docketStmt->execute();
                    $dockets = $docketStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $docketStmt->close();
                    
                    // Validate dockets
                    foreach ($dockets as $docket) {
                        if ($docket['billing_party_name'] !== $invoice['billing_party_name']) {
                            throw new Exception('All dockets must belong to the same billing party.');
                        }
                        if ((float)$docket['basic_freight'] <= 0) {
                            throw new Exception('Docket ' . $docket['consignment_note'] . ' has zero freight.');
                        }
                    }
                    
                    // Add new invoice items with INSERT IGNORE to handle duplicates
                    $insertStmt = $conn->prepare('INSERT IGNORE INTO invoice_items (invoice_id, consignment_id, docket_no, booking_date, freight_amount) VALUES (?, ?, ?, ?, ?)');
                    foreach ($dockets as $docket) {
                        $insertStmt->bind_param('iissd', $invoiceId, $docket['id'], $docket['consignment_note'], $docket['booking_date'], $docket['basic_freight']);
                        $insertStmt->execute();
                    }
                    $insertStmt->close();
                }
                
                // Recalculate amounts
                $totalFreight = !empty($dockets) ? array_sum(array_column($dockets, 'basic_freight')) : 0;
                $taxableAmount = $totalFreight;
                $tax = $taxableAmount * $gstRate / 100;
                $cgst = $gstType === 'CGST_SGST' ? $tax / 2 : 0;
                $sgst = $gstType === 'CGST_SGST' ? $tax / 2 : 0;
                $igst = $gstType === 'IGST' ? $tax : 0;
                $grandTotal = $taxableAmount + $cgst + $sgst + $igst;
                
                // Update invoice with temp hold status
                $statusValue = $tempHold ? 'Temp Hold' : 'Pending';
                $updateStmt = $conn->prepare('UPDATE invoices SET invoice_date=?, gst_type=?, gst_rate=?, taxable_amount=?, cgst_amount=?, sgst_amount=?, igst_amount=?, grand_total=?, remarks=?, submission_status=? WHERE id=?');
                $updateStmt->bind_param('ssddddddssi', $invoiceDate, $gstType, $gstRate, $taxableAmount, $cgst, $sgst, $igst, $grandTotal, $remarks, $statusValue, $invoiceId);
                $updateStmt->execute();
                $updateStmt->close();
                
                $conn->commit();
                
                if ($tempHold) {
                    $msg = 'Invoice marked as Temp Hold with 0 dockets. You can add dockets later.';
                } else {
                    $msg = 'Invoice updated successfully with ' . count($dockets) . ' dockets.';
                }
                
                // Redirect to ensure fresh page load
                header('Location: edit_invoice.php?id=' . $invoiceId . '&msg=' . urlencode($msg));
                exit;
                
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'Could not update invoice: ' . $e->getMessage(); $messageType = 'danger';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars(appBrandTitle('Edit Invoice')) ?></title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .bill-wrap{max-width:1500px;margin:18px auto;padding:0 18px}
        .bill-hero{background:linear-gradient(120deg,#083b5c,#0f766e);border-radius:18px;padding:22px 26px;color:#fff;display:flex;justify-content:space-between;align-items:center;box-shadow:0 14px 30px #0f4c5c2e}
        .bill-hero h2{margin:0;font-size:1.45rem}
        .bill-hero p{margin:6px 0 0;color:#c9f5ee}
        .bill-kpi{display:flex;gap:11px}
        .bill-kpi div{background:#ffffff19;border:1px solid #ffffff35;padding:8px 13px;border-radius:10px;text-align:center}
        .bill-kpi b{font-size:1.1rem;display:block}
        .bill-card{margin-top:16px;background:#fff;border:1px solid #dbe5ea;border-radius:15px;box-shadow:0 4px 16px #12334a0a;padding:18px}
        .bill-top{display:grid;grid-template-columns:1.2fr 1.2fr 1fr 1fr;gap:14px}
        .bill-top label,.filter-grid label{font-size:.71rem;font-weight:800;color:#4b6475;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px;display:block}
        .gst-choice{display:flex;gap:7px}
        .gst-choice button{border:1px solid #cbdbe2;background:#f8fbfc;border-radius:8px;padding:7px 10px;font-weight:700;font-size:.78rem}
        .gst-choice button.active{background:#0f766e;color:#fff;border-color:#0f766e}
        .filter-grid{display:grid;grid-template-columns:1fr 1fr 1.25fr auto;gap:10px;align-items:end}
        .paste-box{background:#f0faf8;border:1px dashed #52a797;border-radius:11px;padding:11px;margin:15px 0;display:flex;gap:10px;align-items:center}
        .paste-box textarea{height:44px;resize:vertical;flex:1;font-size:.78rem}
        .billing-table{width:100%;border-collapse:separate;border-spacing:0 7px}
        .billing-table th{color:#64748b;font-size:.7rem;text-transform:uppercase;padding:0 9px 5px}
        .billing-table td{background:#fff;border-top:1px solid #e3eaee;border-bottom:1px solid #e3eaee;padding:10px 9px;font-size:.82rem}
        .billing-table td:first-child{border-left:1px solid #e3eaee;border-radius:9px 0 0 9px}
        .billing-table td:last-child{border-right:1px solid #e3eaee;border-radius:0 9px 9px 0}
        .freight{font-weight:800;color:#0f766e}
        .pod-pill{font-size:.68rem;border-radius:50px;padding:3px 7px;background:#e9f9ee;color:#16713b}
        .summary{position:sticky;bottom:10px;background:#073b5c;color:#fff;border-radius:14px;padding:13px 17px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 8px 24px #062b4499}
        .sum-items{display:flex;gap:24px}
        .sum-items small{display:block;color:#b6d6e5}
        .sum-items b{font-size:1.08rem}
        .row-edit{font-size:.73rem}
        .notice{display:none;margin:10px 0;padding:10px;border-radius:8px}
        .notice.ok{background:#e9f8ee;color:#166534}
        .notice.err{background:#fff0f0;color:#b42318}
        .docket-modal{display:none;position:fixed;z-index:1000;inset:0;background:#062b44b8;padding:3vh 3vw}
        .docket-modal.open{display:block}
        .modal-box{height:94vh;background:#fff;border-radius:15px;overflow:hidden;box-shadow:0 18px 55px #00111d}
        .modal-top{height:48px;padding:9px 13px;background:#073b5c;color:#fff;display:flex;justify-content:space-between;align-items:center}
        .modal-box iframe{width:100%;height:calc(100% - 48px);border:0}
        .current-dockets{background:#f0faf8;border:1px solid #52a797;border-radius:11px;padding:15px;margin:15px 0}
        .current-dockets-title{font-size:.8rem;font-weight:700;color:#0f766e;margin-bottom:10px}
        .paste-box{background:#f0faf8;border:1px dashed #52a797;border-radius:11px;padding:11px;margin:15px 0;display:flex;gap:10px;align-items:center}
        .paste-box textarea{height:44px;resize:vertical;flex:1;font-size:.78rem}
        @media(max-width:900px){
            .bill-top,.filter-grid{grid-template-columns:1fr 1fr}
            .bill-hero{display:block}
            .bill-kpi{margin-top:15px}
            .billing-table{display:block;overflow:auto}
            .summary{position:static;gap:12px}
            .sum-items{gap:10px;flex-wrap:wrap}
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
        <?php renderAppHeader('Edit Invoice', '✏️'); ?>
        
        <main class="app-body">
            <div class="bill-wrap">
                <?php if($message): ?>
                    <div class="notice <?= $messageType === 'success' ? 'ok' : 'err' ?>" style="display:block"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if(in_array($invoice['submission_status'], ['Pending', 'Temp Hold'])): ?>
                    <section class="bill-hero">
                        <div>
                            <h2>Edit Invoice: <?= htmlspecialchars($invoice['invoice_no']) ?></h2>
                            <p>Modify invoice details, search for additional dockets, add/remove dockets, then save changes.</p>
                        </div>
                        <div class="bill-kpi">
                            <div><b id="heroCount"><?= count($currentDocketIds) - count($uncheckedDocketIds) ?></b><small>Dockets</small></div>
                            <div><b id="heroAmount">₹<?= number_format($invoice['taxable_amount'], 2) ?></b><small>Taxable</small></div>
                        </div>
                    </section>

                    <!-- Search Filter -->
                    <form class="bill-card filter-grid" method="get">
                        <input type="hidden" name="id" value="<?= $invoiceId ?>">
                        <div><label>Booking from</label><input class="form-control" type="date" name="from" value="<?=htmlspecialchars($from)?>"></div>
                        <div><label>Booking to</label><input class="form-control" type="date" name="to" value="<?=htmlspecialchars($to)?>"></div>
                        <div><label>Client / billing party</label><select class="form-select" id="searchParty" name="party"><option value="">All Clients</option><?php foreach($parties as $p):?><option value="<?=htmlspecialchars($p['client_name'])?>" data-gst="<?=htmlspecialchars($p['gst_no']??'')?>" data-code="<?=htmlspecialchars($p['client_code'])?>" <?=($party === $p['client_name'] || $party === $p['client_code'] || (empty($party) && $p['client_name'] === $invoice['billing_party_name']))?'selected':''?>><?=htmlspecialchars($p['client_name'].' · '.$p['client_code'])?></option><?php endforeach;?></select></div>
                        <div><label>&nbsp;</label><label style="text-transform:none"><input type="checkbox" name="pod_only" value="1" <?=$podOnly?'checked':''?>> POD uploaded only</label><button class="btn btn-primary btn-sm" name="search" value="1" style="margin-left:8px">Search dockets</button></div>
                    </form>

                    <form method="post" class="bill-card" id="invoiceForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="update">
                        
                        <!-- Current Dockets Section -->
                        <div class="current-dockets">
                            <div class="current-dockets-title">
                                Current Dockets in Invoice (<?= count($currentDocketIds) - count($uncheckedDocketIds) ?>)
                                <small style="font-weight:400;color:#64748b">These dockets are already in your invoice. Uncheck to move them to available section.</small>
                            </div>
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                                <label><input type="checkbox" id="uncheckAllCurrent"> Uncheck all (remove all)</label>
                            </div>
                            <div style="max-height:200px;overflow-y:auto">
                                <?php 
                                $checkedDocketIds = array_diff($currentDocketIds, $uncheckedDocketIds);
                                if(!empty($checkedDocketIds)): ?>
                                    <?php 
                                    $placeholders = implode(',', array_fill(0, count($checkedDocketIds), '?'));
                                    $types = str_repeat('i', count($checkedDocketIds));
                                    $currentStmt = $conn->prepare("SELECT c.id,c.consignment_note,c.booking_date,c.no_of_pieces,c.charged_weight,c.basic_freight,EXISTS(SELECT 1 FROM pod_files p WHERE p.consignment_id=c.id) pod_ready FROM consignments c WHERE c.id IN ($placeholders)");
                                    $currentStmt->bind_param($types, ...$checkedDocketIds);
                                    $currentStmt->execute();
                                    $currentDockets = $currentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                    $currentStmt->close();
                                    ?>
                                    <table class="billing-table">
                                        <thead><tr><th></th><th>Docket / Booking</th><th>No. of Pieces</th><th>Charged Wt</th><th>POD</th><th>Freight</th></tr></thead>
                                        <tbody>
                                            <?php foreach($currentDockets as $d): ?>
                                            <tr data-note="<?=htmlspecialchars(strtoupper($d['consignment_note']))?>" data-amount="<?=$d['basic_freight']?>">
                                                <td><input class="docket-check current-docket" type="checkbox" name="docket_ids[]" value="<?=$d['id']?>" data-note="<?=htmlspecialchars($d['consignment_note'])?>" checked></td>
                                                <td><b><?=htmlspecialchars($d['consignment_note'])?></b><br><small><?=htmlspecialchars($d['booking_date'])?></small></td>
                                                <td><?=number_format($d['no_of_pieces']??0)?></td>
                                                <td><?=number_format($d['charged_weight']??0,2)?> kg</td>
                                                <td><?=($d['pod_ready'] ?? false)?'<span class="pod-pill">POD ready</span>':'<small>Not uploaded</small>'?></td>
                                                <td class="freight">₹<?=number_format($d['basic_freight'],2)?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div style="text-align:center;padding:20px;color:#9ca3af">No dockets in invoice</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="bill-top">
                            <div>
                                <label>Invoice Number</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($invoice['invoice_no']) ?>" disabled>
                            </div>
                            <div>
                                <label>Invoice Date *</label>
                                <input type="date" class="form-control" name="invoice_date" value="<?= htmlspecialchars($invoice['invoice_date']) ?>" required>
                            </div>
                            <div>
                                <label>Billing Party</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($invoice['billing_party_name']) ?>" disabled>
                            </div>
                            <div>
                                <label>Billing Party GST</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($invoice['billing_party_gstin']) ?>" disabled>
                            </div>
                            <div>
                                <label>GST treatment</label>
                                <div class="gst-choice">
                                    <button type="button" class="<?= $invoice['gst_type'] === 'CGST_SGST' ? 'active' : '' ?>" data-tax="CGST_SGST">CGST + SGST</button>
                                    <button type="button" class="<?= $invoice['gst_type'] === 'IGST' ? 'active' : '' ?>" data-tax="IGST">IGST</button>
                                </div>
                                <input type="hidden" name="gst_type" id="gstType" value="<?= htmlspecialchars($invoice['gst_type']) ?>">
                            </div>
                            <div>
                                <label>GST rate %</label>
                                <input type="number" name="gst_rate" class="form-control" min="0" max="100" step="0.01" value="<?= $invoice['gst_rate'] ?>">
                            </div>
                            <div>
                                <label>Remarks</label>
                                <input type="text" name="remarks" class="form-control" value="<?= htmlspecialchars($invoice['remarks'] ?? '') ?>">
                            </div>
                            <div>
                                <label>Temp Hold</label>
                                <div style="padding:8px 0">
                                    <input type="checkbox" name="temp_hold" id="tempHold" <?= $invoice['submission_status'] === 'Temp Hold' ? 'checked' : '' ?>>
                                    <label for="tempHold" style="font-size:.75rem;color:#64748b;margin-left:5px">0 docket invoice</label>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3" style="border-top:1px solid #e5e7eb;padding-top:15px">
                            <div class="paste-box"><span style="font-size:1.3rem">📋</span><div><b style="font-size:.82rem">Paste docket numbers from Excel</b><br><small>Paste comma, space, or new-line separated docket numbers — only matching dockets will be selected.</small></div><textarea id="pasteDockets" class="form-control" placeholder="DKT-1001&#10;DKT-1002"></textarea><button class="btn btn-outline-primary btn-sm" type="button" id="applyPaste">Select pasted</button></div><div id="notice" class="notice"></div>
                            
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <b style="font-size:.8rem">Available Dockets to Add</b>
                                <label><input type="checkbox" id="selectAll"> Select all shown</label>
                            </div>
                            
                            <?php if($hasSearch && $dockets): ?>
                                <table class="billing-table">
                                    <thead><tr><th></th><th>Docket / Booking</th><th>No. of Pieces</th><th>Charged Wt</th><th>POD</th><th>Freight</th><th>Action</th></tr></thead>
                                    <tbody>
                                        <?php foreach($dockets as $d): ?>
                                        <tr data-note="<?=htmlspecialchars(strtoupper($d['consignment_note']))?>" data-amount="<?=$d['basic_freight']?>" <?= in_array($d['id'], $uncheckedDocketIds) ? 'style="background:#fef3c7"' : '' ?>>
                                            <td><input class="docket-check" type="checkbox" name="docket_ids[]" value="<?=$d['id']?>" data-note="<?=htmlspecialchars($d['consignment_note'])?>" <?= in_array($d['id'], $uncheckedDocketIds) ? 'checked' : '' ?>></td>
                                            <td>
                                                <b><?=htmlspecialchars($d['consignment_note'])?></b>
                                                <?php if(in_array($d['id'], $uncheckedDocketIds)): ?>
                                                    <span style="background:#f59e0b;color:#fff;padding:2px 6px;border-radius:4px;font-size:.65rem;margin-left:5px">Removed</span>
                                                <?php endif; ?>
                                                <br><small><?=htmlspecialchars($d['booking_date'])?></small>
                                            </td>
                                            <td><?=number_format($d['no_of_pieces']??0)?></td>
                                            <td><?=number_format($d['charged_weight']??0,2)?> kg</td>
                                            <td><?=($d['pod_ready'] ?? false)?'<span class="pod-pill">POD ready</span>':'<small>Not uploaded</small>'?></td>
                                            <td class="freight">₹<?=number_format($d['basic_freight'],2)?></td>
                                            <td><a class="btn btn-outline-primary btn-sm row-edit" href="index.php?id=<?=$d['id']?>" target="_blank">Edit docket</a></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div style="text-align:center;padding:26px;color:#9ca3af">
                                    Use the search filters above to find dockets to add to this invoice.
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Summary -->
                        <div class="summary">
                            <div class="sum-items">
                                <div><small>Selected</small><b id="selectedCount"><?= count($currentDocketIds) - count($uncheckedDocketIds) ?> dockets</b></div>
                                <div><small>Taxable freight</small><b id="taxable">₹<?= number_format($invoice['taxable_amount'], 2) ?></b></div>
                                <div><small id="taxLabel"><?= $invoice['gst_type'] === 'IGST' ? 'IGST' : 'CGST + SGST' ?></small><b id="taxTotal">₹<?= number_format($invoice['cgst_amount'] + $invoice['sgst_amount'] + $invoice['igst_amount'], 2) ?></b></div>
                                <div><small>Invoice total</small><b id="grandTotal">₹<?= number_format($invoice['grand_total'], 2) ?></b></div>
                            </div>
                            <button type="submit" class="btn btn-light">Update Invoice →</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="bill-card" style="text-align:center;padding:40px">
                        <h3 style="color:#dc2626">Cannot Edit Invoice</h3>
                        <p style="color:#64748b">This invoice is <?= strtolower($invoice['submission_status']) ?> and cannot be edited.</p>
                        <a href="invoice_management.php" class="btn btn-primary">Back to Invoice List</a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<script>
const csrf=<?=json_encode($csrf)?>, money=n=>'₹'+Number(n).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); 
let taxType='<?= $invoice['gst_type'] ?>';
const checks=[...document.querySelectorAll('.docket-check')], party=document.querySelector('#searchParty');
const gstTypeInput = document.getElementById('gstType');
const gstButtons = document.querySelectorAll('.gst-choice button');
const tempHoldCheckbox = document.getElementById('tempHold');
const gstRateInput = document.querySelector('input[name="gst_rate"]');
const selectAll = document.getElementById('selectAll');
const updateForm = document.getElementById('updateForm');
const uncheckedDocketsInput = document.getElementById('uncheckedDockets');

// Track unchecked current dockets - reload to show in available section
const currentDocketChecks = [...document.querySelectorAll('.docket-check.current-docket')];
const uncheckedCurrentDockets = new Set();
const uncheckAllCurrent = document.getElementById('uncheckAllCurrent');

// Restore unchecked dockets from URL parameter
const urlParams = new URLSearchParams(window.location.search);
const uncheckedParam = urlParams.get('unchecked');
if(uncheckedParam && uncheckedParam.trim() !== '') {
    uncheckedParam.split(',').forEach(id => {
        if(id.trim() !== '') uncheckedCurrentDockets.add(id.trim());
    });
}

// Auto-select billing party in dropdown if unchecked dockets exist
if(uncheckedCurrentDockets.size > 0) {
    const partySelect = document.querySelector('select[name="party"]');
    const billingParty = '<?= addslashes($invoice['billing_party_name']) ?>';
    if(partySelect) {
        for(let i = 0; i < partySelect.options.length; i++) {
            if(partySelect.options[i].text.includes(billingParty)) {
                partySelect.selectedIndex = i;
                break;
            }
        }
    }
}

// Show notice function (global scope)
function show(type, msg) {
    const notice = document.getElementById('notice');
    if(notice) {
        notice.className = 'notice ' + type;
        notice.textContent = msg;
        notice.style.display = 'block';
    }
}

// Auto-select dockets that were found from paste search
const pastedDocketsParam = urlParams.get('pasted_dockets');
if(pastedDocketsParam && pastedDocketsParam.trim() !== '') {
    const pastedDockets = pastedDocketsParam.split(',').map(d => d.trim().toUpperCase());
    let selectedCount = 0;
    
    // Only target available dockets (not current dockets)
    let availableChecks = checks.filter(x => !x.classList.contains('current-docket'));
    
    availableChecks.forEach(x => {
        let docketNote = x.dataset.note ? x.dataset.note.toUpperCase().trim() : '';
        pastedDockets.forEach(pd => {
            if(docketNote === pd || docketNote.includes(pd) || pd.includes(docketNote)) {
                x.checked = true;
                selectedCount++;
            }
        });
    });
    
    if(selectedCount > 0) {
        show('ok', selectedCount + ' docket(s) found and selected from your paste.');
        calc();
    }
    
    // Clear the parameter from URL
    const url = new URL(window.location);
    url.searchParams.delete('pasted_dockets');
    window.history.replaceState({}, '', url.toString());
}

// Handle "Uncheck All" for current dockets
if(uncheckAllCurrent) {
    uncheckAllCurrent.addEventListener('change', function() {
        if(this.checked) {
            currentDocketChecks.forEach(cb => {
                cb.checked = false;
                uncheckedCurrentDockets.add(cb.value);
            });
        } else {
            currentDocketChecks.forEach(cb => {
                cb.checked = true;
                uncheckedCurrentDockets.delete(cb.value);
            });
        }
        // Reload page to show unchecked dockets in available section
        const url = new URL(window.location);
        if(uncheckedCurrentDockets.size > 0) {
            url.searchParams.set('unchecked', Array.from(uncheckedCurrentDockets).join(','));
        } else {
            url.searchParams.delete('unchecked');
        }
        url.searchParams.set('search', '1');
        // Set billing party in URL
        const billingParty = '<?= addslashes($invoice['billing_party_name']) ?>';
        url.searchParams.set('party', billingParty);
        window.location.href = url.toString();
    });
}

// Handle current docket checkbox changes - reload to show in available section
currentDocketChecks.forEach(cb => {
    cb.addEventListener('change', function() {
        if (!this.checked) {
            uncheckedCurrentDockets.add(this.value);
        } else {
            uncheckedCurrentDockets.delete(this.value);
        }
        // Reload page to show unchecked dockets in available section
        const url = new URL(window.location);
        if(uncheckedCurrentDockets.size > 0) {
            url.searchParams.set('unchecked', Array.from(uncheckedCurrentDockets).join(','));
        } else {
            url.searchParams.delete('unchecked');
        }
        url.searchParams.set('search', '1');
        // Set billing party in URL
        const billingParty = '<?= addslashes($invoice['billing_party_name']) ?>';
        url.searchParams.set('party', billingParty);
        window.location.href = url.toString();
    });
});

// GST type button handling
gstButtons.forEach(btn => {
    btn.addEventListener('click', function() {
        gstButtons.forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        gstTypeInput.value = this.dataset.tax;
        taxType = this.dataset.tax;
        calc();
    });
});

// Paste docket numbers functionality
const applyPaste = document.getElementById('applyPaste');
const pasteDockets = document.getElementById('pasteDockets');

if(applyPaste && pasteDockets) {
    applyPaste.onclick = () => {
        let wanted = pasteDockets.value.trim();
        
        if(!wanted) {
            show('err', 'Please paste docket numbers first.');
            return;
        }
        
        // Split and clean the pasted docket numbers
        let docketNumbers = wanted.split(/[\s,;]+/).filter(Boolean).join(',');
        
        // Get current URL and add pasted_dockets parameter
        const url = new URL(window.location);
        url.searchParams.set('pasted_dockets', docketNumbers);
        url.searchParams.set('search', '1');
        
        // Preserve current filters
        const party = document.querySelector('select[name="party"]')?.value;
        const from = document.querySelector('input[name="from"]')?.value;
        const to = document.querySelector('input[name="to"]')?.value;
        const podOnly = document.querySelector('input[name="pod_only"]')?.checked;
        
        if(party) url.searchParams.set('party', party);
        if(from) url.searchParams.set('from', from);
        if(to) url.searchParams.set('to', to);
        if(podOnly) url.searchParams.set('pod_only', '1');
        
        // Redirect to trigger search
        window.location.href = url.toString();
    };
}

// Temp Hold handling
if(tempHoldCheckbox) {
    tempHoldCheckbox.addEventListener('change', function() {
        if(this.checked) {
            checks.forEach(x => x.checked = false);
        }
        calc();
    });
}

// Select all
if(selectAll) {
    selectAll.onchange = e => {
        checks.forEach(x => x.checked = e.target.checked);
        calc();
    };
}

function calc(){
    let a=checks.filter(x=>x.checked).reduce((s,x)=>s+Number(x.closest('tr').dataset.amount),0), r=Number(gstRateInput.value||0), t=a*r/100; 
    const checkedCount = checks.filter(x=>x.checked).length;
    document.getElementById('selectedCount').textContent=checkedCount+' dockets';
    document.getElementById('heroCount').textContent=checkedCount;
    document.getElementById('taxable').textContent=document.getElementById('heroAmount').textContent=money(a); 
    document.getElementById('taxTotal').textContent=money(t); 
    document.getElementById('grandTotal').textContent=money(a+t);
    document.getElementById('taxLabel').textContent=taxType==='IGST'?'IGST @ '+r+'%':'CGST + SGST @ '+r+'%';
}

checks.forEach(x=>x.onchange=calc); 
gstRateInput.oninput=calc;
calc();
</script>
</body>
</html>
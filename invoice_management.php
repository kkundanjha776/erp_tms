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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token. Please refresh and try again.'; $messageType = 'danger';
    } elseif (($_POST['action'] ?? '') === 'submit_invoice') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $submissionDate = trim($_POST['submission_date'] ?? date('Y-m-d'));
        
        // Handle proof upload
        $proofPath = '';
        if (isset($_FILES['submission_proof']) && $_FILES['submission_proof']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/invoice_proofs/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $fileInfo = pathinfo($_FILES['submission_proof']['name']);
            $allowedTypes = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
            if (in_array(strtolower($fileInfo['extension']), $allowedTypes)) {
                $newFileName = 'invoice_proof_' . $invoiceId . '_' . time() . '.' . $fileInfo['extension'];
                $uploadPath = $uploadDir . $newFileName;
                if (move_uploaded_file($_FILES['submission_proof']['tmp_name'], $uploadPath)) {
                    $proofPath = 'uploads/invoice_proofs/' . $newFileName;
                }
            }
        }
        
        $stmt = $conn->prepare('UPDATE invoices SET submission_status=?, submission_date=?, submission_proof_path=? WHERE id=?');
        $stmt->bind_param('sssi', 'Submitted', $submissionDate, $proofPath, $invoiceId);
        if ($stmt->execute()) {
            $message = 'Invoice submitted successfully.';
        } else {
            $message = 'Could not submit invoice.'; $messageType = 'danger';
        }
        $stmt->close();
    } elseif (($_POST['action'] ?? '') === 'cancel_invoice') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $reason = trim($_POST['cancellation_reason'] ?? '');
        
        if ($reason === '') {
            $message = 'Cancellation reason is required.'; $messageType = 'danger';
        } else {
            $stmt = $conn->prepare('UPDATE invoices SET submission_status=?, cancellation_reason=?, cancelled_by=?, cancelled_at=NOW() WHERE id=?');
            $userId = (int)$_SESSION['user_id'];
            $stmt->bind_param('ssii', 'Cancelled', $reason, $userId, $invoiceId);
            if ($stmt->execute()) {
                $message = 'Invoice cancelled successfully.';
            } else {
                $message = 'Could not cancel invoice.'; $messageType = 'danger';
            }
            $stmt->close();
        }
    } elseif (($_POST['action'] ?? '') === 'delete_invoice') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        
        // Check if invoice is submitted
        $stmt = $conn->prepare('SELECT submission_status FROM invoices WHERE id=?');
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $invoice = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($invoice && in_array($invoice['submission_status'], ['Submitted', 'Temp Hold'])) {
            $message = 'Cannot delete submitted or temp hold invoices. Cancel or add dockets first.'; $messageType = 'danger';
        } else {
            $stmt = $conn->prepare('DELETE FROM invoices WHERE id=?');
            $stmt->bind_param('i', $invoiceId);
            if ($stmt->execute()) {
                $message = 'Invoice deleted successfully.';
            } else {
                $message = 'Could not delete invoice.'; $messageType = 'danger';
            }
            $stmt->close();
        }
    }
}

// Get filter parameters
$statusFilter = trim($_GET['status'] ?? '');
$partyFilter = trim($_GET['party'] ?? '');
$fromDate = trim($_GET['from'] ?? date('Y-m-01'));
$toDate = trim($_GET['to'] ?? date('Y-m-d'));

// Build query
$where = ["1=1"];
$params = [];
$types = '';

if ($statusFilter !== '') {
    $where[] = "submission_status = ?";
    $types .= 's';
    $params[] = $statusFilter;
}

if ($partyFilter !== '') {
    $where[] = "billing_party_name LIKE ?";
    $types .= 's';
    $params[] = '%' . $partyFilter . '%';
}

if ($fromDate !== '') {
    $where[] = "invoice_date >= ?";
    $types .= 's';
    $params[] = $fromDate;
}

if ($toDate !== '') {
    $where[] = "invoice_date <= ?";
    $types .= 's';
    $params[] = $toDate;
}

$whereClause = implode(' AND ', $where);

// Get invoices
$sql = "SELECT i.*, c.legal_name, c.trade_name, 
        COUNT(ii.id) as docket_count,
        SUM(ii.freight_amount) as total_freight
        FROM invoices i
        LEFT JOIN companies c ON i.company_id = c.id
        LEFT JOIN invoice_items ii ON i.id = ii.invoice_id
        WHERE $whereClause
        GROUP BY i.id
        ORDER BY i.invoice_date DESC, i.id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get clients for filter
$clients = getClientMasters($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(appBrandTitle('Invoice Management')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .invoice-wrap { padding: 12px; max-width: 1400px; }
        .status-pending { background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; }
        .status-submitted { background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; }
        .status-cancelled { background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; }
        .invoice-table { width: 100%; border-collapse: separate; border-spacing: 0 8px; }
        .invoice-table th { color: #64748b; font-size: 0.7rem; text-transform: uppercase; padding: 0 9px 5px; }
        .invoice-table td { background: #fff; border: 1px solid #e5e7eb; padding: 10px 9px; font-size: 0.82rem; }
        .invoice-table td:first-child { border-left: 1px solid #e5e7eb; border-radius: 9px 0 0 9px; }
        .invoice-table td:last-child { border-right: 1px solid #e5e7eb; border-radius: 0 9px 9px 0; }
        .amount-col { font-weight: 700; color: #0f766e; }
        .proof-link { color: #059669; text-decoration: none; font-size: 0.75rem; }
        .proof-link:hover { text-decoration: underline; }
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
        <?php renderAppHeader('Invoice Management', '📋'); ?>
        
        <main class="app-body">
            <div class="invoice-wrap">
                <?php if($message): ?>
                    <div class="alert alert-<?= htmlspecialchars($messageType) ?> py-2" style="font-size: 0.78rem">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <!-- Filter Section -->
                <div class="card mb-3">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-2">
                                <label style="font-size: 0.71rem; font-weight: 700; color: #4b6475; text-transform: uppercase;">Status</label>
                                <select class="form-select" name="status">
                                    <option value="">All Status</option>
                                    <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="Temp Hold" <?= $statusFilter === 'Temp Hold' ? 'selected' : '' ?>>Temp Hold</option>
                                    <option value="Submitted" <?= $statusFilter === 'Submitted' ? 'selected' : '' ?>>Submitted</option>
                                    <option value="Cancelled" <?= $statusFilter === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label style="font-size: 0.71rem; font-weight: 700; color: #4b6475; text-transform: uppercase;">Client</label>
                                <select class="form-select" name="party">
                                    <option value="">All Clients</option>
                                    <?php foreach($clients as $client): ?>
                                        <option value="<?= htmlspecialchars($client['client_name']) ?>" <?= $partyFilter === $client['client_name'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($client['client_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label style="font-size: 0.71rem; font-weight: 700; color: #4b6475; text-transform: uppercase;">From Date</label>
                                <input type="date" class="form-control" name="from" value="<?= htmlspecialchars($fromDate) ?>">
                            </div>
                            <div class="col-md-2">
                                <label style="font-size: 0.71rem; font-weight: 700; color: #4b6475; text-transform: uppercase;">To Date</label>
                                <input type="date" class="form-control" name="to" value="<?= htmlspecialchars($toDate) ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                                <a href="invoice_management.php" class="btn btn-outline-secondary btn-sm ms-2">Clear</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <small style="font-size: 0.7rem; color: #64748b; text-transform: uppercase;">Total Invoices</small>
                                <div style="font-size: 1.5rem; font-weight: 700; color: #0f766e;"><?= count($invoices) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <small style="font-size: 0.7rem; color: #64748b; text-transform: uppercase;">Pending</small>
                                <div style="font-size: 1.5rem; font-weight: 700; color: #92400e;"><?= count(array_filter($invoices, fn($i) => $i['submission_status'] === 'Pending')) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <small style="font-size: 0.7rem; color: #64748b; text-transform: uppercase;">Temp Hold</small>
                                <div style="font-size: 1.5rem; font-weight: 700; color: #d97706;"><?= count(array_filter($invoices, fn($i) => $i['submission_status'] === 'Temp Hold')) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <small style="font-size: 0.7rem; color: #64748b; text-transform: uppercase;">Submitted</small>
                                <div style="font-size: 1.5rem; font-weight: 700; color: #166534;"><?= count(array_filter($invoices, fn($i) => $i['submission_status'] === 'Submitted')) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <small style="font-size: 0.7rem; color: #64748b; text-transform: uppercase;">Total Amount</small>
                                <div style="font-size: 1.5rem; font-weight: 700; color: #0f766e;">₹<?= number_format(array_sum(array_column($invoices, 'grand_total')), 2) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Invoices Table -->
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 style="font-size: 1rem; margin: 0;">Invoices List</h5>
                            <div class="btn-group">
                                <a href="billing.php" class="btn btn-primary btn-sm">+ Create New Invoice</a>
                                <button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">📥 Import Excel</button>
                            </div>
                        </div>
                        
                        <?php if($invoices): ?>
                            <table class="invoice-table">
                                <thead>
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Date</th>
                                        <th>Client</th>
                                        <th>Dockets</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Submission</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($invoices as $invoice): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($invoice['invoice_no']) ?></strong></td>
                                            <td><?= date('d-M-Y', strtotime($invoice['invoice_date'])) ?></td>
                                            <td><?= htmlspecialchars($invoice['billing_party_name']) ?></td>
                                            <td><?= $invoice['docket_count'] ?></td>
                                            <td class="amount-col">₹<?= number_format($invoice['grand_total'], 2) ?></td>
                                            <td>
                                                <?php if($invoice['submission_status'] === 'Pending'): ?>
                                                    <span class="status-pending">Pending</span>
                                                <?php elseif($invoice['submission_status'] === 'Temp Hold'): ?>
                                                    <span style="background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 600;">Temp Hold</span>
                                                <?php elseif($invoice['submission_status'] === 'Submitted'): ?>
                                                    <span class="status-submitted">Submitted</span>
                                                <?php else: ?>
                                                    <span class="status-cancelled">Cancelled</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if($invoice['submission_status'] === 'Submitted'): ?>
                                                    <?= $invoice['submission_date'] ? date('d-M-Y', strtotime($invoice['submission_date'])) : 'N/A' ?>
                                                    <?php if($invoice['submission_proof_path']): ?>
                                                        <br><a href="<?= htmlspecialchars($invoice['submission_proof_path']) ?>" target="_blank" class="proof-link">📎 View Proof</a>
                                                    <?php endif; ?>
                                                <?php elseif($invoice['submission_status'] === 'Temp Hold'): ?>
                                                    <span style="color: #d97706; font-size: 0.75rem;">0 Dockets - Temp Hold</span>
                                                <?php else: ?>
                                                    <span style="color: #9ca3af; font-size: 0.75rem;">Not submitted</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="invoice_print.php?invoice_id=<?= $invoice['id'] ?>" target="_blank" class="btn btn-outline-primary" title="Print">🖨️</a>
                                                    <a href="api/export_invoice_excel.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-outline-success" title="Excel">📊</a>
                                                    <?php if(in_array($invoice['submission_status'], ['Pending', 'Temp Hold'])): ?>
                                                        <a href="edit_invoice.php?id=<?= $invoice['id'] ?>" class="btn btn-outline-secondary" title="Edit">✏️</a>
                                                        <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#submitModal<?= $invoice['id'] ?>" title="Submit">✓</button>
                                                        <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#cancelModal<?= $invoice['id'] ?>" title="Cancel">✕</button>
                                                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?= $invoice['id'] ?>" title="Delete">🗑️</button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        
                                        <!-- Submit Modal -->
                                        <div class="modal fade" id="submitModal<?= $invoice['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Submit Invoice</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="post" enctype="multipart/form-data">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                            <input type="hidden" name="action" value="submit_invoice">
                                                            <input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>">
                                                            <div class="mb-3">
                                                                <label>Submission Date</label>
                                                                <input type="date" class="form-control" name="submission_date" value="<?= date('Y-m-d') ?>" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label>Submission Proof (Optional)</label>
                                                                <input type="file" class="form-control" name="submission_proof" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                                                                <small style="font-size: 0.7rem; color: #64748b;">Upload proof of submission (PDF, Image, or Document)</small>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-success">Submit Invoice</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Cancel Modal -->
                                        <div class="modal fade" id="cancelModal<?= $invoice['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Cancel Invoice</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="post">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                            <input type="hidden" name="action" value="cancel_invoice">
                                                            <input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>">
                                                            <div class="mb-3">
                                                                <label>Cancellation Reason *</label>
                                                                <textarea class="form-control" name="cancellation_reason" rows="3" required placeholder="Enter reason for cancellation..."></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-warning">Cancel Invoice</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Delete Modal -->
                                        <div class="modal fade" id="deleteModal<?= $invoice['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Delete Invoice</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="post">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                            <input type="hidden" name="action" value="delete_invoice">
                                                            <input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>">
                                                            <p>Are you sure you want to delete invoice <strong><?= htmlspecialchars($invoice['invoice_no']) ?></strong>?</p>
                                                            <p style="color: #dc2626; font-size: 0.85rem;">This action cannot be undone.</p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-danger">Delete Invoice</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="text-center text-muted py-4">
                                No invoices found. <a href="billing.php">Create your first invoice</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Import Invoices from Excel/CSV</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" enctype="multipart/form-data" id="importForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <div class="mb-3">
                        <label>Select CSV File *</label>
                        <input type="file" class="form-control" name="excel_file" accept=".csv" required>
                        <small style="font-size: 0.7rem; color: #64748b;">
                            CSV format: invoice_no,invoice_date,client_name,gst_type,gst_rate,amount<br>
                            Example: INV-001,2024-01-15,ABC Company,CGST_SGST,18,5000
                        </small>
                    </div>
                    <div class="mb-3">
                        <a href="#" onclick="downloadTemplate(); return false;" style="font-size: 0.75rem; color: #0f766e;">📥 Download CSV Template</a>
                    </div>
                    <div id="importResult" style="display: none;" class="alert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Import Invoices</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function downloadTemplate() {
    const csvContent = "invoice_no,invoice_date,client_name,gst_type,gst_rate,amount\nINV-001,2024-01-15,ABC Company,CGST_SGST,18,5000\nINV-002,2024-01-16,XYZ Company,IGST,18,7500";
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    const url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    link.setAttribute("download", "invoice_import_template.csv");
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

document.getElementById('importForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const resultDiv = document.getElementById('importResult');
    
    try {
        const response = await fetch('api/import_invoice_excel.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        resultDiv.style.display = 'block';
        if (data.success) {
            resultDiv.className = 'alert alert-success';
            resultDiv.innerHTML = data.message + (data.data.errors.length > 0 ? '<br><small>Errors: ' + data.data.errors.join(', ') + '</small>' : '');
            setTimeout(() => location.reload(), 2000);
        } else {
            resultDiv.className = 'alert alert-danger';
            resultDiv.innerHTML = data.message;
        }
    } catch (error) {
        resultDiv.style.display = 'block';
        resultDiv.className = 'alert alert-danger';
        resultDiv.innerHTML = 'Import failed: ' + error.message;
    }
});
</script>
</body>
</html>
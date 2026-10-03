<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';

startSecureSession();
requireLogin();
if (!isAdminUser()) { header('Location: dashboard.php'); exit; }

$conn = getDBConnection();
$csrf = generateCSRFToken();
$message = '';
$messageType = 'success';

// Create terms table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS invoice_terms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    term_name VARCHAR(100) NOT NULL,
    term_content TEXT NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token. Please refresh and try again.'; $messageType = 'danger';
    } elseif (($_POST['action'] ?? '') === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $termName = trim($_POST['term_name'] ?? '');
        $termContent = trim($_POST['term_content'] ?? '');
        $isDefault = isset($_POST['is_default']) ? 1 : 0;
        $status = in_array($_POST['status'] ?? '', ['Active','Inactive'], true) ? $_POST['status'] : 'Active';
        
        if ($termName === '' || $termContent === '') {
            $message = 'Term name and content are required.'; $messageType = 'danger';
        } else {
            $conn->begin_transaction();
            try {
                if ($isDefault) {
                    $conn->query("UPDATE invoice_terms SET is_default = 0 WHERE is_default = 1");
                }
                
                if ($id > 0) {
                    $stmt = $conn->prepare('UPDATE invoice_terms SET term_name=?, term_content=?, is_default=?, status=? WHERE id=?');
                    $stmt->bind_param('ssisi', $termName, $termContent, $isDefault, $status, $id);
                } else {
                    $stmt = $conn->prepare('INSERT INTO invoice_terms (term_name, term_content, is_default, status, created_by) VALUES (?,?,?,?,?)');
                    $uid = (int)$_SESSION['user_id'];
                    $stmt->bind_param('ssisi', $termName, $termContent, $isDefault, $status, $uid);
                }
                
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $stmt->close();
                $conn->commit();
                $message = 'Terms and conditions saved successfully.';
                
                if ($id <= 0) {
                    $id = $conn->insert_id;
                }
            } catch (Throwable $e) {
                $conn->rollback();
                $message = 'Could not save terms: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare('DELETE FROM invoice_terms WHERE id=?');
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                $message = 'Terms deleted successfully.';
            } else {
                $message = 'Could not delete terms.'; $messageType = 'danger';
            }
            $stmt->close();
        }
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editingTerm = null;
if ($editId > 0) {
    $stmt = $conn->prepare('SELECT * FROM invoice_terms WHERE id=?');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editingTerm = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$terms = $conn->query('SELECT * FROM invoice_terms ORDER BY is_default DESC, term_name')->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(appBrandTitle('Invoice Terms Master')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .terms-wrap { padding: 12px; max-width: 1200px; }
        .terms-card { border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 12px; }
        .terms-header { background: #f8fafc; padding: 10px 15px; border-bottom: 1px solid #e5e7eb; font-weight: 600; }
        .terms-body { padding: 15px; }
        .terms-content { white-space: pre-wrap; font-size: 0.85rem; color: #475569; }
        .default-badge { background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; }
        .active-badge { background: #dbeafe; color: #1d4ed8; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; }
        .inactive-badge { background: #f1f5f9; color: #64748b; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 600; }
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
        <?php renderAppHeader('Invoice Terms & Conditions', '📋'); ?>
        
        <main class="app-body">
            <div class="terms-wrap">
                <?php if($message): ?>
                    <div class="alert alert-<?= htmlspecialchars($messageType) ?> py-2" style="font-size: 0.78rem">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <b>Invoice Terms & Conditions Master</b>
                        <div class="text-muted small">Manage standard terms and conditions for invoices. Mark one as default for auto-selection.</div>
                    </div>
                    <a class="btn btn-outline-primary btn-sm" href="invoice_terms_master.php">+ Add New Terms</a>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <form method="post" class="card">
                            <div class="card-body">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="id" value="<?= (int)($editingTerm['id'] ?? 0) ?>">
                                
                                <div class="form-group mb-2">
                                    <label>Term Name *</label>
                                    <input class="form-control" required name="term_name" maxlength="100" 
                                           value="<?= htmlspecialchars($editingTerm['term_name'] ?? '') ?>" 
                                           placeholder="e.g., Standard Terms">
                                </div>
                                
                                <div class="form-group mb-2">
                                    <label>Terms & Conditions Content *</label>
                                    <textarea class="form-control" name="term_content" rows="8" required
                                              placeholder="Enter your terms and conditions..."><?= htmlspecialchars($editingTerm['term_content'] ?? '') ?></textarea>
                                </div>
                                
                                <div class="form-group mb-2">
                                    <label>
                                        <input type="checkbox" name="is_default" <?= (!empty($editingTerm['is_default'])) ? 'checked' : '' ?>>
                                        Set as Default
                                    </label>
                                </div>
                                
                                <div class="form-group mb-2">
                                    <label>Status</label>
                                    <select class="form-select" name="status">
                                        <option value="Active" <?= ($editingTerm['status'] ?? 'Active') === 'Active' ? 'selected' : '' ?>>Active</option>
                                        <option value="Inactive" <?= ($editingTerm['status'] ?? '') === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                                
                                <button type="submit" class="btn btn-primary btn-sm">Save Terms</button>
                                <?php if($editingTerm): ?>
                                    <a href="invoice_terms_master.php" class="btn btn-outline-secondary btn-sm">Cancel</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                    
                    <div class="col-md-8">
                        <?php if($terms): ?>
                            <?php foreach($terms as $term): ?>
                                <div class="terms-card">
                                    <div class="terms-header d-flex justify-content-between align-items-center">
                                        <div>
                                            <?= htmlspecialchars($term['term_name']) ?>
                                            <?php if($term['is_default']): ?>
                                                <span class="default-badge">Default</span>
                                            <?php endif; ?>
                                            <span class="<?= $term['status'] === 'Active' ? 'active-badge' : 'inactive-badge' ?>">
                                                <?= $term['status'] ?>
                                            </span>
                                        </div>
                                        <div class="btn-group btn-group-sm">
                                            <a href="invoice_terms_master.php?edit=<?= $term['id'] ?>" class="btn btn-outline-primary">Edit</a>
                                            <form method="post" style="display:inline">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $term['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to delete these terms?')">Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="terms-body">
                                        <div class="terms-content"><?= htmlspecialchars($term['term_content']) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-4">
                                No terms and conditions defined yet. Add your first terms using the form.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
</body>
</html>
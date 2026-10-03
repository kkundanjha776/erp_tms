<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/master_data.php';

startSecureSession();
requireLogin();

if (!isAdminUser()) {
    header('Location: dashboard.php');
    exit;
}

$conn = getDBConnection();
$csrf = generateCSRFToken();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid CSRF token. Please refresh and try again.';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $branchCode = trim($_POST['branch_code'] ?? '');
            $branchName = trim($_POST['branch_name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $cityId = (int)($_POST['city_id'] ?? 0);
            $stateCode = trim($_POST['state_code'] ?? '');
            $pincode = trim($_POST['pincode'] ?? '');
            $contactPerson = trim($_POST['contact_person'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $status = in_array(($_POST['status'] ?? ''), ['Active', 'Inactive'], true) ? $_POST['status'] : 'Active';

            $errors = [];
            if ($branchCode === '') $errors[] = 'Branch Code is required.';
            if ($branchName === '') $errors[] = 'Branch Name is required.';

            if (empty($errors)) {
                if ($id > 0) {
                    $stmt = $conn->prepare('UPDATE branches SET branch_code=?, branch_name=?, address=?, city_id=?, state_code=?, pincode=?, contact_person=?, phone=?, email=?, status=? WHERE id=?');
                    $cityIdParam = $cityId > 0 ? $cityId : null;
                    $stmt->bind_param('sssissssssi', $branchCode, $branchName, $address, $cityIdParam, $stateCode, $pincode, $contactPerson, $phone, $email, $status, $id);
                    if ($stmt->execute()) {
                        $message = 'Branch updated successfully.';
                        $messageType = 'success';
                    } else {
                        $message = 'Failed to update branch: ' . $conn->error;
                        $messageType = 'danger';
                    }
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare('INSERT INTO branches (branch_code, branch_name, address, city_id, state_code, pincode, contact_person, phone, email, status) VALUES (?,?,?,?,?,?,?,?,?,?)');
                    $cityIdParam = $cityId > 0 ? $cityId : null;
                    $stmt->bind_param('sssissssss', $branchCode, $branchName, $address, $cityIdParam, $stateCode, $pincode, $contactPerson, $phone, $email, $status);
                    if ($stmt->execute()) {
                        $message = 'Branch created successfully.';
                        $messageType = 'success';
                    } else {
                        $message = 'Failed to create branch: ' . $conn->error;
                        $messageType = 'danger';
                    }
                    $stmt->close();
                }
            } else {
                $message = implode(' ', $errors);
                $messageType = 'danger';
            }
        } elseif ($action === 'toggleStatus') {
            $id = (int)($_POST['id'] ?? 0);
            $newStatus = in_array(($_POST['status'] ?? ''), ['Active', 'Inactive'], true) ? $_POST['status'] : 'Active';

            if ($id > 0) {
                $stmt = $conn->prepare('UPDATE branches SET status=? WHERE id=?');
                $stmt->bind_param('si', $newStatus, $id);
                if ($stmt->execute()) {
                    $message = 'Branch status updated to ' . $newStatus . '.';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to update status: ' . $conn->error;
                    $messageType = 'danger';
                }
                $stmt->close();
            } else {
                $message = 'Invalid branch ID.';
                $messageType = 'danger';
            }
        }
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editBranch = null;
if ($editId > 0) {
    $stmt = $conn->prepare('SELECT * FROM branches WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editBranch = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$branches = $conn->query("SELECT b.*, c.city_name, s.state_name FROM branches b LEFT JOIN cities c ON c.id = b.city_id LEFT JOIN states s ON s.state_code = b.state_code ORDER BY b.branch_code");
$branchesRows = $branches ? $branches->fetch_all(MYSQLI_ASSOC) : [];

$cities = getAllCities($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(appBrandTitle('Branch Management')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=branch1">
<style>
.bm-wrap{padding:6px;display:flex;flex-direction:column;gap:6px;height:100%;overflow:auto}
.bm-wrap .card{border:1px solid var(--border);border-radius:6px;background:#fff;overflow:hidden;display:flex;flex-direction:column}
.bm-wrap .card-header{background:#f8fafc;padding:6px 10px;border-bottom:1px solid #e5e7eb;font-size:.74rem;font-weight:700;color:var(--primary);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px}
.bm-wrap .card-body{padding:8px 10px;overflow-y:auto;flex:1;min-height:0}
.results-table-wrap{max-height:320px;border:1px solid #e5e7eb;border-radius:4px;overflow-y:auto}
.btn-xs{font-size:.62rem!important;padding:2px 8px!important;height:22px!important;line-height:14px!important;border-radius:3px!important;text-decoration:none!important;display:inline-block!important;font-weight:600!important}
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
<?php renderAppHeader('Branch Management', '🏢'); ?>

<div class="app-body">
  <div class="bm-wrap">

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?> py-2 px-3 mb-0" style="font-size:.72rem"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <div><?= $editBranch ? '✏️ Edit Branch' : '➕ Add New Branch' ?></div>
        <?php if ($editBranch): ?>
            <a href="branch_management.php" class="btn btn-outline-secondary btn-xs">↩ Cancel Edit</a>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <form method="POST" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= $editBranch ? (int)$editBranch['id'] : 0 ?>">
          <div class="form-row">
            <div class="form-group">
              <label>Branch Code <span class="req">*</span></label>
              <input required name="branch_code" maxlength="32" class="form-control" value="<?= htmlspecialchars($editBranch['branch_code'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Branch Name <span class="req">*</span></label>
              <input required name="branch_name" maxlength="150" class="form-control" value="<?= htmlspecialchars($editBranch['branch_name'] ?? '') ?>">
            </div>
          </div>
          <div class="form-row full">
            <div class="form-group">
              <label>Address</label>
              <input name="address" maxlength="255" class="form-control" value="<?= htmlspecialchars($editBranch['address'] ?? '') ?>">
            </div>
          </div>
          <div class="form-row compact-inline">
            <div class="form-group">
              <label>City</label>
              <select name="city_id" class="form-select">
                <option value="">-- Select City --</option>
                <?php foreach ($cities as $city):
                    $cityId = (int)($editBranch['city_id'] ?? 0);
                    $selected = $cityId === (int)$city['id'] ? ' selected' : '';
                    $display = trim($city['city_name'] . ' - ' . ($city['district'] ?? '') . ' (' . ($city['state_code'] ?? '') . ')');
                ?>
                <option value="<?= (int)$city['id'] ?>"<?= $selected ?>><?= htmlspecialchars($display) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>State Code</label>
              <input name="state_code" maxlength="5" class="form-control" value="<?= htmlspecialchars($editBranch['state_code'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>PIN Code</label>
              <input name="pincode" maxlength="10" class="form-control" value="<?= htmlspecialchars($editBranch['pincode'] ?? '') ?>">
            </div>
          </div>
          <div class="form-row compact-inline">
            <div class="form-group">
              <label>Contact Person</label>
              <input name="contact_person" maxlength="100" class="form-control" value="<?= htmlspecialchars($editBranch['contact_person'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Phone</label>
              <input name="phone" maxlength="30" class="form-control" value="<?= htmlspecialchars($editBranch['phone'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Email</label>
              <input type="email" name="email" maxlength="150" class="form-control" value="<?= htmlspecialchars($editBranch['email'] ?? '') ?>">
            </div>
          </div>
          <div class="form-row compact-inline">
            <div class="form-group">
              <label>Status</label>
              <select name="status" class="form-select">
                <?php
                $curStatus = $editBranch['status'] ?? 'Active';
                foreach (['Active', 'Inactive'] as $opt):
                    $sel = $curStatus === $opt ? ' selected' : '';
                ?>
                <option<?= $sel ?>><?= $opt ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"></div>
            <div class="form-group" style="justify-content:flex-end;display:flex;align-items:flex-end">
              <button type="submit" class="btn btn-primary btn-sm">💾 <?= $editBranch ? 'Update Branch' : 'Create Branch' ?></button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div>🏢 Branches List</div>
        <span class="result-count-badge" style="margin:0"><?= count($branchesRows) ?> branch<?= count($branchesRows) === 1 ? '' : 'es' ?></span>
      </div>
      <div class="card-body" style="padding:4px">
        <div class="results-table-wrap">
          <table class="results-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Code</th>
                <th>Name</th>
                <th>City / State</th>
                <th>PIN</th>
                <th>Contact</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Status</th>
                <th class="actions">Actions</th>
              </tr>
            </thead>
            <tbody>
<?php if (empty($branchesRows)): ?>
              <tr class="empty-state"><td colspan="10" class="text-muted text-center py-4" style="font-size:.7rem;padding:24px">No branches found. Add your first branch using the form above.</td></tr>
<?php else: ?>
<?php foreach ($branchesRows as $b): ?>
              <tr id="branch-row-<?= (int)$b['id'] ?>">
                <td class="mono strong"><?= (int)$b['id'] ?></td>
                <td class="mono"><?= htmlspecialchars($b['branch_code']) ?></td>
                <td><?= htmlspecialchars($b['branch_name']) ?></td>
                <td><?= htmlspecialchars(trim(($b['city_name'] ?? '') . ' / ' . ($b['state_name'] ?? ''), ' / ')) ?></td>
                <td><?= htmlspecialchars($b['pincode'] ?? '') ?></td>
                <td><?= htmlspecialchars($b['contact_person'] ?? '') ?></td>
                <td><?= htmlspecialchars($b['phone'] ?? '') ?></td>
                <td><?= htmlspecialchars($b['email'] ?? '') ?></td>
                <td>
                  <?php if (($b['status'] ?? '') === 'Active'): ?>
                    <span class="badge rounded-pill text-success-emphasis bg-success-subtle" style="font-size:.6rem">Active</span>
                  <?php else: ?>
                    <span class="badge rounded-pill text-secondary-emphasis bg-secondary-subtle" style="font-size:.6rem">Inactive</span>
                  <?php endif; ?>
                </td>
                <td class="actions">
                  <div class="d-flex gap-1 flex-wrap justify-content-end">
                    <a href="branch_management.php?edit=<?= (int)$b['id'] ?>" class="btn btn-xs btn-outline-primary">Edit</a>
                    <form method="POST" style="display:inline-block" onsubmit="return confirm('Set branch status to <?= ($b['status'] ?? '') === 'Active' ? 'Inactive' : 'Active' ?>?')">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="action" value="toggleStatus">
                      <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                      <input type="hidden" name="status" value="<?= ($b['status'] ?? '') === 'Active' ? 'Inactive' : 'Active' ?>">
                      <?php if (($b['status'] ?? '') === 'Active'): ?>
                        <button type="submit" class="btn btn-xs btn-outline-warning">Deactivate</button>
                      <?php else: ?>
                        <button type="submit" class="btn btn-xs btn-outline-success">Activate</button>
                      <?php endif; ?>
                    </form>
                  </div>
                </td>
              </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</div>
</div>
</div>
</body>
</html>

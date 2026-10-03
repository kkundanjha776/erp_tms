<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';

startSecureSession();
requireLogin();

if (!isAdminUser()) {
    requireModule('user_management');
}

$conn = getDBConnection();
$csrf = generateCSRFToken();

ensureBranchesSchema($conn);

$message = '';
$messageType = '';

$editId = (int)($_GET['edit'] ?? 0);
$editUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid CSRF token.';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $username = sanitizeForDB($conn, $_POST['username'] ?? '');
            $passwordRaw = trim($_POST['password'] ?? '');
            $fullName = sanitizeForDB($conn, $_POST['full_name'] ?? '');
            $email = sanitizeForDB($conn, $_POST['email'] ?? '');
            $phone = sanitizeForDB($conn, $_POST['phone'] ?? '');
            $role = sanitizeForDB($conn, $_POST['role'] ?? 'Operator');
            $branchId = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
            $status = sanitizeForDB($conn, $_POST['status'] ?? 'Active');
            $isBranchAdmin = !empty($_POST['is_branch_admin']) ? 1 : 0;
            $mods = $_POST['mods'] ?? [];
            if (!is_array($mods)) $mods = [];
            $modsClean = array_values(array_filter(array_map('trim', $mods)));
            $modulePermissions = implode(',', $modsClean);

            $allRoles = array_keys(getAllRoles());
            if (!in_array($role, $allRoles, true)) {
                $role = 'Operator';
            }

            if ($role === 'Admin') {
                $modulePermissions = '';
            }

            ensureBranchesSchema($conn);

            if ($id > 0) {
                if ($passwordRaw !== '') {
                    $passwordHash = password_hash($passwordRaw, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare('UPDATE users SET username=?, password=?, full_name=?, email=?, phone=?, role=?, branch_id=?, module_permissions=?, status=?, is_branch_admin=? WHERE id=?');
                    $stmt->bind_param('ssssssissii', $username, $passwordHash, $fullName, $email, $phone, $role, $branchId, $modulePermissions, $status, $isBranchAdmin, $id);
                } else {
                    $stmt = $conn->prepare('UPDATE users SET username=?, full_name=?, email=?, phone=?, role=?, branch_id=?, module_permissions=?, status=?, is_branch_admin=? WHERE id=?');
                    $stmt->bind_param('sssssissii', $username, $fullName, $email, $phone, $role, $branchId, $modulePermissions, $status, $isBranchAdmin, $id);
                }
            } else {
                if ($passwordRaw === '') {
                    $message = 'Password is required for new users.';
                    $messageType = 'danger';
                } else {
                    $passwordHash = password_hash($passwordRaw, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare('INSERT INTO users (username, password, full_name, email, phone, role, branch_id, module_permissions, status, is_branch_admin) VALUES (?,?,?,?,?,?,?,?,?,?)');
                    $stmt->bind_param('ssssssissi', $username, $passwordHash, $fullName, $email, $phone, $role, $branchId, $modulePermissions, $status, $isBranchAdmin);
                }
            }

            if (isset($stmt)) {
                if ($stmt->execute()) {
                    $savedId = (int)($id > 0 ? $id : $conn->insert_id);
                    $verify = $conn->prepare('SELECT role, status FROM users WHERE id=? LIMIT 1');
                    $roleOK = true;
                    if ($verify) {
                        $verify->bind_param('i', $savedId);
                        $verify->execute();
                        $vr = $verify->get_result()->fetch_assoc();
                        $verify->close();
                        $roleOK = $vr && is_array($vr) && $vr['role'] === $role;
                    }
                    $message = ($id > 0) ? 'User updated successfully.' : 'User created successfully.';
                    $messageType = 'success';
                    if (!$roleOK) {
                        $message .= ' ⚠️ Role could not be saved (column mismatch). Refresh and retry, or run migration.';
                        $messageType = 'warning';
                    }
                    if (session_status() === PHP_SESSION_ACTIVE && (int)($_SESSION['user_id'] ?? 0) === $savedId) {
                        try {
                            loadUserSessionContext($conn, $savedId);
                        } catch (Throwable $e) { /* ignore */ }
                    }
                    $editId = 0;
                    $editUser = null;
                } else {
                    $message = 'Save failed: ' . $conn->error;
                    $messageType = 'danger';
                }
                $stmt->close();
            }

        } elseif ($action === 'toggleStatus') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $conn->prepare('SELECT status FROM users WHERE id=? LIMIT 1');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $res = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($res) {
                    $newStatus = ($res['status'] === 'Active') ? 'Inactive' : 'Active';
                    $upd = $conn->prepare('UPDATE users SET status=? WHERE id=?');
                    $upd->bind_param('si', $newStatus, $id);
                    if ($upd->execute()) {
                        $message = 'User status set to ' . $newStatus . '.';
                        $messageType = 'success';
                    } else {
                        $message = 'Status update failed: ' . $conn->error;
                        $messageType = 'danger';
                    }
                    $upd->close();
                }
            }

        } elseif ($action === 'resetPwd') {
            $id = (int)($_POST['id'] ?? 0);
            $passwordRaw = trim($_POST['new_password'] ?? '');
            if ($id > 0 && $passwordRaw !== '') {
                $passwordHash = password_hash($passwordRaw, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE users SET password=? WHERE id=?');
                $stmt->bind_param('si', $passwordHash, $id);
                if ($stmt->execute()) {
                    $message = 'Password reset successfully.';
                    $messageType = 'success';
                } else {
                    $message = 'Password reset failed: ' . $conn->error;
                    $messageType = 'danger';
                }
                $stmt->close();
            } else {
                $message = 'New password cannot be empty.';
                $messageType = 'danger';
            }
        }
    }
}

if ($editId > 0) {
    $stmt = $conn->prepare('SELECT id, username, full_name, email, phone, role, branch_id, module_permissions, status, is_branch_admin FROM users WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$branches = [];
$br = $conn->query('SELECT id, branch_code, branch_name FROM branches ORDER BY branch_code');
if ($br) {
    while ($row = $br->fetch_assoc()) $branches[] = $row;
    $br->free();
}

$usersSql = 'SELECT u.id, u.username, u.full_name, u.email, u.phone, u.role, u.status, u.is_branch_admin,
                    b.branch_code, b.branch_name
             FROM users u
             LEFT JOIN branches b ON b.id = u.branch_id
             ORDER BY u.username ASC';
$usersRes = $conn->query($usersSql);
$usersList = [];
if ($usersRes) {
    while ($row = $usersRes->fetch_assoc()) $usersList[] = $row;
    $usersRes->free();
}

$allRoles = getAllRoles();
$allModules = getAllModules();

function userModuleList(?string $permissions, array $allModules, string $role): string {
    if ($role === 'Admin') return '<span class="badge rounded-pill bg-primary-subtle text-primary-emphasis" style="font-size:0.58rem">All</span>';
    $raw = trim((string)$permissions);
    if ($raw === '') return '<span class="text-muted" style="font-size:0.58rem">—</span>';
    $keys = array_values(array_filter(array_map('trim', explode(',', $raw))));
    $labels = [];
    foreach ($keys as $k) {
        if (isset($allModules[$k])) $labels[] = $allModules[$k]['icon'] . $allModules[$k]['label'];
    }
    if (empty($labels)) return '<span class="text-muted" style="font-size:0.58rem">—</span>';
    $out = '';
    foreach ($labels as $l) $out .= '<span class="badge rounded-pill bg-light border text-dark me-1 mb-1" style="font-size:0.56rem">' . htmlspecialchars($l) . '</span>';
    return $out;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(appBrandTitle('User Management')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=usermgmt1">
<style>
.um-layout{display:flex;flex-direction:column;gap:6px;height:100%;overflow:hidden;}
.um-card{border:1px solid var(--border);border-radius:6px;background:#fff;overflow:hidden;display:flex;flex-direction:column;flex-shrink:0;}
.um-card-header{background:#f8fafc;padding:5px 10px;border-bottom:1px solid #e5e7eb;font-size:0.72rem;font-weight:700;color:var(--primary);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;}
.um-card-body{padding:8px 10px;overflow-y:auto;flex:1;min-height:0;}
.um-table-wrap{flex:1;min-height:0;overflow:auto;border:1px solid #e5e7eb;border-radius:4px;}
.um-table-wrap .results-table tbody tr{cursor:default;}
.modules-checkbox-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:4px 10px;padding:6px;border:1px solid #e5e7eb;border-radius:4px;background:#fbfcfd;}
.modules-checkbox-grid label{display:flex;align-items:center;gap:5px;margin:0;font-size:0.68rem;font-weight:500;color:#374151;cursor:pointer;}
.modules-checkbox-grid input{margin:0;}
.modules-checkbox-grid .mod-icon{font-size:0.82rem;}
.reset-pwd-modal{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;display:none;align-items:center;justify-content:center;padding:20px;}
.reset-pwd-modal.show{display:flex;}
.reset-pwd-dialog{background:#fff;border-radius:8px;padding:18px;max-width:380px;width:100%;box-shadow:0 12px 32px rgba(0,0,0,.2);}
.reset-pwd-dialog h5{font-size:.82rem;font-weight:700;margin:0 0 10px;color:var(--primary);}
.reset-pwd-dialog .form-label{font-size:.64rem;font-weight:600;color:#475569;margin-bottom:2px;}
.reset-pwd-dialog .btn-row{display:flex;gap:6px;justify-content:flex-end;margin-top:10px;}
.role-admin-note{font-size:.62rem;color:#0369a1;background:#e0f2fe;border:1px solid #bae6fd;border-radius:4px;padding:4px 8px;margin-top:4px;}
.search-bar-wrap{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.badge-role{font-size:.58rem;padding:1px 8px;font-weight:600;}
.badge-role.Admin{background:#fef3c7;color:#92400e;}
.badge-role.Branch-Admin{background:#ede9fe;color:#6d28d9;}
.badge-role.Manager{background:#dbeafe;color:#1d4ed8;}
.badge-role.Operator{background:#e0f2fe;color:#0369a1;}
.pill-active{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.pill-inactive{background:#e5e7eb;color:#475569;border:1px solid #d1d5db;}
.status-pill-wrap{display:inline-flex;align-items:center;gap:4px;padding:1px 10px;border-radius:999px;font-size:.6rem;font-weight:700;}
.status-dot{width:6px;height:6px;border-radius:50%;display:inline-block;}
.pill-active .status-dot{background:#16a34a;}
.pill-inactive .status-dot{background:#9ca3af;}
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
<?php renderAppHeader('User Management', '🔐'); ?>

<div class="app-body">
  <div class="um-layout">

    <?php if ($message !== ''): ?>
    <div class="alert alert-<?= $messageType ?> py-2 px-3 m-0" style="font-size:0.72rem;flex-shrink:0;border-radius:4px"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="um-card">
      <div class="um-card-header">
        <span><?= $editId > 0 ? '✏️ Edit User #' . $editId : '➕ Add New User' ?></span>
        <?php if ($editId > 0): ?><a href="user_management.php" class="btn btn-outline-secondary btn-xs">✕ Cancel Edit</a><?php endif; ?>
      </div>
      <div class="um-card-body">
        <form method="post" id="userForm" class="needs-validation" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" id="user_id" value="<?= $editId > 0 ? $editId : 0 ?>">

          <div class="form-row">
            <div class="form-group">
              <label>Username <span class="req">*</span></label>
              <input required maxlength="50" type="text" class="form-control" name="username" id="username"
                     value="<?= htmlspecialchars($editUser['username'] ?? '') ?>" autocomplete="off">
            </div>
            <div class="form-group">
              <label>Password <?= $editId > 0 ? '<span class="text-muted small">(leave empty to keep current)</span>' : '<span class="req">*</span>' ?></label>
              <input type="password" class="form-control" name="password" id="password" autocomplete="new-password"
                     placeholder="<?= $editId > 0 ? '••••••••' : 'Set password' ?>">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Full Name</label>
              <input type="text" maxlength="150" class="form-control" name="full_name" id="full_name"
                     value="<?= htmlspecialchars($editUser['full_name'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Email</label>
              <input type="email" maxlength="150" class="form-control" name="email" id="email"
                     value="<?= htmlspecialchars($editUser['email'] ?? '') ?>">
            </div>
          </div>

          <div class="form-row compact-inline">
            <div class="form-group">
              <label>Phone</label>
              <input type="text" maxlength="30" class="form-control" name="phone" id="phone"
                     value="<?= htmlspecialchars($editUser['phone'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Role <span class="req">*</span></label>
              <select class="form-select" name="role" id="role">
                <?php foreach ($allRoles as $rVal => $rLabel): ?>
                <option value="<?= htmlspecialchars($rVal) ?>"
                        <?= (isset($editUser['role']) && $editUser['role'] === $rVal) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($rLabel) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Branch</label>
              <select class="form-select" name="branch_id" id="branch_id">
                <option value="">-- No Branch --</option>
                <?php foreach ($branches as $b): ?>
                <option value="<?= (int)$b['id'] ?>"
                        <?= (isset($editUser['branch_id']) && (int)$editUser['branch_id'] === (int)$b['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($b['branch_code']) ?> &mdash; <?= htmlspecialchars($b['branch_name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-row compact-inline">
            <div class="form-group">
              <label>Status</label>
              <select class="form-select" name="status" id="status">
                <option value="Active" <?= (isset($editUser['status']) && $editUser['status'] === 'Active') ? 'selected' : '' ?>>Active</option>
                <option value="Inactive" <?= (isset($editUser['status']) && $editUser['status'] === 'Inactive') ? 'selected' : '' ?>>Inactive</option>
              </select>
            </div>
            <div class="form-group" style="justify-content:center;padding-top:18px;">
              <div class="form-check" style="padding-left:1.4rem;">
                <input class="form-check-input" type="checkbox" name="is_branch_admin" id="is_branch_admin" value="1"
                       <?= (isset($editUser['is_branch_admin']) && $editUser['is_branch_admin'] == 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_branch_admin" style="font-size:.68rem;font-weight:600;">Is Branch Admin</label>
              </div>
            </div>
            <div class="form-group"></div>
          </div>

          <div id="modulesWrapper">
            <?php
            $editRole = $editUser['role'] ?? 'Operator';
            if ($editRole !== 'Admin'):
                $selMods = [];
                if (!empty($editUser['module_permissions'])) {
                    $selMods = array_values(array_filter(array_map('trim', explode(',', $editUser['module_permissions']))));
                }
            ?>
            <div class="form-group" style="margin-top:4px;">
              <label style="margin-bottom:3px;">Module Permissions</label>
              <div class="modules-checkbox-grid" id="modulesGrid">
                <?php foreach ($allModules as $mKey => $mInfo):
                    if (!empty($mInfo['admin_only'])) continue;
                    $checked = in_array($mKey, $selMods, true) ? 'checked' : '';
                ?>
                <label>
                  <input type="checkbox" name="mods[]" value="<?= htmlspecialchars($mKey) ?>" <?= $checked ?>>
                  <span class="mod-icon"><?= $mInfo['icon'] ?></span>
                  <span><?= htmlspecialchars($mInfo['label']) ?></span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
            <?php else: ?>
            <div class="role-admin-note">ℹ️ Admin role automatically gets all module permissions. Individual module toggles are not shown.</div>
            <?php endif; ?>
          </div>

          <div class="d-flex align-items-center gap-2 mt-2">
            <button type="submit" class="btn btn-primary btn-sm"><?= $editId > 0 ? '💾 Update User' : '💾 Create User' ?></button>
            <?php if ($editId > 0): ?><a href="user_management.php" class="btn btn-outline-secondary btn-sm">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <div class="um-card" style="flex:1;min-height:0;">
      <div class="um-card-header">
        <div class="search-bar-wrap">
          <span>👥 Users List</span>
          <input type="text" id="userSearchInput" class="form-control" placeholder="🔍 Search username / name / email / role / branch..." style="height:24px;font-size:0.68rem;width:360px;max-width:50vw">
          <button type="button" class="btn btn-outline-secondary btn-xs" id="clearSearch">Clear</button>
          <span class="result-count-badge" id="userCountBadge" style="margin:0;"><?= count($usersList) ?> user<?= count($usersList) === 1 ? '' : 's' ?></span>
        </div>
      </div>
      <div class="um-card-body" style="padding:4px;">
        <div class="um-table-wrap" id="usersTableWrap">
          <table class="results-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Username</th>
                <th>Full Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Role</th>
                <th>Branch</th>
                <th>Modules</th>
                <th>Status</th>
                <th class="actions">Actions</th>
              </tr>
            </thead>
            <tbody id="usersTbody">
<?php if (!$usersList): ?>
              <tr class="empty-state"><td colspan="10" class="text-center py-4 text-muted" style="font-size:0.7rem;padding:20px">No users found.</td></tr>
<?php else: $i = 1; foreach ($usersList as $u):
    $searchHay = $u['username'] . ' ' . ($u['full_name'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['phone'] ?? '') . ' ' . $u['role'] . ' ' . ($u['branch_code'] ?? '') . ' ' . ($u['branch_name'] ?? '');
    $branchDisplay = !empty($u['branch_code']) ? htmlspecialchars($u['branch_code']) . ' &mdash; ' . htmlspecialchars($u['branch_name']) : '<span class="text-muted">—</span>';
    $roleCssClass = str_replace(' ', '-', $u['role']);
?>
              <tr class="user-row" id="user-row-<?= (int)$u['id'] ?>"
                  data-status="<?= htmlspecialchars($u['status']) ?>"
                  data-search="<?= htmlspecialchars(strtolower($searchHay), ENT_QUOTES) ?>">
                <td style="font-size:0.62rem;color:#94a3b8;"><?= $i++ ?></td>
                <td class="strong mono"><?= htmlspecialchars($u['username']) ?></td>
                <td><?= htmlspecialchars($u['full_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                <td><?= htmlspecialchars($u['phone'] ?? '') ?></td>
                <td><span class="badge badge-role rounded-pill <?= $roleCssClass ?>"><?= htmlspecialchars($u['role']) ?><?= !empty($u['is_branch_admin']) ? ' 🏢' : '' ?></span></td>
                <td><?= $branchDisplay ?></td>
                <td style="max-width:260px;"><?= userModuleList($u['module_permissions'] ?? '', $allModules, $u['role']) ?></td>
                <td>
                  <?php if ($u['status'] === 'Active'): ?>
                    <span class="status-pill-wrap pill-active"><span class="status-dot"></span>Active</span>
                  <?php else: ?>
                    <span class="status-pill-wrap pill-inactive"><span class="status-dot"></span>Inactive</span>
                  <?php endif; ?>
                </td>
                <td class="actions">
                  <div class="d-flex gap-1 flex-wrap justify-content-end">
                    <a class="btn btn-xs btn-outline-primary" href="user_management.php?edit=<?= (int)$u['id'] ?>">Edit</a>
                    <button type="button" class="btn btn-xs btn-outline-info reset-pwd-btn" data-id="<?= (int)$u['id'] ?>" data-username="<?= htmlspecialchars($u['username']) ?>">Reset Pwd</button>
                    <?php if ($u['status'] === 'Active'): ?>
                      <button type="button" class="btn btn-xs btn-outline-warning toggle-status-btn" data-id="<?= (int)$u['id'] ?>" data-target="Inactive">Deactivate</button>
                    <?php else: ?>
                      <button type="button" class="btn btn-xs btn-outline-success toggle-status-btn" data-id="<?= (int)$u['id'] ?>" data-target="Active">Activate</button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
<?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</div>
</div>
</div>

<div class="reset-pwd-modal" id="resetPwdModal">
  <div class="reset-pwd-dialog">
    <h5>🔑 Reset Password</h5>
    <form method="post" id="resetPwdForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="resetPwd">
      <input type="hidden" name="id" id="rp_id">
      <div style="font-size:.68rem;color:#475569;margin-bottom:8px;">
        Set a new password for user: <b id="rp_username" class="text-primary"></b>
      </div>
      <div class="mb-2">
        <label class="form-label">New Password <span class="req">*</span></label>
        <input type="password" class="form-control" name="new_password" id="rp_password" required autocomplete="new-password">
      </div>
      <div class="mb-1">
        <label class="form-label">Confirm Password <span class="req">*</span></label>
        <input type="password" class="form-control" id="rp_password2" required autocomplete="new-password">
        <div class="invalid-feedback" id="rp_pwd_match" style="display:none;font-size:.6rem;">Passwords do not match.</div>
      </div>
      <div class="btn-row">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="rp_cancel">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">💾 Reset Password</button>
      </div>
    </form>
  </div>
</div>

<form method="post" id="toggleStatusForm" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="action" value="toggleStatus">
  <input type="hidden" name="id" id="ts_id">
</form>

<script>
function escapeHtml(s){if(s==null)return '';return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

const userSearchInput = document.getElementById('userSearchInput');
const clearSearchBtn = document.getElementById('clearSearch');
const usersTbody = document.getElementById('usersTbody');
const userCountBadge = document.getElementById('userCountBadge');

function filterUsers() {
  const q = (userSearchInput.value || '').trim().toLowerCase();
  let visible = 0;
  usersTbody.querySelectorAll('tr.user-row').forEach(tr => {
    const hay = tr.getAttribute('data-search') || '';
    const match = !q || hay.indexOf(q) !== -1;
    tr.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  userCountBadge.textContent = visible + ' user' + (visible === 1 ? '' : 's');
  const empty = usersTbody.querySelector('tr.empty-state');
  if (empty) empty.remove();
  if (visible === 0 && usersTbody.querySelectorAll('tr.user-row').length > 0) {
    const tr = document.createElement('tr');
    tr.className = 'empty-state';
    tr.innerHTML = '<td colspan="10" class="text-center py-3 text-muted" style="font-size:0.7rem;padding:18px">No users match your search.</td>';
    usersTbody.appendChild(tr);
  }
}
if (userSearchInput) userSearchInput.addEventListener('input', filterUsers);
if (clearSearchBtn) clearSearchBtn.addEventListener('click', () => {
  userSearchInput.value = '';
  filterUsers();
  userSearchInput.focus();
});

const toggleForm = document.getElementById('toggleStatusForm');
document.querySelectorAll('.toggle-status-btn').forEach(btn => {
  btn.onclick = () => {
    const id = btn.getAttribute('data-id');
    const target = btn.getAttribute('data-target');
    if (!confirm('Set this user status to ' + target + '?')) return;
    document.getElementById('ts_id').value = id;
    toggleForm.submit();
  };
});

const modal = document.getElementById('resetPwdModal');
const rpForm = document.getElementById('resetPwdForm');
const rpCancel = document.getElementById('rp_cancel');
const rpPwd = document.getElementById('rp_password');
const rpPwd2 = document.getElementById('rp_password2');
const rpMatch = document.getElementById('rp_pwd_match');
document.querySelectorAll('.reset-pwd-btn').forEach(btn => {
  btn.onclick = () => {
    document.getElementById('rp_id').value = btn.getAttribute('data-id');
    document.getElementById('rp_username').textContent = btn.getAttribute('data-username') || '';
    rpPwd.value = '';
    rpPwd2.value = '';
    rpMatch.style.display = 'none';
    modal.classList.add('show');
    setTimeout(() => rpPwd.focus(), 30);
  };
});
rpCancel.onclick = () => modal.classList.remove('show');
modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('show'); });

function validatePwdMatch() {
  const a = rpPwd.value; const b = rpPwd2.value;
  if (a && b && a !== b) { rpMatch.style.display = 'block'; return false; }
  rpMatch.style.display = 'none'; return true;
}
rpPwd.addEventListener('input', validatePwdMatch);
rpPwd2.addEventListener('input', validatePwdMatch);
rpForm.addEventListener('submit', e => {
  if (!validatePwdMatch()) { e.preventDefault(); return; }
  if (!rpPwd.value || rpPwd.value.length < 1) { e.preventDefault(); alert('Please enter a password.'); return; }
});

const roleSel = document.getElementById('role');
const modulesWrapper = document.getElementById('modulesWrapper');
const allModules = <?= json_encode($allModules) ?>;

function rebuildModulesForRole(role, selectedMods) {
  if (role === 'Admin') {
    modulesWrapper.innerHTML = '<div class="role-admin-note">ℹ️ Admin role automatically gets all module permissions. Individual module toggles are not shown.</div>';
    return;
  }
  let html = '<div class="form-group" style="margin-top:4px;"><label style="margin-bottom:3px;">Module Permissions</label><div class="modules-checkbox-grid" id="modulesGrid">';
  Object.keys(allModules).forEach(k => {
    const m = allModules[k];
    if (m.admin_only) return;
    const checked = (selectedMods || []).indexOf(k) !== -1 ? 'checked' : '';
    html += '<label><input type="checkbox" name="mods[]" value="' + escapeHtml(k) + '" ' + checked + '><span class="mod-icon">' + m.icon + '</span><span>' + escapeHtml(m.label) + '</span></label>';
  });
  html += '</div></div>';
  modulesWrapper.innerHTML = html;
}

<?php
$currentSel = '[]';
if ($editUser && !empty($editUser['module_permissions'])) {
    $currentSel = json_encode(array_values(array_filter(array_map('trim', explode(',', $editUser['module_permissions'])))));
}
?>
const _editSel = <?= $currentSel ?>;
roleSel.addEventListener('change', () => {
  const existing = [];
  modulesWrapper.querySelectorAll('input[name="mods[]"]:checked').forEach(cb => existing.push(cb.value));
  const combined = _editSel.concat(existing).filter((v, i, a) => a.indexOf(v) === i);
  rebuildModulesForRole(roleSel.value, roleSel.value === (<?= json_encode($editRole ?? 'Operator') ?>) ? _editSel : combined);
});
rebuildModulesForRole(roleSel.value, _editSel);

const userForm = document.getElementById('userForm');
if (userForm) {
  userForm.addEventListener('submit', e => {
    const uname = document.getElementById('username').value.trim();
    const pwField = document.getElementById('password');
    const uid = parseInt(document.getElementById('user_id').value || '0', 10);
    if (!uname) { e.preventDefault(); alert('Username is required.'); return; }
    if (uid <= 0 && !pwField.value) { e.preventDefault(); alert('Password is required for new users.'); return; }
  });
}
</script>
</body>
</html>

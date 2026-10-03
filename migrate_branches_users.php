<?php
error_reporting(E_ALL); ini_set('display_errors', 1);
require_once __DIR__ . '/includes/db_connection.php';

$conn = getDBConnection();
$conn->query('SET FOREIGN_KEY_CHECKS=0');

$errors = [];
$messages = [];
$conn->query('CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_code VARCHAR(32) NOT NULL UNIQUE,
    branch_name VARCHAR(150) NOT NULL,
    address VARCHAR(255) NULL,
    city_id INT NULL,
    state_code VARCHAR(5) NULL,
    pincode VARCHAR(10) NULL,
    contact_person VARCHAR(100) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    status ENUM("Active","Inactive") NOT NULL DEFAULT "Active",
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
if ($conn->error) $errors[] = 'branches table: ' . $conn->error; else $messages[] = 'branches table OK';

function addColIfMissing($conn, $table, $col, $def) {
    $r = $conn->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'");
    $missing = !$r || $r->num_rows === 0;
    if ($r) $r->free();
    if ($missing) {
        $conn->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        if ($conn->error) return "ADD {$table}.{$col}: " . $conn->error;
        return "+ {$table}.{$col} added";
    }
    return "= {$table}.{$col} exists";
}
$messages[] = addColIfMissing($conn, 'users', 'full_name', "VARCHAR(150) NULL AFTER username");
$messages[] = addColIfMissing($conn, 'users', 'email', "VARCHAR(150) NULL AFTER full_name");
$messages[] = addColIfMissing($conn, 'users', 'phone', "VARCHAR(30) NULL AFTER email");
$messages[] = addColIfMissing($conn, 'users', 'branch_id', "INT NULL AFTER role");
$messages[] = addColIfMissing($conn, 'users', 'module_permissions', "TEXT NULL COMMENT 'CSV allowed module keys' AFTER branch_id");
$messages[] = addColIfMissing($conn, 'users', 'status', "ENUM('Active','Inactive') NOT NULL DEFAULT 'Active' AFTER module_permissions");
$messages[] = addColIfMissing($conn, 'users', 'is_branch_admin', "TINYINT(1) NOT NULL DEFAULT 0 AFTER status");

$colRes = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
if ($colRes && ($row = $colRes->fetch_assoc())) {
    if (strpos($row['Type'], 'Branch Admin') === false && strpos($row['Type'], 'Manager') === false) {
        $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('Admin','Branch Admin','Manager','Operator') NOT NULL DEFAULT 'Operator'");
        if ($conn->error) $errors[] = 'role enum: ' . $conn->error; else $messages[] = '+ users.role enum expanded (Admin, Branch Admin, Manager, Operator)';
    }
}
if ($colRes) $colRes->free();

$cnt = $conn->query('SELECT COUNT(*) AS n FROM branches');
if ($cnt && ($r = $cnt->fetch_assoc()) && (int)$r['n'] === 0) {
    $conn->query("INSERT INTO branches (branch_code, branch_name, address, status) VALUES ('HO','Head Office','Main Office','Active')");
    if ($conn->error) $errors[] = 'insert default branch: ' . $conn->error; else $messages[] = '+ Default Head Office branch created';
}
if ($cnt) $cnt->free();

$hoRes = $conn->query('SELECT id FROM branches ORDER BY id ASC LIMIT 1');
if ($hoRes && ($ho = $hoRes->fetch_assoc())) {
    $bid = (int)$ho['id'];
    $conn->query("UPDATE users SET branch_id = {$bid} WHERE branch_id IS NULL");
    $conn->query("UPDATE users SET status = 'Active' WHERE status IS NULL OR status = ''");
    $messages[] = '+ Assigned default branch + Active status to existing users';
}
if ($hoRes) $hoRes->free();

$conn->query('SET FOREIGN_KEY_CHECKS=1');
?><!doctype html><html><head><meta charset=utf-8><title>Migration: Branches & Users Permissions</title>
<style>body{font-family:Segoe UI;font-size:13px;padding:30px;background:#f3f6fa}h1{color:#0f4c81;margin:0 0 10px}
.card{max-width:820px;margin:0 auto;background:#fff;border:1px solid #d0d7de;border-radius:8px;padding:20px}
.ok{color:#166534;margin:3px 0}.err{color:#991b1b;margin:3px 0;font-weight:600}
.btn{display:inline-block;background:#0f766e;color:#fff;padding:8px 14px;border-radius:5px;text-decoration:none;margin-top:12px;font-weight:600}</style></head><body>
<div class=card><h1>🚀 Migration — Branches & User Permissions</h1>
<p><b>Status:</b> <?= empty($errors) ? '✅ Completed successfully' : '⚠️ Completed with ' . count($errors) . ' error(s)' ?></p>
<?php if (!empty($messages)) { echo '<h3 style=margin:14px 0 4px>Messages</h3>'; foreach ($messages as $m) echo '<div class=ok>✓ ' . htmlspecialchars($m) . '</div>'; } ?>
<?php if (!empty($errors))   { echo '<h3 style=margin:14px 0 4px>Errors</h3>';   foreach ($errors as $e)   echo '<div class=err>✗ ' . htmlspecialchars($e) . '</div>'; } ?>
<p style=margin-top:16px>After running this migration once, you may safely delete this file.<br><a class=btn href=dashboard.php>← Back to Dashboard</a></p>
</div></body></html>

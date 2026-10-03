<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
startSecureSession(); requireLogin();
$conn = getDBConnection(); ensureCompanySchema($conn);
if (!isApplicationLocked($conn)) { header('Location: dashboard.php'); exit; }
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>System Locked</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container" style="max-width:600px;margin-top:12vh"><div class="card shadow-sm"><div class="card-body text-center p-4"><h2>🔒 System Locked</h2><p class="text-muted mb-3">This ERP installation has been locked. Only an OTP delivered to the registered owner email can unlock it.</p><a href="owner_control.php" class="btn btn-primary">Owner Lock Control</a><a href="logout.php" class="btn btn-outline-secondary ms-2">Logout</a></div></div></main></body></html>

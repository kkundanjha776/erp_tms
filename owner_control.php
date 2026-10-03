<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/license_config.php';
require_once __DIR__ . '/includes/mailer.php';

startSecureSession();
$conn = getDBConnection();
ensureCompanySchema($conn);
$csrf = generateCSRFToken();
$message = '';
$messageType = 'info';
$pendingAction = '';

function ownerSetting(mysqli $conn, string $key): string {
    $stmt = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1');
    $stmt->bind_param('s', $key); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (string)($row['setting_value'] ?? '');
}
function saveOwnerSetting(mysqli $conn, string $key, string $value): void {
    $stmt = $conn->prepare('INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $stmt->bind_param('ss', $key, $value); $stmt->execute(); $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token. Refresh the page and try again.'; $messageType = 'danger';
    } elseif (($_POST['step'] ?? '') === 'request') {
        $email = strtolower(trim($_POST['owner_email'] ?? ''));
        $action = $_POST['lock_action'] ?? '';
        if (!hash_equals(strtolower(SYSTEM_OWNER_EMAIL), $email) || !in_array($action, ['lock','unlock'], true)) {
            $message = 'Owner email or action is not valid.'; $messageType = 'danger';
        } elseif ((time() - (int)ownerSetting($conn, 'owner_otp_sent_at')) < 60) {
            $message = 'Please wait one minute before requesting another OTP.'; $messageType = 'warning';
        } else {
            $otp = (string)random_int(100000, 999999);
            $expiresAt = time() + 600;
            $subject = 'ERP owner confirmation OTP';
            $body = "Your ERP request: " . strtoupper($action) . "\n\nOTP: {$otp}\n\nThis OTP expires in 10 minutes. Do not share it with anyone.";
            if (sendOwnerMail($subject, $body)) {
                saveOwnerSetting($conn, 'owner_otp_hash', hash('sha256', $otp));
                saveOwnerSetting($conn, 'owner_otp_action', $action);
                saveOwnerSetting($conn, 'owner_otp_expires_at', (string)$expiresAt);
                saveOwnerSetting($conn, 'owner_otp_sent_at', (string)time());
                $pendingAction = $action;
                $message = 'OTP sent to the registered owner email. Enter it below within 10 minutes.'; $messageType = 'success';
            } else {
                $message = 'Email could not be sent. Add your Gmail App Password in includes/license_config.php, then try again.'; $messageType = 'danger';
            }
        }
    } elseif (($_POST['step'] ?? '') === 'verify') {
        $otp = trim($_POST['otp'] ?? '');
        $action = $_POST['lock_action'] ?? '';
        $valid = preg_match('/^\d{6}$/', $otp)
            && in_array($action, ['lock','unlock'], true)
            && hash_equals(ownerSetting($conn, 'owner_otp_action'), $action)
            && time() <= (int)ownerSetting($conn, 'owner_otp_expires_at')
            && hash_equals(ownerSetting($conn, 'owner_otp_hash'), hash('sha256', $otp));
        if (!$valid) {
            $message = 'Invalid, expired, or already-used OTP.'; $messageType = 'danger';
        } else {
            saveOwnerSetting($conn, 'application_locked', $action === 'lock' ? '1' : '0');
            saveOwnerSetting($conn, 'owner_otp_hash', '');
            saveOwnerSetting($conn, 'owner_otp_expires_at', '0');
            $message = $action === 'lock' ? 'System locked successfully.' : 'System unlocked successfully.'; $messageType = 'success';
        }
    }
}
$locked = isApplicationLocked($conn);
$verifyAction = $pendingAction !== '' ? $pendingAction : ($locked ? 'unlock' : 'lock');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Owner Lock Control</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container" style="max-width:620px;margin:8vh auto"><div class="card shadow-sm"><div class="card-body p-4"><h3>Owner Lock Control</h3><p class="text-muted small">Current system status: <b><?= $locked ? 'LOCKED' : 'UNLOCKED' ?></b>. A six-digit OTP is sent only to the registered owner email.</p><?php if($message):?><div class="alert alert-<?=htmlspecialchars($messageType)?> py-2 small"><?=htmlspecialchars($message)?></div><?php endif;?>
<form method="post" class="mb-3"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="step" value="request"><label class="form-label">Owner email</label><input class="form-control mb-2" type="email" name="owner_email" required placeholder="Your registered email"><label class="form-label">Action</label><select class="form-select mb-3" name="lock_action"><option value="<?= $locked ? 'unlock' : 'lock' ?>"><?= $locked ? 'Unlock system' : 'Lock system' ?></option></select><button class="btn btn-primary">Send OTP</button></form>
<form method="post"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="step" value="verify"><input type="hidden" name="lock_action" value="<?=htmlspecialchars($verifyAction)?>"><label class="form-label">OTP received by email to <?=htmlspecialchars($verifyAction)?> the system</label><div class="input-group"><input class="form-control" name="otp" inputmode="numeric" maxlength="6" required><button class="btn btn-success">Confirm OTP</button></div></form><p class="small text-muted mt-3 mb-0">Owner email: <?=htmlspecialchars(SYSTEM_OWNER_EMAIL)?>. OTP expires in 10 minutes.</p></div></div></main></body></html>

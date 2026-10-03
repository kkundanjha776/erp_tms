<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/master_data.php';

startSecureSession();

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$conn = getDBConnection();
ensureCompanySchema($conn);
$companiesResult = $conn->query("SELECT id, company_code, COALESCE(NULLIF(trade_name,''),legal_name) AS company_name FROM companies WHERE status='Active' ORDER BY company_name");
$loginCompanies = $companiesResult ? $companiesResult->fetch_all(MYSQLI_ASSOC) : [];
if ($companiesResult) $companiesResult->free();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $companyId = (int)($_POST['company_id'] ?? 0);

    if (empty($username) || empty($password) || (!empty($loginCompanies) && $companyId <= 0)) {
        $error = 'Username and password required / उपयोगकर्ता नाम और पासवर्ड आवश्यक';
    } else {
        $stmt = $conn->prepare('SELECT id, username, password, role FROM users WHERE username = ?');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            generateCSRFToken();
            loadUserSessionContext($conn, (int)$user['id']);
            $_SESSION['active_company_id'] = $companyId;
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid credentials / अमान्य लॉगिन';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(appBrandTitle('Login')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=scrollfix2">
    <style>
        .login-logo-mark{width:64px;height:64px;margin:0 auto 10px;display:block;filter:drop-shadow(0 4px 10px rgba(12,42,69,0.35))}
        .login-brand{font-size:1.7rem;font-weight:900;letter-spacing:1.6px;color:#0c2a45;margin:0;line-height:1.1}
        .login-brand-sub{font-size:.62rem;color:#50606f;letter-spacing:1.2px;text-transform:uppercase;margin-top:4px;font-weight:600}
        .login-title-tag{margin-top:16px;font-size:.76rem;color:#546273;font-weight:500}
    </style>
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="text-center mb-3">
                <svg class="login-logo-mark" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                  <defs>
                    <linearGradient id="scmsBgLogin" x1="0" y1="0" x2="1" y2="1">
                      <stop offset="0%" stop-color="#0c2a45"/>
                      <stop offset="100%" stop-color="#163e60"/>
                    </linearGradient>
                  </defs>
                  <circle cx="60" cy="60" r="56" fill="url(#scmsBgLogin)"/>
                  <circle cx="60" cy="60" r="56" fill="none" stroke="rgba(255,255,255,0.35)" stroke-width="2"/>
                  <g stroke="#ffffff" stroke-width="3.2" fill="none" stroke-linejoin="miter" stroke-linecap="square">
                    <rect x="28" y="40" width="30" height="26" rx="2" fill="rgba(255,255,255,0.04)"/>
                    <polyline points="28,41 43,54 58,41"/>
                    <rect x="62" y="40" width="30" height="26" rx="2" fill="rgba(255,255,255,0.04)"/>
                    <polyline points="62,41 77,54 92,41"/>
                    <polyline points="59,53 68,53 74,47 68,53 59,53" fill="#ffffff" stroke="none"/>
                    <line x1="58" y1="53" x2="62" y2="53"/>
                  </g>
                </svg>
                <h1 class="login-brand"><?= htmlspecialchars(APP_BRAND_NAME) ?></h1>
                <div class="login-brand-sub"><?= htmlspecialchars(APP_BRAND_SUBTITLE) ?></div>
                <div class="login-title-tag">Supply Chain Management System</div>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" required autofocus
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <?php if (!empty($loginCompanies)): ?>
                <div class="mb-3">
                    <label class="form-label">Company</label>
                    <select name="company_id" class="form-select" required>
                        <option value="">-- Select Company --</option>
                        <?php foreach ($loginCompanies as $company): ?>
                        <option value="<?= (int)$company['id'] ?>" <?= (int)($_POST['company_id'] ?? 0) === (int)$company['id'] ? 'selected' : '' ?>><?= htmlspecialchars($company['company_code'] . ' — ' . $company['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary w-100">Login / लॉगिन</button>
            </form>
            <p class="text-muted text-center mt-3 small">Default: admin / password</p>
        </div>
    </div>
</body>
</html>

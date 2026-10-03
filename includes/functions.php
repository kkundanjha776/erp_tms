<?php
/**
 * Common Helper Functions
 */

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        session_start();
    }
}

function generateCSRFToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken(?string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
    if (empty($_SESSION['module_permissions']) || !isset($_SESSION['branch_name'])) {
        require_once __DIR__ . '/db_connection.php';
        $conn = getDBConnection();
        try {
            loadUserSessionContext($conn, (int)$_SESSION['user_id']);
        } catch (Throwable $e) {
            if (empty($_SESSION['module_permissions'])) {
                $_SESSION['module_permissions'] = ['dashboard','docket_create','docket_search','ewaybill_docket','pod','docket_status','tracking','reports','billing'];
            }
            if (!isset($_SESSION['branch_name'])) {
                $_SESSION['branch_id'] = null;
                $_SESSION['branch_code'] = '';
                $_SESSION['branch_name'] = '';
            }
            if (!isset($_SESSION['role'])) {
                $_SESSION['role'] = 'Operator';
            }
            if (!isset($_SESSION['user_status'])) {
                $_SESSION['user_status'] = 'Active';
            }
        }
    }
    if (($_SESSION['user_status'] ?? 'Active') !== 'Active') {
        session_destroy();
        header('Location: login.php?disabled=1');
        exit;
    }

    $conn = getDBConnection();
    ensureCompanySchema($conn);
    if (!isset($_SESSION['is_system_owner'])) {
        $ownerStmt = $conn->prepare('SELECT is_system_owner FROM users WHERE id=? LIMIT 1');
        if ($ownerStmt) {
            $userId = (int)$_SESSION['user_id'];
            $ownerStmt->bind_param('i', $userId);
            $ownerStmt->execute();
            $ownerRow = $ownerStmt->get_result()->fetch_assoc();
            $_SESSION['is_system_owner'] = (bool)($ownerRow['is_system_owner'] ?? 0);
            $ownerStmt->close();
        }
    }
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (isApplicationLocked($conn) && $script !== 'company_master.php' && $script !== 'system_locked.php') {
        header('Location: system_locked.php');
        exit;
    }
}

function requireLoginAPI(): void
{
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized. Please login. / कृपया लॉगिन करें।'
        ]);
        exit;
    }

    $conn = getDBConnection();
    ensureCompanySchema($conn);
    if (isApplicationLocked($conn)) {
        jsonResponse(false, 'The system is currently locked. Contact the System Owner.', [], 423);
    }
}

function sanitizeInput(string $input): string
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function sanitizeForDB(mysqli $conn, ?string $input): string
{
    return $conn->real_escape_string(trim($input ?? ''));
}

function jsonResponse(bool $success, string $message, array $data = [], int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function getPostValue(string $key, $default = ''): string
{
    return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function getPostFloat(string $key, float $default = 0.0): float
{
    return isset($_POST[$key]) ? (float) $_POST[$key] : $default;
}

function getPostInt(string $key, int $default = 0): int
{
    return isset($_POST[$key]) ? (int) $_POST[$key] : $default;
}

/**
 * Convert number to Indian Rupees in words
 */
function numberToWords(float $number): string
{
    $number = round($number, 2);
    $whole = (int) $number;
    $decimal = (int) round(($number - $whole) * 100);

    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
             'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
             'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    if ($whole === 0) {
        $words = 'Zero';
    } else {
        $words = convertIndianNumber($whole, $ones, $tens);
    }

    $result = 'Rupees ' . $words;

    if ($decimal > 0) {
        $result .= ' and ' . convertIndianNumber($decimal, $ones, $tens) . ' Paise';
    }

    return $result . ' Only';
}

function convertIndianNumber(int $num, array $ones, array $tens): string
{
    if ($num === 0) return '';

    $result = '';

    if ($num >= 10000000) {
        $result .= convertIndianNumber((int) ($num / 10000000), $ones, $tens) . ' Crore ';
        $num %= 10000000;
    }
    if ($num >= 100000) {
        $result .= convertIndianNumber((int) ($num / 100000), $ones, $tens) . ' Lakh ';
        $num %= 100000;
    }
    if ($num >= 1000) {
        $result .= convertIndianNumber((int) ($num / 1000), $ones, $tens) . ' Thousand ';
        $num %= 1000;
    }
    if ($num >= 100) {
        $result .= $ones[(int) ($num / 100)] . ' Hundred ';
        $num %= 100;
    }
    if ($num >= 20) {
        $result .= $tens[(int) ($num / 10)] . ' ';
        $num %= 10;
    }
    if ($num > 0) {
        $result .= $ones[$num] . ' ';
    }

    return trim($result);
}

function calculateTaxes(float $basicFreight, ?string $originStateCode, ?string $destStateCode, float $gstRate = 18.0): array
{
    $gstRate = max(0, min(100, $gstRate));
    $isIntraState = ($originStateCode && $destStateCode && $originStateCode === $destStateCode);

    if ($isIntraState) {
        $sgst = round($basicFreight * ($gstRate / 200), 2);
        $cgst = round($basicFreight * ($gstRate / 200), 2);
        $igst = 0.00;
    } else {
        $sgst = 0.00;
        $cgst = 0.00;
        $igst = round($basicFreight * ($gstRate / 100), 2);
    }

    return [
        'sgst' => $sgst,
        'cgst' => $cgst,
        'igst' => $igst,
        'is_intra_state' => $isIntraState
    ];
}

function calculateGrandTotal(array $charges): float
{
    return round(
        ($charges['basic_freight'] ?? 0) +
        ($charges['fuel_charge'] ?? 0) +
        ($charges['dkt_charge'] ?? 0) +
        ($charges['handling_charge'] ?? 0) +
        ($charges['oda_charge'] ?? 0) +
        ($charges['detention'] ?? 0) +
        ($charges['misc_charge'] ?? 0) +
        ($charges['other_charge'] ?? 0) +
        ($charges['risk_charge'] ?? 0) +
        ($charges['sgst'] ?? 0) +
        ($charges['cgst'] ?? 0) +
        ($charges['igst'] ?? 0),
        2
    );
}

function getCityStateCode(mysqli $conn, int $cityId): ?string
{
    if ($cityId <= 0) return null;

    $stmt = $conn->prepare('SELECT state_code FROM cities WHERE id = ?');
    $stmt->bind_param('i', $cityId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return $row ? $row['state_code'] : null;
}

function currentUserRole(): string
{
    return $_SESSION['role'] ?? 'Operator';
}

function isAdminUser(): bool
{
    return ($_SESSION['role'] ?? '') === 'Admin';
}

/**
 * Creates the company and licensing tables on new as well as existing installs.
 * The first Admin is made System Owner only once, so ordinary Admin accounts
 * cannot grant themselves authority to lock or unlock the application.
 */
function ensureCompanySchema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $conn->query("CREATE TABLE IF NOT EXISTS companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_code VARCHAR(32) NOT NULL UNIQUE,
            legal_name VARCHAR(200) NOT NULL,
            trade_name VARCHAR(200) NULL,
            address TEXT NULL,
            city_id INT NULL,
            state_code VARCHAR(5) NULL,
            pincode VARCHAR(10) NULL,
            pan_no VARCHAR(10) NULL,
            phone VARCHAR(30) NULL,
            email VARCHAR(150) NULL,
            website VARCHAR(150) NULL,
            status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_company_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $conn->query("CREATE TABLE IF NOT EXISTS company_gst_registrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            state_code VARCHAR(5) NOT NULL,
            gstin VARCHAR(15) NOT NULL,
            registration_address TEXT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_company_state (company_id, state_code),
            UNIQUE KEY uq_gstin (gstin),
            CONSTRAINT fk_company_gst_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(80) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $logoColumn = $conn->query("SHOW COLUMNS FROM companies LIKE 'logo_path'");
        $logoMissing = !$logoColumn || $logoColumn->num_rows === 0;
        if ($logoColumn) $logoColumn->free();
        if ($logoMissing) $conn->query("ALTER TABLE companies ADD COLUMN logo_path VARCHAR(255) NULL AFTER website");

        $ownerColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'is_system_owner'");
        $ownerMissing = !$ownerColumn || $ownerColumn->num_rows === 0;
        if ($ownerColumn) $ownerColumn->free();
        if ($ownerMissing) $conn->query("ALTER TABLE users ADD COLUMN is_system_owner TINYINT(1) NOT NULL DEFAULT 0 AFTER is_branch_admin");

        $ownerCount = $conn->query('SELECT COUNT(*) AS total FROM users WHERE is_system_owner=1');
        $hasOwner = $ownerCount && (($ownerCount->fetch_assoc()['total'] ?? 0) > 0);
        if ($ownerCount) $ownerCount->free();
        if (!$hasOwner) {
            $firstAdmin = $conn->query("SELECT id FROM users WHERE role='Admin' ORDER BY id ASC LIMIT 1");
            if ($firstAdmin && ($admin = $firstAdmin->fetch_assoc())) {
                $ownerId = (int)$admin['id'];
                $conn->query("UPDATE users SET is_system_owner=1 WHERE id={$ownerId}");
            }
            if ($firstAdmin) $firstAdmin->free();
        }
        $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('application_locked','0')");
    } catch (Throwable $e) {
        error_log('Company schema setup failed: ' . $e->getMessage());
    }
}

function isSystemOwner(): bool
{
    return !empty($_SESSION['is_system_owner']);
}

function isApplicationLocked(mysqli $conn): bool
{
    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='application_locked' LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) $result->free();
    return (string)($row['setting_value'] ?? '0') === '1';
}

function getActiveCompanyName(mysqli $conn): string
{
    $profile = getActiveCompanyProfile($conn);
    return (string)($profile['name'] ?? '');
}

function getActiveCompanyProfile(mysqli $conn): array
{
    $companyId = (int)($_SESSION['active_company_id'] ?? 0);
    $sql = "SELECT id, company_code, COALESCE(NULLIF(trade_name,''), legal_name) AS name, logo_path FROM companies WHERE status='Active'";
    if ($companyId > 0) $sql .= ' AND id=' . $companyId;
    $sql .= ' ORDER BY id ASC LIMIT 1';
    $result = $conn->query($sql);
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) $result->free();
    return is_array($row) ? $row : [];
}

define('APP_BRAND_NAME', 'SCMS');
define('APP_BRAND_SUBTITLE', 'Powered by Bitombita');

function appBrandTitle(?string $pageTitle = null): string
{
    $brand = APP_BRAND_NAME;
    if ($pageTitle === null || $pageTitle === '') return $brand;
    return $pageTitle . ' - ' . $brand;
}

function renderAppBrand(): void
{
    $brand = APP_BRAND_NAME;
    $company = [];
    try { $company = getActiveCompanyProfile(getDBConnection()); } catch (Throwable $e) { $company = []; }
    $companyName = trim((string)($company['name'] ?? ''));
    $logoPath = trim((string)($company['logo_path'] ?? ''));
    $svg = <<<'SVG'
<svg class="scms-logo-mark" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <defs>
    <linearGradient id="scmsBg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#0c2a45"/>
      <stop offset="100%" stop-color="#163e60"/>
    </linearGradient>
  </defs>
  <circle cx="60" cy="60" r="56" fill="url(#scmsBg)"/>
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
SVG;
    echo '<div class="sidebar-brand scms-brand" role="img" aria-label="' . htmlspecialchars($companyName ?: $brand) . ' Logo">';
    if ($logoPath !== '') {
        echo '<img class="scms-logo-mark" style="object-fit:contain;border-radius:12px;background:#fff" src="' . htmlspecialchars($logoPath) . '" alt="' . htmlspecialchars($companyName) . ' logo">';
    } else {
        echo $svg;
    }
    echo '<div class="brand-text-block">';
    echo '<div class="brand-text">' . htmlspecialchars($companyName ?: $brand) . '</div>';
    echo '<div class="brand-sub">' . htmlspecialchars($companyName ? APP_BRAND_NAME . ' · ' . APP_BRAND_SUBTITLE : APP_BRAND_SUBTITLE) . '</div>';
    echo '</div></div>';
}

function canEditConsignmentStatus(?string $status): bool
{
    if ($status === null || $status === '' || $status === 'Draft') {
        return true;
    }
    if ($status === 'Submitted') {
        return isAdminUser();
    }
    // Approved - no one edits
    return false;
}

function canViewConsignmentStatus(?string $status): bool
{
    return true;
}

/**
 * Module registry — keys used in module_permissions CSV column.
 * Order matters for rendering module links.
 */
function getAllModules(): array
{
    return [
        'dashboard'       => ['label' => 'Dashboard',        'icon'  => '🏠', 'url'   => 'dashboard.php'],
        'docket_create'   => ['label' => 'Create Docket',    'icon'  => '📝', 'url'   => 'index.php'],
        'ewaybill_docket' => ['label' => 'E-Way Bill Entry', 'icon' => '🧾', 'url' => 'ewaybill_docket_entry.php'],
        'docket_search'   => ['label' => 'Docket Search/Edit','icon' => '🔎', 'url'   => 'ops_search.php'],
        'clients'         => ['label' => 'Client Master',    'icon'  => '👥', 'url'   => 'client_master.php'],
        'pod'             => ['label' => 'POD Upload',       'icon'  => '📄', 'url'   => 'pod_upload.php'],
        'docket_status'   => ['label' => 'Docket Status',    'icon'  => '📍', 'url'   => 'docket_status.php'],
        'tracking'        => ['label' => 'Docket Tracking',  'icon'  => '🚛', 'url'   => 'docket_tracking.php'],
        'reports'         => ['label' => 'Detailed Report',  'icon'  => '📊', 'url'   => 'report.php'],
        'billing'         => ['label' => 'Billing & Invoices', 'icon' => '🧾', 'url' => 'billing.php'],
        'thc'             => ['label' => 'Trip Hire Contract', 'icon' => '🚚', 'url' => 'thc_list.php'],
        'user_management' => ['label' => 'User Management',  'icon'  => '🔐', 'url'   => 'user_management.php', 'admin_only' => true],
        'branch_management' => ['label' => 'Branch Management','icon' => '🏢', 'url'   => 'branch_management.php', 'admin_only' => true],
        'company_master' => ['label' => 'Company Master', 'icon' => '🏢', 'url' => 'company_master.php', 'admin_only' => true],
        'route_master'   => ['label' => 'Route Master',   'icon' => '🗺️','url' => 'route_master.php',  'admin_only' => true],
    ];
}

function getAllRoles(): array
{
    return ['Admin' => 'Admin (full access)', 'Branch Admin' => 'Branch Admin', 'Manager' => 'Manager', 'Operator' => 'Operator'];
}

function currentUserBranchId(): ?int
{
    if (!empty($_SESSION['branch_id'])) return (int)$_SESSION['branch_id'];
    return null;
}

function currentUserBranchName(): string
{
    return $_SESSION['branch_name'] ?? '—';
}

function currentUserBranchCode(): string
{
    return $_SESSION['branch_code'] ?? '';
}

function currentUserModules(): array
{
    if (!empty($_SESSION['module_permissions']) && is_array($_SESSION['module_permissions'])) {
        return $_SESSION['module_permissions'];
    }
    $all = array_keys(getAllModules());
    return $all;
}

function isBranchAdminUser(): bool
{
    return (bool)($_SESSION['is_branch_admin'] ?? false) || isAdminUser();
}

function canAccessModule(string $moduleKey): bool
{
    if (isAdminUser()) return true;
    $modules = getAllModules();
    if (!isset($modules[$moduleKey])) return false;
    if (!empty($modules[$moduleKey]['admin_only'])) return false;
    return in_array($moduleKey, currentUserModules(), true);
}

function requireModule(string $moduleKey): void
{
    if (!canAccessModule($moduleKey)) {
        header('Location: dashboard.php?forbidden=' . urlencode($moduleKey));
        exit;
    }
}

function ensureBranchesSchema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
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
    } catch (Throwable $e) { return; }
    foreach ([
        ['full_name', "VARCHAR(150) NULL AFTER username"],
        ['email', "VARCHAR(150) NULL AFTER full_name"],
        ['phone', "VARCHAR(30) NULL AFTER email"],
        ['branch_id', "INT NULL AFTER role"],
        ['module_permissions', "TEXT NULL COMMENT 'CSV allowed module keys' AFTER branch_id"],
        ['status', "ENUM('Active','Inactive') NOT NULL DEFAULT 'Active' AFTER module_permissions"],
        ['is_branch_admin', "TINYINT(1) NOT NULL DEFAULT 0 AFTER status"],
    ] as [$col, $def]) {
        try {
            $r = $conn->query("SHOW COLUMNS FROM users LIKE '" . $conn->real_escape_string($col) . "'");
            $missing = !$r || $r->num_rows === 0;
            if ($r) $r->free();
            if ($missing) {
                $conn->query("ALTER TABLE users ADD COLUMN {$col} {$def}");
            }
        } catch (Throwable $e) { /* skip */ }
    }
    try {
        $colRes = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
        if ($colRes && ($row = $colRes->fetch_assoc())) {
            $t = strtolower($row['Type'] ?? '');
            if (strpos($t, 'manager') === false || strpos($t, 'branch admin') === false) {
                $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('Admin','Branch Admin','Manager','Operator') NOT NULL DEFAULT 'Operator'");
            }
        }
        if ($colRes) $colRes->free();
    } catch (Throwable $e) { /* skip */ }
    try {
        $cnt = $conn->query('SELECT COUNT(*) AS n FROM branches');
        if ($cnt && ($r = $cnt->fetch_assoc()) && (int)$r['n'] === 0) {
            $conn->query("INSERT INTO branches (branch_code, branch_name, address, status) VALUES ('HO','Head Office','Main Office','Active')");
        }
        if ($cnt) $cnt->free();
        $hoRes = $conn->query('SELECT id FROM branches ORDER BY id ASC LIMIT 1');
        if ($hoRes && ($ho = $hoRes->fetch_assoc())) {
            $bid = (int)$ho['id'];
            $conn->query("UPDATE users SET branch_id = {$bid} WHERE branch_id IS NULL");
            $conn->query("UPDATE users SET status = 'Active' WHERE status IS NULL OR status = ''");
        }
        if ($hoRes) $hoRes->free();
    } catch (Throwable $e) { /* skip */ }
}

function loadUserSessionContext(mysqli $conn, int $userId): void
{
    ensureBranchesSchema($conn);
    ensureCompanySchema($conn);

    $u = null;
    try {
        $stmt = $conn->prepare('SELECT u.id, u.username, u.full_name, u.email, u.phone, u.role, u.branch_id, u.module_permissions, u.status, u.is_branch_admin, u.is_system_owner,
                                       b.branch_code, b.branch_name
                                FROM users u
                                LEFT JOIN branches b ON b.id = u.branch_id
                                WHERE u.id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $u = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    } catch (Throwable $e) {
        $u = null;
    }
    if (!$u) {
        try {
            $stmt = $conn->prepare('SELECT u.id, u.username, u.full_name, u.email, u.phone, u.role, u.branch_id, u.module_permissions, u.status, u.is_branch_admin, u.is_system_owner
                                    FROM users u WHERE u.id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $u = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
        } catch (Throwable $e) { $u = null; }
    }
    if (!$u) return;

    $_SESSION['user_id']   = (int)$u['id'];
    $_SESSION['username']  = $u['username'];
    $_SESSION['role']      = $u['role'] ?? 'Operator';
    $_SESSION['full_name'] = $u['full_name'] ?? $u['username'];
    $_SESSION['email']     = $u['email'] ?? '';
    $_SESSION['phone']     = $u['phone'] ?? '';
    $_SESSION['user_status'] = $u['status'] ?? 'Active';
    $_SESSION['branch_id']   = !empty($u['branch_id']) ? (int)$u['branch_id'] : null;
    $_SESSION['branch_code'] = (string)($u['branch_code'] ?? '');
    $_SESSION['branch_name'] = (string)($u['branch_name'] ?? '');
    $_SESSION['is_branch_admin'] = (bool)($u['is_branch_admin'] ?? 0);
    $_SESSION['is_system_owner'] = (bool)($u['is_system_owner'] ?? 0);

    $mods = [];
    if (($_SESSION['role'] ?? '') === 'Admin') {
        $mods = array_keys(getAllModules());
    } else {
        $raw = trim((string)($u['module_permissions'] ?? ''));
        if ($raw !== '') {
            $mods = array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
        if (empty($mods)) {
            $mods = ['dashboard','docket_create','docket_search','ewaybill_docket','pod','docket_status','tracking','reports','billing'];
        }
        if (!in_array('dashboard', $mods, true)) $mods[] = 'dashboard';
    }
    $_SESSION['module_permissions'] = $mods;
}

/**
 * Build sidebar navigation items (OPS + ADMIN), filtered by current user permissions.
 * Each item: [key, label, icon, url, admin_only, extra_query]
 */
function getSidebarNavItems(): array
{
    return [
        'OPS — Operations' => [
            ['key' => 'dashboard',     'label' => 'Dashboard',            'icon' => '🏠', 'url' => 'dashboard.php'],
            ['key' => 'docket_create', 'label' => 'Create Docket Entry', 'icon' => '📝', 'url' => 'index.php'],
            ['key' => 'ewaybill_docket', 'label' => 'E-Way Bill Entry', 'icon' => '🧾', 'url' => 'ewaybill_docket_entry.php'],
            ['key' => 'docket_search', 'label' => 'Docket Search / Edit','icon' => '🔎', 'url' => 'ops_search.php'],
            ['key' => 'clients',       'label' => 'Client Master',        'icon' => '👥', 'url' => 'client_master.php'],
            ['key' => 'pod',           'label' => 'POD Upload',           'icon' => '📄', 'url' => 'pod_upload.php'],
            ['key' => 'pod',           'label' => 'POD Uploaded',         'icon' => '✅', 'url' => 'pod_upload.php?view=uploaded'],
            ['key' => 'docket_status', 'label' => 'Docket Status',        'icon' => '📍', 'url' => 'docket_status.php'],
            ['key' => 'tracking',      'label' => 'Docket Tracking',      'icon' => '🚛', 'url' => 'docket_tracking.php'],
            ['key' => 'reports',       'label' => 'Detailed Report',      'icon' => '📊', 'url' => 'report.php'],
            ['key' => 'billing',       'label' => 'Create Invoice',       'icon' => '🧾', 'url' => 'billing.php'],
            ['key' => 'invoice_mgmt',  'label' => 'Invoice Management',   'icon' => '📋', 'url' => 'invoice_management.php'],
            ['key' => 'thc',           'label' => 'Trip Hire Contract',   'icon' => '🚚', 'url' => 'thc_list.php'],
        ],
        'ADMIN — Administration' => [
            ['key' => 'user_management',   'label' => 'User Management',   'icon' => '🔐', 'url' => 'user_management.php',   'admin_only' => true],
            ['key' => 'branch_management', 'label' => 'Branch Management', 'icon' => '🏢', 'url' => 'branch_management.php', 'admin_only' => true],
            ['key' => 'company_master', 'label' => 'Company Master', 'icon' => '🏢', 'url' => 'company_master.php', 'admin_only' => true],
            ['key' => 'invoice_terms', 'label' => 'Invoice Terms', 'icon' => '📋', 'url' => 'invoice_terms_master.php', 'admin_only' => true],
            ['key' => 'route_master',  'label' => 'Route Master',    'icon' => '🗺️', 'url' => 'route_master.php',  'admin_only' => true],
        ],
    ];
}

function getActiveSidebarModule(): string
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $map = [
        'dashboard.php' => 'dashboard',
        'index.php' => 'docket_create',
        'ewaybill_docket_entry.php' => 'ewaybill_docket',
        'ops_search.php' => 'docket_search',
        'client_master.php' => 'clients',
        'add_client.php' => 'clients',
        'edit_client.php' => 'clients',
        'pod_upload.php' => 'pod',
        'docket_status.php' => 'docket_status',
        'docket_tracking.php' => 'tracking',
        'report.php' => 'reports',
        'billing.php' => 'billing',
        'export_report.php' => 'reports',
        'user_management.php' => 'user_management',
        'branch_management.php' => 'branch_management',
        'company_master.php' => 'company_master',
        'invoice_terms_master.php' => 'invoice_terms',
        'invoice_management.php' => 'invoice_mgmt',
        'edit_invoice.php' => 'invoice_mgmt',
        'thc_list.php'     => 'thc',
        'thc_create.php'   => 'thc',
        'thc_loading_tally.php' => 'thc',
        'thc_manifest.php' => 'thc',
        'thc_dispatch.php' => 'thc',
        'route_master.php' => 'route_master',
    ];
    return $map[$script] ?? '';
}

/**
 * Echo the sidebar nav HTML for the current page.
 * Uses URL matching (via SCRIPT_NAME) to determine which link is active.
 */
function renderSidebarNav(): void
{
    $groups = getSidebarNavItems();
    $active = getActiveSidebarModule();
    $podView = $_GET['view'] ?? '';

    foreach ($groups as $groupTitle => $items) {
        $visible = [];
        foreach ($items as $it) {
            if (!empty($it['admin_only']) && !isAdminUser()) continue;
            if ($it['key'] === 'company_master' && !empty($_SESSION['active_company_id'])) continue;
            if (canAccessModule($it['key'])) $visible[] = $it;
        }
        if (empty($visible)) continue;
        echo '<div class="nav-module">', "\n";
        echo '    <div class="nav-module-title">', htmlspecialchars($groupTitle), "</div>\n";
        echo '    <ul class="nav-list">', "\n";
        foreach ($visible as $it) {
            $isActive = ($it['key'] === $active);
            if ($isActive && $it['key'] === 'pod') {
                $urlHasUploaded = (strpos($it['url'], 'view=uploaded') !== false);
                $isActive = ($podView === 'uploaded') ? $urlHasUploaded : !$urlHasUploaded;
            }
            $cls = 'nav-item' . ($isActive ? ' active' : '');
            $title = htmlspecialchars($it['label']);
            echo '        <li><a href="', htmlspecialchars($it['url']), '" class="', $cls, '" title="', $title, '"><span class="nav-icon">', htmlspecialchars($it['icon']), '</span><span class="nav-label">', $title, "</span></a></li>\n";
        }
        echo "    </ul>\n</div>\n";
    }
}

function renderSidebarFooter(): void
{
    $initials = strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1));
    $user = htmlspecialchars($_SESSION['username'] ?? '');
    $role = htmlspecialchars($_SESSION['role'] ?? '');
    $branchName = htmlspecialchars(currentUserBranchName());
    $branchCode = htmlspecialchars(currentUserBranchCode());
    $branchBadge = '';
    $companyFooter = '';
    try {
        $company = getActiveCompanyProfile(getDBConnection());
        if (!empty($company['name'])) {
            $logo = !empty($company['logo_path']) ? '<img src="' . htmlspecialchars($company['logo_path']) . '" alt="" style="width:20px;height:20px;object-fit:contain;border-radius:4px;background:#fff;margin-right:5px">' : '🏢 ';
            $companyFooter = '<div class="sidebar-role" style="margin-top:4px;display:flex;align-items:center">' . $logo . htmlspecialchars($company['name']) . '</div>';
        }
    } catch (Throwable $e) { $companyFooter = ''; }
    if ($branchName !== '' && $branchName !== '—') {
        $branchBadge = '<div class="sidebar-role" style="margin-top:2px">🏢 ' . ($branchCode !== '' ? $branchCode . ' · ' : '') . $branchName . "</div>\n";
    }
    echo '<div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-user-avatar">', $initials, '</div>
            <div class="sidebar-user-info">
                <div class="sidebar-username">', $user, '</div>
                <div class="sidebar-role">', $role, "</div>\n", $branchBadge, $companyFooter, '
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout" title="Logout">↪ Logout</a>
    </div>';
}

function renderAppHeader(string $title, string $titleIcon = ''): void
{
    $user = htmlspecialchars($_SESSION['username'] ?? '');
    $role = htmlspecialchars($_SESSION['role'] ?? '');
    $branch = currentUserBranchName();
    $suffix = '';
    if ($branch && $branch !== '—') {
        $bc = currentUserBranchCode();
        $suffix = ' · ' . htmlspecialchars(($bc ? $bc . ' — ' : '') . $branch);
    }
    $company = '';
    $companyLogo = '';
    try {
        $conn = getDBConnection();
        ensureCompanySchema($conn);
        $profile = getActiveCompanyProfile($conn);
        $company = (string)($profile['name'] ?? '');
        $companyLogo = (string)($profile['logo_path'] ?? '');
    } catch (Throwable $e) { $company = ''; }
    $companyBadge = $company !== '' ? '<span class="user-info" style="margin-right:10px;display:inline-flex;align-items:center;font-weight:700">' . ($companyLogo !== '' ? '<img src="' . htmlspecialchars($companyLogo) . '" alt="" style="width:26px;height:26px;object-fit:contain;border-radius:5px;background:#fff;margin-right:7px">' : '🏢 ') . htmlspecialchars($company) . '</span>' : '';
    echo '<header class="app-header">
    <h1>', $titleIcon ? htmlspecialchars($titleIcon) . ' ' : '', htmlspecialchars($title), '</h1>
    <div class="header-actions">
        ', $companyBadge, '<span class="user-info">', $user, ' (', $role, ')', $suffix, '</span>
        <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
    </div>
</header>';
}

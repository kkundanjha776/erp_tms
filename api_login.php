<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/master_data.php';

$conn = getDBConnection();
ensureCompanySchema($conn);

// 1. GET Request: Agar Flutter app company list mang rahi ho
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $companiesResult = $conn->query("SELECT id, company_code, COALESCE(NULLIF(trade_name,''),legal_name) AS company_name FROM companies WHERE status='Active' ORDER BY company_name");
    $companies = $companiesResult ? $companiesResult->fetch_all(MYSQLI_ASSOC) : [];
    if ($companiesResult) $companiesResult->free();
    
    echo json_encode(["status" => "success", "companies" => $companies]);
    exit;
}

// 2. POST Request: Jab user Mobile App se Login karega
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $companyId = (int)($_POST['company_id'] ?? 0);

    if (empty($username) || empty($password)) {
        echo json_encode(["status" => "error", "message" => "Username aur Password zaroori hai"]);
        exit;
    }

    $stmt = $conn->prepare('SELECT id, username, password, role FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($password, $user['password'])) {
        echo json_encode([
            "status" => "success",
            "message" => "Login successful",
            "user" => [
                "id" => (int)$user['id'],
                "username" => $user['username'],
                "role" => $user['role'],
                "company_id" => $companyId
            ]
        ]);
        exit;
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid credentials / अमान्य लॉगिन"]);
        exit;
    }
}

echo json_encode(["status" => "error", "message" => "Invalid Request"]);
?>
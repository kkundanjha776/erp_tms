<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';

startSecureSession(); requireLoginAPI();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) jsonResponse(false, 'Invalid request', [], 403);

$conn = getDBConnection(); ensurePODSchema($conn);
$id = (int) ($_POST['consignment_id'] ?? 0);
if ($id <= 0 || empty($_FILES['pod_files']['name'])) jsonResponse(false, 'Select at least one POD file', [], 422);
$uploadDir = dirname(__DIR__) . '/uploads/pod';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) jsonResponse(false, 'Unable to create POD storage', [], 500);

$allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$finfo = new finfo(FILEINFO_MIME_TYPE); $saved = 0;
$conn->begin_transaction();
try {
    $stmt = $conn->prepare('INSERT INTO pod_files (consignment_id, original_name, stored_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($_FILES['pod_files']['name'] as $i => $name) {
        if ($_FILES['pod_files']['error'][$i] !== UPLOAD_ERR_OK) throw new Exception('One file could not be uploaded');
        $size = (int) $_FILES['pod_files']['size'][$i];
        if ($size <= 0 || $size > 2 * 1024 * 1024) throw new Exception('Each file must be 2 MB or smaller');
        $tmp = $_FILES['pod_files']['tmp_name'][$i]; $mime = $finfo->file($tmp);
        if (!isset($allowed[$mime])) throw new Exception('Only PDF, JPG, PNG, or WEBP files are allowed');
        $stored = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($tmp, $uploadDir . '/' . $stored)) throw new Exception('Unable to store POD file');
        $safeName = basename((string) $name); $userId = (int) $_SESSION['user_id'];
        $stmt->bind_param('isssii', $id, $safeName, $stored, $mime, $size, $userId);
        if (!$stmt->execute()) throw new Exception('Unable to save POD file record');
        $saved++;
    }
    $stmt->close(); $conn->commit();
    jsonResponse(true, $saved . ' POD file(s) uploaded');
} catch (Throwable $e) {
    $conn->rollback(); jsonResponse(false, $e->getMessage(), [], 422);
}

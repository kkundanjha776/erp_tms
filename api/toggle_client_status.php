<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';

startSecureSession();
requireLoginAPI();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(false, 'Invalid request', [], 403);
}

$clientId = (int) ($_POST['client_id'] ?? 0);
$target = trim((string) ($_POST['status'] ?? ''));
if ($clientId <= 0) jsonResponse(false, 'Client ID required', [], 422);
if (!in_array($target, ['Active', 'Inactive'], true)) jsonResponse(false, 'Invalid status', [], 422);

$conn = getDBConnection();
ensureClientContractSchema($conn);
ensureClientRiskColumns($conn);

$stmt = $conn->prepare('UPDATE client_masters SET status = ? WHERE id = ?');
$stmt->bind_param('si', $target, $clientId);
if (!$stmt->execute()) jsonResponse(false, 'Unable to update status: ' . $conn->error, [], 500);
$stmt->close();

$sc = $conn->prepare('SELECT status, updated_at, expiry_date FROM client_masters WHERE id = ? LIMIT 1');
$sc->bind_param('i', $clientId);
$sc->execute();
$row = $sc->get_result()->fetch_assoc();
$sc->close();

jsonResponse(true, 'Client contract status updated to ' . $target, [
    'client_id' => $clientId,
    'status' => $row ? $row['status'] : $target,
    'updated_at' => $row['updated_at'] ?? '',
    'expiry_date' => $row['expiry_date'] ?? '',
]);

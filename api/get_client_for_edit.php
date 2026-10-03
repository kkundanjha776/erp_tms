<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';

startSecureSession();
requireLoginAPI();
header('Content-Type: application/json; charset=utf-8');

$clientId = (int) ($_GET['client_id'] ?? 0);
if ($clientId <= 0) {
    jsonResponse(false, 'Client ID required', [], 422);
}

$conn = getDBConnection();
ensureClientContractSchema($conn);
ensureClientRiskColumns($conn);

$stmt = $conn->prepare('SELECT * FROM client_masters WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $clientId);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$client) {
    jsonResponse(false, 'Client not found', [], 404);
}

function formatDateForInput($v) {
    if (!$v) return '';
    $dt = is_numeric(strpos($v, '-')) ? $v : date('Y-m-d', strtotime($v));
    if (strpos($dt, ' ') !== false) $dt = substr($dt, 0, strpos($dt, ' '));
    return $dt;
}
if (isset($client['expiry_date'])) $client['expiry_date'] = formatDateForInput($client['expiry_date']);

$lanes = getClientLaneRates($conn, $clientId);
jsonResponse(true, 'Client loaded', ['client' => $client, 'lanes' => $lanes]);

<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';
startSecureSession(); requireLoginAPI();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) jsonResponse(false, 'Invalid request', [], 403);
$id = (int) ($_POST['consignment_id'] ?? 0); $priority = $_POST['pod_priority'] ?? 'Normal'; $date = $_POST['delivery_date'] ?? '';
if ($id <= 0 || !in_array($priority, ['Normal', 'High', 'Urgent'], true)) jsonResponse(false, 'Invalid POD details', [], 422);
if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jsonResponse(false, 'Invalid delivery date', [], 422);
$conn = getDBConnection(); ensurePODSchema($conn);
$deliveryDate = $date !== '' ? $date : null;
$stmt = $conn->prepare('UPDATE consignments SET pod_priority = ?, delivery_date = ? WHERE id = ?');
$stmt->bind_param('ssi', $priority, $deliveryDate, $id);
if (!$stmt->execute()) jsonResponse(false, 'Unable to update POD status', [], 500);
$stmt->close(); jsonResponse(true, 'POD status updated');

<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';
startSecureSession(); requireLoginAPI();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) jsonResponse(false, 'Invalid request', [], 403);
$id = (int) ($_POST['consignment_id'] ?? 0); $status = $_POST['docket_tracking_status'] ?? '';
$allowed = ['In Transit','Connecting to Next Destination','Out for Delivery','Delivered','Hold','Return'];
if ($id <= 0 || !in_array($status, $allowed, true)) jsonResponse(false, 'Invalid docket status', [], 422);
$conn = getDBConnection(); ensurePODSchema($conn);
$stmt = $conn->prepare('UPDATE consignments SET docket_tracking_status = ? WHERE id = ?');
$stmt->bind_param('si', $status, $id);
if (!$stmt->execute()) jsonResponse(false, 'Unable to update docket status', [], 500);
$stmt->close(); jsonResponse(true, 'Docket status updated');

<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';

startSecureSession();
requireLoginAPI();

header('Content-Type: application/json; charset=utf-8');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    jsonResponse(false, 'Invalid template ID / अमान्य ID', [], 400);
}

$conn = getDBConnection();
$consignment = getConsignmentById($conn, $id);

if (!$consignment) {
    jsonResponse(false, 'Template not found / टेम्पलेट नहीं मिला', [], 404);
}

// Clear unique fields for new entry
unset($consignment['id'], $consignment['consignment_note'], $consignment['status']);
unset($consignment['created_at'], $consignment['updated_at'], $consignment['created_by']);

jsonResponse(true, 'Template loaded / टेम्पलेट लोड', ['consignment' => $consignment]);

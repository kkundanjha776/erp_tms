<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/validation.php';

startSecureSession();
requireLoginAPI();

header('Content-Type: application/json; charset=utf-8');

$note = isset($_GET['note']) ? trim($_GET['note']) : '';
$excludeId = isset($_GET['exclude_id']) ? (int) $_GET['exclude_id'] : 0;

if (empty($note)) {
    jsonResponse(false, 'Consignment note required / कन्साइनमेंट नोट आवश्यक', ['available' => false], 400);
}

$conn = getDBConnection();
$isUnique = isConsignmentNoteUnique($conn, $note, $excludeId);

jsonResponse(true, $isUnique ? 'Available / उपलब्ध' : 'Already exists / पहले से मौजूद', [
    'available' => $isUnique,
    'consignment_note' => $note
]);

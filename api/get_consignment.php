<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';

startSecureSession();
requireLoginAPI();

header('Content-Type: application/json; charset=utf-8');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$note = isset($_GET['note']) ? trim($_GET['note']) : '';

if ($id <= 0 && $note === '') {
    jsonResponse(false, 'Consignment ID or note required / ID या नोट आवश्यक है', [], 400);
}

$conn = getDBConnection();

if ($note !== '') {
    $consignment = getConsignmentByNote($conn, $note);
} else {
    $consignment = getConsignmentById($conn, $id);
}

if (!$consignment) {
    jsonResponse(false, 'Consignment not found / कन्साइनमेंट नहीं मिला', [], 404);
}

jsonResponse(true, 'Consignment loaded / कन्साइनमेंट लोड', ['consignment' => $consignment]);

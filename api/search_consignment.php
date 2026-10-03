<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';

startSecureSession();
requireLoginAPI();

header('Content-Type: application/json; charset=utf-8');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($q === '') {
    jsonResponse(false, 'Search query required / खोज क्वेरी आवश्यक है', ['results' => []], 400);
}

$conn = getDBConnection();
$results = searchConsignmentsByNote($conn, $q, 20);

jsonResponse(true, count($results) . ' result(s) found / परिणाम मिले', ['results' => $results]);

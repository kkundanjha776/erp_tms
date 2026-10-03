<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';

startSecureSession();
requireLoginAPI();

header('Content-Type: application/json; charset=utf-8');

$conn = getDBConnection();
$stateCode = isset($_GET['state_code']) ? trim($_GET['state_code']) : null;
$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;

if ($query !== '') {
    $cities = searchCities($conn, $query, $stateCode, $limit);
} elseif ($stateCode !== null && $stateCode !== '') {
    $cities = getCitiesByState($conn, $stateCode);
} else {
    $cities = getAllCities($conn);
}

jsonResponse(true, 'Cities fetched / शहर प्राप्त', ['cities' => $cities]);

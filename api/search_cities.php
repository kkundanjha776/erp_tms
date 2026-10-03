<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/city_data.php';

startSecureSession();
requireLoginAPI();

header('Content-Type: application/json; charset=utf-8');

$conn = getDBConnection();
$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$stateCode = isset($_GET['state_code']) ? trim((string) $_GET['state_code']) : null;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;

if ($query === '') {
    jsonResponse(true, 'Enter a search term / खोज शब्द दर्ज करें', ['cities' => []]);
}

$cities = searchCities($conn, $query, $stateCode, $limit);
jsonResponse(true, 'Cities fetched / शहर प्राप्त', ['cities' => $cities]);

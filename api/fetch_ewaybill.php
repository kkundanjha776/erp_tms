<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ewaybill_service.php';

startSecureSession();
requireLoginAPI();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(false, 'Invalid CSRF token.', [], 403);
}

$rawNumbers = trim($_POST['ewb_numbers'] ?? '');
$single = trim($_POST['ewb_no'] ?? '');

$numbers = [];
if ($rawNumbers !== '') {
    $parts = preg_split('/[\s,;]+/', $rawNumbers);
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $numbers[] = $p;
    }
}
if ($single !== '') {
    $numbers[] = $single;
}
$numbers = array_values(array_unique($numbers));

if (empty($numbers)) {
    jsonResponse(false, 'Enter at least one 12-digit E-Way Bill number.', [], 422);
}

$service = new EwaybillService();
$results = $service->fetchMultiple($numbers);

$successCount = 0;
foreach ($results as $r) {
    if (!empty($r['success'])) $successCount++;
}

jsonResponse(true, "Fetched {$successCount} of " . count($results) . ' E-Way Bill(s).', [
    'results' => $results,
    'demo_mode' => isEwaybillDemoMode(),
    'api_configured' => isEwaybillConfigured(),
]);

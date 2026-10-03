<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';

startSecureSession();
requireLoginAPI();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(false, 'Invalid request', [], 403);
}

$conn = getDBConnection();
ensureClientContractSchema($conn);
ensureClientRiskColumns($conn);
$id = (int) ($_POST['id'] ?? 0);
$code = trim($_POST['client_code'] ?? '');
$name = trim($_POST['client_name'] ?? '');
if ($name === '') jsonResponse(false, 'Client name is required', [], 422);
if ($code === '') {
    $code = getNextClientCode($conn);
}

$fields = ['division', 'address', 'pin', 'gst_no', 'pan_no', 'phone', 'email', 'status'];
$values = [];
foreach ($fields as $field) $values[$field] = trim((string) ($_POST[$field] ?? ''));
$expiryRaw = trim((string) ($_POST['expiry_date'] ?? ''));
$expiryDate = $expiryRaw !== '' ? $expiryRaw : null;
$stateId = (int) ($_POST['state_id'] ?? 0) ?: null;
$cityId = (int) ($_POST['city_id'] ?? 0) ?: null;
$docket = max(0, (float) ($_POST['docket_charge'] ?? 0));
$oda = max(0, (float) ($_POST['oda_charge'] ?? 0));
$fuel = max(0, (float) ($_POST['fuel_charge'] ?? 0));
$riskPercent = max(0, (float) ($_POST['risk_charge_percent'] ?? 0));
$riskMin = max(0, (float) ($_POST['risk_minimum_charge'] ?? 0));

if ($id > 0) {
    $stmt = $conn->prepare('UPDATE client_masters SET client_code=?, client_name=?, division=?, address=?, state_id=?, city_id=?, pin=?, gst_no=?, pan_no=?, phone=?, email=?, docket_charge=?, oda_charge=?, fuel_charge=?, risk_charge_percent=?, risk_minimum_charge=?, status=?, expiry_date=? WHERE id=?');
    $stmt->bind_param('ssssiisssssdddddssi', $code, $name, $values['division'], $values['address'], $stateId, $cityId, $values['pin'], $values['gst_no'], $values['pan_no'], $values['phone'], $values['email'], $docket, $oda, $fuel, $riskPercent, $riskMin, $values['status'], $expiryDate, $id);
} else {
    $stmt = $conn->prepare('INSERT INTO client_masters (client_code, client_name, division, address, state_id, city_id, pin, gst_no, pan_no, phone, email, docket_charge, oda_charge, fuel_charge, risk_charge_percent, risk_minimum_charge, status, expiry_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('ssssiisssssdddddss', $code, $name, $values['division'], $values['address'], $stateId, $cityId, $values['pin'], $values['gst_no'], $values['pan_no'], $values['phone'], $values['email'], $docket, $oda, $fuel, $riskPercent, $riskMin, $values['status'], $expiryDate);
}
if (!$stmt || !$stmt->execute()) jsonResponse(false, 'Unable to save client: ' . $conn->error, [], 500);
$clientId = $id ?: (int) $conn->insert_id;
$stmt->close();

if ($id > 0) {
    $del = $conn->prepare('DELETE FROM client_lane_rates WHERE client_id = ?');
    $del->bind_param('i', $clientId);
    $del->execute();
    $del->close();
}

$origins = is_array($_POST['origin_city_id'] ?? null) ? $_POST['origin_city_id'] : [$_POST['origin_city_id'] ?? 0];
$destinations = is_array($_POST['destination_city_id'] ?? null) ? $_POST['destination_city_id'] : [$_POST['destination_city_id'] ?? 0];
$kgRates = is_array($_POST['rate_per_kg'] ?? null) ? $_POST['rate_per_kg'] : [$_POST['rate_per_kg'] ?? 0];
$pieceRates = is_array($_POST['rate_per_piece'] ?? null) ? $_POST['rate_per_piece'] : [$_POST['rate_per_piece'] ?? 0];
$kmRates = is_array($_POST['rate_per_km'] ?? null) ? $_POST['rate_per_km'] : [$_POST['rate_per_km'] ?? 0];
foreach ($origins as $i => $originValue) {
    $origin = (int) $originValue;
    $destination = (int) ($destinations[$i] ?? 0);
    if ($origin <= 0 || $destination <= 0) continue;
    $kg = max(0, (float) ($kgRates[$i] ?? 0));
    $piece = max(0, (float) ($pieceRates[$i] ?? 0));
    $km = max(0, (float) ($kmRates[$i] ?? 0));
    $lane = $conn->prepare('INSERT INTO client_lane_rates (client_id,origin_city_id,destination_city_id,rate_per_kg,rate_per_piece,rate_per_km,risk_charge_percent,risk_minimum_charge) VALUES (?,?,?,?,?,?,?,?)');
    $legacyLaneRisk = 0.0;
    $lane->bind_param('iiiddddd', $clientId, $origin, $destination, $kg, $piece, $km, $legacyLaneRisk, $legacyLaneRisk);
    if (!$lane->execute()) jsonResponse(false, 'Client saved but lane could not be saved', [], 500);
    $lane->close();
}

$laneCount = 0;
$countRes = $conn->prepare('SELECT COUNT(*) c FROM client_lane_rates WHERE client_id = ?');
$countRes->bind_param('i', $clientId);
$countRes->execute();
$row = $countRes->get_result()->fetch_assoc();
$countRes->close();
if ($row) $laneCount = (int)$row['c'];
$stateName = ''; $cityName = '';
$sc = $conn->prepare('SELECT s.state_name, c.city_name, cm.created_at, cm.updated_at, cm.expiry_date FROM client_masters cm LEFT JOIN states s ON s.id = cm.state_id LEFT JOIN cities c ON c.id = cm.city_id WHERE cm.id = ? LIMIT 1');
$sc->bind_param('i', $clientId);
$sc->execute();
$srow = $sc->get_result()->fetch_assoc();
$sc->close();
if ($srow) {
    $stateName = $srow['state_name'] ?? '';
    $cityName = $srow['city_name'] ?? '';
    $createdAt = $srow['created_at'] ?? '';
    $updatedAt = $srow['updated_at'] ?? '';
    $expiryDate = $srow['expiry_date'] ?? '';
}
jsonResponse(true, 'Client contract saved', [
    'client_id' => $clientId,
    'client' => [
        'id' => $clientId,
        'client_code' => $code,
        'client_name' => $name,
        'division' => $values['division'],
        'status' => $values['status'],
        'docket_charge' => $docket,
        'oda_charge' => $oda,
        'fuel_charge' => $fuel,
        'state_name' => $stateName,
        'city_name' => $cityName,
        'created_at' => $createdAt ?? '',
        'updated_at' => $updatedAt ?? '',
        'expiry_date' => $expiryDate ?? '',
    ],
    'lane_count' => $laneCount,
]);

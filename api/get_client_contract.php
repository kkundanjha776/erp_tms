<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';

startSecureSession();
requireLoginAPI();
header('Content-Type: application/json; charset=utf-8');

$clientId = (int) ($_GET['client_id'] ?? 0);
$originId = (int) ($_GET['origin_city_id'] ?? 0);
$destinationId = (int) ($_GET['destination_city_id'] ?? 0);
$quantity = max(0, (float) ($_GET['quantity'] ?? 0));
$declaredValue = max(0, (float) ($_GET['declared_value'] ?? 0));
$basis = strtolower(trim($_GET['billing_basis'] ?? 'kg'));

if ($clientId <= 0 || $originId <= 0 || $destinationId <= 0) {
    jsonResponse(false, 'Client, origin and destination are required', [], 422);
}

$conn = getDBConnection();
ensureClientContractSchema($conn);
    $stmt = $conn->prepare('SELECT cm.docket_charge, cm.oda_charge, cm.fuel_charge,
        cm.risk_charge_percent, cm.risk_minimum_charge,
        clr.rate_per_kg, clr.rate_per_piece, clr.rate_per_km
    FROM client_masters cm
    LEFT JOIN client_lane_rates clr ON clr.client_id = cm.id
        AND clr.origin_city_id = ? AND clr.destination_city_id = ?
    WHERE cm.id = ? AND cm.status = \'Active\' LIMIT 1');
$stmt->bind_param('iii', $originId, $destinationId, $clientId);
$stmt->execute();
$contract = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contract) {
    jsonResponse(false, 'Active client contract not found', [], 404);
}

$rateColumn = $basis === 'pieces' ? 'rate_per_piece' : ($basis === 'km' ? 'rate_per_km' : 'rate_per_kg');
$rate = (float) ($contract[$rateColumn] ?? 0);
$riskPercent = (float) ($contract['risk_charge_percent'] ?? 0);
$riskMinimum = (float) ($contract['risk_minimum_charge'] ?? 0);
$riskCharge = max($riskMinimum, $declaredValue * $riskPercent / 100);

jsonResponse(true, 'Contract charges retrieved', ['contract' => [
    'transport_charge' => round($rate * $quantity, 2),
    'applied_rate' => $rate,
    'quantity' => $quantity,
    'docket_charge' => (float) $contract['docket_charge'],
    'oda_charge' => (float) $contract['oda_charge'],
    'fuel_charge' => (float) $contract['fuel_charge'],
    'rate_per_kg' => (float) $contract['rate_per_kg'],
    'rate_per_piece' => (float) $contract['rate_per_piece'],
    'rate_per_km' => (float) $contract['rate_per_km'],
    'billing_basis' => $basis,
    'risk_charge_percent' => $riskPercent,
    'risk_minimum_charge' => $riskMinimum,
    'risk_charge' => round($riskCharge, 2),
]]);

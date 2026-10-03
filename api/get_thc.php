<?php
/**
 * API: Get THC detail (with LTs, manifests, touching points)
 * GET ?thc_id=N
 */
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';

startSecureSession();
requireLoginAPI();
header('Content-Type: application/json; charset=utf-8');

$conn  = getDBConnection();
$thcId = (int)($_GET['thc_id'] ?? 0);
if ($thcId <= 0) jsonResponse(false, 'thc_id required', [], 422);

ensureTHCSchema($conn);

$thc = getTHCById($conn, $thcId);
if (!$thc) jsonResponse(false, 'THC not found', [], 404);

// Loading tallies
$thc['loading_tallies'] = getLTsByTHC($conn, $thcId);

// Touching points
$tp = $conn->prepare("SELECT ttp.*, c.city_name
    FROM thc_touching_points ttp
    LEFT JOIN cities c ON c.id = ttp.city_id
    WHERE ttp.thc_id = ? ORDER BY ttp.point_sequence");
$tp->bind_param('i', $thcId);
$tp->execute();
$thc['touching_points'] = $tp->get_result()->fetch_all(MYSQLI_ASSOC);
$tp->close();

// Manifests
$mn = $conn->prepare("SELECT m.*, dc.city_name AS dest_name
    FROM thc_manifests m
    LEFT JOIN cities dc ON dc.id = m.destination_city_id
    WHERE m.thc_id = ? ORDER BY m.id");
$mn->bind_param('i', $thcId);
$mn->execute();
$thc['manifests'] = $mn->get_result()->fetch_all(MYSQLI_ASSOC);
$mn->close();

// Route points if route attached
if (!empty($thc['route_id'])) {
    $thc['route_points'] = getRoutePoints($conn, (int)$thc['route_id']);
} else {
    $thc['route_points'] = [];
}

jsonResponse(true, 'OK', ['thc' => $thc]);

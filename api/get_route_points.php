<?php
/**
 * API: Get route points for a given route_id
 * GET ?route_id=N  → returns points array with city names
 * GET ?all=1       → returns all active routes with their points (for THC form)
 */
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';

startSecureSession();
requireLoginAPI();

header('Content-Type: application/json; charset=utf-8');

$conn = getDBConnection();
ensureTHCSchema($conn);

if (!empty($_GET['all'])) {
    // Return all active routes with their point sequences
    $routes = $conn->query("SELECT rm.id, rm.route_code, rm.route_name,
        rm.origin_city_id, rm.destination_city_id, rm.transport_mode,
        oc.city_name AS origin_name, dc.city_name AS destination_name
        FROM route_masters rm
        LEFT JOIN cities oc ON oc.id = rm.origin_city_id
        LEFT JOIN cities dc ON dc.id = rm.destination_city_id
        WHERE rm.status='Active'
        ORDER BY rm.route_name");
    $data = [];
    if ($routes) {
        while ($r = $routes->fetch_assoc()) {
            $r['points'] = getRoutePoints($conn, (int)$r['id']);
            $data[] = $r;
        }
    }
    jsonResponse(true, 'OK', ['routes' => $data]);
}

$routeId = (int)($_GET['route_id'] ?? 0);
if ($routeId <= 0) {
    jsonResponse(false, 'route_id required', [], 422);
}

$points = getRoutePoints($conn, $routeId);
jsonResponse(true, 'OK', ['points' => $points]);

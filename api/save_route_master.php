<?php
/**
 * API: Save Route Master (create / update) + Delete
 * POST actions: save, delete
 */
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';

startSecureSession();
requireLoginAPI();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', [], 405);
}
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(false, 'Invalid CSRF token', [], 403);
}
if (!isAdminUser()) {
    jsonResponse(false, 'Admin access required', [], 403);
}

$conn   = getDBConnection();
$action = trim($_POST['action'] ?? 'save');
ensureTHCSchema($conn);

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['route_id'] ?? 0);
    if ($id <= 0) jsonResponse(false, 'Invalid route ID', [], 422);

    // Check no THC references this route
    $used = $conn->prepare("SELECT COUNT(*) AS n FROM thc WHERE route_id = ?");
    $used->bind_param('i', $id);
    $used->execute();
    $cnt = (int)$used->get_result()->fetch_assoc()['n'];
    $used->close();
    if ($cnt > 0) {
        jsonResponse(false, "Cannot delete: {$cnt} THC record(s) use this route. Set it Inactive instead.", [], 422);
    }

    $stmt = $conn->prepare("DELETE FROM route_masters WHERE id = ?");
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) jsonResponse(true, 'Route deleted');
    jsonResponse(false, 'Delete failed: ' . $conn->error, [], 500);
}

// ── SAVE (create / update) ────────────────────────────────────────────────────
$id          = (int)($_POST['route_id'] ?? 0);
$routeName   = trim($_POST['route_name'] ?? '');
$originId    = (int)($_POST['origin_city_id'] ?? 0);
$destId      = (int)($_POST['destination_city_id'] ?? 0);
$mode        = trim($_POST['transport_mode'] ?? 'Surface');
$distance    = strlen($_POST['total_distance_km'] ?? '') ? (float)$_POST['total_distance_km'] : null;
$status      = trim($_POST['status'] ?? 'Active');
// Points: JSON array of [{city_id, point_sequence, point_label}]
$pointsRaw   = trim($_POST['route_points'] ?? '[]');
$points      = json_decode($pointsRaw, true);

// Validation
$errors = [];
if ($routeName === '') $errors[] = 'Route name is required';
if ($originId <= 0)   $errors[] = 'Origin city is required';
if ($destId <= 0)     $errors[] = 'Destination city is required';
if ($originId === $destId && $originId > 0) $errors[] = 'Origin and destination cannot be the same';
$validModes = ['Air','FTL','Rail','Surface','Data Movement','Co-loader'];
if (!in_array($mode, $validModes, true)) $errors[] = 'Invalid transport mode';
if (!empty($errors)) jsonResponse(false, implode('; ', $errors), ['errors' => $errors], 422);

// Validate points array
if (!is_array($points)) $points = [];
foreach ($points as $i => $pt) {
    if (empty($pt['city_id']) || (int)$pt['city_id'] <= 0) {
        $errors[] = "Point #" . ($i+1) . ": city is required";
    }
}
if (!empty($errors)) jsonResponse(false, implode('; ', $errors), ['errors' => $errors], 422);

try {
    $conn->begin_transaction();

    if ($id > 0) {
        $distVal = $distance;
        $stmt = $conn->prepare("UPDATE route_masters SET
            route_name=?, origin_city_id=?, destination_city_id=?,
            transport_mode=?, total_distance_km=?, status=?, updated_at=NOW()
            WHERE id=?");
        $stmt->bind_param('siisdsi', $routeName, $originId, $destId, $mode, $distVal, $status, $id);
        $stmt->execute();
        $stmt->close();
        $routeId = $id;
    } else {
        // Auto route_code: RM-YYYY-NNNN
        $year = date('Y');
        $last = $conn->query("SELECT route_code FROM route_masters WHERE route_code LIKE 'RM-{$year}-%' ORDER BY id DESC LIMIT 1");
        $seq  = 1;
        if ($last && ($lr = $last->fetch_assoc())) {
            $parts = explode('-', $lr['route_code']);
            $seq   = (int)end($parts) + 1;
        }
        $routeCode = 'RM-' . $year . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
        $distVal   = $distance;
        $createdBy = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare("INSERT INTO route_masters
            (route_code, route_name, origin_city_id, destination_city_id,
             transport_mode, total_distance_km, status, created_by)
            VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param('ssiisdsi', $routeCode, $routeName, $originId, $destId,
                                      $mode, $distVal, $status, $createdBy);
        $stmt->execute();
        $routeId = (int)$conn->insert_id;
        $stmt->close();
    }

    // Replace route points
    $delPts = $conn->prepare("DELETE FROM route_points WHERE route_id = ?");
    $delPts->bind_param('i', $routeId);
    $delPts->execute();
    $delPts->close();

    if (!empty($points)) {
        $insPt = $conn->prepare("INSERT INTO route_points
            (route_id, city_id, point_sequence, point_label) VALUES (?,?,?,?)");
        foreach ($points as $pt) {
            $cid   = (int)$pt['city_id'];
            $seq   = (int)$pt['point_sequence'];
            $label = trim($pt['point_label'] ?? '');
            $insPt->bind_param('iiis', $routeId, $cid, $seq, $label);
            $insPt->execute();
        }
        $insPt->close();
    }

    $conn->commit();
    jsonResponse(true, $id > 0 ? 'Route updated successfully' : 'Route created successfully',
        ['route_id' => $routeId]);

} catch (Exception $e) {
    $conn->rollback();
    error_log('save_route_master error: ' . $e->getMessage());
    jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
}

<?php
/**
 * API: Save THC (Trip Hire Contract) - create / update / delete
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

$conn   = getDBConnection();
$action = trim($_POST['action'] ?? 'save');
ensureTHCSchema($conn);

// ── DELETE ────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['thc_id'] ?? 0);
    if ($id <= 0) jsonResponse(false, 'Invalid THC ID', [], 422);

    $check = $conn->prepare("SELECT status FROM thc WHERE id = ? LIMIT 1");
    $check->bind_param('i', $id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$row) jsonResponse(false, 'THC not found', [], 404);
    if (in_array($row['status'], ['Dispatched','Completed'], true)) {
        jsonResponse(false, 'Cannot delete a Dispatched or Completed THC', [], 422);
    }
    $stmt = $conn->prepare("DELETE FROM thc WHERE id = ?");
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    jsonResponse($ok, $ok ? 'THC deleted' : 'Delete failed', [], $ok ? 200 : 500);
}

// ── SAVE ──────────────────────────────────────────────────────────────────
$id        = (int)($_POST['thc_id'] ?? 0);
$thcDate   = trim($_POST['thc_date'] ?? '');
$originId  = (int)($_POST['origin_city_id'] ?? 0);
$destId    = (int)($_POST['destination_city_id'] ?? 0);
$mode      = trim($_POST['transport_mode'] ?? 'Surface');
$routeId   = ((int)($_POST['route_id'] ?? 0)) ?: null;
$vendor    = trim($_POST['vendor_name'] ?? '');
$vehicleNo = strtoupper(trim($_POST['vehicle_no'] ?? ''));
$driver1   = trim($_POST['driver1_name'] ?? '');
$driver1ph = trim($_POST['driver1_phone'] ?? '');
$driver2   = trim($_POST['driver2_name'] ?? '');
$driver2ph = trim($_POST['driver2_phone'] ?? '');
$contract  = (float)($_POST['contract_amount'] ?? 0);
$other     = (float)($_POST['other_amount'] ?? 0);
$advance   = (float)($_POST['advance_amount'] ?? 0);
$deduction = (float)($_POST['deduction_amount'] ?? 0);
$remarks   = trim($_POST['remarks'] ?? '');
$status    = trim($_POST['status'] ?? 'Draft');
$userId    = (int)($_SESSION['user_id'] ?? 0);

// Server-side recalc
$total   = round($contract + $other, 2);
$balance = round($total - $advance - $deduction, 2);

// Validation
$errors = [];
if (empty($thcDate)) $errors[] = 'THC Date is required';
if ($originId <= 0)  $errors[] = 'Origin city is required';
if ($destId   <= 0)  $errors[] = 'Destination city is required';
if (!in_array($mode, ['Air','FTL','Rail','Surface','Data Movement','Co-loader'], true))
    $errors[] = 'Invalid transport mode';
if (!empty($errors)) jsonResponse(false, implode('; ', $errors), ['errors' => $errors], 422);

try {
    $conn->begin_transaction();

    if ($id > 0) {
        // ── UPDATE ────────────────────────────────────────────────────────
        $ex = $conn->prepare("SELECT id FROM thc WHERE id = ? LIMIT 1");
        $ex->bind_param('i', $id);
        $ex->execute();
        if (!$ex->get_result()->fetch_assoc()) {
            $conn->rollback();
            jsonResponse(false, 'THC not found', [], 404);
        }
        $ex->close();

        // 20 params: s i i s i s s s s s s d d d d d d s s i
        $stmt = $conn->prepare("UPDATE thc SET
            thc_date=?, origin_city_id=?, destination_city_id=?, transport_mode=?,
            route_id=?, vendor_name=?, vehicle_no=?, driver1_name=?, driver1_phone=?,
            driver2_name=?, driver2_phone=?, contract_amount=?, other_amount=?,
            total_amount=?, advance_amount=?, deduction_amount=?, balance_amount=?,
            remarks=?, status=?, updated_at=NOW()
            WHERE id=?");
        if (!$stmt) throw new Exception('Prepare UPDATE failed: ' . $conn->error);
        $stmt->bind_param(
            'siisissssssddddddssi',
            // s     i         i       s
            $thcDate, $originId, $destId, $mode,
            // i        s        s          s        s
            $routeId, $vendor, $vehicleNo, $driver1, $driver1ph,
            // s        s          d          d       d       d
            $driver2, $driver2ph, $contract, $other, $total, $advance,
            // d          d         s        s       i
            $deduction, $balance, $remarks, $status, $id
        );
        $stmt->execute();
        $stmt->close();
        $thcId = $id;
        $msg   = 'THC updated successfully';
        $thcNo = '';

    } else {
        // ── INSERT ────────────────────────────────────────────────────────
        $thcNo = getNextTHCNo($conn);

        // 21 params: s s i i s i s s s s s s d d d d d d s s i
        $stmt = $conn->prepare("INSERT INTO thc
            (thc_no, thc_date, origin_city_id, destination_city_id, transport_mode,
             route_id, vendor_name, vehicle_no, driver1_name, driver1_phone,
             driver2_name, driver2_phone, contract_amount, other_amount,
             total_amount, advance_amount, deduction_amount, balance_amount,
             remarks, status, created_by)
            VALUES (?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,?)");
        if (!$stmt) throw new Exception('Prepare INSERT failed: ' . $conn->error);
        $stmt->bind_param(
            'ssiisissssssddddddssi',
            // s      s         i         i       s
            $thcNo, $thcDate, $originId, $destId, $mode,
            // i        s        s          s        s
            $routeId, $vendor, $vehicleNo, $driver1, $driver1ph,
            // s        s          d          d       d       d
            $driver2, $driver2ph, $contract, $other, $total, $advance,
            // d          d         s        s       i
            $deduction, $balance, $remarks, $status, $userId
        );
        $stmt->execute();
        $thcId = (int)$conn->insert_id;
        $stmt->close();

        // Auto-create touching points from route
        if ($routeId) {
            $pts   = getRoutePoints($conn, $routeId);
            $insTp = $conn->prepare("INSERT INTO thc_touching_points
                (thc_id, route_point_id, city_id, point_sequence) VALUES (?,?,?,?)");
            foreach ($pts as $pt) {
                $rpId = (int)$pt['id'];
                $cid  = (int)$pt['city_id'];
                $seq  = (int)$pt['point_sequence'];
                $insTp->bind_param('iiii', $thcId, $rpId, $cid, $seq);
                $insTp->execute();
            }
            $insTp->close();
        }

        $msg = 'THC created successfully';
    }

    $conn->commit();

    // Fetch thc_no for response
    if (empty($thcNo)) {
        $rr    = $conn->query("SELECT thc_no FROM thc WHERE id=" . (int)$thcId);
        $thcNo = ($rr && ($rrow = $rr->fetch_assoc())) ? $rrow['thc_no'] : '';
    }

    jsonResponse(true, $msg, ['thc_id' => $thcId, 'thc_no' => $thcNo]);

} catch (Exception $e) {
    $conn->rollback();
    error_log('save_thc error: ' . $e->getMessage());
    jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
}

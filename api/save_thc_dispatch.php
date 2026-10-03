<?php
/**
 * API: THC Dispatch & Touching Point operations
 *
 * actions:
 *   dispatch_thc       → mark THC as Dispatched, set all LTs to Dispatched
 *   arrive_tp          → mark touching point arrival
 *   save_unloading     → save unloading tally items for a touching point
 *   dispatch_from_tp   → dispatch from a touching point to next
 *   complete_thc       → mark THC as Completed (final destination reached)
 */
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';

startSecureSession();
requireLoginAPI();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid method', [], 405);
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) jsonResponse(false, 'Invalid CSRF token', [], 403);

$conn   = getDBConnection();
$action = trim($_POST['action'] ?? '');
ensureTHCSchema($conn);
$userId = (int)($_SESSION['user_id'] ?? 0);

// Helper: fetch THC
function fetchTHC(mysqli $c, int $id): array {
    $s = $c->prepare("SELECT * FROM thc WHERE id=? LIMIT 1");
    $s->bind_param('i', $id); $s->execute();
    $r = $s->get_result()->fetch_assoc(); $s->close();
    return $r ?: [];
}

// ── DISPATCH THC ───────────────────────────────────────────────────────────
if ($action === 'dispatch_thc') {
    $thcId = (int)($_POST['thc_id'] ?? 0);
    if ($thcId <= 0) jsonResponse(false, 'thc_id required', [], 422);

    $thc = fetchTHC($conn, $thcId);
    if (!$thc) jsonResponse(false, 'THC not found', [], 404);
    if ($thc['status'] === 'Dispatched') jsonResponse(false, 'THC already dispatched', [], 422);
    if ($thc['status'] === 'Completed')  jsonResponse(false, 'THC already completed', [], 422);

    // Need at least one LT with dockets
    $ltCheck = $conn->query("SELECT COUNT(*) AS n FROM loading_tally_items WHERE thc_id=" . (int)$thcId);
    $ltCnt = (int)($ltCheck->fetch_assoc()['n'] ?? 0);
    if ($ltCnt === 0) jsonResponse(false, 'No dockets in Loading Tally. Cannot dispatch.', [], 422);

    try {
        $conn->begin_transaction();
        $conn->query("UPDATE thc SET status='Dispatched', dispatched_at=NOW() WHERE id=" . (int)$thcId);
        $conn->query("UPDATE loading_tally SET status='Dispatched' WHERE thc_id=" . (int)$thcId);
        // Mark first touching point as Pending→Pending (already is; just ensure records exist)
        $conn->commit();
        jsonResponse(true, 'THC dispatched successfully');
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}

// ── ARRIVE AT TOUCHING POINT ───────────────────────────────────────────────
if ($action === 'arrive_tp') {
    $thcId  = (int)($_POST['thc_id'] ?? 0);
    $tpId   = (int)($_POST['tp_id'] ?? 0);
    $dt     = trim($_POST['arrival_datetime'] ?? date('Y-m-d H:i:s'));
    if ($thcId <= 0 || $tpId <= 0) jsonResponse(false, 'thc_id and tp_id required', [], 422);

    $stmt = $conn->prepare("UPDATE thc_touching_points SET
        arrival_datetime=?, status='Arrived', handled_by=?, updated_at=NOW()
        WHERE id=? AND thc_id=?");
    $stmt->bind_param('siii', $dt, $userId, $tpId, $thcId);
    $stmt->execute();
    $stmt->close();
    jsonResponse(true, 'Arrival recorded');
}

// ── SAVE UNLOADING TALLY ───────────────────────────────────────────────────
if ($action === 'save_unloading') {
    $thcId  = (int)($_POST['thc_id'] ?? 0);
    $tpId   = (int)($_POST['tp_id'] ?? 0);
    // items: JSON array [{consignment_id, received_packages, original_packages, short_count, damaged_count, short_reason, damage_reason, remarks}]
    $itemsRaw = trim($_POST['items'] ?? '[]');
    $items = json_decode($itemsRaw, true);
    if (!is_array($items)) $items = [];
    if ($thcId <= 0) jsonResponse(false, 'thc_id required', [], 422);

    try {
        $conn->begin_transaction();
        // Delete existing for this tp
        $del = $conn->prepare("DELETE FROM thc_unloading_tally WHERE thc_id=? AND touching_point_id=?");
        $del->bind_param('ii', $thcId, $tpId);
        $del->execute();
        $del->close();

        $ins = $conn->prepare("INSERT INTO thc_unloading_tally
            (thc_id, touching_point_id, consignment_id, received_packages, original_packages,
             short_count, damaged_count, short_reason, damage_reason, remarks, received_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($items as $it) {
            $cid     = (int)($it['consignment_id'] ?? 0);
            if ($cid <= 0) continue;
            $recPkg  = (int)($it['received_packages'] ?? 0);
            $origPkg = (int)($it['original_packages'] ?? 0);
            $short   = (int)($it['short_count'] ?? 0);
            $dam     = (int)($it['damaged_count'] ?? 0);
            $sReason = substr(trim($it['short_reason']  ?? ''), 0, 200);
            $dReason = substr(trim($it['damage_reason'] ?? ''), 0, 200);
            $rem     = substr(trim($it['remarks']       ?? ''), 0, 200);
            $tpBind  = $tpId ?: null;
            $ins->bind_param('iiiiiiisssi',
                $thcId, $tpBind, $cid, $recPkg, $origPkg,
                $short, $dam, $sReason, $dReason, $rem, $userId);
            $ins->execute();
        }
        $ins->close();

        // Mark TP as Unloaded
        if ($tpId > 0) {
            $stmt = $conn->prepare("UPDATE thc_touching_points SET status='Unloaded' WHERE id=? AND thc_id=?");
            $stmt->bind_param('ii', $tpId, $thcId);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
        jsonResponse(true, 'Unloading tally saved');
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}

// ── DISPATCH FROM TOUCHING POINT ──────────────────────────────────────────
if ($action === 'dispatch_from_tp') {
    $thcId  = (int)($_POST['thc_id'] ?? 0);
    $tpId   = (int)($_POST['tp_id'] ?? 0);
    $dt     = trim($_POST['departure_datetime'] ?? date('Y-m-d H:i:s'));
    if ($thcId <= 0 || $tpId <= 0) jsonResponse(false, 'thc_id and tp_id required', [], 422);

    $stmt = $conn->prepare("UPDATE thc_touching_points SET
        departure_datetime=?, status='Dispatched', updated_at=NOW()
        WHERE id=? AND thc_id=?");
    $stmt->bind_param('sii', $dt, $tpId, $thcId);
    $stmt->execute();
    $stmt->close();
    jsonResponse(true, 'Dispatched from touching point');
}

// ── COMPLETE THC ───────────────────────────────────────────────────────────
if ($action === 'complete_thc') {
    $thcId = (int)($_POST['thc_id'] ?? 0);
    if ($thcId <= 0) jsonResponse(false, 'thc_id required', [], 422);

    $stmt = $conn->prepare("UPDATE thc SET status='Completed', completed_at=NOW() WHERE id=? AND status='Dispatched'");
    $stmt->bind_param('i', $thcId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected === 0) jsonResponse(false, 'THC not found or not in Dispatched state', [], 422);
    jsonResponse(true, 'THC marked as Completed');
}

jsonResponse(false, 'Unknown action: ' . htmlspecialchars($action), [], 422);

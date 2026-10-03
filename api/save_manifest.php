<?php
/**
 * API: Save / Generate THC Manifest
 * POST actions: generate_auto | save_manual | delete
 *
 * generate_auto:
 *   thc_id, lt_id (optional) → auto-creates manifests grouped by route destination
 *
 * save_manual:
 *   thc_id, lt_id, manifest_date, destination_city_id, docket_ids[], remarks
 *
 * delete:
 *   manifest_id
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
$action = trim($_POST['action'] ?? 'save_manual');
ensureTHCSchema($conn);
$userId = (int)($_SESSION['user_id'] ?? 0);

// ── DELETE ─────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $mId = (int)($_POST['manifest_id'] ?? 0);
    if ($mId <= 0) jsonResponse(false, 'manifest_id required', [], 422);
    $stmt = $conn->prepare("DELETE FROM thc_manifests WHERE id=? AND status NOT IN ('Dispatched','Delivered')");
    $stmt->bind_param('i', $mId);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected === 0) jsonResponse(false, 'Manifest not found or already dispatched', [], 422);
    jsonResponse(true, 'Manifest deleted');
}

// ── AUTO GENERATE ──────────────────────────────────────────────────────────
if ($action === 'generate_auto') {
    $thcId = (int)($_POST['thc_id'] ?? 0);
    $ltId  = (int)($_POST['lt_id'] ?? 0);
    if ($thcId <= 0) jsonResponse(false, 'thc_id required', [], 422);

    // Get all dockets in this LT (or all LTs for this THC)
    if ($ltId > 0) {
        $stmt = $conn->prepare("SELECT lti.consignment_id, c.destination_city_id, dc.city_name AS dest_name
            FROM loading_tally_items lti
            JOIN consignments c ON c.id = lti.consignment_id
            LEFT JOIN cities dc ON dc.id = c.destination_city_id
            WHERE lti.lt_id = ?");
        $stmt->bind_param('i', $ltId);
    } else {
        $stmt = $conn->prepare("SELECT lti.consignment_id, c.destination_city_id, dc.city_name AS dest_name
            FROM loading_tally_items lti
            JOIN consignments c ON c.id = lti.consignment_id
            LEFT JOIN cities dc ON dc.id = c.destination_city_id
            WHERE lti.thc_id = ?");
        $stmt->bind_param('i', $thcId);
    }
    $stmt->execute();
    $dockets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($dockets)) jsonResponse(false, 'No dockets found in Loading Tally', [], 422);

    // Get route points to map sequence
    $thc = getTHCById($conn, $thcId);
    $routePts = $thc['route_id'] ? getRoutePoints($conn, (int)$thc['route_id']) : [];
    $routeCityIds = array_column($routePts, 'city_id');

    // Group dockets by destination
    $groups = [];
    foreach ($dockets as $d) {
        $destId = (int)$d['destination_city_id'];
        if (!isset($groups[$destId])) {
            $groups[$destId] = ['dest_id' => $destId, 'dest_name' => $d['dest_name'], 'items' => []];
        }
        $groups[$destId]['items'][] = (int)$d['consignment_id'];
    }

    $manifDate = date('Y-m-d');
    $created = 0;
    try {
        $conn->begin_transaction();
        $insMnf = $conn->prepare("INSERT INTO thc_manifests
            (manifest_no, thc_id, lt_id, manifest_date, manifest_type,
             destination_city_id, status, created_by)
            VALUES (?,?,?,?,'Auto',?,'Saved',?)");
        $insMi = $conn->prepare("INSERT IGNORE INTO thc_manifest_items
            (manifest_id, consignment_id) VALUES (?,?)");

        foreach ($groups as $destId => $g) {
            $mNo = getNextManifestNo($conn, $thcId);
            $ltIdBind = $ltId ?: null;
            $insMnf->bind_param('siisii', $mNo, $thcId, $ltIdBind, $manifDate, $destId, $userId);
            $insMnf->execute();
            $mId = (int)$conn->insert_id;
            foreach ($g['items'] as $cid) {
                $insMi->bind_param('ii', $mId, $cid);
                $insMi->execute();
            }
            $created++;
        }
        $insMnf->close();
        $insMi->close();
        $conn->commit();
        jsonResponse(true, "Auto manifest generated: {$created} manifest(s) created", ['count' => $created]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}

// ── MANUAL SAVE ────────────────────────────────────────────────────────────
$thcId     = (int)($_POST['thc_id'] ?? 0);
$ltId      = (int)($_POST['lt_id'] ?? 0) ?: null;
$mId       = (int)($_POST['manifest_id'] ?? 0);
$manifDate = trim($_POST['manifest_date'] ?? date('Y-m-d'));
$destId    = (int)($_POST['destination_city_id'] ?? 0);
$docketIds = array_map('intval', (array)($_POST['docket_ids'] ?? []));
$docketIds = array_filter($docketIds);
$remarks   = trim($_POST['remarks'] ?? '');

$errors = [];
if ($thcId <= 0) $errors[] = 'thc_id required';
if ($destId <= 0) $errors[] = 'Destination city required';
if (empty($docketIds)) $errors[] = 'At least one docket required';
if (!empty($errors)) jsonResponse(false, implode('; ', $errors), ['errors' => $errors], 422);

try {
    $conn->begin_transaction();

    if ($mId > 0) {
        // Update header
        $stmt = $conn->prepare("UPDATE thc_manifests SET
            manifest_date=?, manifest_type='Manual', destination_city_id=?,
            remarks=?, updated_at=NOW() WHERE id=? AND thc_id=?");
        $stmt->bind_param('sisii', $manifDate, $destId, $remarks, $mId, $thcId);
        $stmt->execute();
        $stmt->close();
        // Replace items
        $del = $conn->prepare("DELETE FROM thc_manifest_items WHERE manifest_id=?");
        $del->bind_param('i', $mId);
        $del->execute();
        $del->close();
    } else {
        $mNo  = getNextManifestNo($conn, $thcId);
        $stmt = $conn->prepare("INSERT INTO thc_manifests
            (manifest_no, thc_id, lt_id, manifest_date, manifest_type,
             destination_city_id, remarks, status, created_by)
            VALUES (?,?,?,?,'Manual',?,?,'Saved',?)");
        $stmt->bind_param('siisssi', $mNo, $thcId, $ltId, $manifDate, $destId, $remarks, $userId);
        $stmt->execute();
        $mId = (int)$conn->insert_id;
        $stmt->close();
    }

    $ins = $conn->prepare("INSERT IGNORE INTO thc_manifest_items (manifest_id, consignment_id) VALUES (?,?)");
    foreach ($docketIds as $cid) {
        $ins->bind_param('ii', $mId, $cid);
        $ins->execute();
    }
    $ins->close();

    $conn->commit();
    jsonResponse(true, 'Manifest saved', ['manifest_id' => $mId]);

} catch (Exception $e) {
    $conn->rollback();
    error_log('save_manifest error: ' . $e->getMessage());
    jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
}

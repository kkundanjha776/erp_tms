<?php
/**
 * API: Save Loading Tally
 * POST actions: save | remove_docket | delete_lt
 *
 * save:
 *   thc_id, lt_id (0=new), lt_date, remarks
 *   add_consignment_ids[] - docket IDs to add (optional)
 *
 * remove_docket:
 *   lt_id, consignment_id
 *
 * delete_lt:
 *   lt_id
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
$action = trim($_POST['action'] ?? 'save');
ensureTHCSchema($conn);

// ── REMOVE SINGLE DOCKET ──────────────────────────────────────────────────
if ($action === 'remove_docket') {
    $ltId  = (int)($_POST['lt_id'] ?? 0);
    $conId = (int)($_POST['consignment_id'] ?? 0);
    if ($ltId <= 0 || $conId <= 0) jsonResponse(false, 'lt_id and consignment_id required', [], 422);

    $stmt = $conn->prepare("DELETE FROM loading_tally_items WHERE lt_id=? AND consignment_id=?");
    $stmt->bind_param('ii', $ltId, $conId);
    $ok = $stmt->execute();
    $stmt->close();
    jsonResponse($ok, $ok ? 'Docket removed' : 'Remove failed');
}

// ── DELETE LOADING TALLY ──────────────────────────────────────────────────
if ($action === 'delete_lt') {
    $ltId = (int)($_POST['lt_id'] ?? 0);
    if ($ltId <= 0) jsonResponse(false, 'lt_id required', [], 422);

    $chk = $conn->prepare("SELECT status FROM loading_tally WHERE id=? LIMIT 1");
    $chk->bind_param('i', $ltId);
    $chk->execute();
    $lt = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$lt) jsonResponse(false, 'LT not found', [], 404);
    if ($lt['status'] === 'Dispatched') jsonResponse(false, 'Cannot delete a dispatched Loading Tally', [], 422);

    $stmt = $conn->prepare("DELETE FROM loading_tally WHERE id=?");
    $stmt->bind_param('i', $ltId);
    $ok = $stmt->execute();
    $stmt->close();
    jsonResponse($ok, $ok ? 'Loading Tally deleted' : 'Delete failed');
}

// ── SAVE / ADD DOCKETS ────────────────────────────────────────────────────
$thcId    = (int)($_POST['thc_id'] ?? 0);
$ltId     = (int)($_POST['lt_id'] ?? 0);
$ltDate   = trim($_POST['lt_date'] ?? date('Y-m-d'));
$remarks  = trim($_POST['remarks'] ?? '');
$addIds   = array_map('intval', (array)($_POST['add_consignment_ids'] ?? []));
$addIds   = array_filter($addIds);
$userId   = (int)($_SESSION['user_id'] ?? 0);

if ($thcId <= 0) jsonResponse(false, 'thc_id is required', [], 422);

// Verify THC exists
$thcRow = $conn->prepare("SELECT id, status FROM thc WHERE id=? LIMIT 1");
$thcRow->bind_param('i', $thcId);
$thcRow->execute();
$thc = $thcRow->get_result()->fetch_assoc();
$thcRow->close();
if (!$thc) jsonResponse(false, 'THC not found', [], 404);

try {
    $conn->begin_transaction();

    // Create or verify Loading Tally header
    if ($ltId > 0) {
        $ltChk = $conn->prepare("SELECT id FROM loading_tally WHERE id=? AND thc_id=? LIMIT 1");
        $ltChk->bind_param('ii', $ltId, $thcId);
        $ltChk->execute();
        if (!$ltChk->get_result()->fetch_assoc()) {
            $conn->rollback();
            jsonResponse(false, 'Loading Tally not found for this THC', [], 404);
        }
        $ltChk->close();
        // Update header
        $upd = $conn->prepare("UPDATE loading_tally SET lt_date=?, remarks=?, updated_at=NOW() WHERE id=?");
        $upd->bind_param('ssi', $ltDate, $remarks, $ltId);
        $upd->execute();
        $upd->close();
    } else {
        $ltNo  = getNextLTNo($conn, $thcId);
        $ins   = $conn->prepare("INSERT INTO loading_tally (lt_no, thc_id, lt_date, remarks, status, created_by)
                                 VALUES (?,?,?,?,'Draft',?)");
        $ins->bind_param('sissi', $ltNo, $thcId, $ltDate, $remarks, $userId);
        $ins->execute();
        $ltId  = (int)$conn->insert_id;
        $ins->close();
    }

    // Add dockets — skip already-assigned ones (duplicate prevention)
    $skipped = []; $added = 0;
    if (!empty($addIds)) {
        $insItem = $conn->prepare("INSERT IGNORE INTO loading_tally_items
            (lt_id, thc_id, consignment_id, added_by) VALUES (?,?,?,?)");
        foreach ($addIds as $cid) {
            // Check not already in any LT
            $dup = $conn->prepare("SELECT lt_id FROM loading_tally_items WHERE consignment_id=? LIMIT 1");
            $dup->bind_param('i', $cid);
            $dup->execute();
            $dupRow = $dup->get_result()->fetch_assoc();
            $dup->close();
            if ($dupRow) {
                $skipped[] = $cid;
                continue;
            }
            $insItem->bind_param('iiii', $ltId, $thcId, $cid, $userId);
            $insItem->execute();
            $added++;
        }
        $insItem->close();
    }

    // Update THC status to Active if Draft
    if ($thc['status'] === 'Draft') {
        $conn->query("UPDATE thc SET status='Active' WHERE id=" . (int)$thcId);
    }

    $conn->commit();

    $msg = 'Loading Tally saved.';
    if ($added > 0) $msg .= " {$added} docket(s) added.";
    if (!empty($skipped)) $msg .= ' ' . count($skipped) . ' already assigned (skipped).';

    jsonResponse(true, $msg, [
        'lt_id'   => $ltId,
        'added'   => $added,
        'skipped' => $skipped,
    ]);

} catch (Exception $e) {
    $conn->rollback();
    error_log('save_loading_tally error: ' . $e->getMessage());
    jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
}

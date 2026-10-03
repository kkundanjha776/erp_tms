<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';

startSecureSession();
requireLoginAPI();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', [], 405);
}

$id = (int) ($_POST['id'] ?? 0);
$rate = round((float) ($_POST['rate'] ?? 0), 2);
if ($id <= 0 || $rate < 0 || $rate > 100) {
    jsonResponse(false, 'Enter a GST rate between 0 and 100', [], 422);
}

$conn = getDBConnection();
getGSTRates($conn);
$conn->begin_transaction();

// Older versions could recreate 18% after it had been edited to another
// value. Remove that duplicate first, so changing a rate back is always valid.
$duplicate = $conn->prepare('DELETE FROM gst_rates WHERE rate = ? AND id <> ?');
if (!$duplicate) jsonResponse(false, 'Unable to update GST master', [], 500);
$duplicate->bind_param('di', $rate, $id);
if (!$duplicate->execute()) {
    $duplicate->close();
    $conn->rollback();
    jsonResponse(false, 'Unable to update GST master', [], 500);
}
$duplicate->close();

$stmt = $conn->prepare('UPDATE gst_rates SET rate = ? WHERE id = ?');
if (!$stmt) {
    $conn->rollback();
    jsonResponse(false, 'Unable to update GST master', [], 500);
}
$stmt->bind_param('di', $rate, $id);
if (!$stmt->execute()) {
    $message = $stmt->errno === 1062 ? 'This GST rate already exists' : 'Unable to update GST master';
    $stmt->close();
    $conn->rollback();
    jsonResponse(false, $message, [], 422);
}
$stmt->close();
$conn->commit();
jsonResponse(true, 'GST master updated', ['id' => $id, 'rate' => $rate]);

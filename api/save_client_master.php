<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();
requireLoginAPI();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method / अमान्य अनुरोध', [], 405);
}

$conn = getDBConnection();
$partyType = strtolower(trim($_POST['party_type'] ?? 'billing'));
$partyName = trim($_POST['party_name'] ?? '');

if ($partyName === '') {
    jsonResponse(false, 'Party name is required / पार्टी का नाम आवश्यक है', [], 422);
}

$fieldPrefix = $partyType === 'consignee' ? 'consignee_' : '';
$address = trim($_POST[$fieldPrefix . 'address'] ?? $_POST['address'] ?? '');
$cityId = isset($_POST[$fieldPrefix . 'city_id']) ? (int) $_POST[$fieldPrefix . 'city_id'] : (isset($_POST['city_id']) ? (int) $_POST['city_id'] : 0);
$stateId = isset($_POST[$fieldPrefix . 'state_id']) ? (int) $_POST[$fieldPrefix . 'state_id'] : (isset($_POST['state_id']) ? (int) $_POST['state_id'] : 0);
$pin = trim($_POST[$fieldPrefix . 'pin'] ?? $_POST['pin'] ?? '');
$phone = trim($_POST[$fieldPrefix . 'phone'] ?? $_POST['phone'] ?? '');
$gstNo = trim($_POST[$fieldPrefix . 'gst_no'] ?? $_POST['gst_no'] ?? '');

$stmt = $conn->prepare(
    "INSERT INTO party_masters (party_name, address, city_id, state_id, pin, phone, gst_no)
     VALUES (?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
     address = VALUES(address),
     city_id = VALUES(city_id),
     state_id = VALUES(state_id),
     pin = VALUES(pin),
     phone = VALUES(phone),
     gst_no = VALUES(gst_no)"
);

if (!$stmt) {
    jsonResponse(false, 'Unable to save client master / क्लाइंट मास्टर सहेजने में असमर्थ', [], 500);
}

$stmt->bind_param('ssiiiss', $partyName, $address, $cityId, $stateId, $pin, $phone, $gstNo);

if (!$stmt->execute()) {
    $stmt->close();
    jsonResponse(false, 'Database error / डेटाबेस त्रुटि', [], 500);
}

$stmt->close();
jsonResponse(true, 'Client master saved / क्लाइंट मास्टर सहेजा गया', [
    'party_name' => $partyName
]);

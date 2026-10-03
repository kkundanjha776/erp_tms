<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ewaybill_service.php';

startSecureSession();
requireLoginAPI();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(false, 'Invalid CSRF token.', [], 403);
}

$payload = $_POST['entries'] ?? '';
if (is_string($payload)) {
    $entries = json_decode($payload, true);
} else {
    $entries = $payload;
}

if (!is_array($entries) || empty($entries)) {
    jsonResponse(false, 'No E-Way Bill entries to save.', [], 422);
}

$saveStatus = in_array($_POST['save_status'] ?? 'Draft', ['Draft', 'Submitted'], true)
    ? $_POST['save_status']
    : 'Draft';

$conn = getDBConnection();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$saved = [];
$errors = [];

foreach ($entries as $idx => $entry) {
    if (!is_array($entry)) {
        $errors[] = 'Entry #' . ($idx + 1) . ' is invalid.';
        continue;
    }
    try {
        $entry['save_status'] = $saveStatus;
        if (!empty($entry['demo'])) {
            $entry['is_demo'] = 1;
        }
        if (!empty($entry['raw'])) {
            $entry['raw_response'] = is_string($entry['raw']) ? $entry['raw'] : json_encode($entry['raw'], JSON_UNESCAPED_UNICODE);
        }
        $id = saveEwaybillDocket($conn, $entry, $userId);
        $saved[] = ['id' => $id, 'ewb_no' => preg_replace('/\D/', '', (string) ($entry['ewb_no'] ?? ''))];
    } catch (Throwable $e) {
        $errors[] = 'EWB ' . ($entry['ewb_no'] ?? '?') . ': ' . $e->getMessage();
    }
}

if (empty($saved)) {
    jsonResponse(false, 'Failed to save entries.', ['errors' => $errors], 422);
}

$msg = count($saved) . ' E-Way Bill docket(s) saved successfully.';
if (!empty($errors)) {
    $msg .= ' Some entries failed.';
}

jsonResponse(true, $msg, ['saved' => $saved, 'errors' => $errors]);

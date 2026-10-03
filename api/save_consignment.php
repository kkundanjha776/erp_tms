<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/master_data.php';

if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    startSecureSession();
    requireLoginAPI();

    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method / अमान्य अनुरोध', [], 405);
    }

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        jsonResponse(false, 'Invalid CSRF token / अमान्य CSRF टोकन', [], 403);
    }

    $conn = getDBConnection();
    $data = collectConsignmentDataFromPost();
    $action = getPostValue('action', 'draft');
    $status = ($action === 'submit') ? 'Submitted' : 'Draft';

    // Save each LxBxH row. In automatic mode, charge whichever is higher:
    // actual weight or total LxBxH volumetric weight. Manual mode retains the
    // entered charge weight, but never allows it below actual weight.
    $dimensionSummary = calculateDimensionSummary(
        $data['dimension_lengths'],
        $data['dimension_widths'],
        $data['dimension_heights']
    );
    $data['volume_lxwxh'] = $dimensionSummary['volume_lxwxh'];
    $data['charged_weight'] = $data['charge_weight_mode'] === 'Auto'
        ? max((float) $data['actual_weight'], $dimensionSummary['volumetric_weight'])
        : max((float) $data['actual_weight'], (float) $data['charged_weight']);

    $errors = validateConsignmentForm($conn, $data, $action);

    if (!empty($errors)) {
        jsonResponse(false, 'Validation failed / सत्यापन विफल', ['errors' => $errors], 422);
    }

    // Recalculate taxes and grand total server-side
    $originState = getCityStateCode($conn, (int) $data['origin_city_id']);
    $destState = getCityStateCode($conn, (int) $data['destination_city_id']);
    $taxes = calculateTaxes((float) $data['basic_freight'], $originState, $destState, (float) ($data['gst_rate'] ?? 18));
    $data = array_merge($data, $taxes);

    $data['grand_total'] = calculateGrandTotal($data);
    $data['amount_words'] = numberToWords($data['grand_total']);
    $data['status'] = $status;
    $data['created_by'] = $_SESSION['user_id'];

    try {
        if ($data['id'] > 0) {
            // Update existing
            $existing = getConsignmentById($conn, $data['id']);
            if (!$existing) {
                jsonResponse(false, 'Consignment not found / कन्साइनमेंट नहीं मिला', [], 404);
            }
            // A billed docket is a financial record: never allow edits after
            // an invoice item has been created, regardless of user role or URL.
            ensureBillingSchema($conn);
            $billedCheck = $conn->prepare('SELECT invoice_id FROM invoice_items WHERE consignment_id=? LIMIT 1');
            $billedCheck->bind_param('i', $data['id']);
            $billedCheck->execute();
            $isBilled = (bool)$billedCheck->get_result()->fetch_assoc();
            $billedCheck->close();
            if ($isBilled) {
                jsonResponse(false, 'This docket is already billed and cannot be edited.', [], 403);
            }
            if (!canEditConsignmentStatus($existing['status'] ?? null)) {
                $role = currentUserRole();
                if (($existing['status'] ?? '') === 'Approved') {
                    jsonResponse(false, 'Cannot edit Approved consignment / Approved कन्साइनमेंट संपादित नहीं कर सकते', ['role' => $role], 403);
                }
                jsonResponse(false, 'Only Admin can edit Submitted consignment / केवल Admin ही जमा किया गया संपादित कर सकता है', ['role' => $role], 403);
            }

            $consignmentId = updateConsignment($conn, $data);
            $message = ($status === 'Submitted')
                ? 'Consignment submitted successfully / कन्साइनमेंट सफलतापूर्वक जमा'
                : 'Draft saved successfully / ड्राफ्ट सफलतापूर्वक सहेजा गया';
        } else {
            $consignmentId = insertConsignment($conn, $data);
            $message = ($status === 'Submitted')
                ? 'Consignment submitted successfully / कन्साइनमेंट सफलतापूर्वक जमा'
                : 'Draft saved successfully / ड्राफ्ट सफलतापूर्वक सहेजा गया';
        }

        upsertConsignmentPartyMasters($conn, $data);

        jsonResponse(true, $message, [
            'consignment_id' => $consignmentId,
            'consignment_note' => $data['consignment_note'],
            'status' => $status,
            'grand_total' => $data['grand_total'],
            'amount_words' => $data['amount_words']
        ]);

    } catch (Exception $e) {
        error_log('Save consignment error: ' . $e->getMessage());
        jsonResponse(false, 'Database error / डेटाबेस त्रुटि: ' . $e->getMessage(), [], 500);
    }
}

function getConsignmentSchema(): array
{
    return [
        'consignment_note' => 's', 'gst_no' => 's', 'truck_no' => 's',
        'booking_type_id' => 'i',
        'origin_city_id' => 'i', 'destination_city_id' => 'i',
        'booking_date' => 's', 'handover_date' => 's', 'handover_time' => 's',
        'ba_franchisee_code' => 's',
        'billing_party_name' => 's', 'client_master_id' => 'i', 'billing_address' => 's',
        'billing_city_id' => 'i', 'billing_state_id' => 'i',
        'billing_pin' => 's', 'billing_gst_no' => 's', 'billing_phone' => 's',
        'consignee_name' => 's', 'consignee_address' => 's',
        'consignee_city_id' => 'i', 'consignee_state_id' => 'i',
        'consignee_pin' => 's', 'consignee_gst_no' => 's', 'consignee_phone' => 's',
        'consignor_name' => 's', 'consignor_address' => 's',
        'consignor_city_id' => 'i', 'consignor_state_id' => 'i',
        'consignor_pin' => 's', 'consignor_phone' => 's', 'consignor_gst_no' => 's',
        'consignor_signature' => 's', 'party_po_number' => 's', 'payment_type_id' => 'i',
        'basic_freight' => 'd', 'fuel_charge' => 'd', 'dkt_charge' => 'd',
        'handling_charge' => 'd', 'oda_charge' => 'd', 'detention' => 'd',
        'misc_charge' => 'd', 'other_charge' => 'd', 'risk_charge' => 'd',
        'sgst' => 'd', 'cgst' => 'd', 'igst' => 'd', 'grand_total' => 'd',
        'amount_words' => 's', 'party_invoice_no' => 's', 'declared_value' => 'd',
        'eway_bill_no' => 's', 'validity_date' => 's', 'truck_type_id' => 'i',
        'description' => 's', 'no_of_pieces' => 'i', 'packing_method_id' => 'i',
        'actual_weight' => 'd', 'charged_weight' => 'd', 'charge_weight_mode' => 's', 'volume_lxwxh' => 's',
        'received_by_name' => 's', 'pickup_time' => 's', 'booking_incharge' => 's',
        'carrier_risk_type' => 's', 'remarks' => 's', 'courier_company_id' => 'i',
        'status' => 's', 'created_by' => 'i',
    ];
}

function resolveConsignmentValue(array $d, string $key, $nullifyEmpty = true)
{
    if ($key === 'validity_date') {
        return !empty($d['validity_date']) ? $d['validity_date'] : null;
    }
    if ($key === 'pickup_time') {
        return !empty($d['pickup_time']) ? $d['pickup_time'] : null;
    }
    if (!array_key_exists($key, $d)) {
        return null;
    }
    $v = $d[$key];
    if ($nullifyEmpty && is_string($v) && $v === '') {
        return null;
    }
    return $v;
}

function insertConsignment(mysqli $conn, array $d): int
{
    $schema = getConsignmentSchema();
    $cols = array_keys($schema);
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $typeStr = implode('', $schema);
    $sql = 'INSERT INTO consignments (' . implode(',', $cols) . ") VALUES ({$placeholders})";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $store = [];
    $params = [$typeStr];
    foreach ($cols as $c) {
        $store[$c] = resolveConsignmentValue($d, $c);
        $params[] =& $store[$c];
    }

    call_user_func_array([$stmt, 'bind_param'], $params);

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $id = (int) $conn->insert_id;
    $stmt->close();
    return $id;
}

function updateConsignment(mysqli $conn, array $d): int
{
    $schema = getConsignmentSchema();
    unset($schema['created_by']); // don't update created_by; id goes at end

    $assignments = [];
    foreach (array_keys($schema) as $c) {
        $assignments[] = "{$c}=?";
    }
    $cols = array_keys($schema);
    $cols[] = 'id'; // for WHERE clause

    $typeStr = implode('', $schema) . 'i'; // add 'id' type
    $sql = 'UPDATE consignments SET ' . implode(',', $assignments) . ' WHERE id=?';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $store = [];
    $params = [$typeStr];
    foreach ($cols as $c) {
        $store[$c] = resolveConsignmentValue($d, $c);
        $params[] =& $store[$c];
    }

    call_user_func_array([$stmt, 'bind_param'], $params);

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $stmt->close();
    return (int) $d['id'];
}

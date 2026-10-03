<?php
/**
 * Server-side Validation for Consignment Form
 */

/**
 * Returns a compact, persistable LxBxH value and the total volumetric weight.
 */
function calculateDimensionSummary(array $lengths, array $widths, array $heights): array
{
    $rows = [];
    $totalVolume = 0.0;
    $count = max(count($lengths), count($widths), count($heights));

    for ($i = 0; $i < $count; $i++) {
        $length = max(0, (float) ($lengths[$i] ?? 0));
        $width = max(0, (float) ($widths[$i] ?? 0));
        $height = max(0, (float) ($heights[$i] ?? 0));
        if ($length <= 0 || $width <= 0 || $height <= 0) {
            continue;
        }

        $totalVolume += $length * $width * $height;
        $format = static fn(float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        $rows[] = $format($length) . 'x' . $format($width) . 'x' . $format($height);
    }

    return [
        'volume_lxwxh' => implode(' | ', $rows),
        'volumetric_weight' => round($totalVolume, 2),
    ];
}

function validateConsignmentForm(mysqli $conn, array $data, string $action = 'submit'): array
{
    $errors = [];

    // Required fields
    validateRequired($data, 'consignment_note', 'Consignment Note', $errors);
    validateRequired($data, 'booking_date', 'Booking Date', $errors);
    validateRequired($data, 'consignor_name', 'Client / Party Name', $errors);
    validateRequired($data, 'consignee_name', 'Consignee Name', $errors);
    validateRequired($data, 'billing_party_name', 'Consignor / Billing Party Name', $errors);
    validateRequired($data, 'billing_phone', 'Billing Phone', $errors);
    validateRequired($data, 'billing_gst_no', 'Billing GST No', $errors);

    // Origin & Destination
    if (empty($data['origin_city_id']) || (int) $data['origin_city_id'] <= 0) {
        $errors['origin_city_id'] = 'Origin city is required / मूल शहर आवश्यक है';
    }
    if (empty($data['destination_city_id']) || (int) $data['destination_city_id'] <= 0) {
        $errors['destination_city_id'] = 'Destination city is required / गंतव्य शहर आवश्यक है';
    }

    // Basic freight (required on submit)
    if ($action === 'submit') {
        $freight = (float) ($data['basic_freight'] ?? 0);
        if ($freight <= 0) {
            $errors['basic_freight'] = 'Basic freight must be greater than 0 / मूल भाड़ा 0 से अधिक होना चाहिए';
        }
    }

    // Actual weight (required on submit)
    if ($action === 'submit') {
        $weight = (float) ($data['actual_weight'] ?? 0);
        if ($weight <= 0) {
            $errors['actual_weight'] = 'Actual weight must be greater than 0 / वास्तविक वजन 0 से अधिक होना चाहिए';
        }
    }

    // Consignment note uniqueness
    if (!empty($data['consignment_note'])) {
        $excludeId = isset($data['id']) ? (int) $data['id'] : 0;
        if (!isConsignmentNoteUnique($conn, $data['consignment_note'], $excludeId)) {
            $errors['consignment_note'] = 'Consignment note already exists / कन्साइनमेंट नोट पहले से मौजूद है';
        }
    }

    // Format validations
    validatePhone($data, 'billing_phone', 'Billing Phone', $errors);
    if (!empty($data['consignee_phone'])) {
        validatePhone($data, 'consignee_phone', 'Consignee Phone', $errors);
    }

    validateGST($data, 'billing_gst_no', 'Billing GST No', $errors);
    if (!empty($data['consignee_gst_no'])) {
        validateGST($data, 'consignee_gst_no', 'Consignee GST No', $errors);
    }
    if (!empty($data['gst_no'])) {
        validateGST($data, 'gst_no', 'GST No', $errors);
    }

    if (!empty($data['billing_pin'])) {
        validatePIN($data, 'billing_pin', 'Billing PIN', $errors);
    }
    if (!empty($data['consignee_pin'])) {
        validatePIN($data, 'consignee_pin', 'Consignee PIN', $errors);
    }

    if (!empty($data['eway_bill_no'])) {
        if (!preg_match('/^\d+$/', $data['eway_bill_no'])) {
            $errors['eway_bill_no'] = 'Eway bill must be numeric / ई-वे बिल केवल संख्याएं होनी चाहिए';
        }
    }

    // Date validations
    if (!empty($data['booking_date'])) {
        validatePastOrTodayDate($data['booking_date'], 'booking_date', 'Booking Date', $errors);
    }
    if (!empty($data['validity_date'])) {
        validatePastOrTodayDate($data['validity_date'], 'validity_date', 'Validity Date', $errors);
    }

    // Business logic
    $actualWeight = (float) ($data['actual_weight'] ?? 0);
    $chargedWeight = (float) ($data['charged_weight'] ?? 0);
    if ($chargedWeight > 0 && $chargedWeight < $actualWeight) {
        $errors['charged_weight'] = 'Charged weight must be >= actual weight / चार्ज वजन वास्तविक वजन से कम नहीं हो सकता';
    }

    $numericFields = [
        'basic_freight', 'fuel_charge', 'dkt_charge', 'handling_charge',
        'oda_charge', 'detention', 'misc_charge', 'other_charge', 'risk_charge', 'sgst', 'cgst', 'igst',
        'grand_total', 'declared_value', 'actual_weight', 'charged_weight'
    ];
    foreach ($numericFields as $field) {
        if (isset($data[$field]) && $data[$field] !== '') {
            $val = (float) $data[$field];
            if ($val < 0 || $val > 999999.99) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' must be between 0 and 999999.99';
            }
        }
    }

    // FK existence validations
    // Required FKs
    validateFkExists($conn, $data, 'origin_city_id', 'cities', 'Origin city', $errors, true);
    validateFkExists($conn, $data, 'destination_city_id', 'cities', 'Destination city', $errors, true);
    // Optional FKs (if present, must exist; 0/null OK)
    validateFkExists($conn, $data, 'booking_type_id', 'booking_types', 'Booking type', $errors, false);
    validateFkExists($conn, $data, 'client_master_id', 'client_masters', 'Client', $errors, false);
    validateFkExists($conn, $data, 'billing_city_id', 'cities', 'Billing city', $errors, false);
    validateFkExists($conn, $data, 'billing_state_id', 'states', 'Billing state', $errors, false);
    validateFkExists($conn, $data, 'consignee_city_id', 'cities', 'Consignee city', $errors, false);
    validateFkExists($conn, $data, 'consignee_state_id', 'states', 'Consignee state', $errors, false);
    validateFkExists($conn, $data, 'consignor_city_id', 'cities', 'Consignor city', $errors, false);
    validateFkExists($conn, $data, 'consignor_state_id', 'states', 'Consignor state', $errors, false);
    validateFkExists($conn, $data, 'payment_type_id', 'payment_types', 'Payment type', $errors, false);
    validateFkExists($conn, $data, 'truck_type_id', 'truck_types', 'Truck type', $errors, false);
    validateFkExists($conn, $data, 'packing_method_id', 'packing_methods', 'Packing method', $errors, false);
    validateFkExists($conn, $data, 'courier_company_id', 'courier_companies', 'Courier company', $errors, false);

    // Grand total verification
    if ($action === 'submit' && empty($errors)) {
        $originState = getCityStateCode($conn, (int) ($data['origin_city_id'] ?? 0));
        $destState = getCityStateCode($conn, (int) ($data['destination_city_id'] ?? 0));
        $taxes = calculateTaxes((float) $data['basic_freight'], $originState, $destState, (float) ($data['gst_rate'] ?? 18));

        $expectedGrandTotal = calculateGrandTotal(array_merge($data, $taxes));
        $submittedTotal = round((float) ($data['grand_total'] ?? 0), 2);

        if (abs($expectedGrandTotal - $submittedTotal) > 0.05) {
            $errors['grand_total'] = 'Grand total mismatch. Expected: ' . $expectedGrandTotal . ' / कुल राशि मेल नहीं खाती';
        }
    }

    return $errors;
}

function validateRequired(array $data, string $field, string $label, array &$errors): void
{
    if (empty(trim($data[$field] ?? ''))) {
        $errors[$field] = $label . ' is required / ' . $label . ' आवश्यक है';
    }
}

function validateFkExists(mysqli $conn, array $data, string $field, string $table, string $label, array &$errors, bool $required): void
{
    $val = $data[$field] ?? null;
    if ($val === null || $val === '' || (int)$val <= 0) {
        if ($required) {
            $errors[$field] = $label . ' is required / ' . $label . ' आवश्यक है';
        }
        return;
    }
    $id = (int)$val;
    $stmt = $conn->prepare("SELECT 1 FROM {$table} WHERE id = ? LIMIT 1");
    if (!$stmt) {
        $errors[$field] = $label . ' configuration error';
        return;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    if (!$exists) {
        $errors[$field] = $label . ' does not exist / ' . $label . ' मौजूद नहीं है';
    }
}

function validatePhone(array $data, string $field, string $label, array &$errors): void
{
    if (!empty($data[$field]) && !preg_match('/^\d{10}$/', $data[$field])) {
        $errors[$field] = $label . ' must be 10 digits / ' . $label . ' 10 अंकों का होना चाहिए';
    }
}

function validateGST(array $data, string $field, string $label, array &$errors): void
{
    if (!empty($data[$field])) {
        $gst = strtoupper($data[$field]);
        if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $gst)) {
            $errors[$field] = $label . ' invalid format (15 chars) / ' . $label . ' अमान्य प्रारूप';
        }
    }
}

function validatePIN(array $data, string $field, string $label, array &$errors): void
{
    if (!empty($data[$field]) && !preg_match('/^\d{6}$/', $data[$field])) {
        $errors[$field] = $label . ' must be 6 digits / ' . $label . ' 6 अंकों का होना चाहिए';
    }
}

function validatePastOrTodayDate(string $date, string $field, string $label, array &$errors): void
{
    $inputDate = strtotime($date);
    $today = strtotime(date('Y-m-d'));

    if ($inputDate === false) {
        $errors[$field] = $label . ' is invalid / ' . $label . ' अमान्य है';
    } elseif ($inputDate > $today) {
        $errors[$field] = $label . ' cannot be future date / ' . $label . ' भविष्य की तारीख नहीं हो सकती';
    }
}

function isConsignmentNoteUnique(mysqli $conn, string $note, int $excludeId = 0): bool
{
    if ($excludeId > 0) {
        $stmt = $conn->prepare('SELECT id FROM consignments WHERE consignment_note = ? AND id != ?');
        $stmt->bind_param('si', $note, $excludeId);
    } else {
        $stmt = $conn->prepare('SELECT id FROM consignments WHERE consignment_note = ?');
        $stmt->bind_param('s', $note);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();

    return !$exists;
}

function collectConsignmentDataFromPost(): array
{
    $invoiceNos = $_POST['party_invoice_no'] ?? [];
    $declaredValues = $_POST['declared_value'] ?? [];
    if (!is_array($invoiceNos)) { $invoiceNos = $invoiceNos === '' || $invoiceNos === null ? [] : [$invoiceNos]; }
    if (!is_array($declaredValues)) { $declaredValues = $declaredValues === '' || $declaredValues === null ? [] : [$declaredValues]; }
    $invoicePairs = [];
    $declaredTotal = 0.0;

    $fIntNull = function(string $k): ?int {
        $v = $_POST[$k] ?? null;
        if ($v === null || $v === '' || $v === '0' || $v === 0 || (is_string($v) && trim($v) === '')) {
            return null;
        }
        return (int)$v;
    };

    $rowCount = max(count($invoiceNos), count($declaredValues));
    for ($i = 0; $i < $rowCount; $i++) {
        $invoiceNo = isset($invoiceNos[$i]) ? trim((string) $invoiceNos[$i]) : '';
        $declaredValue = isset($declaredValues[$i]) ? (float) $declaredValues[$i] : 0.0;

        if ($invoiceNo === '' && $declaredValue <= 0) {
            continue;
        }

        $declaredTotal += $declaredValue;
        if ($invoiceNo !== '') {
            $invoicePairs[] = $invoiceNo . ':' . number_format($declaredValue, 2, '.', '');
        } elseif ($declaredValue > 0) {
            $invoicePairs[] = ':' . number_format($declaredValue, 2, '.', '');
        }
    }

    return [
        'id' => getPostInt('id'),
        'consignment_note' => getPostValue('consignment_note'),
        'gst_no' => strtoupper(getPostValue('gst_no')),
        'truck_no' => getPostValue('truck_no'),
        'booking_type_id' => $fIntNull('booking_type_id'),
        'origin_city_id' => getPostInt('origin_city_id'),
        'destination_city_id' => getPostInt('destination_city_id'),
        'booking_date' => getPostValue('booking_date'),
        'handover_date' => getPostValue('handover_date'),
        'handover_time' => getPostValue('handover_time'),
        'ba_franchisee_code' => getPostValue('ba_franchisee_code'),
        'billing_party_name' => getPostValue('billing_party_name'),
        'client_master_id' => $fIntNull('client_master_id'),
        'billing_address' => getPostValue('billing_address'),
        'billing_city_id' => $fIntNull('billing_city_id'),
        'billing_state_id' => $fIntNull('billing_state_id'),
        'billing_pin' => getPostValue('billing_pin'),
        'billing_gst_no' => strtoupper(getPostValue('billing_gst_no')),
        'billing_phone' => getPostValue('billing_phone'),
        'consignee_name' => getPostValue('consignee_name'),
        'consignee_address' => getPostValue('consignee_address'),
        'consignee_city_id' => $fIntNull('consignee_city_id'),
        'consignee_state_id' => $fIntNull('consignee_state_id'),
        'consignee_pin' => getPostValue('consignee_pin'),
        'consignee_gst_no' => strtoupper(getPostValue('consignee_gst_no')),
        'consignee_phone' => getPostValue('consignee_phone'),
        'consignor_name' => getPostValue('consignor_name'),
        'consignor_address' => getPostValue('consignor_address'),
        'consignor_city_id' => $fIntNull('consignor_city_id'),
        'consignor_state_id' => $fIntNull('consignor_state_id'),
        'consignor_pin' => getPostValue('consignor_pin'),
        'consignor_phone' => getPostValue('consignor_phone'),
        'consignor_gst_no' => strtoupper(getPostValue('consignor_gst_no')),
        'consignor_signature' => getPostValue('consignor_signature'),
        'party_po_number' => getPostValue('party_po_number'),
        'payment_type_id' => $fIntNull('payment_type_id'),
        'basic_freight' => getPostFloat('basic_freight'),
        'fuel_charge' => getPostFloat('fuel_charge'),
        'dkt_charge' => getPostFloat('dkt_charge'),
        'handling_charge' => getPostFloat('handling_charge'),
        'oda_charge' => getPostFloat('oda_charge'),
        'detention' => getPostFloat('detention'),
        'misc_charge' => getPostFloat('misc_charge'),
        'other_charge' => getPostFloat('other_charge'),
        'risk_charge' => getPostFloat('risk_charge'),
        'sgst' => getPostFloat('sgst'),
        'cgst' => getPostFloat('cgst'),
        'igst' => getPostFloat('igst'),
        'grand_total' => getPostFloat('grand_total'),
        'amount_words' => getPostValue('amount_words'),
        'party_invoice_no' => implode('|', $invoicePairs),
        'declared_value' => round($declaredTotal, 2),
        'eway_bill_no' => getPostValue('eway_bill_no'),
        'validity_date' => getPostValue('validity_date') ?: null,
        'truck_type_id' => $fIntNull('truck_type_id'),
        'description' => getPostValue('description'),
        'no_of_pieces' => getPostInt('no_of_pieces'),
        'packing_method_id' => $fIntNull('packing_method_id'),
        'actual_weight' => getPostFloat('actual_weight'),
        'charged_weight' => getPostFloat('charged_weight'),
        'gst_rate' => getPostFloat('gst_rate', 18),
        'charge_weight_mode' => getPostValue('charge_weight_mode') === 'Manual' ? 'Manual' : 'Auto',
        'volume_lxwxh' => getPostValue('volume_lxwxh'),
        'dimension_lengths' => is_array($_POST['dimension_length'] ?? null) ? $_POST['dimension_length'] : [],
        'dimension_widths' => is_array($_POST['dimension_width'] ?? null) ? $_POST['dimension_width'] : [],
        'dimension_heights' => is_array($_POST['dimension_height'] ?? null) ? $_POST['dimension_height'] : [],
        'received_by_name' => getPostValue('received_by_name'),
        'pickup_time' => getPostValue('pickup_time') ?: null,
        'booking_incharge' => getPostValue('booking_incharge'),
        'carrier_risk_type' => getPostValue('carrier_risk_type'),
        'remarks' => getPostValue('remarks'),
        'courier_company_id' => $fIntNull('courier_company_id'),
        'status' => getPostValue('status', 'Draft'),
    ];
}

<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';

startSecureSession();
// Handle both POST and GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireLogin();
} else {
    requireLoginAPI();
}
requireModule('billing');

$conn = getDBConnection();
ensureBillingSchema($conn);

$invoiceId = (int)($_POST['invoice_id'] ?? $_GET['invoice_id'] ?? 0);
$selectedCharges = $_POST['charges'] ?? $_GET['charges'] ?? [];

if ($invoiceId <= 0) {
    jsonResponse(false, 'Invalid invoice ID');
}

// Get invoice details
$stmt = $conn->prepare('SELECT i.*, c.legal_name, c.trade_name, c.address, c.phone, c.email, 
                         g.registration_address as gst_address, g.state_code as gst_state
                         FROM invoices i 
                         JOIN companies c ON i.company_id = c.id
                         JOIN company_gst_registrations g ON i.company_gst_registration_id = g.id
                         WHERE i.id = ?');
$stmt->bind_param('i', $invoiceId);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    jsonResponse(false, 'Invoice not found');
}

// Get invoice items with full docket details
$stmt = $conn->prepare('SELECT ii.*, c.consignment_note, c.booking_date, c.origin_city_id, c.destination_city_id,
                         c.billing_party_name, c.consignee_name, c.consignee_address, c.basic_freight, c.fuel_charge,
                         c.dkt_charge, c.handling_charge, c.oda_charge, c.detention, c.misc_charge, c.other_charge,
                         c.risk_charge, c.cgst, c.sgst, c.igst, c.grand_total, c.no_of_pieces, c.actual_weight,
                         c.charged_weight, c.description, c.truck_no
                         FROM invoice_items ii
                         JOIN consignments c ON ii.consignment_id = c.id
                         WHERE ii.invoice_id = ?');
$stmt->bind_param('i', $invoiceId);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get city names
$cityIds = array_merge(
    array_column($items, 'origin_city_id'),
    array_column($items, 'destination_city_id')
);
$cityIds = array_unique(array_filter($cityIds));
$cities = [];
if (!empty($cityIds)) {
    $placeholders = implode(',', array_fill(0, count($cityIds), '?'));
    $stmt = $conn->prepare("SELECT id, city_name FROM cities WHERE id IN ($placeholders)");
    $stmt->bind_param(str_repeat('i', count($cityIds)), ...$cityIds);
    $stmt->execute();
    $cityResult = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($cityResult as $city) {
        $cities[$city['id']] = $city['city_name'];
    }
}

// Map charges to display names
$chargeNames = [
    'basic_freight' => 'Basic Freight',
    'fuel_charge' => 'Fuel Charge',
    'dkt_charge' => 'Docket Charge',
    'handling_charge' => 'Handling Charge',
    'oda_charge' => 'ODA Charge',
    'detention' => 'Detention',
    'misc_charge' => 'Misc Charge',
    'other_charge' => 'Other Charge',
    'risk_charge' => 'Risk Charge',
    'cgst' => 'CGST',
    'sgst' => 'SGST',
    'igst' => 'IGST'
];

// Function to get charge value
function getChargeValue($item, $charge) {
    return (float)($item[$charge] ?? 0);
}

// Generate CSV
$filename = 'Invoice_' . $invoice['invoice_no'] . '_' . date('YmdHis') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Header
fputcsv($output, ['Invoice Details']);
fputcsv($output, ['Invoice Number', $invoice['invoice_no']]);
fputcsv($output, ['Invoice Date', date('d-M-Y', strtotime($invoice['invoice_date']))]);
fputcsv($output, ['Company', $invoice['trade_name'] ?: $invoice['legal_name']]);
fputcsv($output, ['Billing Party', $invoice['billing_party_name']]);
fputcsv($output, ['GST Type', $invoice['gst_type']]);
fputcsv($output, ['GST Rate', $invoice['gst_rate'] . '%']);
fputcsv($output, []);

// Item headers
$headers = ['#', 'Docket No', 'Date', 'Origin', 'Destination', 'Consignee', 'Pieces', 'Weight (Kg)', 'Charged Weight (Kg)', 'Truck No'];
foreach ($chargeNames as $key => $name) {
    if (in_array($key, $selectedCharges) || empty($selectedCharges)) {
        $headers[] = $name;
    }
}
fputcsv($output, $headers);

// Items
foreach ($items as $index => $item) {
    $row = [
        $index + 1,
        $item['consignment_note'],
        date('d-M-Y', strtotime($item['booking_date'])),
        $cities[$item['origin_city_id']] ?? '',
        $cities[$item['destination_city_id']] ?? '',
        $item['consignee_name'],
        $item['no_of_pieces'],
        $item['actual_weight'],
        $item['charged_weight'],
        $item['truck_no']
    ];
    
    foreach ($chargeNames as $key => $name) {
        if (in_array($key, $selectedCharges) || empty($selectedCharges)) {
            $row[] = getChargeValue($item, $key);
        }
    }
    
    fputcsv($output, $row);
}

// Totals
fputcsv($output, []);
fputcsv($output, ['Charge Summary']);
foreach ($chargeNames as $key => $name) {
    if (in_array($key, $selectedCharges) || empty($selectedCharges)) {
        $total = array_sum(array_map(function($item) use ($key) {
            return getChargeValue($item, $key);
        }, $items));
        if ($total > 0) {
            fputcsv($output, [$name, $total]);
        }
    }
}

fclose($output);
exit;
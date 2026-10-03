<?php
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/master_data.php';

startSecureSession();
requireLogin();
requireModule('billing');

$conn = getDBConnection();
ensureBillingSchema($conn);

$invoiceId = (int)($_GET['invoice_id'] ?? 0);
$format = $_GET['format'] ?? 'standard';
$termsId = (int)($_GET['terms_id'] ?? 0);
$selectedCharges = $_GET['charges'] ?? [];

if ($invoiceId <= 0) {
    die('Invalid invoice ID');
}

// Get invoice details
$stmt = $conn->prepare('SELECT i.*, c.logo_path, c.legal_name, c.trade_name, c.address, c.phone, c.email, c.pan_no, 
                         co.city_name, co.state_code, co.pincode as company_pin,
                         g.registration_address as gst_address, g.state_code as gst_state
                         FROM invoices i 
                         JOIN companies c ON i.company_id = c.id
                         LEFT JOIN cities co ON c.city_id = co.id
                         JOIN company_gst_registrations g ON i.company_gst_registration_id = g.id
                         WHERE i.id = ?');
$stmt->bind_param('i', $invoiceId);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Increment print count
$stmt = $conn->prepare('UPDATE invoices SET print_count = COALESCE(print_count, 0) + 1 WHERE id = ?');
$stmt->bind_param('i', $invoiceId);
$stmt->execute();
$stmt->close();

if (!$invoice) {
    die('Invoice not found');
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

// Get terms if selected
$terms = null;
if ($termsId > 0) {
    $stmt = $conn->prepare('SELECT * FROM invoice_terms WHERE id = ?');
    $stmt->bind_param('i', $termsId);
    $stmt->execute();
    $terms = $stmt->get_result()->fetch_assoc();
    $stmt->close();
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

// Calculate totals based on selected charges
$totals = [];
foreach ($chargeNames as $key => $name) {
    if (in_array($key, $selectedCharges) || empty($selectedCharges)) {
        $totals[$key] = array_sum(array_map(function($item) use ($key) {
            return getChargeValue($item, $key);
        }, $items));
    } else {
        $totals[$key] = 0;
    }
}

$grandTotal = array_sum($totals);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?= htmlspecialchars($invoice['invoice_no']) ?></title>
    <style>
        @page {
            size: A4;
            margin: 10mm;
        }
        
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 0;
        }
        
        .invoice-container {
            max-width: 210mm;
            margin: 0 auto;
            padding: 20px;
            background: white;
        }
        
        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            border-bottom: 2px solid #0f766e;
            padding-bottom: 15px;
        }
        
        .company-logo {
            max-width: 150px;
            max-height: 80px;
            object-fit: contain;
        }
        
        .company-info {
            flex: 1;
            margin-left: 20px;
        }
        
        .company-name {
            font-size: 18px;
            font-weight: bold;
            color: #0f766e;
            margin-bottom: 5px;
        }
        
        .company-details {
            font-size: 10px;
            color: #555;
        }
        
        .invoice-details {
            text-align: right;
        }
        
        .invoice-title {
            font-size: 24px;
            font-weight: bold;
            color: #0f766e;
            margin-bottom: 10px;
        }
        
        .invoice-number {
            font-size: 14px;
            font-weight: bold;
        }
        
        .invoice-date {
            font-size: 12px;
            color: #666;
        }
        
        .print-count {
            font-size: 10px;
            color: #999;
            margin-top: 5px;
        }
        
        .parties-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            gap: 20px;
        }
        
        .party-box {
            flex: 1;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            background: #f8fafc;
        }
        
        .party-title {
            font-size: 12px;
            font-weight: bold;
            color: #0f766e;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        
        .party-name {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .party-details {
            font-size: 10px;
            color: #555;
        }
        
        .gst-info {
            font-size: 11px;
            font-weight: bold;
            color: #1d4ed8;
            margin-top: 8px;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .items-table th {
            background: #0f766e;
            color: white;
            font-weight: bold;
            padding: 10px 8px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
        }
        
        .items-table td {
            border: 1px solid #e5e7eb;
            padding: 8px;
            font-size: 10px;
        }
        
        .items-table tr:nth-child(even) {
            background: #f8fafc;
        }
        
        .amount-col {
            text-align: right;
            font-weight: bold;
        }
        
        .totals-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 20px;
        }
        
        .totals-box {
            width: 300px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            background: #f8fafc;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .total-row:last-child {
            border-bottom: none;
        }
        
        .total-label {
            font-weight: bold;
        }
        
        .total-amount {
            font-weight: bold;
        }
        
        .grand-total {
            font-size: 14px;
            color: #0f766e;
            border-top: 2px solid #0f766e;
            padding-top: 10px;
            margin-top: 5px;
        }
        
        .terms-section {
            margin-top: 20px;
            padding: 15px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #f8fafc;
        }
        
        .terms-title {
            font-size: 12px;
            font-weight: bold;
            color: #0f766e;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        
        .terms-content {
            font-size: 9px;
            color: #555;
            white-space: pre-wrap;
        }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 9px;
            color: #999;
            border-top: 1px solid #e5e7eb;
            padding-top: 15px;
        }
        
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
            padding-top: 20px;
        }
        
        .signature-box {
            width: 200px;
            text-align: center;
        }
        
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 40px;
            padding-top: 5px;
            font-size: 10px;
        }
        
        @media print {
            body {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            .invoice-container {
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- Header -->
        <div class="invoice-header">
            <div style="display: flex; align-items: flex-start;">
                <?php if (!empty($invoice['logo_path'])): ?>
                    <img src="<?= htmlspecialchars($invoice['logo_path']) ?>" alt="Company Logo" class="company-logo">
                <?php endif; ?>
                <div class="company-info">
                    <div class="company-name"><?= htmlspecialchars($invoice['trade_name'] ?: $invoice['legal_name']) ?></div>
                    <div class="company-details">
                        <?= htmlspecialchars($invoice['address']) ?><br>
                        <?= htmlspecialchars($cities[$invoice['city_id']] ?? '') ?>, 
                        <?= htmlspecialchars($invoice['state_code']) ?> - 
                        <?= htmlspecialchars($invoice['company_pin']) ?><br>
                        Phone: <?= htmlspecialchars($invoice['phone']) ?><br>
                        <?php if (!empty($invoice['email'])): ?>Email: <?= htmlspecialchars($invoice['email']) ?><br><?php endif; ?>
                        <?php if (!empty($invoice['pan_no'])): ?>PAN: <?= htmlspecialchars($invoice['pan_no']) ?><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="invoice-details">
                <div class="invoice-title">TAX INVOICE</div>
                <div class="invoice-number">Invoice #: <?= htmlspecialchars($invoice['invoice_no']) ?></div>
                <div class="invoice-date">Date: <?= date('d-M-Y', strtotime($invoice['invoice_date'])) ?></div>
                <div class="print-count">Print Count: <?= number_format($invoice['print_count'] ?? 0) ?></div>
            </div>
        </div>

        <!-- Parties -->
        <div class="parties-section">
            <div class="party-box">
                <div class="party-title">Bill From</div>
                <div class="party-name"><?= htmlspecialchars($invoice['trade_name'] ?: $invoice['legal_name']) ?></div>
                <div class="party-details">
                    <?= htmlspecialchars($invoice['gst_address'] ?: $invoice['address']) ?><br>
                    GSTIN: <?= htmlspecialchars($invoice['company_gstin']) ?><br>
                    State: <?= htmlspecialchars($invoice['gst_state']) ?>
                </div>
            </div>
            <div class="party-box">
                <div class="party-title">Bill To</div>
                <div class="party-name"><?= htmlspecialchars($invoice['billing_party_name']) ?></div>
                <div class="party-details">
                    GSTIN: <?= htmlspecialchars($invoice['billing_party_gstin'] ?: 'N/A') ?>
                </div>
            </div>
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Docket No</th>
                    <th>Date</th>
                    <th>Origin</th>
                    <th>Destination</th>
                    <th>Consignee</th>
                    <?php foreach ($chargeNames as $key => $name): ?>
                        <?php if (in_array($key, $selectedCharges) || empty($selectedCharges)): ?>
                            <th><?= htmlspecialchars($name) ?></th>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $index => $item): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($item['consignment_note']) ?></td>
                        <td><?= date('d-M-Y', strtotime($item['booking_date'])) ?></td>
                        <td><?= htmlspecialchars($cities[$item['origin_city_id']] ?? '') ?></td>
                        <td><?= htmlspecialchars($cities[$item['destination_city_id']] ?? '') ?></td>
                        <td><?= htmlspecialchars($item['consignee_name']) ?></td>
                        <?php foreach ($chargeNames as $key => $name): ?>
                            <?php if (in_array($key, $selectedCharges) || empty($selectedCharges)): ?>
                                <td class="amount-col"><?= number_format(getChargeValue($item, $key), 2) ?></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals-section">
            <div class="totals-box">
                <?php foreach ($chargeNames as $key => $name): ?>
                    <?php if ($totals[$key] > 0): ?>
                        <div class="total-row">
                            <span class="total-label"><?= htmlspecialchars($name) ?></span>
                            <span class="total-amount">₹<?= number_format($totals[$key], 2) ?></span>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="total-row grand-total">
                    <span class="total-label">Grand Total</span>
                    <span class="total-amount">₹<?= number_format($grandTotal, 2) ?></span>
                </div>
            </div>
        </div>

        <!-- Terms -->
        <?php if ($terms): ?>
            <div class="terms-section">
                <div class="terms-title">Terms & Conditions</div>
                <div class="terms-content"><?= htmlspecialchars($terms['term_content']) ?></div>
            </div>
        <?php endif; ?>

        <!-- Signature -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line">For <?= htmlspecialchars($invoice['trade_name'] ?: $invoice['legal_name']) ?></div>
                <div style="font-size: 9px; margin-top: 5px;">Authorized Signatory</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">Receiver's Signature</div>
                <div style="font-size: 9px; margin-top: 5px;"></div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            This is a computer-generated invoice and does not require physical signature.
        </div>
    </div>

    <script>
        // Auto print on load
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>
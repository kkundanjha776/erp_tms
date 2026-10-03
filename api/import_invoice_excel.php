<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';

// jsonResponse function already exists in functions.php

startSecureSession();
requireLoginAPI();
requireModule('billing');

$conn = getDBConnection();
ensureBillingSchema($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', [], 405);
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(false, 'Invalid CSRF token', [], 403);
}

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(false, 'Please upload a valid Excel file');
}

$file = $_FILES['excel_file'];
$allowedTypes = ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv'];
if (!in_array($file['type'], $allowedTypes)) {
    jsonResponse(false, 'Invalid file type. Please upload Excel or CSV file');
}

// For simplicity, we'll process CSV files
// For actual Excel files, you would need a library like PhpSpreadsheet
if ($file['type'] === 'text/csv' || pathinfo($file['name'], PATHINFO_EXTENSION) === 'csv') {
    $handle = fopen($file['tmp_name'], 'r');
    if ($handle === false) {
        jsonResponse(false, 'Could not read file');
    }
    
    $header = fgetcsv($handle);
    $imported = 0;
    $errors = [];
    
    // Expected CSV format: invoice_no,invoice_date,client_name,gst_type,gst_rate,amount
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 6) {
            $errors[] = 'Invalid row format: ' . implode(',', $row);
            continue;
        }
        
        $invoiceNo = trim($row[0]);
        $invoiceDate = trim($row[1]);
        $clientName = trim($row[2]);
        $gstType = trim($row[3]);
        $gstRate = (float)trim($row[4]);
        $amount = (float)trim($row[5]);
        
        // Validate
        if ($invoiceNo === '' || $invoiceDate === '' || $clientName === '') {
            $errors[] = 'Missing required fields in row: ' . implode(',', $row);
            continue;
        }
        
        // Check if invoice already exists
        $check = $conn->prepare('SELECT id FROM invoices WHERE invoice_no = ?');
        $check->bind_param('s', $invoiceNo);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $errors[] = 'Invoice ' . $invoiceNo . ' already exists';
            $check->close();
            continue;
        }
        $check->close();
        
        // Get client details
        $clientStmt = $conn->prepare('SELECT id, gst_no FROM client_masters WHERE client_name = ? LIMIT 1');
        $clientStmt->bind_param('s', $clientName);
        $clientStmt->execute();
        $client = $clientStmt->get_result()->fetch_assoc();
        $clientStmt->close();
        
        if (!$client) {
            $errors[] = 'Client not found: ' . $clientName;
            continue;
        }
        
        // Get company GST
        $companyStmt = $conn->prepare('SELECT g.id, g.company_id, g.gstin FROM company_gst_registrations g JOIN companies c ON c.id = g.company_id WHERE c.status = "Active" AND g.status = "Active" AND g.is_default = 1 LIMIT 1');
        $companyStmt->execute();
        $company = $companyStmt->get_result()->fetch_assoc();
        $companyStmt->close();
        
        if (!$company) {
            $errors[] = 'No active company GST registration found';
            continue;
        }
        
        // Calculate tax
        $taxableAmount = $amount;
        $tax = $taxableAmount * $gstRate / 100;
        $cgst = $gstType === 'CGST_SGST' ? $tax / 2 : 0;
        $sgst = $gstType === 'CGST_SGST' ? $tax / 2 : 0;
        $igst = $gstType === 'IGST' ? $tax : 0;
        $grandTotal = $taxableAmount + $cgst + $sgst + $igst;
        
        // Insert invoice
        $stmt = $conn->prepare('INSERT INTO invoices (invoice_no, invoice_date, company_id, company_gst_registration_id, company_gstin, billing_party_name, billing_party_gstin, gst_type, gst_rate, taxable_amount, cgst_amount, sgst_amount, igst_amount, grand_total, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $userId = (int)$_SESSION['user_id'];
        $stmt->bind_param('ssisssssddddd', $invoiceNo, $invoiceDate, $company['company_id'], $company['id'], $company['gstin'], $clientName, $client['gst_no'], $gstType, $gstRate, $taxableAmount, $cgst, $sgst, $igst, $grandTotal, $userId);
        
        if ($stmt->execute()) {
            $imported++;
        } else {
            $errors[] = 'Failed to import invoice ' . $invoiceNo . ': ' . $stmt->error;
        }
        $stmt->close();
    }
    
    fclose($handle);
    
    jsonResponse(true, 'Imported ' . $imported . ' invoices successfully', [
        'imported' => $imported,
        'errors' => $errors
    ]);
} else {
    jsonResponse(false, 'For Excel files, please convert to CSV format first. CSV import is supported.');
}
<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_data.php';
startSecureSession(); requireLoginAPI();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method.', [], 405);
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) jsonResponse(false, 'Invalid CSRF token.', [], 403);
$conn=getDBConnection(); ensureBillingSchema($conn);
$ids=array_values(array_unique(array_filter(array_map('intval', $_POST['consignment_ids'] ?? []))));
$party=trim($_POST['billing_party_name'] ?? ''); $companyGstin=strtoupper(trim($_POST['company_gstin'] ?? ''));
$date=trim($_POST['invoice_date'] ?? date('Y-m-d')); $rate=(float)($_POST['gst_rate'] ?? 0); $gstType=$_POST['gst_type'] ?? '';

// Enhanced validation with specific error messages
if (empty($ids)) {
    jsonResponse(false,'No dockets selected. Please select at least one docket to create invoice.',[],422);
}
if (empty($party)) {
    jsonResponse(false,'Billing party not selected. Please select a billing party.',[],422);
}
if (empty($companyGstin)) {
    jsonResponse(false,'Company GST not selected. Please select company GST registration.',[],422);
}
if (!in_array($gstType,['CGST_SGST','IGST'],true)) {
    jsonResponse(false,'Invalid GST type. Please select CGST+SGST or IGST.',[],422);
}
if ($rate < 0 || $rate > 100) {
    jsonResponse(false,'Invalid GST rate. Please enter a value between 0 and 100.',[],422);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) {
    jsonResponse(false,'Invalid invoice date format. Use YYYY-MM-DD format.',[],422);
}
try {
    $conn->begin_transaction();
    $companyId=(int)($_POST['company_id']??0); $regId=(int)($_POST['company_gst_registration_id']??0);
    $companyCheck=$conn->prepare("SELECT g.id FROM company_gst_registrations g JOIN companies c ON c.id=g.company_id WHERE g.id=? AND g.company_id=? AND g.gstin=? AND g.status='Active' AND c.status='Active'");
    $companyCheck->bind_param('iis',$regId,$companyId,$companyGstin); $companyCheck->execute();
    if (!$companyCheck->get_result()->num_rows) throw new Exception('Selected owner GST registration is invalid or inactive.');
    $companyCheck->close();
    $clientCheck=$conn->prepare("SELECT id FROM client_masters WHERE client_name=? AND status='Active' LIMIT 1");
    $clientCheck->bind_param('s',$party); $clientCheck->execute();
    if (!$clientCheck->get_result()->num_rows) throw new Exception('Billing party must be an active Client Master record.');
    $clientCheck->close();
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $types=str_repeat('i',count($ids));
    $sql="SELECT c.id,c.consignment_note,c.booking_date,c.billing_party_name,c.basic_freight,ii.id billed_id FROM consignments c LEFT JOIN invoice_items ii ON ii.consignment_id=c.id WHERE c.id IN ($marks) FOR UPDATE";
    $st=$conn->prepare($sql); $st->bind_param($types,...$ids); $st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    if(count($rows)!==count($ids)) throw new Exception('One or more selected dockets no longer exist.');
    $freight=0.0;
    foreach($rows as $r){
        if($r['billed_id']) throw new Exception('Docket '.$r['consignment_note'].' is already billed.');
        if((float)$r['basic_freight']<=0) throw new Exception('Docket '.$r['consignment_note'].' has zero freight and cannot be billed.');
        if($r['billing_party_name']!==$party) throw new Exception('All selected dockets must belong to billing party: '.$party.'.');
        $freight+=(float)$r['basic_freight'];
    }
    $tax=round($freight*$rate/100,2); $cgst=$gstType==='CGST_SGST'?round($tax/2,2):0; $sgst=$gstType==='CGST_SGST'?round($tax/2,2):0; $igst=$gstType==='IGST'?$tax:0; $total=round($freight+$cgst+$sgst+$igst,2);
    $invoiceNo=strtoupper(trim($_POST['invoice_no'] ?? ''));
    if($invoiceNo==='') $invoiceNo='INV-'.date('ymd').'-'.str_pad((string)(random_int(1,9999)),4,'0',STR_PAD_LEFT);
    $check=$conn->prepare('SELECT id FROM invoices WHERE invoice_no=?');$check->bind_param('s',$invoiceNo);$check->execute();if($check->get_result()->num_rows) throw new Exception('Invoice number already exists.');$check->close();
    $partyGstin=strtoupper(trim($_POST['billing_party_gstin']??''));$remarks=trim($_POST['remarks']??'');$uid=(int)$_SESSION['user_id'];
    $st=$conn->prepare('INSERT INTO invoices (invoice_no,invoice_date,company_id,company_gst_registration_id,company_gstin,billing_party_name,billing_party_gstin,gst_type,gst_rate,taxable_amount,cgst_amount,sgst_amount,igst_amount,grand_total,remarks,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->bind_param('ssiissssddddddsi',$invoiceNo,$date,$companyId,$regId,$companyGstin,$party,$partyGstin,$gstType,$rate,$freight,$cgst,$sgst,$igst,$total,$remarks,$uid);$st->execute();$invoiceId=$conn->insert_id;$st->close();
    $item=$conn->prepare('INSERT INTO invoice_items (invoice_id,consignment_id,docket_no,booking_date,freight_amount) VALUES (?,?,?,?,?)');
    foreach($rows as $r){$cid=(int)$r['id'];$note=$r['consignment_note'];$booking=$r['booking_date'];$amount=(float)$r['basic_freight'];$item->bind_param('iissd',$invoiceId,$cid,$note,$booking,$amount);$item->execute();}$item->close();
    $conn->commit(); jsonResponse(true,'Invoice created successfully.', ['invoice_id'=>$invoiceId,'invoice_no'=>$invoiceNo,'grand_total'=>$total]);
} catch(Throwable $e){$conn->rollback();error_log('Invoice save: '.$e->getMessage());jsonResponse(false,$e->getMessage(),[],422);}

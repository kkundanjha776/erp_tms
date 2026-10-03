<?php
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();
requireLoginAPI();

header('Content-Type: application/json; charset=utf-8');

$basicFreight = isset($_GET['basic_freight']) ? (float) $_GET['basic_freight'] : 0;
$originCityId = isset($_GET['origin_city_id']) ? (int) $_GET['origin_city_id'] : 0;
$destCityId = isset($_GET['destination_city_id']) ? (int) $_GET['destination_city_id'] : 0;

$fuelCharge = isset($_GET['fuel_charge']) ? (float) $_GET['fuel_charge'] : 0;
$dktCharge = isset($_GET['dkt_charge']) ? (float) $_GET['dkt_charge'] : 0;
$handlingCharge = isset($_GET['handling_charge']) ? (float) $_GET['handling_charge'] : 0;
$odaCharge = isset($_GET['oda_charge']) ? (float) $_GET['oda_charge'] : 0;
$detention = isset($_GET['detention']) ? (float) $_GET['detention'] : 0;
$miscCharge = isset($_GET['misc_charge']) ? (float) $_GET['misc_charge'] : 0;
$otherCharge = isset($_GET['other_charge']) ? (float) $_GET['other_charge'] : 0;
$riskCharge = isset($_GET['risk_charge']) ? (float) $_GET['risk_charge'] : 0;
$gstRate = isset($_GET['gst_rate']) ? (float) $_GET['gst_rate'] : 18.0;

$conn = getDBConnection();
$originState = getCityStateCode($conn, $originCityId);
$destState = getCityStateCode($conn, $destCityId);

$taxes = calculateTaxes($basicFreight, $originState, $destState, $gstRate);

$charges = array_merge([
    'basic_freight' => $basicFreight,
    'fuel_charge' => $fuelCharge,
    'dkt_charge' => $dktCharge,
    'handling_charge' => $handlingCharge,
    'oda_charge' => $odaCharge,
    'detention' => $detention,
    'misc_charge' => $miscCharge,
    'other_charge' => $otherCharge,
    'risk_charge' => $riskCharge,
], $taxes);

$grandTotal = calculateGrandTotal($charges);
$amountWords = numberToWords($grandTotal);

jsonResponse(true, 'Taxes calculated / कर गणना की गई', [
    'sgst' => $taxes['sgst'],
    'cgst' => $taxes['cgst'],
    'igst' => $taxes['igst'],
    'gst_rate' => $gstRate,
    'is_intra_state' => $taxes['is_intra_state'],
    'origin_state' => $originState,
    'destination_state' => $destState,
    'grand_total' => $grandTotal,
    'amount_words' => $amountWords
]);

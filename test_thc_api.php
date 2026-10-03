<?php
// Quick API test - shows exact PHP error from save_thc.php
// Delete after use
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/master_data.php';

startSecureSession();
if (!isLoggedIn()) { echo 'Not logged in'; exit; }

$conn = getDBConnection();

echo '<h3>1. PHP Version: ' . PHP_VERSION . '</h3>';

echo '<h3>2. ensureTHCSchema test:</h3>';
try {
    ensureTHCSchema($conn);
    echo '✅ Schema OK<br>';
} catch (Throwable $e) {
    echo '❌ Schema error: ' . $e->getMessage() . '<br>';
}

echo '<h3>3. THC tables exist:</h3>';
$tables = ['route_masters','route_points','thc','loading_tally','loading_tally_items',
           'thc_manifests','thc_manifest_items','thc_touching_points','thc_unloading_tally'];
foreach ($tables as $t) {
    $r = $conn->query("SHOW TABLES LIKE '{$t}'");
    echo ($r && $r->num_rows > 0 ? '✅' : '❌') . " {$t}<br>";
}

echo '<h3>4. getNextTHCNo:</h3>';
try {
    $no = getNextTHCNo($conn);
    echo '✅ Next THC No: ' . $no . '<br>';
} catch (Throwable $e) {
    echo '❌ ' . $e->getMessage() . '<br>';
}

echo '<h3>5. Simulate INSERT bind_param:</h3>';
try {
    $thcNo    = getNextTHCNo($conn);
    $thcDate  = date('Y-m-d');
    $originId = 1; $destId = 2; $mode = 'Surface';
    $routeId  = null;
    $vendor = $vehicleNo = $driver1 = $driver1ph = $driver2 = $driver2ph = '';
    $contract = $other = $total = $advance = $deduction = $balance = 0.0;
    $remarks = $status = ''; $status = 'Draft';
    $userId = (int)($_SESSION['user_id'] ?? 1);

    $stmt = $conn->prepare("INSERT INTO thc
        (thc_no, thc_date, origin_city_id, destination_city_id, transport_mode,
         route_id, vendor_name, vehicle_no, driver1_name, driver1_phone,
         driver2_name, driver2_phone, contract_amount, other_amount,
         total_amount, advance_amount, deduction_amount, balance_amount,
         remarks, status, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    if (!$stmt) {
        echo '❌ Prepare failed: ' . $conn->error . '<br>';
    } else {
        $typeStr = 'ssiisisssssddddddssi';
        echo 'Type string length: ' . strlen($typeStr) . ' (need 21)<br>';
        $bound = $stmt->bind_param($typeStr,
            $thcNo, $thcDate, $originId, $destId, $mode,
            $routeId, $vendor, $vehicleNo, $driver1, $driver1ph,
            $driver2, $driver2ph, $contract, $other,
            $total, $advance, $deduction, $balance,
            $remarks, $status, $userId);
        echo $bound ? '✅ bind_param OK<br>' : '❌ bind_param FAILED: ' . $stmt->error . '<br>';
        $stmt->close();
    }
} catch (Throwable $e) {
    echo '❌ Exception: ' . $e->getMessage() . '<br>';
}

echo '<h3>6. Duplicate FK constraints check:</h3>';
$fkRes = $conn->query("SELECT CONSTRAINT_NAME, TABLE_NAME FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA='erp_tms' AND CONSTRAINT_TYPE='FOREIGN KEY' 
    AND TABLE_NAME IN ('thc','thc_manifests','thc_touching_points','thc_manifest_items','thc_unloading_tally','loading_tally','loading_tally_items','route_masters','route_points')
    ORDER BY TABLE_NAME, CONSTRAINT_NAME");
if ($fkRes) {
    while ($row = $fkRes->fetch_assoc()) {
        echo $row['TABLE_NAME'] . ' → ' . $row['CONSTRAINT_NAME'] . '<br>';
    }
}

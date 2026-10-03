<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/master_data.php';

echo '<pre>';
echo 'PHP: ' . PHP_VERSION . "\n\n";

startSecureSession();
if (!isLoggedIn()) { echo 'Not logged in - open dashboard first'; exit; }

$conn = getDBConnection();
echo "DB connected\n\n";

// Step 1: Drop old THC tables if they exist with bad constraints
$drop = [
    'thc_unloading_tally',
    'thc_manifest_items',
    'thc_manifests',
    'thc_touching_points',
    'loading_tally_items',
    'loading_tally',
    'thc',
    'route_points',
    'route_masters',
];

echo "=== Dropping old THC tables ===\n";
$conn->query("SET FOREIGN_KEY_CHECKS=0");
foreach ($drop as $t) {
    $exists = $conn->query("SHOW TABLES LIKE '{$t}'")->num_rows > 0;
    if ($exists) {
        $ok = $conn->query("DROP TABLE `{$t}`");
        echo ($ok ? "Dropped: " : "FAIL drop: ") . $t . "\n";
    } else {
        echo "Skip (not found): {$t}\n";
    }
}
$conn->query("SET FOREIGN_KEY_CHECKS=1");

echo "\n=== Creating THC tables (ensureTHCSchema) ===\n";
try {
    ensureTHCSchema($conn);
    echo "ensureTHCSchema() OK\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
}

echo "\n=== Verify tables ===\n";
$tables = ['route_masters','route_points','thc','loading_tally','loading_tally_items',
           'thc_manifests','thc_manifest_items','thc_touching_points','thc_unloading_tally'];
foreach ($tables as $t) {
    $ok = $conn->query("SHOW TABLES LIKE '{$t}'")->num_rows > 0;
    echo ($ok ? "OK: " : "MISSING: ") . $t . "\n";
}

echo "\n=== Test INSERT bind_param ===\n";
$thcNo   = getNextTHCNo($conn);
$date    = date('Y-m-d');
$oid=1; $did=2; $mode='Surface'; $rid=null;
$v=$vn=$d1=$d1p=$d2=$d2p=$rem=''; $status='Draft';
$c=$o=$tot=$adv=$ded=$bal=0.0;
$uid=(int)($_SESSION['user_id']??1);

$sql = "INSERT INTO thc (thc_no,thc_date,origin_city_id,destination_city_id,transport_mode,
    route_id,vendor_name,vehicle_no,driver1_name,driver1_phone,
    driver2_name,driver2_phone,contract_amount,other_amount,
    total_amount,advance_amount,deduction_amount,balance_amount,
    remarks,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
$stmt = $conn->prepare($sql);
if (!$stmt) { echo "Prepare FAILED: " . $conn->error . "\n"; }
else {
    // 21 params: s s i i s i s s s s s s d d d d d d s s i
    $ts = 'ssiisissssssddddddssi';
    echo "Type string: '{$ts}' len=" . strlen($ts) . " (need 21)\n";
    $bound = $stmt->bind_param($ts,
        $thcNo, $date,  $oid,  $did,  $mode,
        $rid,   $v,     $vn,   $d1,   $d1p,
        $d2,    $d2p,   $c,    $o,    $tot,
        $adv,   $ded,   $bal,  $rem,  $status, $uid);
    echo $bound ? "bind_param OK\n" : "bind_param FAILED: " . $stmt->error . "\n";
    if ($bound) {
        $exec = $stmt->execute();
        echo $exec ? "INSERT OK - id=" . $conn->insert_id . "\n" : "execute FAILED: " . $stmt->error . "\n";
        if ($exec) {
            // clean up test record
            $conn->query("DELETE FROM thc WHERE id=" . $conn->insert_id);
            echo "Test record cleaned up\n";
        }
    }
    $stmt->close();
}

echo "\nDone. Delete this file.\n</pre>";

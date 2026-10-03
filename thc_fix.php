<?php
// One-time fix: drop partially-created THC tables so ensureTHCSchema() can recreate them cleanly
// Run ONCE at: http://localhost/erp_tms/thc_fix.php
// Delete this file after running.
require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/functions.php';

startSecureSession();
requireLogin();
if (!isAdminUser()) die('Admin only');

$conn = getDBConnection();

// Disable FK checks to allow dropping in any order
$conn->query("SET FOREIGN_KEY_CHECKS=0");

$tables = [
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

$results = [];
foreach ($tables as $t) {
    $check = $conn->query("SHOW TABLES LIKE '{$t}'");
    if ($check && $check->num_rows > 0) {
        $ok = $conn->query("DROP TABLE `{$t}`");
        $results[] = ($ok ? "✅ Dropped" : "❌ Failed to drop") . ": {$t}";
    } else {
        $results[] = "⏭️ Not found (skip): {$t}";
    }
}

$conn->query("SET FOREIGN_KEY_CHECKS=1");

echo '<pre style="font-family:monospace;font-size:14px;padding:20px">';
echo "THC Schema Fix\n";
echo str_repeat('─', 40) . "\n";
foreach ($results as $r) echo $r . "\n";
echo "\n✅ Done. Now visit any THC page to recreate tables.\n";
echo "\n<b>⚠️ DELETE this file after use: thc_fix.php</b>\n";
echo '</pre>';
echo '<p style="padding:0 20px"><a href="thc_list.php">→ Go to THC List</a></p>';

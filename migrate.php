<?php
/**
 * Database Migration - Adds missing columns to consignments table.
 * Run this ONCE by visiting: http://localhost/erp_tms/migrate.php
 * Then delete this file.
 */

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/master_data.php';

$conn = getDBConnection();

$migrations = [
    "ALTER TABLE consignments ADD COLUMN handover_date DATE DEFAULT NULL AFTER booking_date",
    "ALTER TABLE consignments ADD COLUMN handover_time TIME DEFAULT NULL AFTER handover_date",
    "ALTER TABLE consignments ADD COLUMN other_charge DECIMAL(10,2) DEFAULT 0.00 AFTER misc_charge",
    "ALTER TABLE consignments ADD COLUMN consignor_address TEXT DEFAULT NULL AFTER consignor_name",
    "ALTER TABLE consignments ADD COLUMN consignor_city_id INT DEFAULT NULL AFTER consignor_address",
    "ALTER TABLE consignments ADD COLUMN consignor_state_id INT DEFAULT NULL AFTER consignor_city_id",
    "ALTER TABLE consignments ADD COLUMN consignor_pin VARCHAR(6) DEFAULT NULL AFTER consignor_state_id",
    "ALTER TABLE consignments ADD COLUMN consignor_phone VARCHAR(10) DEFAULT NULL AFTER consignor_pin",
    "ALTER TABLE consignments ADD COLUMN consignor_gst_no VARCHAR(15) DEFAULT NULL AFTER consignor_phone",
    "ALTER TABLE consignments ADD COLUMN charge_weight_mode VARCHAR(10) NOT NULL DEFAULT 'Auto' AFTER charged_weight",
    "ALTER TABLE consignments ADD COLUMN client_master_id INT DEFAULT NULL AFTER billing_party_name",
    "ALTER TABLE consignments ADD COLUMN risk_charge DECIMAL(10,2) DEFAULT 0.00 AFTER other_charge",
];

$results = [];

foreach ($migrations as $sql) {
    preg_match('/ADD COLUMN (\w+)/', $sql, $m);
    $colName = $m[1] ?? '?';

    $check = $conn->query("SHOW COLUMNS FROM consignments LIKE '{$colName}'");
    if ($check && $check->num_rows > 0) {
        $results[] = "[SKIP] Column `{$colName}` already exists.";
        continue;
    }

    if ($conn->query($sql)) {
        $results[] = "[OK] Added column `{$colName}`.";
    } else {
        $results[] = "[FAIL] `{$colName}`: " . $conn->error;
    }
}

ensureClientContractSchema($conn);
ensureClientRiskColumns($conn);
$results[] = '[OK] Client master and lane-rate contract tables are ready.';

// LxBxH is now stored for every dimension row, so allow enough room for a
// multi-piece shipment. This safely upgrades existing installations.
$volumeColumn = $conn->query("SHOW COLUMNS FROM consignments LIKE 'volume_lxwxh'");
if ($volumeColumn && ($column = $volumeColumn->fetch_assoc())) {
    if (stripos($column['Type'], 'varchar(500)') === false) {
        if ($conn->query('ALTER TABLE consignments MODIFY COLUMN volume_lxwxh VARCHAR(500) DEFAULT NULL')) {
            $results[] = '[OK] Expanded `volume_lxwxh` for LxBxH rows.';
        } else {
            $results[] = '[FAIL] `volume_lxwxh`: ' . $conn->error;
        }
    } else {
        $results[] = '[SKIP] Column `volume_lxwxh` already supports LxBxH rows.';
    }
}

$fkMigrations = [
    [
        "name" => "consignor_city_id_fk",
        "check" => "SELECT COUNT(*) AS cnt FROM information_schema.TABLE_CONSTRAINTS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='consignments'
                    AND CONSTRAINT_NAME='consignments_ibfk_consignor_city'",
        "add"   => "ALTER TABLE consignments ADD CONSTRAINT consignments_ibfk_consignor_city
                    FOREIGN KEY (consignor_city_id) REFERENCES cities(id)"
    ],
    [
        "name" => "consignor_state_id_fk",
        "check" => "SELECT COUNT(*) AS cnt FROM information_schema.TABLE_CONSTRAINTS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='consignments'
                    AND CONSTRAINT_NAME='consignments_ibfk_consignor_state'",
        "add"   => "ALTER TABLE consignments ADD CONSTRAINT consignments_ibfk_consignor_state
                    FOREIGN KEY (consignor_state_id) REFERENCES states(id)"
    ],
];

foreach ($fkMigrations as $fk) {
    $row = $conn->query($fk['check'])->fetch_assoc();
    if ($row['cnt'] > 0) {
        $results[] = "[SKIP] FK `{$fk['name']}` already exists.";
        continue;
    }
    if ($conn->query($fk['add'])) {
        $results[] = "[OK] Added FK `{$fk['name']}`.";
    } else {
        $results[] = "[FAIL] FK `{$fk['name']}`: " . $conn->error;
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head><title>DB Migration Results</title>
<style>
    body { font-family: Segoe UI, Arial, sans-serif; padding: 30px; background:#f5f6fa; }
    .card { max-width: 700px; margin: 0 auto; background:#fff; padding:24px 32px; border-radius:10px; box-shadow:0 4px 18px rgba(0,0,0,.08); }
    h1 { margin: 0 0 16px; font-size: 20px; color:#2c3e50; }
    .result { padding: 6px 10px; margin: 4px 0; border-left: 4px solid #ccc; font-family: Consolas, monospace; font-size: 13px; background:#fafbfc; border-radius:4px; }
    .ok { border-color: #27ae60; color:#1e8449; }
    .skip { border-color: #f39c12; color:#b7791f; }
    .fail { border-color: #e74c3c; color:#c0392b; background:#fdf2f2; }
    .warn { margin-top: 16px; padding: 12px; background:#fff6e5; border-left: 4px solid #f39c12; color:#7a5d10; border-radius: 4px; }
    a.btn { display:inline-block; margin-top:18px; padding:10px 20px; background:#1a5276; color:#fff; text-decoration:none; border-radius:6px; }
    a.btn:hover { background:#2471a3; }
</style>
</head>
<body>
<div class="card">
<h1>Database Migration — consignments table</h1>
<?php foreach ($results as $r):
    $cls = strpos($r, '[OK]') === 0 ? 'ok' : (strpos($r, '[SKIP]') === 0 ? 'skip' : 'fail');
    ?>
    <div class="result <?= $cls ?>"><?= htmlspecialchars($r) ?></div>
<?php endforeach; ?>

<div class="warn">
    <strong>Important:</strong> Delete this file (<code>migrate.php</code>) after successful migration for security.
</div>

<a class="btn" href="index.php">← Back to Consignment Form</a>
</div>
</body>
</html>

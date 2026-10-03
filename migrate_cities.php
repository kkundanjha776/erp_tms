<?php
/**
 * Cities schema migration + West Bengal PIN directory import.
 * Run once: http://localhost/erp_tms/migrate_cities.php
 */

require_once __DIR__ . '/includes/db_connection.php';
require_once __DIR__ . '/includes/city_data.php';

$conn = getDBConnection();
$results = [];
$excelPath = __DIR__ . '/West_Bengal_PIN_Codes_Directory.xlsx';

try {
    ensureCitiesSchema($conn);
    $results[] = '[OK] Cities table schema is up to date (pincode, district, zone_region, area).';

    if (!is_file($excelPath)) {
        $results[] = '[FAIL] Excel file not found at West_Bengal_PIN_Codes_Directory.xlsx';
    } else {
        $import = importWestBengalCities($conn, $excelPath, true);
        $results[] = '[OK] Imported West Bengal PIN directory.';
        $results[] = '[INFO] Rows processed: ' . $import['total_rows'];
        $results[] = '[INFO] Inserted: ' . $import['inserted'];
        $results[] = '[INFO] Updated: ' . $import['updated'];
        $results[] = '[INFO] Removed unused legacy WB cities: ' . $import['removed_legacy'];
    }
} catch (Throwable $e) {
    $results[] = '[FAIL] ' . $e->getMessage();
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Cities Migration</title>
    <style>
        body { font-family: Segoe UI, Arial, sans-serif; padding: 30px; background:#f5f6fa; }
        .card { max-width: 760px; margin: 0 auto; background:#fff; padding:24px 32px; border-radius:10px; box-shadow:0 4px 18px rgba(0,0,0,.08); }
        h1 { margin: 0 0 16px; font-size: 20px; color:#2c3e50; }
        .result { padding: 6px 10px; margin: 4px 0; border-left: 4px solid #ccc; font-family: Consolas, monospace; font-size: 13px; background:#fafbfc; border-radius:4px; }
        .ok { border-color: #27ae60; color:#1e8449; }
        .info { border-color: #3498db; color:#1f618d; }
        .fail { border-color: #e74c3c; color:#c0392b; background:#fdf2f2; }
        .warn { margin-top: 16px; padding: 12px; background:#fff6e5; border-left: 4px solid #f39c12; color:#7a5d10; border-radius: 4px; }
        a.btn { display:inline-block; margin-top:18px; padding:10px 20px; background:#1a5276; color:#fff; text-decoration:none; border-radius:6px; }
    </style>
</head>
<body>
<div class="card">
    <h1>Cities Migration — West Bengal PIN Directory</h1>
    <?php foreach ($results as $result):
        $cls = strpos($result, '[OK]') === 0 ? 'ok' : (strpos($result, '[INFO]') === 0 ? 'info' : 'fail');
        ?>
        <div class="result <?= $cls ?>"><?= htmlspecialchars($result) ?></div>
    <?php endforeach; ?>
    <div class="warn">
        After verifying city search in the app, you may delete <code>migrate_cities.php</code> for security.
    </div>
    <a class="btn" href="index.php">← Back to Consignment Form</a>
</div>
</body>
</html>

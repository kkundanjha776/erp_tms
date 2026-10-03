<?php
/**
 * Cities schema, West Bengal PIN import, and search helpers.
 */

function ensureCitiesSchema(mysqli $conn): void
{
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM cities');
    if ($result) {
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $column) {
            $columns[$column['Field']] = true;
        }
    }

    $additions = [
        'pincode' => "ALTER TABLE cities ADD COLUMN pincode VARCHAR(6) DEFAULT NULL AFTER city_name",
        'district' => "ALTER TABLE cities ADD COLUMN district VARCHAR(100) DEFAULT NULL AFTER pincode",
        'zone_region' => "ALTER TABLE cities ADD COLUMN zone_region VARCHAR(100) DEFAULT NULL AFTER district",
        'area' => "ALTER TABLE cities ADD COLUMN area TEXT DEFAULT NULL AFTER zone_region",
    ];

    foreach ($additions as $field => $sql) {
        if (!isset($columns[$field])) {
            $conn->query($sql);
        }
    }

    $indexes = [];
    $idxResult = $conn->query("SHOW INDEX FROM cities");
    if ($idxResult) {
        foreach ($idxResult->fetch_all(MYSQLI_ASSOC) as $idx) {
            $indexes[$idx['Key_name']] = true;
        }
    }

    if (!isset($indexes['idx_cities_pincode'])) {
        $conn->query('ALTER TABLE cities ADD INDEX idx_cities_pincode (pincode)');
    }
    if (!isset($indexes['idx_cities_district'])) {
        $conn->query('ALTER TABLE cities ADD INDEX idx_cities_district (district)');
    }
    if (!isset($indexes['uq_cities_pincode_state'])) {
        $conn->query('ALTER TABLE cities ADD UNIQUE KEY uq_cities_pincode_state (pincode, state_code)');
    }
}

function deriveCityNameFromPinRow(string $areas, string $zoneRegion, string $district): string
{
    $areas = trim($areas);
    if ($areas !== '') {
        $parts = array_map('trim', explode(',', $areas));
        $first = $parts[0] ?? '';
        if ($first !== '') {
            return $first;
        }
    }

    $zoneRegion = trim($zoneRegion);
    if ($zoneRegion !== '') {
        return $zoneRegion;
    }

    return trim($district) !== '' ? trim($district) : 'Unknown';
}

function formatCityLabel(array $city): string
{
    $name = trim((string) ($city['city_name'] ?? ''));
    $pin = trim((string) ($city['pincode'] ?? ''));
    $district = trim((string) ($city['district'] ?? ''));

    $label = $name;
    if ($pin !== '') {
        $label .= ' (' . $pin . ')';
    }
    if ($district !== '' && stripos($label, $district) === false) {
        $label .= ' - ' . $district;
    }

    return $label;
}

function normalizeCityRow(array $row): array
{
    $row['display_label'] = formatCityLabel($row);
    return $row;
}

function citySelectColumns(): string
{
    return 'c.id, c.city_name, c.pincode, c.district, c.zone_region, c.area, c.state_code, s.state_name';
}

function searchCities(mysqli $conn, string $query, ?string $stateCode = null, int $limit = 25): array
{
    ensureCitiesSchema($conn);

    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $limit = max(1, min($limit, 50));
    $like = '%' . $query . '%';
    $pinPrefix = preg_match('/^\d{1,6}$/', $query) ? $query . '%' : null;

    $sql = 'SELECT ' . citySelectColumns() . '
            FROM cities c
            JOIN states s ON c.state_code = s.state_code
            WHERE (c.city_name LIKE ? OR c.district LIKE ? OR c.area LIKE ? OR c.zone_region LIKE ?';
    $types = 'ssss';
    $params = [$like, $like, $like, $like];

    if ($pinPrefix !== null) {
        $sql .= ' OR c.pincode LIKE ?';
        $types .= 's';
        $params[] = $pinPrefix;
    }

    $sql .= ')';

    if ($stateCode !== null && $stateCode !== '') {
        $sql .= ' AND c.state_code = ?';
        $types .= 's';
        $params[] = $stateCode;
    }

    $sql .= ' ORDER BY
                CASE
                    WHEN c.pincode = ? THEN 0
                    WHEN c.pincode LIKE ? THEN 1
                    WHEN c.city_name LIKE ? THEN 2
                    WHEN c.district LIKE ? THEN 3
                    ELSE 4
                END,
                c.district,
                c.pincode,
                c.city_name
              LIMIT ?';

    $exactPin = preg_match('/^\d{6}$/', $query) ? $query : '';
    $types .= 'ssssi';
    $params[] = $exactPin;
    $params[] = $pinPrefix ?? $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $limit;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_map('normalizeCityRow', $rows);
}

function getCityById(mysqli $conn, int $cityId): ?array
{
    if ($cityId <= 0) {
        return null;
    }

    ensureCitiesSchema($conn);
    $stmt = $conn->prepare(
        'SELECT ' . citySelectColumns() . '
         FROM cities c
         JOIN states s ON c.state_code = s.state_code
         WHERE c.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $cityId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? normalizeCityRow($row) : null;
}

function parseWestBengalPinExcel(string $filePath): array
{
    if (!is_file($filePath)) {
        throw new RuntimeException('Excel file not found: ' . $filePath);
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('Unable to open Excel file.');
    }

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $doc = new DOMDocument();
        $doc->loadXML($sharedXml);
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($xpath->query('//m:si') as $si) {
            $text = '';
            foreach ($xpath->query('.//m:t', $si) as $t) {
                $text .= $t->textContent;
            }
            $shared[] = $text;
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Worksheet sheet1.xml not found in Excel file.');
    }

    $doc = new DOMDocument();
    $doc->loadXML($sheetXml);
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $rows = [];
    foreach ($xpath->query('//m:sheetData/m:row') as $row) {
        $line = [];
        foreach ($xpath->query('./m:c', $row) as $cell) {
            $ref = $cell->getAttribute('r');
            preg_match('/^([A-Z]+)/', strtoupper($ref), $match);
            $letters = $match[1] ?? 'A';
            $index = 0;
            for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
                $index = $index * 26 + (ord($letters[$i]) - 64);
            }
            $index--;

            $type = $cell->getAttribute('t');
            $valueNode = $cell->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'v')->item(0);
            $raw = $valueNode ? $valueNode->textContent : '';
            if ($type === 's') {
                $raw = $shared[(int) $raw] ?? '';
            }
            $line[$index] = trim((string) $raw);
        }

        if ($line) {
            ksort($line);
            $rows[] = array_values($line);
        }
    }

    if (count($rows) < 2) {
        throw new RuntimeException('Excel file has no data rows.');
    }

    $headers = array_map('strtolower', $rows[0]);
    $mapped = [];
    for ($i = 1, $count = count($rows); $i < $count; $i++) {
        $row = $rows[$i];
        $record = [
            'pincode' => '',
            'district' => '',
            'zone_region' => '',
            'area' => '',
        ];

        foreach ($headers as $idx => $header) {
            $value = $row[$idx] ?? '';
            if (strpos($header, 'pin') !== false) {
                $record['pincode'] = preg_replace('/\D/', '', $value);
            } elseif ($header === 'district') {
                $record['district'] = $value;
            } elseif (strpos($header, 'zone') !== false || strpos($header, 'region') !== false) {
                $record['zone_region'] = $value;
            } elseif (strpos($header, 'area') !== false || strpos($header, 'local') !== false) {
                $record['area'] = $value;
            }
        }

        if (strlen($record['pincode']) === 6) {
            $mapped[] = $record;
        }
    }

    return $mapped;
}

function removeUnusedLegacyWestBengalCities(mysqli $conn): int
{
    $sql = "DELETE c FROM cities c
            WHERE c.state_code = 'WB'
              AND (c.pincode IS NULL OR c.pincode = '')
              AND c.id NOT IN (
                  SELECT city_id FROM (
                      SELECT origin_city_id AS city_id FROM consignments WHERE origin_city_id IS NOT NULL
                      UNION SELECT destination_city_id FROM consignments WHERE destination_city_id IS NOT NULL
                      UNION SELECT billing_city_id FROM consignments WHERE billing_city_id IS NOT NULL
                      UNION SELECT consignor_city_id FROM consignments WHERE consignor_city_id IS NOT NULL
                      UNION SELECT consignee_city_id FROM consignments WHERE consignee_city_id IS NOT NULL
                      UNION SELECT city_id FROM client_masters WHERE city_id IS NOT NULL
                      UNION SELECT origin_city_id FROM client_lane_rates WHERE origin_city_id IS NOT NULL
                      UNION SELECT destination_city_id FROM client_lane_rates WHERE destination_city_id IS NOT NULL
                      UNION SELECT city_id FROM party_masters WHERE city_id IS NOT NULL
                  ) referenced
              )";

    $conn->query($sql);
    return $conn->affected_rows;
}

function importWestBengalCities(mysqli $conn, string $filePath, bool $removeLegacy = true): array
{
    ensureCitiesSchema($conn);

    $records = parseWestBengalPinExcel($filePath);
    $removedLegacy = 0;
    if ($removeLegacy) {
        $removedLegacy = removeUnusedLegacyWestBengalCities($conn);
    }

    $stmt = $conn->prepare(
        'INSERT INTO cities (city_name, pincode, district, zone_region, area, state_code)
         VALUES (?, ?, ?, ?, ?, \'WB\')
         ON DUPLICATE KEY UPDATE
            city_name = VALUES(city_name),
            district = VALUES(district),
            zone_region = VALUES(zone_region),
            area = VALUES(area)'
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare city import statement: ' . $conn->error);
    }

    $inserted = 0;
    $updated = 0;

    foreach ($records as $record) {
        $cityName = deriveCityNameFromPinRow($record['area'], $record['zone_region'], $record['district']);
        $pincode = $record['pincode'];
        $district = $record['district'];
        $zoneRegion = $record['zone_region'];
        $area = $record['area'];

        $stmt->bind_param('sssss', $cityName, $pincode, $district, $zoneRegion, $area);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('City import failed for PIN ' . $pincode . ': ' . $error);
        }

        if ($stmt->affected_rows === 1) {
            $inserted++;
        } elseif ($stmt->affected_rows === 2) {
            $updated++;
        }
    }

    $stmt->close();

    return [
        'total_rows' => count($records),
        'inserted' => $inserted,
        'updated' => $updated,
        'removed_legacy' => $removedLegacy,
    ];
}

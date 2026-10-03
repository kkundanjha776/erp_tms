<?php
$file = __DIR__ . '/../West_Bengal_PIN_Codes_Directory.xlsx';
$zip = new ZipArchive();
$zip->open($file);
$shared = [];
$sharedXml = $zip->getFromName('xl/sharedStrings.xml');
if ($sharedXml !== false) {
    $doc = new DOMDocument();
    $doc->loadXML($sharedXml);
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    foreach ($xpath->query('//m:si') as $si) {
        $text = '';
        foreach ($xpath->query('.//m:t', $si) as $t) $text .= $t->textContent;
        $shared[] = $text;
    }
}
$sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
$zip->close();
$doc = new DOMDocument();
$doc->loadXML($sheetXml);
$xpath = new DOMXPath($doc);
$xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
function colIndex(string $ref): int {
    preg_match('/^([A-Z]+)/', strtoupper($ref), $m);
    $n = 0; foreach (str_split($m[1]) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}
function cellValue(DOMElement $cell, array $shared): string {
    $type = $cell->getAttribute('t');
    $vNodes = $cell->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'v');
    if ($vNodes->length === 0) return '';
    $raw = $vNodes->item(0)->textContent;
    return $type === 's' ? ($shared[(int)$raw] ?? '') : $raw;
}
$rows = [];
foreach ($xpath->query('//m:sheetData/m:row') as $row) {
    $line = [];
    foreach ($xpath->query('./m:c', $row) as $cell) {
        $line[colIndex($cell->getAttribute('r'))] = cellValue($cell, $shared);
    }
    if ($line) { ksort($line); $rows[] = array_values($line); }
}
$headers = array_shift($rows);
$districts = [];
foreach ($rows as $r) $districts[$r[1]] = ($districts[$r[1]] ?? 0) + 1;
echo "Districts:\n"; print_r($districts);
echo "Last 3 rows:\n";
foreach (array_slice($rows, -3) as $r) echo json_encode(array_combine($headers, $r), JSON_UNESCAPED_UNICODE) . "\n";

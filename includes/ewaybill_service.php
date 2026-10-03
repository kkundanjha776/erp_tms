<?php
/**
 * E-Way Bill API Service — NIC GST E-Way Bill System integration (v1.03)
 */

require_once __DIR__ . '/ewaybill_config.php';

class EwaybillService
{
    private string $baseUrl;
    private ?string $authToken = null;
    private ?string $sek = null;
    private ?string $appKey = null;

    public function __construct()
    {
        $this->baseUrl = getEwaybillBaseUrl();
        $this->restoreSessionTokens();
    }

    public function fetchEwayBill(string $ewbNo): array
    {
        $ewbNo = preg_replace('/\D/', '', $ewbNo);
        if (!preg_match('/^\d{12}$/', $ewbNo)) {
            return ['success' => false, 'message' => 'E-Way Bill number must be exactly 12 digits.', 'data' => null];
        }

        if (isEwaybillDemoMode()) {
            return ['success' => true, 'message' => 'Demo data loaded (configure API credentials for live lookup).', 'data' => $this->buildDemoResponse($ewbNo), 'demo' => true];
        }

        try {
            $this->ensureAuthenticated();
            $raw = $this->callGetEwayBill($ewbNo);
            $normalized = $this->normalizeResponse($raw);
            return ['success' => true, 'message' => 'E-Way Bill fetched successfully.', 'data' => $normalized, 'raw' => $raw];
        } catch (Throwable $e) {
            error_log('Ewaybill fetch error [' . $ewbNo . ']: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'data' => null];
        }
    }

    public function fetchMultiple(array $ewbNumbers): array
    {
        $results = [];
        foreach ($ewbNumbers as $no) {
            $no = trim((string) $no);
            if ($no === '') continue;
            $results[] = array_merge(['ewb_no' => preg_replace('/\D/', '', $no)], $this->fetchEwayBill($no));
        }
        return $results;
    }

    private function ensureAuthenticated(): void
    {
        if ($this->authToken && $this->sek) {
            return;
        }
        $this->authenticate();
    }

    private function authenticate(): void
    {
        if (!is_file(EWAYBILL_PUBLIC_KEY_PATH)) {
            throw new RuntimeException('E-Way Bill public key not found at ' . EWAYBILL_PUBLIC_KEY_PATH);
        }

        $publicKey = file_get_contents(EWAYBILL_PUBLIC_KEY_PATH);
        if ($publicKey === false) {
            throw new RuntimeException('Unable to read E-Way Bill public key.');
        }

        $this->appKey = $this->generateAppKey();
        $payload = [
            'action' => 'ACCESSTOKEN',
            'username' => EWAYBILL_USERNAME,
            'password' => $this->rsaEncrypt(EWAYBILL_PASSWORD, $publicKey),
            'app_key' => $this->rsaEncrypt($this->appKey, $publicKey),
        ];

        $dataField = $this->rsaEncrypt(base64_encode(json_encode($payload)), $publicKey);
        $body = json_encode(['Data' => $dataField]);

        $url = $this->baseUrl . 'authenticate/';
        $response = $this->httpPost($url, $body, $this->authHeaders(false));

        if (($response['status'] ?? '0') !== '1' && ($response['status'] ?? 0) != 1) {
            $err = $response['ErrorDetails'][0]['ErrorMessage'] ?? ($response['error'] ?? 'Authentication failed');
            throw new RuntimeException('E-Way Bill authentication failed: ' . $err);
        }

        $this->authToken = (string) ($response['authtoken'] ?? '');
        $encryptedSek = (string) ($response['sek'] ?? '');
        if ($this->authToken === '' || $encryptedSek === '') {
            throw new RuntimeException('Invalid authentication response from E-Way Bill system.');
        }

        $this->sek = $this->aesDecrypt(base64_decode($encryptedSek), $this->appKey);
        $this->persistSessionTokens();
    }

    private function callGetEwayBill(string $ewbNo): array
    {
        $requestJson = json_encode(['ewbNo' => $ewbNo]);
        $encrypted = $this->aesEncrypt($requestJson, $this->sek);
        $body = json_encode(['Data' => base64_encode($encrypted)]);

        $url = $this->baseUrl . 'ewayapi/GetEwayBill';
        $response = $this->httpPost($url, $body, $this->authHeaders(true));

        if (isset($response['error'])) {
            throw new RuntimeException('E-Way Bill lookup failed: ' . $response['error']);
        }

        if (isset($response['Data'])) {
            $rekEncrypted = base64_decode((string) $response['rek']);
            $dataEncrypted = base64_decode((string) $response['Data']);
            $rek = $this->aesDecrypt($rekEncrypted, $this->sek);
            $plain = $this->aesDecrypt($dataEncrypted, $rek);
            $decoded = json_decode($plain, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Unable to decode E-Way Bill response.');
            }
            return $decoded;
        }

        return is_array($response) ? $response : [];
    }

    private function authHeaders(bool $withToken): array
    {
        $headers = [
            'Content-Type: application/json',
            'client-id: ' . EWAYBILL_CLIENT_ID,
            'client-secret: ' . EWAYBILL_CLIENT_SECRET,
            'Gstin: ' . EWAYBILL_GSTIN,
        ];
        if ($withToken && $this->authToken) {
            $headers[] = 'authtoken: ' . $this->authToken;
        }
        return $headers;
    }

    private function httpPost(string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException('HTTP error contacting E-Way Bill API: ' . $error);
        }
        if ($httpCode >= 400) {
            throw new RuntimeException('E-Way Bill API returned HTTP ' . $httpCode);
        }

        $json = json_decode((string) $raw, true);
        return is_array($json) ? $json : ['error' => 'Invalid JSON response', 'raw' => $raw];
    }

    private function rsaEncrypt(string $data, string $publicKeyPem): string
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            throw new RuntimeException('Invalid E-Way Bill public key.');
        }
        $encrypted = '';
        if (!openssl_public_encrypt($data, $encrypted, $key, OPENSSL_PKCS1_PADDING)) {
            throw new RuntimeException('RSA encryption failed.');
        }
        return base64_encode($encrypted);
    }

    private function aesEncrypt(string $plainText, string $key): string
    {
        $key = substr($key, 0, 32);
        $cipher = openssl_encrypt($plainText, 'AES-256-ECB', $key, OPENSSL_RAW_DATA);
        if ($cipher === false) {
            throw new RuntimeException('AES encryption failed.');
        }
        return $cipher;
    }

    private function aesDecrypt(string $cipherText, string $key): string
    {
        $key = substr($key, 0, 32);
        $plain = openssl_decrypt($cipherText, 'AES-256-ECB', $key, OPENSSL_RAW_DATA);
        if ($plain === false) {
            throw new RuntimeException('AES decryption failed.');
        }
        return $plain;
    }

    private function generateAppKey(): string
    {
        return substr(bin2hex(random_bytes(16)), 0, 32);
    }

    private function persistSessionTokens(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION['ewaybill_auth'] = [
            'token' => $this->authToken,
            'sek' => $this->sek ? base64_encode($this->sek) : null,
            'app_key' => $this->appKey,
            'expires' => time() + (350 * 60),
        ];
    }

    private function restoreSessionTokens(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $saved = $_SESSION['ewaybill_auth'] ?? null;
        if (!is_array($saved)) {
            return;
        }
        if (($saved['expires'] ?? 0) < time()) {
            unset($_SESSION['ewaybill_auth']);
            return;
        }
        $this->authToken = $saved['token'] ?? null;
        $this->appKey = $saved['app_key'] ?? null;
        if (!empty($saved['sek'])) {
            $this->sek = base64_decode((string) $saved['sek']);
        }
    }

    public function normalizeResponse(array $raw): array
    {
        $items = [];
        foreach ($raw['itemList'] ?? [] as $item) {
            $items[] = [
                'item_no' => $item['itemNo'] ?? '',
                'product_name' => trim($item['productName'] ?? $item['productDesc'] ?? ''),
                'hsn_code' => $item['hsnCode'] ?? '',
                'quantity' => $item['quantity'] ?? '',
                'taxable_amount' => $item['taxableAmount'] ?? '',
                'cgst_rate' => $item['cgstRate'] ?? '',
                'sgst_rate' => $item['sgstRate'] ?? '',
                'igst_rate' => $item['igstRate'] ?? '',
            ];
        }

        $vehicles = [];
        foreach ($raw['VehiclListDetails'] ?? [] as $v) {
            $vehicles[] = [
                'vehicle_no' => trim($v['vehicleNo'] ?? ''),
                'from_place' => $v['fromPlace'] ?? '',
                'from_state' => $v['fromState'] ?? '',
                'trans_mode' => $this->transportModeLabel($v['transMode'] ?? ''),
                'trans_doc_no' => $v['transDocNo'] ?? '',
                'trans_doc_date' => $v['transDocDate'] ?? '',
                'entered_date' => $v['enteredDate'] ?? '',
            ];
        }

        $consignorAddr = trim(implode(', ', array_filter([
            $raw['fromAddr1'] ?? '',
            $raw['fromAddr2'] ?? '',
            $raw['fromPlace'] ?? '',
        ])));

        $consigneeAddr = trim(implode(', ', array_filter([
            $raw['toAddr1'] ?? '',
            $raw['toAddr2'] ?? '',
            $raw['toPlace'] ?? '',
        ])));

        $goodsSummary = [];
        foreach ($items as $it) {
            $goodsSummary[] = ($it['product_name'] ?: 'Item') . ' (HSN: ' . ($it['hsn_code'] ?: '—') . ')';
        }

        return [
            'ewb_no' => (string) ($raw['ewbNo'] ?? ''),
            'eway_bill_date' => $raw['ewayBillDate'] ?? '',
            'valid_from' => $raw['ewayBillDate'] ?? '',
            'valid_upto' => $raw['validUpto'] ?? '',
            'gen_mode' => $raw['genMode'] ?? '',
            'status' => $this->statusLabel($raw['status'] ?? ''),
            'status_code' => $raw['status'] ?? '',
            'doc_type' => $raw['docType'] ?? '',
            'doc_no' => $raw['docNo'] ?? '',
            'doc_date' => $raw['docDate'] ?? '',
            'consignor_name' => $raw['fromTrdName'] ?? '',
            'consignor_gstin' => $raw['fromGstin'] ?? '',
            'consignor_address' => $consignorAddr,
            'consignor_place' => $raw['fromPlace'] ?? '',
            'consignor_pincode' => (string) ($raw['fromPincode'] ?? ''),
            'consignor_state_code' => (string) ($raw['fromStateCode'] ?? ''),
            'consignee_name' => $raw['toTrdName'] ?? '',
            'consignee_gstin' => $raw['toGstin'] ?? '',
            'consignee_address' => $consigneeAddr,
            'consignee_place' => $raw['toPlace'] ?? '',
            'consignee_pincode' => (string) ($raw['toPincode'] ?? ''),
            'consignee_state_code' => (string) ($raw['toStateCode'] ?? ''),
            'invoice_value' => $raw['totInvValue'] ?? ($raw['totalValue'] ?? ''),
            'taxable_value' => $raw['totalValue'] ?? '',
            'actual_distance' => $raw['actualDist'] ?? '',
            'transporter_id' => $raw['transporterId'] ?? '',
            'transporter_name' => $raw['transporterName'] ?? '',
            'vehicle_no' => $vehicles[0]['vehicle_no'] ?? '',
            'supply_type' => $raw['supplyType'] ?? '',
            'sub_supply_type' => $raw['subSupplyType'] ?? '',
            'transaction_type' => $raw['transactionType'] ?? '',
            'goods_details' => implode('; ', $goodsSummary),
            'hsn_details' => implode(', ', array_filter(array_column($items, 'hsn_code'))),
            'items' => $items,
            'part_a' => [
                'from_gstin' => $raw['fromGstin'] ?? '',
                'from_trade_name' => $raw['fromTrdName'] ?? '',
                'to_gstin' => $raw['toGstin'] ?? '',
                'to_trade_name' => $raw['toTrdName'] ?? '',
                'doc_type' => $raw['docType'] ?? '',
                'doc_no' => $raw['docNo'] ?? '',
                'doc_date' => $raw['docDate'] ?? '',
                'supply_type' => $raw['supplyType'] ?? '',
                'sub_supply_type' => $raw['subSupplyType'] ?? '',
                'transaction_type' => $raw['transactionType'] ?? '',
            ],
            'part_b' => $vehicles,
            'reject_status' => $raw['rejectStatus'] ?? '',
            'extended_times' => $raw['extendedTimes'] ?? 0,
        ];
    }

    private function statusLabel(string $code): string
    {
        $map = [
            'ACT' => 'Active',
            'CNL' => 'Cancelled',
            'DIS' => 'Discarded',
            'EXP' => 'Expired',
        ];
        return $map[strtoupper($code)] ?? $code;
    }

    private function transportModeLabel($mode): string
    {
        $mode = trim((string) $mode);
        $map = ['1' => 'Road', '2' => 'Rail', '3' => 'Air', '4' => 'Ship'];
        return $map[$mode] ?? $mode;
    }

    private function buildDemoResponse(string $ewbNo): array
    {
        $suffix = substr($ewbNo, -4);
        return $this->normalizeResponse([
            'ewbNo' => (int) $ewbNo,
            'ewayBillDate' => date('d/m/Y h:i:s A'),
            'validUpto' => date('d/m/Y h:i:s A', strtotime('+3 days')),
            'genMode' => 'API',
            'status' => 'ACT',
            'docType' => 'INV',
            'docNo' => 'INV-DEMO-' . $suffix,
            'docDate' => date('d/m/Y'),
            'fromGstin' => '29AABCU9603R1ZM',
            'fromTrdName' => 'Demo Consignor Pvt Ltd',
            'fromAddr1' => '123 Industrial Area',
            'fromAddr2' => 'Peenya',
            'fromPlace' => 'Bangalore',
            'fromPincode' => 560058,
            'fromStateCode' => 29,
            'toGstin' => '27AAACR5055K1Z7',
            'toTrdName' => 'Demo Consignee Ltd',
            'toAddr1' => '456 Warehouse Road',
            'toAddr2' => 'Bhiwandi',
            'toPlace' => 'Mumbai',
            'toPincode' => 421302,
            'toStateCode' => 27,
            'totalValue' => 125000.00,
            'totInvValue' => 147500.00,
            'transporterId' => '29AABCU9603R1ZM',
            'transporterName' => 'Demo Transporter Services',
            'actualDist' => 980,
            'supplyType' => 'O',
            'subSupplyType' => '1',
            'transactionType' => 1,
            'rejectStatus' => 'N',
            'extendedTimes' => 0,
            'itemList' => [
                [
                    'itemNo' => 1,
                    'productName' => 'Electronic Components',
                    'hsnCode' => '85423100',
                    'quantity' => 50,
                    'taxableAmount' => 75000,
                    'cgstRate' => 0,
                    'sgstRate' => 0,
                    'igstRate' => 18,
                ],
                [
                    'itemNo' => 2,
                    'productName' => 'Packaging Material',
                    'hsnCode' => '39239090',
                    'quantity' => 200,
                    'taxableAmount' => 50000,
                    'cgstRate' => 0,
                    'sgstRate' => 0,
                    'igstRate' => 18,
                ],
            ],
            'VehiclListDetails' => [
                [
                    'vehicleNo' => 'KA01AB' . $suffix,
                    'fromPlace' => 'Bangalore',
                    'fromState' => 29,
                    'transMode' => '1',
                    'transDocNo' => 'LR-' . $suffix,
                    'transDocDate' => date('d/m/Y'),
                    'enteredDate' => date('d/m/Y h:i:s A'),
                ],
            ],
        ]);
    }
}

function ensureEwaybillSchema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $conn->query("CREATE TABLE IF NOT EXISTS ewaybill_dockets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ewb_no VARCHAR(12) NOT NULL,
        eway_bill_date VARCHAR(50) DEFAULT NULL,
        valid_from VARCHAR(50) DEFAULT NULL,
        valid_upto VARCHAR(50) DEFAULT NULL,
        consignor_name VARCHAR(200) DEFAULT NULL,
        consignor_gstin VARCHAR(15) DEFAULT NULL,
        consignor_address TEXT DEFAULT NULL,
        consignor_place VARCHAR(100) DEFAULT NULL,
        consignor_pincode VARCHAR(10) DEFAULT NULL,
        consignee_name VARCHAR(200) DEFAULT NULL,
        consignee_gstin VARCHAR(15) DEFAULT NULL,
        consignee_address TEXT DEFAULT NULL,
        consignee_place VARCHAR(100) DEFAULT NULL,
        consignee_pincode VARCHAR(10) DEFAULT NULL,
        doc_no VARCHAR(50) DEFAULT NULL,
        doc_date VARCHAR(20) DEFAULT NULL,
        doc_type VARCHAR(20) DEFAULT NULL,
        invoice_value DECIMAL(14,2) DEFAULT NULL,
        goods_details TEXT DEFAULT NULL,
        hsn_details TEXT DEFAULT NULL,
        actual_distance INT DEFAULT NULL,
        vehicle_no VARCHAR(30) DEFAULT NULL,
        transporter_id VARCHAR(20) DEFAULT NULL,
        transporter_name VARCHAR(200) DEFAULT NULL,
        part_a_json TEXT DEFAULT NULL,
        part_b_json TEXT DEFAULT NULL,
        items_json TEXT DEFAULT NULL,
        current_status VARCHAR(30) DEFAULT NULL,
        raw_response LONGTEXT DEFAULT NULL,
        consignment_id INT DEFAULT NULL,
        remarks TEXT DEFAULT NULL,
        is_demo TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('Draft','Submitted') NOT NULL DEFAULT 'Draft',
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ewb_no (ewb_no),
        INDEX idx_ewb_status (current_status),
        INDEX idx_ewb_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function saveEwaybillDocket(mysqli $conn, array $data, int $userId): int
{
    ensureEwaybillSchema($conn);

    $ewbNo = preg_replace('/\D/', '', (string) ($data['ewb_no'] ?? ''));
    if (!preg_match('/^\d{12}$/', $ewbNo)) {
        throw new InvalidArgumentException('Invalid E-Way Bill number.');
    }

    $fields = [
        'ewb_no' => $ewbNo,
        'eway_bill_date' => $data['eway_bill_date'] ?? null,
        'valid_from' => $data['valid_from'] ?? null,
        'valid_upto' => $data['valid_upto'] ?? null,
        'consignor_name' => $data['consignor_name'] ?? null,
        'consignor_gstin' => $data['consignor_gstin'] ?? null,
        'consignor_address' => $data['consignor_address'] ?? null,
        'consignor_place' => $data['consignor_place'] ?? null,
        'consignor_pincode' => $data['consignor_pincode'] ?? null,
        'consignee_name' => $data['consignee_name'] ?? null,
        'consignee_gstin' => $data['consignee_gstin'] ?? null,
        'consignee_address' => $data['consignee_address'] ?? null,
        'consignee_place' => $data['consignee_place'] ?? null,
        'consignee_pincode' => $data['consignee_pincode'] ?? null,
        'doc_no' => $data['doc_no'] ?? null,
        'doc_date' => $data['doc_date'] ?? null,
        'doc_type' => $data['doc_type'] ?? null,
        'invoice_value' => isset($data['invoice_value']) ? (float) $data['invoice_value'] : null,
        'goods_details' => $data['goods_details'] ?? null,
        'hsn_details' => $data['hsn_details'] ?? null,
        'actual_distance' => isset($data['actual_distance']) ? (int) $data['actual_distance'] : null,
        'vehicle_no' => $data['vehicle_no'] ?? null,
        'transporter_id' => $data['transporter_id'] ?? null,
        'transporter_name' => $data['transporter_name'] ?? null,
        'part_a_json' => json_encode($data['part_a'] ?? [], JSON_UNESCAPED_UNICODE),
        'part_b_json' => json_encode($data['part_b'] ?? [], JSON_UNESCAPED_UNICODE),
        'items_json' => json_encode($data['items'] ?? [], JSON_UNESCAPED_UNICODE),
        'current_status' => $data['status'] ?? ($data['current_status'] ?? null),
        'raw_response' => $data['raw_response'] ?? null,
        'remarks' => $data['remarks'] ?? null,
        'is_demo' => !empty($data['is_demo']) ? 1 : 0,
        'status' => in_array($data['save_status'] ?? 'Draft', ['Draft', 'Submitted'], true) ? $data['save_status'] : 'Draft',
        'created_by' => $userId,
    ];

    $existing = $conn->prepare('SELECT id FROM ewaybill_dockets WHERE ewb_no=? LIMIT 1');
    $existing->bind_param('s', $ewbNo);
    $existing->execute();
    $row = $existing->get_result()->fetch_assoc();
    $existing->close();

    if ($row) {
        $id = (int) $row['id'];
        $sets = [];
        $types = '';
        $values = [];
        foreach ($fields as $col => $val) {
            if ($col === 'created_by') continue;
            $sets[] = "$col=?";
            if (is_int($val)) {
                $types .= 'i';
            } elseif (is_float($val)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $values[] = $val;
        }
        $types .= 'i';
        $values[] = $id;
        $sql = 'UPDATE ewaybill_dockets SET ' . implode(',', $sets) . ' WHERE id=?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();
        return $id;
    }

    $cols = array_keys($fields);
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $types = '';
    foreach ($fields as $val) {
        if (is_int($val)) $types .= 'i';
        elseif (is_float($val)) $types .= 'd';
        else $types .= 's';
    }
    $sql = 'INSERT INTO ewaybill_dockets (' . implode(',', $cols) . ") VALUES ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...array_values($fields));
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();
    return $id;
}

function getRecentEwaybillDockets(mysqli $conn, int $limit = 20): array
{
    ensureEwaybillSchema($conn);
    $limit = max(1, min(100, $limit));
    $result = $conn->query("SELECT id, ewb_no, consignor_name, consignee_name, vehicle_no, current_status, valid_upto, status, created_at
                            FROM ewaybill_dockets ORDER BY created_at DESC LIMIT $limit");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

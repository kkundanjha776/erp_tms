<?php
/**
 * Master Data Fetch Functions
 */

require_once __DIR__ . '/city_data.php';

function getAllStates(mysqli $conn): array
{
    $result = $conn->query('SELECT id, state_name, state_code FROM states ORDER BY state_name');
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getCitiesByState(mysqli $conn, ?string $stateCode = null): array
{
    ensureCitiesSchema($conn);

    if ($stateCode) {
        $stmt = $conn->prepare(
            'SELECT ' . citySelectColumns() . '
             FROM cities c
             JOIN states s ON c.state_code = s.state_code
             WHERE c.state_code = ?
             ORDER BY c.district, c.pincode, c.city_name'
        );
        $stmt->bind_param('s', $stateCode);
        $stmt->execute();
        $result = $stmt->get_result();
        $cities = array_map('normalizeCityRow', $result->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
        return $cities;
    }

    $result = $conn->query(
        'SELECT ' . citySelectColumns() . '
         FROM cities c
         JOIN states s ON c.state_code = s.state_code
         ORDER BY c.state_code, c.district, c.pincode, c.city_name'
    );
    return $result ? array_map('normalizeCityRow', $result->fetch_all(MYSQLI_ASSOC)) : [];
}

function getAllCities(mysqli $conn): array
{
    return getCitiesByState($conn);
}

function getBookingTypes(mysqli $conn): array
{
    $result = $conn->query('SELECT id, type_name FROM booking_types ORDER BY id');
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getPaymentTypes(mysqli $conn): array
{
    $result = $conn->query('SELECT id, type_name FROM payment_types ORDER BY id');
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getTruckTypes(mysqli $conn): array
{
    $result = $conn->query('SELECT id, type_name, capacity FROM truck_types ORDER BY id');
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getPackingMethods(mysqli $conn): array
{
    $result = $conn->query('SELECT id, method_name FROM packing_methods ORDER BY id');
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getCourierCompanies(mysqli $conn): array
{
    $result = $conn->query("SELECT id, company_name FROM courier_companies WHERE status = 'Active' ORDER BY company_name");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function ensurePartyMasterTable(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS party_masters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            party_name VARCHAR(200) NOT NULL UNIQUE,
            address TEXT DEFAULT NULL,
            city_id INT DEFAULT NULL,
            state_id INT DEFAULT NULL,
            pin VARCHAR(6) DEFAULT NULL,
            phone VARCHAR(10) DEFAULT NULL,
            gst_no VARCHAR(15) DEFAULT NULL,
            status ENUM('Active', 'Inactive') DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $conn->query(
        "INSERT INTO party_masters (party_name, address, city_id, state_id, pin, phone, gst_no)
         SELECT
            c.billing_party_name,
            c.billing_address,
            NULLIF(c.billing_city_id, 0),
            NULLIF(c.billing_state_id, 0),
            c.billing_pin,
            c.billing_phone,
            c.billing_gst_no
         FROM consignments c
         INNER JOIN (
            SELECT billing_party_name, MAX(id) AS latest_id
            FROM consignments
            WHERE billing_party_name IS NOT NULL AND billing_party_name <> ''
            GROUP BY billing_party_name
         ) latest ON latest.latest_id = c.id
         ON DUPLICATE KEY UPDATE
            address = VALUES(address),
            city_id = VALUES(city_id),
            state_id = VALUES(state_id),
            pin = VALUES(pin),
            phone = VALUES(phone),
            gst_no = VALUES(gst_no)"
    );

    // Backfill names that have historically appeared only as consignor or
    // consignee, without replacing an already maintained party master record.
    $conn->query(
        "INSERT IGNORE INTO party_masters (party_name, address, city_id, state_id, pin, phone, gst_no)
         SELECT consignor_name, consignor_address, NULLIF(consignor_city_id, 0), NULLIF(consignor_state_id, 0),
                consignor_pin, consignor_phone, consignor_gst_no
         FROM consignments WHERE consignor_name IS NOT NULL AND consignor_name <> ''"
    );
    $conn->query(
        "INSERT IGNORE INTO party_masters (party_name, address, city_id, state_id, pin, phone, gst_no)
         SELECT consignee_name, consignee_address, NULLIF(consignee_city_id, 0), NULLIF(consignee_state_id, 0),
                consignee_pin, consignee_phone, consignee_gst_no
         FROM consignments WHERE consignee_name IS NOT NULL AND consignee_name <> ''"
    );
}

/** Client contracts are kept separate from the general party autocomplete master. */
function ensureClientContractSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS client_masters (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_code VARCHAR(50) NOT NULL UNIQUE,
        client_name VARCHAR(200) NOT NULL,
        division VARCHAR(150) DEFAULT NULL,
        address TEXT DEFAULT NULL,
        state_id INT DEFAULT NULL,
        city_id INT DEFAULT NULL,
        pin VARCHAR(6) DEFAULT NULL,
        gst_no VARCHAR(15) DEFAULT NULL,
        pan_no VARCHAR(10) DEFAULT NULL,
        phone VARCHAR(15) DEFAULT NULL,
        email VARCHAR(150) DEFAULT NULL,
        docket_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        oda_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        fuel_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        risk_charge_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        risk_minimum_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (state_id) REFERENCES states(id),
        FOREIGN KEY (city_id) REFERENCES cities(id)
    ) ENGINE=InnoDB");

    $conn->query("CREATE TABLE IF NOT EXISTS client_lane_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        origin_city_id INT NOT NULL,
        destination_city_id INT NOT NULL,
        rate_per_kg DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        rate_per_piece DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        rate_per_km DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        risk_charge_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        risk_minimum_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_client_lane (client_id, origin_city_id, destination_city_id),
        FOREIGN KEY (client_id) REFERENCES client_masters(id) ON DELETE CASCADE,
        FOREIGN KEY (origin_city_id) REFERENCES cities(id),
        FOREIGN KEY (destination_city_id) REFERENCES cities(id)
    ) ENGINE=InnoDB");
}

function ensureConsignmentContractColumns(mysqli $conn): void
{
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM consignments');
    if ($result) foreach ($result->fetch_all(MYSQLI_ASSOC) as $column) $columns[$column['Field']] = true;
    if (!isset($columns['client_master_id'])) {
        $conn->query('ALTER TABLE consignments ADD COLUMN client_master_id INT DEFAULT NULL AFTER billing_party_name');
    }
    if (!isset($columns['risk_charge'])) {
        $conn->query('ALTER TABLE consignments ADD COLUMN risk_charge DECIMAL(10,2) DEFAULT 0.00 AFTER other_charge');
    }
}

function ensureClientRiskColumns(mysqli $conn): void
{
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM client_masters');
    if ($result) foreach ($result->fetch_all(MYSQLI_ASSOC) as $column) $columns[$column['Field']] = true;
    if (!isset($columns['risk_charge_percent'])) {
        $conn->query('ALTER TABLE client_masters ADD COLUMN risk_charge_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER fuel_charge');
    }
    if (!isset($columns['risk_minimum_charge'])) {
        $conn->query('ALTER TABLE client_masters ADD COLUMN risk_minimum_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER risk_charge_percent');
    }
    if (!isset($columns['expiry_date'])) {
        $conn->query('ALTER TABLE client_masters ADD COLUMN expiry_date DATE DEFAULT NULL AFTER status');
    }
    if (!isset($columns['created_at'])) {
        $conn->query('ALTER TABLE client_masters ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER expiry_date');
    }
    if (!isset($columns['updated_at'])) {
        $conn->query('ALTER TABLE client_masters ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
    }
}

function getClientMasters(mysqli $conn, bool $activeOnly = true): array
{
    ensureClientContractSchema($conn);
    ensureClientRiskColumns($conn);
    $where = $activeOnly ? "WHERE cm.status = 'Active'" : '';
    $result = $conn->query("SELECT cm.*, s.state_name, c.city_name
        FROM client_masters cm
        LEFT JOIN states s ON s.id = cm.state_id
        LEFT JOIN cities c ON c.id = cm.city_id
        {$where} ORDER BY cm.client_name");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getClientLaneRates(mysqli $conn, int $clientId): array
{
    ensureClientContractSchema($conn);
    if ($clientId <= 0) return [];
    $stmt = $conn->prepare("SELECT clr.*, oc.city_name AS origin_name, dc.city_name AS destination_name
        FROM client_lane_rates clr
        LEFT JOIN cities oc ON oc.id = clr.origin_city_id
        LEFT JOIN cities dc ON dc.id = clr.destination_city_id
        WHERE clr.client_id = ? ORDER BY clr.id");
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function getNextClientCode(mysqli $conn): string
{
    ensureClientContractSchema($conn);
    $nextNum = 1;
    $result = $conn->query("SELECT client_code FROM client_masters WHERE client_code REGEXP '^CLT-[0-9]+$'");
    if ($result) {
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $num = (int)substr($row['client_code'], 4);
            if ($num >= $nextNum) $nextNum = $num + 1;
        }
    }
    return 'CLT-' . str_pad((string)$nextNum, 4, '0', STR_PAD_LEFT);
}

function getGSTRates(mysqli $conn): array
{
    $conn->query("CREATE TABLE IF NOT EXISTS gst_rates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rate DECIMAL(5,2) NOT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        UNIQUE KEY uq_gst_rate (rate)
    ) ENGINE=InnoDB");
    // Seed 18% only for a brand-new, empty GST master. Do not recreate it
    // after an administrator has renamed that rate (for example 18% to 9%).
    $conn->query("INSERT INTO gst_rates (rate)
                  SELECT 18.00 WHERE NOT EXISTS (SELECT 1 FROM gst_rates)");
    $result = $conn->query("SELECT id, rate FROM gst_rates WHERE status = 'Active' ORDER BY rate");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function ensurePODSchema(mysqli $conn): void
{
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM consignments');
    if ($result) foreach ($result->fetch_all(MYSQLI_ASSOC) as $column) $columns[$column['Field']] = true;
    if (!isset($columns['pod_priority'])) {
        $conn->query("ALTER TABLE consignments ADD COLUMN pod_priority ENUM('Normal','High','Urgent') NOT NULL DEFAULT 'Normal'");
    }
    if (!isset($columns['delivery_date'])) {
        $conn->query('ALTER TABLE consignments ADD COLUMN delivery_date DATE DEFAULT NULL');
    }
    if (!isset($columns['docket_tracking_status'])) {
        $conn->query("ALTER TABLE consignments ADD COLUMN docket_tracking_status ENUM('In Transit','Connecting to Next Destination','Out for Delivery','Delivered','Hold','Return') NOT NULL DEFAULT 'In Transit'");
    }
    $conn->query("CREATE TABLE IF NOT EXISTS pod_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consignment_id INT NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL UNIQUE,
        mime_type VARCHAR(100) NOT NULL,
        file_size INT NOT NULL,
        uploaded_by INT DEFAULT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pod_consignment (consignment_id),
        FOREIGN KEY (consignment_id) REFERENCES consignments(id) ON DELETE CASCADE,
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
}

function getPODQueue(mysqli $conn, string $view = 'pending', string $query = '', int $limit = 200): array
{
    ensurePODSchema($conn);
    // A delivered docket that still has no POD after one day requires action.
    $conn->query("UPDATE consignments c SET c.pod_priority = 'Urgent'
                  WHERE c.delivery_date <= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                  AND NOT EXISTS (SELECT 1 FROM pod_files p WHERE p.consignment_id = c.id)");
    $having = $view === 'uploaded' ? 'pod_count > 0' : 'pod_count = 0';
    $sql = "SELECT c.id, c.consignment_note, c.booking_date, c.consignee_name, c.pod_priority, c.delivery_date, c.docket_tracking_status,
                   COUNT(p.id) AS pod_count, MAX(p.uploaded_at) AS last_uploaded
            FROM consignments c LEFT JOIN pod_files p ON p.consignment_id = c.id";
    $like = '';
    if ($query !== '') {
        $sql .= ' WHERE c.consignment_note LIKE ? OR c.consignee_name LIKE ?';
        $like = '%' . $query . '%';
    }
    $sql .= " GROUP BY c.id HAVING {$having}
              ORDER BY FIELD(c.pod_priority, 'Urgent', 'High', 'Normal'), c.delivery_date IS NULL DESC, c.booking_date DESC
              LIMIT ?";
    $stmt = $conn->prepare($sql);
    if ($query !== '') {
        $stmt->bind_param('ssi', $like, $like, $limit);
    } else {
        $stmt->bind_param('i', $limit);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getBillingParties(mysqli $conn): array
{
    ensurePartyMasterTable($conn);

    $result = $conn->query(
        "SELECT
            id,
            party_name,
            address,
            city_id,
            state_id,
            pin,
            phone,
            gst_no
         FROM party_masters
         WHERE status = 'Active'
         ORDER BY party_name"
    );

    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/** Invoice tables are created lazily so existing installations upgrade safely. */
function ensureBillingSchema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->query("CREATE TABLE IF NOT EXISTS invoices (id INT AUTO_INCREMENT PRIMARY KEY, invoice_no VARCHAR(50) NOT NULL UNIQUE, invoice_date DATE NOT NULL, company_id INT NULL, company_gst_registration_id INT NULL, company_gstin VARCHAR(15) NOT NULL, billing_party_name VARCHAR(200) NOT NULL, billing_party_gstin VARCHAR(15) NULL, gst_type ENUM('CGST_SGST','IGST') NOT NULL, gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00, taxable_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, cgst_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, sgst_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, igst_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00, remarks TEXT NULL, submission_status ENUM('Pending','Submitted','Cancelled') NOT NULL DEFAULT 'Pending', submission_date DATE NULL, submission_proof_path VARCHAR(255) NULL, cancellation_reason TEXT NULL, cancelled_by INT NULL, cancelled_at TIMESTAMP NULL, created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_invoice_party_date (billing_party_name, invoice_date), INDEX idx_submission_status (submission_status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS invoice_items (id INT AUTO_INCREMENT PRIMARY KEY, invoice_id INT NOT NULL, consignment_id INT NOT NULL, docket_no VARCHAR(50) NOT NULL, booking_date DATE NULL, freight_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_invoice_consignment (consignment_id), INDEX idx_invoice_item_invoice (invoice_id), CONSTRAINT fk_invoice_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE, CONSTRAINT fk_invoice_item_consignment FOREIGN KEY (consignment_id) REFERENCES consignments(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS invoice_terms (id INT AUTO_INCREMENT PRIMARY KEY, term_name VARCHAR(100) NOT NULL, term_content TEXT NOT NULL, is_default TINYINT(1) NOT NULL DEFAULT 0, status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active', created_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Add new columns to existing invoices table if they don't exist
    $checkColumns = $conn->query("SHOW COLUMNS FROM invoices LIKE 'submission_status'");
    if ($checkColumns->num_rows == 0) {
        $conn->query("ALTER TABLE invoices ADD COLUMN submission_status ENUM('Pending','Temp Hold','Submitted','Cancelled') NOT NULL DEFAULT 'Pending'");
        $conn->query("ALTER TABLE invoices ADD COLUMN submission_date DATE NULL");
        $conn->query("ALTER TABLE invoices ADD COLUMN submission_proof_path VARCHAR(255) NULL");
        $conn->query("ALTER TABLE invoices ADD COLUMN cancellation_reason TEXT NULL");
        $conn->query("ALTER TABLE invoices ADD COLUMN cancelled_by INT NULL");
        $conn->query("ALTER TABLE invoices ADD COLUMN cancelled_at TIMESTAMP NULL");
        $conn->query("ALTER TABLE invoices ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        $conn->query("ALTER TABLE invoices ADD INDEX idx_submission_status (submission_status)");
    } else {
        // Check if Temp Hold exists in ENUM, if not add it
        $enumCheck = $conn->query("SHOW COLUMNS FROM invoices LIKE 'submission_status'");
        $enumRow = $enumCheck->fetch_assoc();
        $enumType = $enumRow['Type'];
        if (strpos($enumType, 'Temp Hold') === false) {
            $conn->query("ALTER TABLE invoices MODIFY COLUMN submission_status ENUM('Pending','Temp Hold','Submitted','Cancelled') NOT NULL DEFAULT 'Pending'");
        }
    }
}

function getInvoiceTerms(mysqli $conn): array
{
    ensureBillingSchema($conn);
    $result = $conn->query("SELECT * FROM invoice_terms WHERE status='Active' ORDER BY is_default DESC, term_name");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getDefaultInvoiceTerms(mysqli $conn): ?array
{
    ensureBillingSchema($conn);
    $stmt = $conn->prepare("SELECT * FROM invoice_terms WHERE is_default=1 AND status='Active' LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result;
}

/** Save all three party sections whenever a consignment is saved. */
function upsertConsignmentPartyMasters(mysqli $conn, array $data): void
{
    ensurePartyMasterTable($conn);
    $stmt = $conn->prepare(
        "INSERT INTO party_masters (party_name, address, city_id, state_id, pin, phone, gst_no)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            address = COALESCE(NULLIF(VALUES(address), ''), address),
            city_id = IF(VALUES(city_id) > 0, VALUES(city_id), city_id),
            state_id = IF(VALUES(state_id) > 0, VALUES(state_id), state_id),
            pin = COALESCE(NULLIF(VALUES(pin), ''), pin),
            phone = COALESCE(NULLIF(VALUES(phone), ''), phone),
            gst_no = COALESCE(NULLIF(VALUES(gst_no), ''), gst_no)"
    );
    if (!$stmt) throw new Exception('Unable to prepare party master save: ' . $conn->error);

    foreach (['billing' => 'billing_party_name', 'consignor' => 'consignor_name', 'consignee' => 'consignee_name'] as $prefix => $nameField) {
        $name = trim((string) ($data[$nameField] ?? ''));
        if ($name === '') continue;
        $address = (string) ($data[$prefix . '_address'] ?? '');
        $cityId = (int) ($data[$prefix . '_city_id'] ?? 0);
        $stateId = (int) ($data[$prefix . '_state_id'] ?? 0);
        $pin = (string) ($data[$prefix . '_pin'] ?? '');
        $phone = (string) ($data[$prefix . '_phone'] ?? '');
        $gstNo = (string) ($data[$prefix . '_gst_no'] ?? '');
        $stmt->bind_param('ssiisss', $name, $address, $cityId, $stateId, $pin, $phone, $gstNo);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new Exception('Unable to save party master: ' . $error);
        }
    }
    $stmt->close();
}

function getConsignmentById(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM consignments WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function getRecentConsignments(mysqli $conn, int $limit = 10): array
{
    $stmt = $conn->prepare(
        'SELECT id, consignment_note, booking_date, billing_party_name, consignee_name,
                truck_no, actual_weight, charged_weight, grand_total, status, created_at
         FROM consignments ORDER BY created_at DESC LIMIT ?'
    );
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function searchConsignmentsByNote(mysqli $conn, string $query, int $limit = 20): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $search = '%' . $query . '%';
    $stmt = $conn->prepare(
        'SELECT id, consignment_note, billing_party_name, consignee_name, status, booking_date, grand_total, created_at 
         FROM consignments 
         WHERE consignment_note LIKE ? 
         ORDER BY created_at DESC 
         LIMIT ?'
    );
    $stmt->bind_param('si', $search, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getConsignmentByNote(mysqli $conn, string $note): ?array
{
    $note = trim($note);
    if ($note === '') {
        return null;
    }
    $stmt = $conn->prepare('SELECT * FROM consignments WHERE consignment_note = ?');
    $stmt->bind_param('s', $note);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function getFilteredConsignments(
    mysqli $conn,
    string $note = '',
    string $billingParty = '',
    string $consignee = '',
    string $status = '',
    string $dateFrom = '',
    string $dateTo = '',
    int $limit = 200
): array {
    $where = [];
    $types = '';
    $params = [];

    $note = trim($note);
    if ($note !== '') {
        $where[] = 'consignment_note LIKE ?';
        $types .= 's';
        $params[] = '%' . $note . '%';
    }

    $billingParty = trim($billingParty);
    if ($billingParty !== '') {
        $where[] = 'billing_party_name LIKE ?';
        $types .= 's';
        $params[] = '%' . $billingParty . '%';
    }

    $consignee = trim($consignee);
    if ($consignee !== '') {
        $where[] = 'consignee_name LIKE ?';
        $types .= 's';
        $params[] = '%' . $consignee . '%';
    }

    if ($status !== '' && in_array($status, ['Draft', 'Submitted', 'Approved'], true)) {
        $where[] = 'status = ?';
        $types .= 's';
        $params[] = $status;
    }

    if ($dateFrom !== '') {
        $where[] = 'booking_date >= ?';
        $types .= 's';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'booking_date <= ?';
        $types .= 's';
        $params[] = $dateTo;
    }

    $sql = 'SELECT id, consignment_note, billing_party_name, consignee_name, status, booking_date, truck_no, actual_weight, charged_weight, grand_total, created_at 
            FROM consignments';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$limit;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getAllMasterData(mysqli $conn): array
{
    ensureConsignmentContractColumns($conn);
    ensureCitiesSchema($conn);
    return [
        'states' => getAllStates($conn),
        'cities' => getAllCities($conn),
        'booking_types' => getBookingTypes($conn),
        'payment_types' => getPaymentTypes($conn),
        'truck_types' => getTruckTypes($conn),
        'packing_methods' => getPackingMethods($conn),
        'courier_companies' => getCourierCompanies($conn),
        'gst_rates' => getGSTRates($conn),
        'billing_parties' => getBillingParties($conn),
        // Inactive clients must not be available for Billing Party contract selection.
        'client_masters' => getClientMasters($conn),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// THC (Trip Hire Contract) Schema & Data Functions
// ─────────────────────────────────────────────────────────────────────────────

function ensureTHCSchema(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    // --- Route Masters ---
    $conn->query("CREATE TABLE IF NOT EXISTS route_masters (
        id INT AUTO_INCREMENT PRIMARY KEY,
        route_code VARCHAR(50) NOT NULL UNIQUE,
        route_name VARCHAR(200) NOT NULL,
        origin_city_id INT NOT NULL,
        destination_city_id INT NOT NULL,
        transport_mode ENUM('Air','FTL','Rail','Surface','Data Movement','Co-loader') NOT NULL DEFAULT 'Surface',
        total_distance_km DECIMAL(10,2) DEFAULT NULL,
        status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_route_status (status),
        KEY idx_route_origin (origin_city_id),
        KEY idx_route_dest (destination_city_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- Route Points (touching points in sequence) ---
    $conn->query("CREATE TABLE IF NOT EXISTS route_points (
        id INT AUTO_INCREMENT PRIMARY KEY,
        route_id INT NOT NULL,
        city_id INT NOT NULL,
        point_sequence INT NOT NULL,
        point_label VARCHAR(50) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_route_point_seq (route_id, point_sequence),
        KEY idx_rp_route (route_id),
        CONSTRAINT fk_rp_route FOREIGN KEY (route_id) REFERENCES route_masters(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- THC (Trip Hire Contract) ---
    $conn->query("CREATE TABLE IF NOT EXISTS thc (
        id INT AUTO_INCREMENT PRIMARY KEY,
        thc_no VARCHAR(50) NOT NULL UNIQUE,
        thc_date DATE NOT NULL,
        origin_city_id INT NOT NULL,
        destination_city_id INT NOT NULL,
        transport_mode ENUM('Air','FTL','Rail','Surface','Data Movement','Co-loader') NOT NULL DEFAULT 'Surface',
        route_id INT DEFAULT NULL,
        vendor_name VARCHAR(200) DEFAULT NULL,
        vehicle_no VARCHAR(30) DEFAULT NULL,
        driver1_name VARCHAR(100) DEFAULT NULL,
        driver1_phone VARCHAR(15) DEFAULT NULL,
        driver2_name VARCHAR(100) DEFAULT NULL,
        driver2_phone VARCHAR(15) DEFAULT NULL,
        contract_amount DECIMAL(12,2) DEFAULT 0.00,
        other_amount DECIMAL(12,2) DEFAULT 0.00,
        total_amount DECIMAL(12,2) DEFAULT 0.00,
        advance_amount DECIMAL(12,2) DEFAULT 0.00,
        deduction_amount DECIMAL(12,2) DEFAULT 0.00,
        balance_amount DECIMAL(12,2) DEFAULT 0.00,
        remarks TEXT DEFAULT NULL,
        status ENUM('Draft','Active','Dispatched','Completed','Cancelled') NOT NULL DEFAULT 'Draft',
        dispatched_at TIMESTAMP NULL DEFAULT NULL,
        completed_at TIMESTAMP NULL DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_thc_date (thc_date),
        KEY idx_thc_status (status),
        KEY idx_thc_route (route_id),
        CONSTRAINT fk_thc_route FOREIGN KEY (route_id) REFERENCES route_masters(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- Loading Tally (one or more per THC) ---
    $conn->query("CREATE TABLE IF NOT EXISTS loading_tally (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lt_no VARCHAR(50) NOT NULL UNIQUE,
        thc_id INT NOT NULL,
        lt_date DATE NOT NULL,
        remarks TEXT DEFAULT NULL,
        status ENUM('Draft','Saved','Dispatched') NOT NULL DEFAULT 'Draft',
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_lt_thc (thc_id),
        CONSTRAINT fk_lt_thc FOREIGN KEY (thc_id) REFERENCES thc(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- Loading Tally Items (dockets in a loading tally) ---
    $conn->query("CREATE TABLE IF NOT EXISTS loading_tally_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lt_id INT NOT NULL,
        thc_id INT NOT NULL,
        consignment_id INT NOT NULL,
        short_reason VARCHAR(200) DEFAULT NULL,
        added_by INT DEFAULT NULL,
        added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_lti_consignment (consignment_id),
        KEY idx_lti_lt (lt_id),
        KEY idx_lti_thc (thc_id),
        CONSTRAINT fk_lti_lt FOREIGN KEY (lt_id) REFERENCES loading_tally(id) ON DELETE CASCADE,
        CONSTRAINT fk_lti_consignment FOREIGN KEY (consignment_id) REFERENCES consignments(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- THC Touching Points (must exist before thc_manifests references it) ---
    $conn->query("CREATE TABLE IF NOT EXISTS thc_touching_points (
        id INT AUTO_INCREMENT PRIMARY KEY,
        thc_id INT NOT NULL,
        route_point_id INT DEFAULT NULL,
        city_id INT NOT NULL,
        point_sequence INT NOT NULL DEFAULT 0,
        arrival_datetime DATETIME DEFAULT NULL,
        departure_datetime DATETIME DEFAULT NULL,
        status ENUM('Pending','Arrived','Unloaded','Dispatched') NOT NULL DEFAULT 'Pending',
        remarks TEXT DEFAULT NULL,
        handled_by INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_tp_thc (thc_id),
        KEY idx_tp_seq (thc_id, point_sequence),
        CONSTRAINT fk_thctp_thc FOREIGN KEY (thc_id) REFERENCES thc(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- THC Manifests ---
    $conn->query("CREATE TABLE IF NOT EXISTS thc_manifests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        manifest_no VARCHAR(50) NOT NULL UNIQUE,
        thc_id INT NOT NULL,
        lt_id INT DEFAULT NULL,
        manifest_date DATE NOT NULL,
        manifest_type ENUM('Auto','Manual') NOT NULL DEFAULT 'Auto',
        destination_city_id INT NOT NULL,
        touching_point_id INT DEFAULT NULL,
        remarks TEXT DEFAULT NULL,
        status ENUM('Draft','Saved','Dispatched','Delivered') NOT NULL DEFAULT 'Draft',
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_mnf_thc (thc_id),
        KEY idx_mnf_lt (lt_id),
        KEY idx_mnf_tp (touching_point_id),
        CONSTRAINT fk_thcmnf_thc FOREIGN KEY (thc_id) REFERENCES thc(id) ON DELETE CASCADE,
        CONSTRAINT fk_thcmnf_tp FOREIGN KEY (touching_point_id) REFERENCES thc_touching_points(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- THC Manifest Items ---
    $conn->query("CREATE TABLE IF NOT EXISTS thc_manifest_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        manifest_id INT NOT NULL,
        consignment_id INT NOT NULL,
        short_reason VARCHAR(200) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_thcmi_con (manifest_id, consignment_id),
        KEY idx_thcmi_manifest (manifest_id),
        CONSTRAINT fk_thcmi_manifest FOREIGN KEY (manifest_id) REFERENCES thc_manifests(id) ON DELETE CASCADE,
        CONSTRAINT fk_thcmi_con FOREIGN KEY (consignment_id) REFERENCES consignments(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- THC Unloading Tally ---
    $conn->query("CREATE TABLE IF NOT EXISTS thc_unloading_tally (
        id INT AUTO_INCREMENT PRIMARY KEY,
        thc_id INT NOT NULL,
        touching_point_id INT DEFAULT NULL,
        consignment_id INT NOT NULL,
        received_packages INT DEFAULT 0,
        original_packages INT DEFAULT 0,
        short_count INT DEFAULT 0,
        damaged_count INT DEFAULT 0,
        short_reason VARCHAR(200) DEFAULT NULL,
        damage_reason VARCHAR(200) DEFAULT NULL,
        remarks VARCHAR(200) DEFAULT NULL,
        received_by INT DEFAULT NULL,
        received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_thcut_thc (thc_id),
        KEY idx_thcut_con (consignment_id),
        CONSTRAINT fk_thcut_thc FOREIGN KEY (thc_id) REFERENCES thc(id) ON DELETE CASCADE,
        CONSTRAINT fk_thcut_con FOREIGN KEY (consignment_id) REFERENCES consignments(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function getNextTHCNo(mysqli $conn): string
{
    ensureTHCSchema($conn);
    $year = date('y');
    $prefix = 'THC/' . $year . '/';
    $result = $conn->query("SELECT thc_no FROM thc WHERE thc_no LIKE '" . $conn->real_escape_string($prefix) . "%' ORDER BY id DESC LIMIT 1");
    $last = $result ? $result->fetch_assoc() : null;
    if ($last) {
        $parts = explode('/', $last['thc_no']);
        $num = (int) end($parts);
        return $prefix . str_pad((string)($num + 1), 4, '0', STR_PAD_LEFT);
    }
    return $prefix . '0001';
}

function getNextLTNo(mysqli $conn, int $thcId): string
{
    ensureTHCSchema($conn);
    $result = $conn->query("SELECT lt_no FROM loading_tally WHERE thc_id = " . (int)$thcId . " ORDER BY id DESC LIMIT 1");
    $last = $result ? $result->fetch_assoc() : null;
    // Format: LT/THC_ID/SEQ
    $seq = 1;
    if ($last) {
        $parts = explode('/', $last['lt_no']);
        $seq = (int) end($parts) + 1;
    }
    return 'LT/' . $thcId . '/' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

function getNextManifestNo(mysqli $conn, int $thcId): string
{
    ensureTHCSchema($conn);
    $result = $conn->query("SELECT manifest_no FROM thc_manifests WHERE thc_id = " . (int)$thcId . " ORDER BY id DESC LIMIT 1");
    $last = $result ? $result->fetch_assoc() : null;
    $seq = 1;
    if ($last) {
        $parts = explode('/', $last['manifest_no']);
        $seq = (int) end($parts) + 1;
    }
    return 'MNF/' . $thcId . '/' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

function getRouteMasters(mysqli $conn, bool $activeOnly = true): array
{
    ensureTHCSchema($conn);
    $where = $activeOnly ? "WHERE rm.status='Active'" : '';
    $result = $conn->query("SELECT rm.*, oc.city_name AS origin_name, dc.city_name AS destination_name
        FROM route_masters rm
        LEFT JOIN cities oc ON oc.id = rm.origin_city_id
        LEFT JOIN cities dc ON dc.id = rm.destination_city_id
        {$where} ORDER BY rm.route_name");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getRoutePoints(mysqli $conn, int $routeId): array
{
    ensureTHCSchema($conn);
    $stmt = $conn->prepare("SELECT rp.*, c.city_name
        FROM route_points rp
        LEFT JOIN cities c ON c.id = rp.city_id
        WHERE rp.route_id = ?
        ORDER BY rp.point_sequence ASC");
    $stmt->bind_param('i', $routeId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getTHCById(mysqli $conn, int $thcId): ?array
{
    ensureTHCSchema($conn);
    $stmt = $conn->prepare("SELECT t.*, oc.city_name AS origin_name, dc.city_name AS destination_name,
        rm.route_name, rm.route_code
        FROM thc t
        LEFT JOIN cities oc ON oc.id = t.origin_city_id
        LEFT JOIN cities dc ON dc.id = t.destination_city_id
        LEFT JOIN route_masters rm ON rm.id = t.route_id
        WHERE t.id = ? LIMIT 1");
    $stmt->bind_param('i', $thcId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function getLTsByTHC(mysqli $conn, int $thcId): array
{
    ensureTHCSchema($conn);
    $stmt = $conn->prepare("SELECT lt.*, COUNT(lti.id) AS item_count
        FROM loading_tally lt
        LEFT JOIN loading_tally_items lti ON lti.lt_id = lt.id
        WHERE lt.thc_id = ?
        GROUP BY lt.id
        ORDER BY lt.id ASC");
    $stmt->bind_param('i', $thcId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

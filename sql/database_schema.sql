-- Consignment Management ERP - Database Schema
-- MySQL 5.7+

CREATE DATABASE IF NOT EXISTS erp_tms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE erp_tms;

-- ============================================================
-- MASTER TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS states (
    id INT AUTO_INCREMENT PRIMARY KEY,
    state_name VARCHAR(100) NOT NULL,
    state_code VARCHAR(5) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    city_name VARCHAR(100) NOT NULL,
    pincode VARCHAR(6) DEFAULT NULL,
    district VARCHAR(100) DEFAULT NULL,
    zone_region VARCHAR(100) DEFAULT NULL,
    area TEXT DEFAULT NULL,
    state_code VARCHAR(5) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_state_code (state_code),
    INDEX idx_cities_pincode (pincode),
    INDEX idx_cities_district (district),
    UNIQUE KEY uq_cities_pincode_state (pincode, state_code),
    FOREIGN KEY (state_code) REFERENCES states(state_code) ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS courier_companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(150) NOT NULL,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS booking_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS truck_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(100) NOT NULL,
    capacity VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS packing_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    method_name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('Admin', 'Operator') DEFAULT 'Operator',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Company profile supports a separate GSTIN for every registered state.
CREATE TABLE IF NOT EXISTS companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_code VARCHAR(32) NOT NULL UNIQUE,
    legal_name VARCHAR(200) NOT NULL,
    trade_name VARCHAR(200) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    city_id INT DEFAULT NULL,
    state_code VARCHAR(5) DEFAULT NULL,
    pincode VARCHAR(10) DEFAULT NULL,
    pan_no VARCHAR(10) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    website VARCHAR(150) DEFAULT NULL,
    logo_path VARCHAR(255) DEFAULT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS company_gst_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    state_code VARCHAR(5) NOT NULL,
    gstin VARCHAR(15) NOT NULL UNIQUE,
    registration_address TEXT DEFAULT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    UNIQUE KEY uq_company_state (company_id, state_code),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value TEXT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- MAIN TRANSACTION TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS consignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consignment_note VARCHAR(50) NOT NULL UNIQUE,
    gst_no VARCHAR(15) DEFAULT NULL,
    truck_no VARCHAR(30) DEFAULT NULL,
    booking_type_id INT DEFAULT NULL,
    origin_city_id INT NOT NULL,
    destination_city_id INT NOT NULL,
    booking_date DATE NOT NULL,
    handover_date DATE DEFAULT NULL,
    handover_time TIME DEFAULT NULL,
    ba_franchisee_code VARCHAR(50) DEFAULT NULL,
    billing_party_name VARCHAR(200) NOT NULL,
    client_master_id INT DEFAULT NULL,
    billing_address TEXT DEFAULT NULL,
    billing_city_id INT DEFAULT NULL,
    billing_state_id INT DEFAULT NULL,
    billing_pin VARCHAR(6) DEFAULT NULL,
    billing_gst_no VARCHAR(15) NOT NULL,
    billing_phone VARCHAR(10) NOT NULL,
    consignee_name VARCHAR(200) NOT NULL,
    consignee_address TEXT DEFAULT NULL,
    consignee_city_id INT DEFAULT NULL,
    consignee_state_id INT DEFAULT NULL,
    consignee_pin VARCHAR(6) DEFAULT NULL,
    consignee_gst_no VARCHAR(15) DEFAULT NULL,
    consignee_phone VARCHAR(10) DEFAULT NULL,
    consignor_name VARCHAR(200) NOT NULL,
    consignor_address TEXT DEFAULT NULL,
    consignor_city_id INT DEFAULT NULL,
    consignor_state_id INT DEFAULT NULL,
    consignor_pin VARCHAR(6) DEFAULT NULL,
    consignor_phone VARCHAR(10) DEFAULT NULL,
    consignor_gst_no VARCHAR(15) DEFAULT NULL,
    consignor_signature VARCHAR(200) DEFAULT NULL,
    party_po_number VARCHAR(50) DEFAULT NULL,
    payment_type_id INT DEFAULT NULL,
    basic_freight DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    fuel_charge DECIMAL(10,2) DEFAULT 0.00,
    dkt_charge DECIMAL(10,2) DEFAULT 0.00,
    handling_charge DECIMAL(10,2) DEFAULT 0.00,
    oda_charge DECIMAL(10,2) DEFAULT 0.00,
    detention DECIMAL(10,2) DEFAULT 0.00,
    misc_charge DECIMAL(10,2) DEFAULT 0.00,
    other_charge DECIMAL(10,2) DEFAULT 0.00,
    risk_charge DECIMAL(10,2) DEFAULT 0.00,
    sgst DECIMAL(10,2) DEFAULT 0.00,
    cgst DECIMAL(10,2) DEFAULT 0.00,
    igst DECIMAL(10,2) DEFAULT 0.00,
    grand_total DECIMAL(10,2) DEFAULT 0.00,
    amount_words TEXT DEFAULT NULL,
    party_invoice_no VARCHAR(50) DEFAULT NULL,
    declared_value DECIMAL(10,2) DEFAULT 0.00,
    eway_bill_no VARCHAR(20) DEFAULT NULL,
    validity_date DATE DEFAULT NULL,
    truck_type_id INT DEFAULT NULL,
    description TEXT DEFAULT NULL,
    no_of_pieces INT DEFAULT 0,
    packing_method_id INT DEFAULT NULL,
    actual_weight DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    charged_weight DECIMAL(10,2) DEFAULT 0.00,
    charge_weight_mode VARCHAR(10) NOT NULL DEFAULT 'Auto',
    volume_lxwxh VARCHAR(500) DEFAULT NULL,
    received_by_name VARCHAR(100) DEFAULT NULL,
    pickup_time TIME DEFAULT NULL,
    booking_incharge VARCHAR(100) DEFAULT NULL,
    carrier_risk_type VARCHAR(50) DEFAULT NULL,
    remarks TEXT DEFAULT NULL,
    courier_company_id INT DEFAULT NULL,
    pod_priority ENUM('Normal', 'High', 'Urgent') NOT NULL DEFAULT 'Normal',
    delivery_date DATE DEFAULT NULL,
    docket_tracking_status ENUM('In Transit', 'Connecting to Next Destination', 'Out for Delivery', 'Delivered', 'Hold', 'Return') NOT NULL DEFAULT 'In Transit',
    status ENUM('Draft', 'Submitted', 'Approved') DEFAULT 'Draft',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_type_id) REFERENCES booking_types(id),
    FOREIGN KEY (client_master_id) REFERENCES client_masters(id),
    FOREIGN KEY (origin_city_id) REFERENCES cities(id),
    FOREIGN KEY (destination_city_id) REFERENCES cities(id),
    FOREIGN KEY (billing_city_id) REFERENCES cities(id),
    FOREIGN KEY (billing_state_id) REFERENCES states(id),
    FOREIGN KEY (consignee_city_id) REFERENCES cities(id),
    FOREIGN KEY (consignee_state_id) REFERENCES states(id),
    FOREIGN KEY (consignor_city_id) REFERENCES cities(id),
    FOREIGN KEY (consignor_state_id) REFERENCES states(id),
    FOREIGN KEY (payment_type_id) REFERENCES payment_types(id),
    FOREIGN KEY (truck_type_id) REFERENCES truck_types(id),
    FOREIGN KEY (packing_method_id) REFERENCES packing_methods(id),
    FOREIGN KEY (courier_company_id) REFERENCES courier_companies(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS client_masters (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS client_lane_rates (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pod_files (
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
) ENGINE=InnoDB;

-- ============================================================
-- MIGRATION: Run this ALTER TABLE on existing databases
-- ============================================================
-- ALTER TABLE consignments
--   ADD COLUMN handover_date DATE DEFAULT NULL AFTER booking_date,
--   ADD COLUMN handover_time TIME DEFAULT NULL AFTER handover_date,
--   ADD COLUMN other_charge DECIMAL(10,2) DEFAULT 0.00 AFTER misc_charge,
--   ADD COLUMN consignor_address TEXT DEFAULT NULL AFTER consignor_name,
--   ADD COLUMN consignor_city_id INT DEFAULT NULL AFTER consignor_address,
--   ADD COLUMN consignor_state_id INT DEFAULT NULL AFTER consignor_city_id,
--   ADD COLUMN consignor_pin VARCHAR(6) DEFAULT NULL AFTER consignor_state_id,
--   ADD COLUMN consignor_phone VARCHAR(10) DEFAULT NULL AFTER consignor_pin,
--   ADD COLUMN consignor_gst_no VARCHAR(15) DEFAULT NULL AFTER consignor_phone,
--   ADD INDEX (consignor_city_id),
--   ADD INDEX (consignor_state_id),
--   ADD FOREIGN KEY (consignor_city_id) REFERENCES cities(id),
--   ADD FOREIGN KEY (consignor_state_id) REFERENCES states(id);
-- ============================================================

-- ============================================================
-- MASTER DATA - INDIAN STATES
-- ============================================================

INSERT INTO states (state_name, state_code) VALUES
('Andhra Pradesh', 'AP'),
('Arunachal Pradesh', 'AR'),
('Assam', 'AS'),
('Bihar', 'BR'),
('Chhattisgarh', 'CG'),
('Goa', 'GA'),
('Gujarat', 'GJ'),
('Haryana', 'HR'),
('Himachal Pradesh', 'HP'),
('Jharkhand', 'JH'),
('Karnataka', 'KA'),
('Kerala', 'KL'),
('Madhya Pradesh', 'MP'),
('Maharashtra', 'MH'),
('Manipur', 'MN'),
('Meghalaya', 'ML'),
('Mizoram', 'MZ'),
('Nagaland', 'NL'),
('Odisha', 'OD'),
('Punjab', 'PB'),
('Rajasthan', 'RJ'),
('Sikkim', 'SK'),
('Tamil Nadu', 'TN'),
('Telangana', 'TS'),
('Tripura', 'TR'),
('Uttar Pradesh', 'UP'),
('Uttarakhand', 'UK'),
('West Bengal', 'WB'),
('Delhi', 'DL'),
('Jammu and Kashmir', 'JK'),
('Ladakh', 'LA'),
('Puducherry', 'PY'),
('Chandigarh', 'CH');

-- ============================================================
-- MASTER DATA - CITIES (Major Indian cities)
-- ============================================================

INSERT INTO cities (city_name, state_code) VALUES
('Mumbai', 'MH'), ('Pune', 'MH'), ('Nagpur', 'MH'), ('Nashik', 'MH'), ('Thane', 'MH'),
('Delhi', 'DL'), ('New Delhi', 'DL'),
('Bangalore', 'KA'), ('Mysore', 'KA'), ('Hubli', 'KA'),
('Chennai', 'TN'), ('Coimbatore', 'TN'), ('Madurai', 'TN'),
('Kolkata', 'WB'), ('Howrah', 'WB'), ('Durgapur', 'WB'),
('Hyderabad', 'TS'), ('Warangal', 'TS'),
('Ahmedabad', 'GJ'), ('Surat', 'GJ'), ('Vadodara', 'GJ'), ('Rajkot', 'GJ'),
('Jaipur', 'RJ'), ('Jodhpur', 'RJ'), ('Udaipur', 'RJ'),
('Lucknow', 'UP'), ('Kanpur', 'UP'), ('Varanasi', 'UP'), ('Noida', 'UP'), ('Ghaziabad', 'UP'),
('Chandigarh', 'CH'),
('Indore', 'MP'), ('Bhopal', 'MP'), ('Jabalpur', 'MP'),
('Patna', 'BR'), ('Gaya', 'BR'),
('Bhubaneswar', 'OD'), ('Cuttack', 'OD'),
('Guwahati', 'AS'), ('Silchar', 'AS'),
('Kochi', 'KL'), ('Thiruvananthapuram', 'KL'), ('Kozhikode', 'KL'),
('Visakhapatnam', 'AP'), ('Vijayawada', 'AP'), ('Guntur', 'AP'),
('Ranchi', 'JH'), ('Jamshedpur', 'JH'),
('Raipur', 'CG'), ('Bilaspur', 'CG'),
('Dehradun', 'UK'), ('Haridwar', 'UK'),
('Amritsar', 'PB'), ('Ludhiana', 'PB'), ('Jalandhar', 'PB'),
('Panaji', 'GA'), ('Margao', 'GA'),
('Shimla', 'HP'), ('Manali', 'HP'),
('Gangtok', 'SK'),
('Imphal', 'MN'),
('Shillong', 'ML'),
('Aizawl', 'MZ'),
('Kohima', 'NL'),
('Agartala', 'TR'),
('Srinagar', 'JK'), ('Jammu', 'JK'),
('Leh', 'LA'),
('Puducherry', 'PY');

-- ============================================================
-- MASTER DATA - OTHER TABLES
-- ============================================================

INSERT INTO courier_companies (company_name, status) VALUES
('NATIONAL COURIER', 'Active'),
('INDIA CARGO', 'Active'),
('SELF DELIVERY', 'Active'),
('DTDC EXPRESS', 'Active'),
('BLUE DART', 'Active'),
('GATI', 'Active'),
('DELHIVERY', 'Active'),
('EKART', 'Active');

INSERT INTO booking_types (type_name) VALUES
('Standard'),
('Express'),
('Economy');

INSERT INTO payment_types (type_name) VALUES
('Paid'),
('TBB'),
('ToPay');

INSERT INTO truck_types (type_name, capacity) VALUES
('TATA ACE', '750 kg'),
('TATA 407', '2.5 MT'),
('TATA 709', '3.5 MT'),
('TATA 1109', '7 MT'),
('TATA 1613', '10 MT'),
('TATA 2518', '16 MT'),
('CONTAINER 20FT', '20 MT'),
('CONTAINER 32FT', '32 MT'),
('TRAILER', '40 MT');

INSERT INTO packing_methods (method_name) VALUES
('Carton Box'),
('Wooden Crate'),
('Bubble Wrap'),
('Shrink Wrap'),
('Pallet'),
('Gunny Bag'),
('Steel Drum'),
('Loose'),
('Corrugated Box'),
('Thermocol Box');

-- Default admin user: admin / admin123 (change in production)
INSERT INTO users (username, password, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin'),
('operator', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Operator');

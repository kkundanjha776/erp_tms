# Consignment Management ERP System

A single-page, no-scroll Consignment Entry Form for logistics/courier management with PHP backend, MySQL database, and AJAX-powered validations.

## Features

- **Single-page 3-column layout** — Booking, Party Info, Charges (no page scroll on desktop)
- **Master tables** — States, cities, courier companies, booking/payment types, truck types, packing methods
- **Server-side validation** — Required fields, GST/PIN/phone formats, business rules
- **AJAX** — Consignment note uniqueness, tax auto-calculation, city filtering by state
- **Status workflow** — Draft → Submitted → Approved
- **Security** — Session auth, CSRF tokens, prepared statements
- **Bilingual messages** — English and Hindi error/success text

## Requirements

- PHP 7.4+
- MySQL 5.7+
- Apache (XAMPP recommended)
- Modern browser

## Installation

### 1. Database Setup

```bash
# From XAMPP shell or MySQL CLI
mysql -u root -p < sql/database_schema.sql
```

Or import `sql/database_schema.sql` via phpMyAdmin.

### 2. Configure Database

Edit `includes/db_connection.php` if needed:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'erp_tms');
```

### 3. Access Application

Open in browser:

```
http://localhost/erp_tms/login.php
```

**Default credentials:**
| Username | Password |
|----------|----------|
| admin    | password |
| operator | password |

> Change passwords in production using PHP: `password_hash('your_password', PASSWORD_DEFAULT)`

## File Structure

```
erp_tms/
├── index.php              # Main consignment form
├── login.php              # Authentication
├── logout.php             # Session destroy
├── api/
│   ├── save_consignment.php
│   ├── get_cities.php
│   ├── check_consignment_note.php
│   ├── calculate_taxes.php
│   └── get_template.php
├── includes/
│   ├── db_connection.php
│   ├── validation.php
│   ├── master_data.php
│   └── functions.php
├── css/style.css
├── js/form.js
└── sql/database_schema.sql
```

## Tax Calculation Logic

| Scenario | Tax Applied |
|----------|-------------|
| Same state (intra-state) | SGST 9% + CGST 9% on Basic Freight |
| Different states (inter-state) | IGST 18% on Basic Freight |

Grand Total = All charges + SGST + CGST + IGST

## Form Actions

| Button | Action |
|--------|--------|
| Save Draft | Saves with status `Draft` (editable) |
| Submit | Saves with status `Submitted` (read-only) |
| Clear | Resets form |
| Print / PDF | Browser print dialog |
| Load Template | Auto-fills from previous consignment |

## API Endpoints

All API endpoints require an active session (login first).

| Endpoint | Method | Description |
|----------|--------|-------------|
| `api/save_consignment.php` | POST | Save draft or submit |
| `api/get_cities.php?state_code=MH` | GET | Filter cities by state |
| `api/check_consignment_note.php?note=CN001` | GET | Uniqueness check |
| `api/calculate_taxes.php` | GET | Tax & grand total calculation |
| `api/get_template.php?id=1` | GET | Load previous entry as template |

## Validation Rules

- Consignment Note: required, unique
- Phone: 10 digits
- GST: 15-character Indian GST format
- PIN: 6 digits
- Basic Freight & Actual Weight: > 0 on submit
- Charged Weight ≥ Actual Weight
- Booking/Validity dates: not future dates

## License

Internal use — Consignment Management ERP

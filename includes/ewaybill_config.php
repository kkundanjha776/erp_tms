<?php
/**
 * E-Way Bill API Configuration
 *
 * Register for API access at https://ewaybillgst.gov.in/
 * Mainmenu → Registration → API (direct) or GSP registration.
 *
 * Sandbox testing: https://einv-apisandbox.nic.in/
 */

// production | sandbox | demo
define('EWAYBILL_ENV', 'production');

// Production NIC E-Way Bill API base URL (v1.03)
// Use the URL assigned to you on the E-Way Bill portal after API registration.
define('EWAYBILL_API_BASE_URL', 'https://api.mastergst.com/ewaybillapi/v1.03/');

define('EWAYBILL_SANDBOX_BASE_URL', 'https://ewb1api.gstsandbox.nic.in/ewaybillapi/v1.03/');

// Credentials — fill these from your E-Way Bill portal API registration
define('EWAYBILL_CLIENT_ID', '');
define('EWAYBILL_CLIENT_SECRET', '');
define('EWAYBILL_GSTIN', '');          // Requester GSTIN (supplier/recipient/transporter on the bill)
define('EWAYBILL_USERNAME', '');        // API username from portal
define('EWAYBILL_PASSWORD', '');        // API password from portal

// Path to NIC RSA public key PEM file (download from E-Way Bill portal / API docs)
define('EWAYBILL_PUBLIC_KEY_PATH', __DIR__ . '/ewaybill_public_key.pem');

// When true and credentials are empty, returns structured demo data for UI testing
define('EWAYBILL_DEMO_MODE', true);

function getEwaybillBaseUrl(): string
{
    if (EWAYBILL_ENV === 'sandbox') {
        return EWAYBILL_SANDBOX_BASE_URL;
    }
    return rtrim(EWAYBILL_API_BASE_URL, '/') . '/';
}

function isEwaybillConfigured(): bool
{
    return EWAYBILL_CLIENT_ID !== ''
        && EWAYBILL_CLIENT_SECRET !== ''
        && EWAYBILL_GSTIN !== ''
        && EWAYBILL_USERNAME !== ''
        && EWAYBILL_PASSWORD !== ''
        && is_file(EWAYBILL_PUBLIC_KEY_PATH);
}

function isEwaybillDemoMode(): bool
{
    return EWAYBILL_DEMO_MODE && !isEwaybillConfigured();
}

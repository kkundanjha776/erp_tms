<?php
/**
 * Database Connection Configuration
 */

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'erp_tms');
define('DB_CHARSET', 'utf8mb4');

function getDBConnection(): mysqli
{
    static $conn = null;

    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        if ($conn->connect_error) {
            error_log('Database connection failed: ' . $conn->connect_error);
            http_response_code(500);
            die(json_encode([
                'success' => false,
                'message' => 'Database connection failed / डेटाबेस कनेक्शन विफल'
            ]));
        }

        $conn->set_charset(DB_CHARSET);
    }

    return $conn;
}

function closeDBConnection(): void
{
    $conn = getDBConnection();
    if ($conn) {
        $conn->close();
    }
}

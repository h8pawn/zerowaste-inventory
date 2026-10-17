<?php
// Settings and the database connection. Nothing here needs editing for a deployment:
// each value comes from, in order,
//   1. config.local.php next to this file (copy config.local.example.php; it is never committed),
//   2. an environment variable of the same name (for hosts that set them),
//   3. the XAMPP default below.

$localConfig = is_file(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];

function config_value(string $key, string $default): string {
    global $localConfig;
    if (is_array($localConfig) && array_key_exists($key, $localConfig)) {
        return (string) $localConfig[$key];
    }
    $env = getenv($key);
    return $env !== false ? $env : $default;
}

// 'production' hides error details from visitors; 'development' shows them (your own computer only).
define('APP_ENV', config_value('APP_ENV', 'production') === 'development' ? 'development' : 'production');
define('APP_DEBUG', APP_ENV === 'development');

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// PHP and MySQL must agree on what "today" is, otherwise date() and CURDATE()
// disagree for part of the day.
date_default_timezone_set('Asia/Manila');

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(
    config_value('DB_HOST', '127.0.0.1'),
    config_value('DB_USER', 'root'),
    config_value('DB_PASSWORD', ''),
    config_value('DB_NAME', 'zerowaste'),
    (int) config_value('DB_PORT', '3306')
);

if ($conn->connect_error) {
    error_log('ZeroWaste: MySQL connection failed: ' . $conn->connect_error);
    http_response_code(503);
    die(APP_DEBUG
        ? 'MySQL connection failed. Make sure MySQL is running and check the DB_* values in config.local.php. Error: ' . htmlspecialchars($conn->connect_error)
        : 'The system is temporarily unavailable. Please try again in a few minutes.');
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");
// The SQL mode the app was built and tested with (XAMPP's default). Many hosts run MySQL 8,
// whose default adds ONLY_FULL_GROUP_BY and strict mode; pinning it keeps behaviour identical.
$conn->query("SET SESSION sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");

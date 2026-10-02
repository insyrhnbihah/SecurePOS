<?php
require_once __DIR__ . '/bootstrap.php';

try {
    $host = (string)secureposSetting('db_host', '');
    $username = (string)secureposSetting('db_user', '');
    $password = (string)secureposSetting('db_password', '');
    $database = (string)secureposSetting('db_name', '');
    $port = filter_var(secureposSetting('db_port', 3306), FILTER_VALIDATE_INT);
    if ($host === '' || $username === '' || $database === '' || !$port || $port < 1 || $port > 65535) {
        throw new RuntimeException('Required database settings are missing.');
    }
    if (secureposIsProduction()) {
        foreach ([$host, $username, $database, $password] as $value) {
            if ($value === '' || strpos($value, 'REPLACE_WITH_') !== false) {
                throw new RuntimeException('Production database settings are incomplete.');
            }
        }
    }
    // Preserve the existing explicit false/error checks on both PHP 7.4 and 8.x.
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = new mysqli($host, $username, $password, $database, (int)$port);
    if ($mysqli->connect_errno || !$mysqli->set_charset('utf8mb4')
        || !$mysqli->query("SET time_zone = '+08:00'")) {
        throw new RuntimeException('Database initialization failed.');
    }
} catch (Throwable $exception) {
    error_log('SecurePOS database connection/configuration failed.');
    http_response_code(503);
    exit('Database connection failed. Please contact the administrator.');
}

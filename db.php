<?php

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

define('DB_HOST', getenv('DB_HOST') ?: 'pawnhub.mysql.database.azure.com');
define('DB_USER', getenv('DB_USER') ?: 'PawnhubAdmin@pawnhub');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'pawnhub');
define('DB_PORT', getenv('DB_PORT') ?: '3306');

$ssl_cert = __DIR__ . '/certs/DigiCertGlobalRootG2.crt.pem';

if (!file_exists($ssl_cert)) {
    error_log('DB ERROR: SSL certificate file not found: ' . $ssl_cert);
    throw new Exception('Database SSL certificate missing');
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    DB_HOST,
    DB_PORT,
    DB_NAME
);

try {
    $pdo = new PDO(
        $dsn,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_SSL_CA => $ssl_cert,
        ]
    );

    error_log('DB CONNECT: success');

} catch (PDOException $e) {
    error_log('DB CONNECT ERROR: ' . $e->getMessage());
    throw new Exception('Database connection failed: ' . $e->getMessage(), 0, $e);
}
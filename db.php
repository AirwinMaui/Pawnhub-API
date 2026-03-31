<?php
define('DB_HOST', getenv('DB_HOST') ?: 'pawnhub.mysql.database.azure.com');
define('DB_USER', getenv('DB_USER') ?: 'PawnhubAdmin');
define('DB_PASS', getenv('DB_PASS') ?: 'Admin123');
define('DB_NAME', getenv('DB_NAME') ?: 'pawnhub');
define('DB_PORT', getenv('DB_PORT') ?: '3306');

$ssl_cert = __DIR__ . '/certs/DigiCertGlobalRootG2.crt.pem';

try {
    if (!file_exists($ssl_cert)) {
        throw new Exception('SSL certificate file not found: ' . $ssl_cert);
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

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
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
        'error' => $e->getMessage(),
    ]);
    exit;
}
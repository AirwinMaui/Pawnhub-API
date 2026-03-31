<?php
define('DB_HOST', getenv('DB_HOST'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));
define('DB_NAME', getenv('DB_NAME'));
define('DB_PORT', getenv('DB_PORT') ?: '3306');

$ssl_cert = __DIR__ . '/certs/DigiCertGlobalRootG2.crt.pem';

try {
    if (!file_exists($ssl_cert)) {
        throw new Exception('SSL certificate file not found: ' . $ssl_cert);
    }

    $dsn = 'mysql:host=' . DB_HOST .
           ';port=' . DB_PORT .
           ';dbname=' . DB_NAME .
           ';charset=utf8mb4';

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
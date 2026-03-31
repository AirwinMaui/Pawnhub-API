<?php
header('Content-Type: text/plain');

require __DIR__ . '/db.php';

try {
    $stmt = $pdo->query("SELECT 1");
    echo "DB connected!";
} catch (Throwable $e) {
    http_response_code(500);
    echo "DB error: " . $e->getMessage();
}
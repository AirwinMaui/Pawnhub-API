<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
ini_set('display_errors', '1');
error_reporting(E_ALL);

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

echo json_encode([
    "step" => "before require",
    "path" => __DIR__ . "/../db.php",
    "exists" => file_exists(__DIR__ . "/../db.php")
]);
exit;
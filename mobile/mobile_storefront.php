<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . "/../db.php";

echo json_encode([
    "success" => true,
    "message" => "after require",
    "has_pdo" => isset($pdo)
]);
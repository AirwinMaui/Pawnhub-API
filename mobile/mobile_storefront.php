<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
ini_set('display_errors', '1');
error_reporting(E_ALL);

function respond($data) {
    echo json_encode($data);
    exit;
}

require_once __DIR__ . "/../db.php";

respond([
    "step" => "after require",
    "conn_exists" => isset($conn),
    "conn_type" => isset($conn) ? gettype($conn) : null
]);
<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . "/../db.php";

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function getIntParam(string $key): ?int
{
    return isset($_GET[$key]) && is_numeric($_GET[$key])
        ? (int) $_GET[$key]
        : null;
}

function fullImageUrl(string $path): string
{
    $base = "https://pawnhub-api-hqfkfxdaddhnfthf.southeastasia-01.azurewebsites.net/";
    if (!$path) return "";
    if (preg_match('/^https?:\/\//', $path)) return $path;
    return $base . ltrim($path, '/');
}

if (!isset($conn)) {
    respond(500, ["success" => false, "message" => "DB connection failed"]);
}

$tenantId = getIntParam("tenant_id");
if (!$tenantId) {
    respond(400, ["success" => false, "message" => "Missing tenant_id"]);
}

/*
|--------------------------------------------------------------------------
| Simple working query (no advanced fields yet)
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT 
        id,
        item_name,
        item_category,
        item_photo_path,
        appraisal_value
    FROM item_inventory
    WHERE tenant_id = ?
    LIMIT 20
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    respond(500, ["success" => false, "error" => $conn->error]);
}

$stmt->bind_param("i", $tenantId);

if (!$stmt->execute()) {
    respond(500, ["success" => false, "error" => $stmt->error]);
}

$result = $stmt->get_result();

$products = [];

while ($row = $result->fetch_assoc()) {
    $products[] = [
        "id" => (string)$row["id"],
        "name" => $row["item_name"],
        "category" => $row["item_category"],
        "price" => "$" . number_format((float)$row["appraisal_value"], 2),
        "image" => fullImageUrl($row["item_photo_path"] ?? "")
    ];
}

respond(200, [
    "success" => true,
    "products" => $products
]);
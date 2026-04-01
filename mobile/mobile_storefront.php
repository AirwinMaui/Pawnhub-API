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

function getIntParam(string $key, ?int $default = null): ?int
{
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return $default;
    }

    if (!is_numeric($_GET[$key])) {
        return $default;
    }

    return (int) $_GET[$key];
}

function fullImageUrl(string $path, string $baseUrl): string
{
    $path = trim($path);

    if ($path === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    respond(500, [
        "success" => false,
        "message" => "PDO database connection is not available."
    ]);
}

$imageBaseUrl = "https://pawnhub-api-hqfkfxdaddhnfthf.southeastasia-01.azurewebsites.net/";

$tenantId = getIntParam("tenant_id");
if (!$tenantId || $tenantId <= 0) {
    respond(400, [
        "success" => false,
        "message" => "Missing or invalid tenant_id."
    ]);
}

try {
    $tenantStmt = $pdo->prepare("
        SELECT id, name, status
        FROM tenants
        WHERE id = :tenant_id
        LIMIT 1
    ");
    $tenantStmt->execute(["tenant_id" => $tenantId]);
    $tenant = $tenantStmt->fetch();

    if (!$tenant) {
        respond(404, [
            "success" => false,
            "message" => "Tenant not found."
        ]);
    }

    if (isset($tenant["status"]) && strtolower((string)$tenant["status"]) !== "active") {
        respond(403, [
            "success" => false,
            "message" => "Tenant is inactive."
        ]);
    }

    $productsStmt = $pdo->prepare("
        SELECT
            id,
            item_name,
            item_category,
            item_photo_path,
            appraisal_value
        FROM item_inventory
        WHERE tenant_id = :tenant_id
        ORDER BY id DESC
        LIMIT 20
    ");
    $productsStmt->execute(["tenant_id" => $tenantId]);
    $rows = $productsStmt->fetchAll();

    $products = [];

    foreach ($rows as $row) {
        $products[] = [
            "id" => (string)$row["id"],
            "name" => $row["item_name"],
            "category" => $row["item_category"],
            "price" => "$" . number_format((float)$row["appraisal_value"], 2),
            "image" => fullImageUrl((string)($row["item_photo_path"] ?? ""), $imageBaseUrl),
        ];
    }

    respond(200, [
        "success" => true,
        "tenant" => [
            "id" => (int)$tenant["id"],
            "name" => $tenant["name"],
        ],
        "products" => $products,
    ]);
} catch (Throwable $e) {
    respond(500, [
        "success" => false,
        "message" => "Storefront query failed.",
        "error" => $e->getMessage(),
    ]);
}
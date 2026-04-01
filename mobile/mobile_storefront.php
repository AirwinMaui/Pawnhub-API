<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . "/../db.php";

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function getTenantId(): ?int
{
    if (isset($_GET["tenant"]) && is_numeric($_GET["tenant"])) {
        return (int) $_GET["tenant"];
    }
    return null;
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
    respond(500, ["success" => false, "message" => "PDO missing"]);
}

$tenantId = getTenantId();
if (!$tenantId) {
    respond(400, ["success" => false, "message" => "Missing tenant"]);
}

$imageBaseUrl = "https://pawnhub-api-hqfkfxdaddhnfthf.southeastasia-01.azurewebsites.net/";

try {
    $stmt = $pdo->prepare("
        SELECT id, business_name AS name, status
        FROM tenants
        WHERE id = :tenant
        LIMIT 1
    ");
    $stmt->execute(["tenant" => $tenantId]);
    $tenant = $stmt->fetch();

    if (!$tenant) {
        respond(200, [
            "success" => false,
            "message" => "Tenant not found",
            "tenant_passed" => $tenantId
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
        WHERE tenant_id = :tenant
        ORDER BY id DESC
        LIMIT 20
    ");
    $productsStmt->execute(["tenant" => $tenantId]);
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
        "tenant" => $tenant,
        "products" => $products
    ]);
} catch (Throwable $e) {
    respond(500, [
        "success" => false,
        "message" => "Storefront query failed",
        "error" => $e->getMessage()
    ]);
}
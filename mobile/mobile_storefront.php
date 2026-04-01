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

if (!isset($pdo) || !($pdo instanceof PDO)) {
    respond(500, ["success" => false, "message" => "PDO missing"]);
}

$tenantId = getTenantId();
if (!$tenantId) {
    respond(400, ["success" => false, "message" => "Missing tenant"]);
}

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

    respond(200, [
        "success" => true,
        "tenant" => $tenant
    ]);
} catch (Throwable $e) {
    respond(500, [
        "success" => false,
        "message" => "Tenant query failed",
        "error" => $e->getMessage()
    ]);
}
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

    if (isset($_GET["tenant_id"]) && is_numeric($_GET["tenant_id"])) {
        return (int) $_GET["tenant_id"];
    }

    return null;
}

$tenantId = getTenantId();

respond(200, [
    "success" => true,
    "tenant" => $tenantId
]);
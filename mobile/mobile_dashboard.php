<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

ini_set("display_errors", "0");
error_reporting(E_ALL);

require_once __DIR__ . "/db.php";

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$customerId = $_GET["customer_id"] ?? $_POST["customer_id"] ?? null;
$tenantId = $_GET["tenant_id"] ?? $_POST["tenant_id"] ?? null;

if (!$customerId || !$tenantId) {
    respond(400, [
        "success" => false,
        "message" => "Missing customer_id or tenant_id.",
    ]);
}

try {
    $customerStmt = $pdo->prepare("
        SELECT
            c.id,
            c.tenant_id,
            c.full_name,
            c.username,
            c.email,
            c.contact_number,
            c.birthdate,
            c.address,
            c.gender,
            c.nationality,
            c.created_at,
            t.business_name,
            t.tenant_code
        FROM mobile_customers c
        INNER JOIN tenants t ON t.id = c.tenant_id
        WHERE c.id = :customer_id
          AND c.tenant_id = :tenant_id
        LIMIT 1
    ");

    $customerStmt->execute([
        ":customer_id" => $customerId,
        ":tenant_id" => $tenantId,
    ]);

    $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        respond(404, [
            "success" => false,
            "message" => "Customer not found.",
        ]);
    }

    respond(200, [
        "success" => true,
        "customer" => $customer,
    ]);
} catch (Throwable $e) {
    respond(500, [
        "success" => false,
        "message" => "Failed to load customer.",
        "error" => $e->getMessage(),
    ]);
}
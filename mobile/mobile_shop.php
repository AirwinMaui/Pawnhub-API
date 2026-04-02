<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
ini_set('display_errors', '0');
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit;
}

require_once __DIR__ . "/../db.php";

function respond(int $statusCode, array $payload): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fullImageUrl(string $path): string {
    $base = "https://pawnhub-api-hqfkfxdaddhnfthf.southeastasia-01.azurewebsites.net/";
    if (!$path) return "";
    if (preg_match('/^https?:\/\//', $path)) return $path;
    return $base . ltrim($path, '/');
}

// Support GET and POST
$tenantId = (int)($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? 0);

if (!$tenantId) {
    respond(400, ["success" => false, "message" => "Missing tenant_id"]);
}

try {
    $stmt = $pdo->prepare("
        SELECT
            id,
            item_name,
            item_category,
            item_photo_path,
            appraisal_value
        FROM item_inventory
        WHERE tenant_id = ?
        ORDER BY id DESC
        LIMIT 20
    ");
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $products = [];
    foreach ($rows as $row) {
        $products[] = [
            "id"       => (string)$row["id"],
            "name"     => (string)($row["item_name"] ?? ''),
            "category" => (string)($row["item_category"] ?? ''),
            "price"    => "₱" . number_format((float)$row["appraisal_value"], 2),
            "image"    => fullImageUrl((string)($row["item_photo_path"] ?? "")),
        ];
    }

    respond(200, ["success" => true, "products" => $products]);

} catch (Throwable $e) {
    respond(500, ["success" => false, "message" => "Server error", "error" => $e->getMessage()]);
}

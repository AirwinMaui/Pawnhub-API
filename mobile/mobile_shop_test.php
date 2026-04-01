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

set_exception_handler(function ($e) {
    respond(500, [
        "success" => false,
        "message" => "Unhandled exception",
        "error" => $e->getMessage()
    ]);
});

set_error_handler(function ($severity, $message, $file, $line) {
    respond(500, [
        "success" => false,
        "message" => "PHP error",
        "error" => $message,
        "file" => basename($file),
        "line" => $line
    ]);
});

function getStringParam(string $key, string $default = ""): string
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
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

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond(500, [
        "success" => false,
        "message" => "Database connection is not available."
    ]);
}

$conn->set_charset("utf8mb4");

$imageBaseUrl = "https://pawnhub-api-hqfkfxdaddhnfthf.southeastasia-01.azurewebsites.net/";

$tenantId = getIntParam("tenant_id");
if (!$tenantId || $tenantId <= 0) {
    respond(400, [
        "success" => false,
        "message" => "Missing or invalid tenant_id."
    ]);
}

$search = getStringParam("search");
$categoryId = getIntParam("category_id");

/*
|--------------------------------------------------------------------------
| Validate tenant
|--------------------------------------------------------------------------
*/
$tenantSql = "SELECT id, name, status FROM tenants WHERE id = ? LIMIT 1";
$tenantStmt = $conn->prepare($tenantSql);

if (!$tenantStmt) {
    respond(500, [
        "success" => false,
        "message" => "Failed to prepare tenant query.",
        "error" => $conn->error
    ]);
}

$tenantStmt->bind_param("i", $tenantId);

if (!$tenantStmt->execute()) {
    respond(500, [
        "success" => false,
        "message" => "Failed to execute tenant query.",
        "error" => $tenantStmt->error
    ]);
}

$tenantResult = $tenantStmt->get_result();
$tenant = $tenantResult->fetch_assoc();
$tenantStmt->close();

if (!$tenant) {
    respond(404, [
        "success" => false,
        "message" => "Tenant not found."
    ]);
}

if (isset($tenant["status"]) && strtolower((string) $tenant["status"]) !== "active") {
    respond(403, [
        "success" => false,
        "message" => "Tenant is inactive."
    ]);
}

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/
$categories = [];

$categoriesSql = "
    SELECT
        id,
        name,
        icon,
        sort_order
    FROM shop_categories
    WHERE tenant_id = ?
      AND is_active = 1
    ORDER BY sort_order ASC, name ASC
";

$categoriesStmt = $conn->prepare($categoriesSql);

if (!$categoriesStmt) {
    respond(500, [
        "success" => false,
        "message" => "Failed to prepare categories query.",
        "error" => $conn->error
    ]);
}

$categoriesStmt->bind_param("i", $tenantId);

if (!$categoriesStmt->execute()) {
    respond(500, [
        "success" => false,
        "message" => "Failed to execute categories query.",
        "error" => $categoriesStmt->error
    ]);
}

$categoriesResult = $categoriesStmt->get_result();

while ($row = $categoriesResult->fetch_assoc()) {
    $categories[] = [
        "id" => (string) $row["id"],
        "label" => $row["name"],
        "icon" => $row["icon"] ?: "grid-view",
    ];
}

$categoriesStmt->close();

/*
|--------------------------------------------------------------------------
| Featured item
|--------------------------------------------------------------------------
*/
$featured = null;

$featuredSql = "
    SELECT
        ii.id,
        ii.item_name,
        ii.item_category,
        ii.condition_notes,
        ii.item_photo_path,
        ii.display_price,
        ii.is_featured,
        sc.name AS category_name
    FROM item_inventory ii
    LEFT JOIN shop_categories sc
        ON sc.id = ii.category_id
       AND sc.tenant_id = ii.tenant_id
    WHERE ii.tenant_id = ?
      AND ii.is_shop_visible = 1
      AND ii.is_featured = 1
      AND ii.status = 'available'
    ORDER BY ii.sort_order ASC, ii.id DESC
    LIMIT 1
";

$featuredStmt = $conn->prepare($featuredSql);

if (!$featuredStmt) {
    respond(500, [
        "success" => false,
        "message" => "Failed to prepare featured item query.",
        "error" => $conn->error
    ]);
}

$featuredStmt->bind_param("i", $tenantId);

if (!$featuredStmt->execute()) {
    respond(500, [
        "success" => false,
        "message" => "Failed to execute featured item query.",
        "error" => $featuredStmt->error
    ]);
}

$featuredResult = $featuredStmt->get_result();
$featuredRow = $featuredResult->fetch_assoc();
$featuredStmt->close();

if ($featuredRow) {
    $featured = [
        "id" => (string) $featuredRow["id"],
        "name" => $featuredRow["item_name"],
        "subtitle" => $featuredRow["condition_notes"] ?: "Premium item available now.",
        "image" => fullImageUrl((string) ($featuredRow["item_photo_path"] ?? ""), $imageBaseUrl),
        "price" => number_format((float) $featuredRow["display_price"], 2, ".", ""),
        "category" => $featuredRow["category_name"] ?: $featuredRow["item_category"],
    ];
}

/*
|--------------------------------------------------------------------------
| Products
|--------------------------------------------------------------------------
*/
$products = [];

$productsSql = "
    SELECT
        ii.id,
        ii.item_name,
        ii.item_category,
        ii.condition_notes,
        ii.item_photo_path,
        ii.display_price,
        ii.is_featured,
        sc.name AS category_name
    FROM item_inventory ii
    LEFT JOIN shop_categories sc
        ON sc.id = ii.category_id
       AND sc.tenant_id = ii.tenant_id
    WHERE ii.tenant_id = ?
      AND ii.is_shop_visible = 1
      AND ii.status = 'available'
";

$params = [$tenantId];
$types = "i";

if ($categoryId && $categoryId > 0) {
    $productsSql .= " AND ii.category_id = ? ";
    $params[] = $categoryId;
    $types .= "i";
}

if ($search !== "") {
    $productsSql .= " AND (
        ii.item_name LIKE ?
        OR ii.item_category LIKE ?
        OR ii.condition_notes LIKE ?
    ) ";
    $searchLike = "%" . $search . "%";
    $params[] = $searchLike;
    $params[] = $searchLike;
    $params[] = $searchLike;
    $types .= "sss";
}

$productsSql .= "
    ORDER BY ii.is_featured DESC, ii.sort_order ASC, ii.id DESC
    LIMIT 50
";

$productsStmt = $conn->prepare($productsSql);

if (!$productsStmt) {
    respond(500, [
        "success" => false,
        "message" => "Failed to prepare products query.",
        "error" => $conn->error
    ]);
}

$productsStmt->bind_param($types, ...$params);

if (!$productsStmt->execute()) {
    respond(500, [
        "success" => false,
        "message" => "Failed to execute products query.",
        "error" => $productsStmt->error
    ]);
}

$productsResult = $productsStmt->get_result();

while ($row = $productsResult->fetch_assoc()) {
    $products[] = [
        "id" => (string) $row["id"],
        "category" => $row["category_name"] ?: $row["item_category"],
        "name" => $row["item_name"],
        "price" => "$" . number_format((float) $row["display_price"], 2),
        "raw_price" => number_format((float) $row["display_price"], 2, ".", ""),
        "image" => fullImageUrl((string) ($row["item_photo_path"] ?? ""), $imageBaseUrl),
        "badge" => ((int) $row["is_featured"] === 1) ? "Featured" : null,
        "description" => $row["condition_notes"] ?: "",
    ];
}

$productsStmt->close();

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/
respond(200, [
    "success" => true,
    "tenant" => [
        "id" => (int) $tenant["id"],
        "name" => $tenant["name"],
    ],
    "filters" => [
        "search" => $search,
        "category_id" => $categoryId,
    ],
    "categories" => $categories,
    "featured" => $featured,
    "products" => $products,
]);
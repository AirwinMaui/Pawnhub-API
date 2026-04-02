<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
ini_set("display_errors", "1");
error_reporting(E_ALL);

require_once __DIR__ . "/../db.php";

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function requestData(): array
{
    static $data = null;

    if ($data !== null) {
        return $data;
    }

    $data = [];

    if (!empty($_GET)) {
        $data = array_merge($data, $_GET);
    }

    if (!empty($_POST)) {
        $data = array_merge($data, $_POST);
    }

    $raw = file_get_contents("php://input");
    if ($raw !== false && trim($raw) !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = array_merge($data, $json);
        } else {
            $parsed = [];
            parse_str($raw, $parsed);
            if (is_array($parsed) && !empty($parsed)) {
                $data = array_merge($data, $parsed);
            }
        }
    }

    return $data;
}

function getIntParam(string $key): ?int
{
    $data = requestData();
    $value = $data[$key] ?? null;

    if ($value === null || $value === '' || !is_numeric((string)$value)) {
        return null;
    }

    return (int) $value;
}

function getStringParam(string $key, int $maxLen = 100): string
{
    $data = requestData();
    $value = trim((string)($data[$key] ?? ''));

    if ($value === '') {
        return '';
    }

    return mb_substr($value, 0, $maxLen);
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

function mapCategoryIcon(string $category): string
{
    $category = strtolower(trim($category));

    return match (true) {
        str_contains($category, 'watch') => 'watch',
        str_contains($category, 'phone') || str_contains($category, 'mobile') => 'smartphone',
        str_contains($category, 'laptop') || str_contains($category, 'computer') => 'laptop',
        str_contains($category, 'jewel') || str_contains($category, 'gold') || str_contains($category, 'ring') => 'diamond',
        str_contains($category, 'camera') => 'photo-camera',
        str_contains($category, 'bag') => 'shopping-bag',
        default => 'category',
    };
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    respond(500, [
        "success" => false,
        "message" => "PDO missing"
    ]);
}

$tenantId = getIntParam("tenant");
$categoryId = getIntParam("category_id");
$search = getStringParam("keyword");
$limit = getIntParam("limit") ?? 20;

if (!$tenantId) {
    respond(400, [
        "success" => false,
        "message" => "Missing tenant",
        "debug" => requestData()
    ]);
}

$limit = max(1, min($limit, 50));

$imageBaseUrl = "https://pawnhub-api-hqfkfxdaddhnfthf.southeastasia-01.azurewebsites.net/";

try {
    $stmt = $pdo->prepare("
        SELECT id, business_name AS name, status
        FROM tenants
        WHERE id = :tenant
        LIMIT 1
    ");
    $stmt->execute(["tenant" => $tenantId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant) {
        respond(404, [
            "success" => false,
            "message" => "Tenant not found",
            "tenant_passed" => $tenantId
        ]);
    }

    $catStmt = $pdo->prepare("
        SELECT
            MIN(id) AS id,
            item_category AS label
        FROM item_inventory
        WHERE tenant_id = :tenant
          AND item_category IS NOT NULL
          AND item_category <> ''
        GROUP BY item_category
        ORDER BY item_category ASC
    ");
    $catStmt->execute(["tenant" => $tenantId]);
    $categoryRows = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    $categories = [];
    foreach ($categoryRows as $row) {
        $categories[] = [
            "id" => (string) $row["id"],
            "label" => (string) $row["label"],
            "icon" => mapCategoryIcon((string) $row["label"]),
        ];
    }

    $featuredStmt = $pdo->prepare("
        SELECT
            id,
            item_name,
            item_category,
            item_photo_path,
            appraisal_value
        FROM item_inventory
        WHERE tenant_id = :tenant
        ORDER BY appraisal_value DESC, id DESC
        LIMIT 1
    ");
    $featuredStmt->execute(["tenant" => $tenantId]);
    $featuredRow = $featuredStmt->fetch(PDO::FETCH_ASSOC);

    $featured = null;
    if ($featuredRow) {
        $featured = [
            "id" => (string) $featuredRow["id"],
            "name" => (string) $featuredRow["item_name"],
            "subtitle" => "Top valued item in this shop",
            "image" => fullImageUrl((string) ($featuredRow["item_photo_path"] ?? ""), $imageBaseUrl),
            "price" => number_format((float) $featuredRow["appraisal_value"], 2),
            "category" => (string) ($featuredRow["item_category"] ?? "General"),
        ];
    }

    $selectedCategoryLabel = null;
    if ($categoryId !== null) {
        $resolveCatStmt = $pdo->prepare("
            SELECT item_category
            FROM item_inventory
            WHERE tenant_id = :tenant
              AND id = :id
            LIMIT 1
        ");
        $resolveCatStmt->execute([
            "tenant" => $tenantId,
            "id" => $categoryId
        ]);
        $selectedCategoryLabel = $resolveCatStmt->fetchColumn();

        if ($selectedCategoryLabel === false) {
            $selectedCategoryLabel = null;
        }
    }

    $sql = "
        SELECT
            id,
            item_name,
            item_category,
            item_photo_path,
            appraisal_value
        FROM item_inventory
        WHERE tenant_id = :tenant
    ";

    $params = [
        "tenant" => $tenantId
    ];

    if ($search !== '') {
        $sql .= " AND (
            item_name LIKE :search
            OR item_category LIKE :search
        )";
        $params["search"] = "%" . $search . "%";
    }

    if ($selectedCategoryLabel !== null) {
        $sql .= " AND item_category = :category_label";
        $params["category_label"] = $selectedCategoryLabel;
    }

    $sql .= " ORDER BY id DESC LIMIT :limit";

    $productsStmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $productsStmt->bindValue(
            ":" . $key,
            $value,
            is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
        );
    }

    $productsStmt->bindValue(":limit", $limit, PDO::PARAM_INT);
    $productsStmt->execute();

    $rows = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

    $products = [];
    foreach ($rows as $row) {
        $rawPrice = (float) ($row["appraisal_value"] ?? 0);

        $badge = null;
        if ($rawPrice >= 50000) {
            $badge = "Premium";
        } elseif ($rawPrice >= 20000) {
            $badge = "Popular";
        }

        $products[] = [
            "id" => (string) $row["id"],
            "name" => (string) $row["item_name"],
            "category" => (string) ($row["item_category"] ?? "General"),
            "price" => "$" . number_format($rawPrice, 2),
            "raw_price" => number_format($rawPrice, 2, '.', ''),
            "image" => fullImageUrl((string) ($row["item_photo_path"] ?? ""), $imageBaseUrl),
            "badge" => $badge,
            "description" => (string) ($row["item_category"] ?? "General") . " item available for purchase",
        ];
    }

    respond(200, [
        "success" => true,
        "tenant" => [
            "id" => (int) $tenant["id"],
            "name" => (string) $tenant["name"],
            "status" => (string) $tenant["status"],
        ],
        "filters" => [
            "search" => $search,
            "category_id" => $categoryId,
        ],
        "categories" => $categories,
        "featured" => $featured,
        "products" => $products,
    ]);
} catch (Throwable $e) {
    respond(500, [
        "success" => false,
        "message" => "Storefront query failed",
        "error" => $e->getMessage()
    ]);
}
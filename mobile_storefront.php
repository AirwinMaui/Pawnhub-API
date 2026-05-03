<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
ini_set("display_errors", "0");
error_reporting(E_ALL);

require_once __DIR__ . "/db.php";

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

    return (int)$value;
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

function normalizeStatus(?string $status): string
{
    return strtolower(trim((string)$status));
}

function isInventoryAvailable(array $row): bool
{
    $stockQty = (int)($row["stock_qty"] ?? 0);
    $status = normalizeStatus((string)($row["status"] ?? ""));

    $unavailableStatuses = [
        "sold",
        "sold out",
        "released",
        "reserved",
        "unavailable",
    ];

    if ($stockQty <= 0) {
        return false;
    }

    if (in_array($status, $unavailableStatuses, true)) {
        return false;
    }

    return true;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    respond(500, [
        "success" => false,
        "message" => "PDO missing",
    ]);
}

$tenantId = getIntParam("tenant");
$categoryId = getIntParam("category_id");
$search = getStringParam("keyword");
$limit = getIntParam("limit") ?? 30;

if (!$tenantId) {
    respond(400, [
        "success" => false,
        "message" => "Missing tenant",
        "debug" => requestData(),
    ]);
}

$limit = max(1, min($limit, 100));

$imageBaseUrl = "https://pawnhub-api-hqfkfxdaddhnfthf.southeastasia-01.azurewebsites.net/";

try {
    $stmt = $pdo->prepare("
        SELECT id, business_name AS name, status
        FROM tenants
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant) {
        respond(404, [
            "success" => false,
            "message" => "Tenant not found",
            "tenant_passed" => $tenantId,
        ]);
    }

    $catStmt = $pdo->prepare("
        SELECT id, name AS label, icon
        FROM shop_categories
        WHERE tenant_id = ?
          AND is_active = 1
        ORDER BY sort_order ASC, name ASC
    ");

    $catStmt->execute([$tenantId]);
    $categoryRows = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    $categories = [];

    foreach ($categoryRows as $row) {
        $categories[] = [
            "id" => (string)$row["id"],
            "label" => (string)$row["label"],
            "icon" => $row["icon"] ?: mapCategoryIcon((string)$row["label"]),
        ];
    }

    $featuredStmt = $pdo->prepare("
        SELECT
            i.id,
            i.item_name,
            i.item_category,
            i.item_photo_path,
            i.display_price,
            i.appraisal_value,
            i.is_featured,
            i.stock_qty,
            i.status,
            c.name AS cat_name
        FROM item_inventory i
        LEFT JOIN shop_categories c ON c.id = i.category_id
        WHERE i.tenant_id = ?
          AND i.is_shop_visible = 1
          AND COALESCE(i.stock_qty, 0) > 0
          AND LOWER(TRIM(COALESCE(i.status, ''))) NOT IN (
              'sold',
              'sold out',
              'released',
              'reserved',
              'unavailable'
          )
        ORDER BY i.is_featured DESC, i.display_price DESC, i.id DESC
        LIMIT 1
    ");

    $featuredStmt->execute([$tenantId]);
    $featuredRow = $featuredStmt->fetch(PDO::FETCH_ASSOC);

    $featured = null;

    if ($featuredRow) {
        $price = (float)(
            ((float)$featuredRow["display_price"] > 0)
                ? $featuredRow["display_price"]
                : $featuredRow["appraisal_value"]
        );

        $featured = [
            "id" => (string)$featuredRow["id"],
            "name" => (string)$featuredRow["item_name"],
            "subtitle" => (int)$featuredRow["is_featured"] === 1
                ? "Featured item"
                : "Top valued item in this shop",
            "image" => fullImageUrl((string)($featuredRow["item_photo_path"] ?? ""), $imageBaseUrl),
            "price" => number_format($price, 2, '.', ''),
            "category" => (string)($featuredRow["cat_name"] ?? $featuredRow["item_category"] ?? "General"),
            "stock_qty" => (int)($featuredRow["stock_qty"] ?? 0),
            "status" => (string)($featuredRow["status"] ?? ""),
            "is_available" => true,
        ];
    }

    $selectedCategoryId = $categoryId;

    /*
      Product grid returns visible items, including sold-out items.
      Sold-out display is handled by ShopScreen.tsx.
    */
    $sql = "
        SELECT
            i.id,
            i.item_name,
            i.item_category,
            i.item_photo_path,
            i.display_price,
            i.appraisal_value,
            i.is_featured,
            i.stock_qty,
            i.condition_notes,
            i.status,
            c.name AS cat_name,
            c.icon AS cat_icon
        FROM item_inventory i
        LEFT JOIN shop_categories c ON c.id = i.category_id
        WHERE i.tenant_id = ?
          AND i.is_shop_visible = 1
    ";

    $params = [$tenantId];

    if ($search !== '') {
        $sql .= "
          AND (
              i.item_name LIKE ?
              OR i.item_category LIKE ?
              OR c.name LIKE ?
          )
        ";

        $searchLike = "%" . $search . "%";
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
    }

    if ($selectedCategoryId !== null) {
        $sql .= " AND i.category_id = ?";
        $params[] = $selectedCategoryId;
    }

    $sql .= "
        ORDER BY
            CASE
                WHEN COALESCE(i.stock_qty, 0) > 0
                 AND LOWER(TRIM(COALESCE(i.status, ''))) NOT IN (
                    'sold',
                    'sold out',
                    'released',
                    'reserved',
                    'unavailable'
                 )
                THEN 0
                ELSE 1
            END ASC,
            i.is_featured DESC,
            i.sort_order ASC,
            i.id DESC
        LIMIT " . $limit;

    $productsStmt = $pdo->prepare($sql);
    $productsStmt->execute($params);

    $rows = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

    $products = [];

    foreach ($rows as $row) {
        $rawPrice = (float)(
            ((float)$row["display_price"] > 0)
                ? $row["display_price"]
                : $row["appraisal_value"]
        );

        $isAvailable = isInventoryAvailable($row);

        $badge = null;

        if (!$isAvailable) {
            $badge = "Sold Out";
        } elseif ((int)$row["is_featured"] === 1) {
            $badge = "Featured";
        } elseif ($rawPrice >= 50000) {
            $badge = "Premium";
        } elseif ($rawPrice >= 20000) {
            $badge = "Popular";
        }

        $category = (string)($row["cat_name"] ?? $row["item_category"] ?? "General");

        $products[] = [
            "id" => (string)$row["id"],
            "name" => (string)$row["item_name"],
            "category" => $category,
            "category_icon" => (string)($row["cat_icon"] ?? mapCategoryIcon((string)($row["item_category"] ?? ""))),
            "price" => "₱" . number_format($rawPrice, 2),
            "raw_price" => number_format($rawPrice, 2, '.', ''),
            "image" => fullImageUrl((string)($row["item_photo_path"] ?? ""), $imageBaseUrl),
            "badge" => $badge,
            "stock_qty" => (int)($row["stock_qty"] ?? 0),
            "status" => (string)($row["status"] ?? ""),
            "is_available" => $isAvailable,
            "condition" => (string)($row["condition_notes"] ?? ""),
            "description" => $isAvailable
                ? $category . " item available for purchase"
                : $category . " item is sold out",
        ];
    }

    respond(200, [
        "success" => true,
        "tenant" => [
            "id" => (int)$tenant["id"],
            "name" => (string)$tenant["name"],
            "status" => (string)$tenant["status"],
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
        "error" => $e->getMessage(),
    ]);
}
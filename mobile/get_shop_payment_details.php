<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once dirname(__DIR__) . '/db.php';

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function requestData(): array
{
    $data = [];

    if (!empty($_GET)) {
        $data = array_merge($data, $_GET);
    }

    if (!empty($_POST)) {
        $data = array_merge($data, $_POST);
    }

    $raw = file_get_contents('php://input');

    if ($raw !== false && trim($raw) !== '') {
        $json = json_decode($raw, true);

        if (is_array($json)) {
            $data = array_merge($data, $json);
        }
    }

    return $data;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        respond(500, [
            'success' => false,
            'message' => 'PDO missing.',
        ]);
    }

    $data = requestData();

    $tenantId = intval($data['tenant_id'] ?? 0);
    $customerId = intval($data['customer_id'] ?? 0);
    $orderId = intval($data['order_id'] ?? 0);

    if ($tenantId <= 0 || $customerId <= 0 || $orderId <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Missing tenant, customer, or order ID.',
        ]);
    }

    $stmt = $pdo->prepare("
        SELECT
            o.id AS order_id,
            o.order_number,
            o.customer_id,
            o.customer_name,
            o.customer_email,
            o.customer_phone,
            o.subtotal,
            o.shipping_fee,
            o.tax_amount,
            o.discount_amount,
            o.total_amount,
            o.payment_method,
            o.payment_status,
            o.payment_provider,
            o.payment_reference_number,
            o.status AS order_status,
            o.created_at,
            o.updated_at,

            oi.inventory_item_id,
            oi.product_name,
            oi.product_category,
            oi.product_image_url,
            oi.quantity,
            oi.unit_price,
            oi.line_total,
            oi.item_status,

            i.stock_qty,
            i.status AS inventory_status,
            i.sold_amount,
            i.sold_at,
            i.item_photo_path
        FROM shop_orders o
        LEFT JOIN shop_order_items oi
            ON oi.order_id = o.id
            AND oi.tenant_id = o.tenant_id
        LEFT JOIN item_inventory i
            ON i.id = oi.inventory_item_id
            AND i.tenant_id = o.tenant_id
        WHERE o.id = ?
          AND o.tenant_id = ?
          AND o.customer_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $orderId,
        $tenantId,
        $customerId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        respond(404, [
            'success' => false,
            'message' => 'Order not found.',
        ]);
    }

    $imageBaseUrl = 'https://pawnhub-api-hqfkfxdaddhnfthf.southeastasia-01.azurewebsites.net/';

    $imagePath = trim((string)($row['product_image_url'] ?: $row['item_photo_path'] ?: ''));

    if ($imagePath !== '' && !preg_match('/^https?:\/\//i', $imagePath)) {
        $imagePath = rtrim($imageBaseUrl, '/') . '/' . ltrim($imagePath, '/');
    }

    $paymentDate = $row['sold_at'] ?: $row['updated_at'] ?: $row['created_at'];

    respond(200, [
        'success' => true,
        'order' => [
            'id' => (int)$row['order_id'],
            'order_number' => (string)($row['order_number'] ?? ''),
            'customer_id' => (int)$row['customer_id'],
            'customer_name' => (string)($row['customer_name'] ?? ''),
            'subtotal' => number_format((float)$row['subtotal'], 2, '.', ''),
            'shipping_fee' => number_format((float)$row['shipping_fee'], 2, '.', ''),
            'tax_amount' => number_format((float)$row['tax_amount'], 2, '.', ''),
            'discount_amount' => number_format((float)$row['discount_amount'], 2, '.', ''),
            'total_amount' => number_format((float)$row['total_amount'], 2, '.', ''),
            'payment_method' => (string)($row['payment_method'] ?: $row['payment_provider'] ?: 'paymongo'),
            'payment_status' => (string)($row['payment_status'] ?: 'paid'),
            'payment_reference_number' => (string)($row['payment_reference_number'] ?? ''),
            'payment_date' => (string)($paymentDate ?? ''),
            'order_status' => (string)($row['order_status'] ?? ''),
        ],
        'item' => [
            'id' => (int)$row['inventory_item_id'],
            'name' => (string)($row['product_name'] ?? ''),
            'category' => (string)($row['product_category'] ?? ''),
            'image' => $imagePath,
            'quantity' => (int)($row['quantity'] ?? 1),
            'unit_price' => number_format((float)$row['unit_price'], 2, '.', ''),
            'line_total' => number_format((float)$row['line_total'], 2, '.', ''),
            'remaining_stock' => (int)($row['stock_qty'] ?? 0),
            'inventory_status' => (string)($row['inventory_status'] ?? ''),
            'sold_amount' => number_format((float)($row['sold_amount'] ?? $row['total_amount']), 2, '.', ''),
            'sold_at' => (string)($row['sold_at'] ?? ''),
        ],
    ]);
} catch (Throwable $e) {
    respond(500, [
        'success' => false,
        'message' => 'Unable to load payment details.',
        'error' => $e->getMessage(),
    ]);
}
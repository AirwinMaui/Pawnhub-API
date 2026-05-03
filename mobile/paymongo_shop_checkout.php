<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/paymongo_config.php';

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function buildUrlWithQuery(string $baseUrl, array $params): string
{
    $separator = str_contains($baseUrl, '?') ? '&' : '?';

    return $baseUrl . $separator . http_build_query($params);
}

function normalizeInventoryStatus(?string $status): string
{
    return strtolower(trim((string)$status));
}

function isUnavailableInventoryStatus(?string $status): bool
{
    $unavailableStatuses = [
        'sold',
        'sold out',
        'released',
        'reserved',
        'unavailable',
    ];

    return in_array(normalizeInventoryStatus($status), $unavailableStatuses, true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed',
    ]);
}

try {
    $rawInput = file_get_contents('php://input');
    $input = json_decode((string)$rawInput, true);

    if (!is_array($input)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid JSON body.',
        ]);
    }

    $tenantId = intval($input['tenant_id'] ?? 0);
    $customerId = intval($input['customer_id'] ?? 0);
    $customerNameFromApp = trim((string)($input['customer_name'] ?? 'PawnHub Customer'));
    $productId = intval($input['product_id'] ?? 0);

    /*
      One item_inventory row represents one actual item.
      Force quantity to 1 to prevent buying the same item more than once.
    */
    $quantity = 1;

    $streetAddress = trim((string)($input['street_address'] ?? ''));
    $city = trim((string)($input['city'] ?? ''));
    $postalCode = trim((string)($input['postal_code'] ?? ''));

    if ($tenantId <= 0 || $customerId <= 0 || $productId <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Missing tenant, customer, or product.',
        ]);
    }

    if (PAYMONGO_SECRET_KEY === '') {
        respond(500, [
            'success' => false,
            'message' => 'PayMongo secret key is not configured.',
        ]);
    }

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        respond(500, [
            'success' => false,
            'message' => 'PDO missing.',
        ]);
    }

    $pdo->beginTransaction();

    /*
      Check customer.
    */
    $customerStmt = $pdo->prepare("
        SELECT id, tenant_id, full_name, email, contact_number
        FROM mobile_customers
        WHERE id = ?
          AND tenant_id = ?
          AND is_active = 1
        LIMIT 1
    ");

    $customerStmt->execute([
        $customerId,
        $tenantId,
    ]);

    $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        $pdo->rollBack();

        respond(404, [
            'success' => false,
            'message' => 'Customer not found.',
        ]);
    }

    /*
      Lock the inventory row before checking stock.
      This prevents two customers from buying the same item at the same time.
    */
    $itemStmt = $pdo->prepare("
        SELECT
            id,
            tenant_id,
            item_name,
            item_category,
            item_photo_path,
            display_price,
            appraisal_value,
            stock_qty,
            status,
            is_shop_visible
        FROM item_inventory
        WHERE id = ?
          AND tenant_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $itemStmt->execute([
        $productId,
        $tenantId,
    ]);

    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item || intval($item['is_shop_visible'] ?? 0) !== 1) {
        $pdo->rollBack();

        respond(409, [
            'success' => false,
            'message' => 'Sorry, this item is no longer available.',
        ]);
    }

    $stockQty = intval($item['stock_qty'] ?? 0);
    $itemStatus = normalizeInventoryStatus((string)($item['status'] ?? ''));

    if ($stockQty < $quantity || isUnavailableInventoryStatus($itemStatus)) {
        $pdo->rollBack();

        respond(409, [
            'success' => false,
            'message' => 'Sorry, this item is already sold out or no longer available.',
            'stock_qty' => $stockQty,
            'status' => $itemStatus,
        ]);
    }

    $unitPrice = floatval($item['display_price'] ?? 0);

    if ($unitPrice <= 0) {
        $unitPrice = floatval($item['appraisal_value'] ?? 0);
    }

    if ($unitPrice <= 0) {
        $pdo->rollBack();

        respond(400, [
            'success' => false,
            'message' => 'Item has no valid display price.',
        ]);
    }

    $subtotal = $unitPrice * $quantity;
    $shippingFee = 0.00;
    $taxAmount = 0.00;
    $discountAmount = 0.00;
    $totalAmount = $subtotal + $shippingFee + $taxAmount - $discountAmount;

    /*
      Mark item as sold immediately inside this transaction.
      If PayMongo checkout creation fails, the transaction rolls back.
    */
    $markSoldStmt = $pdo->prepare("
        UPDATE item_inventory
        SET
            stock_qty = 0,
            is_shop_visible = 0,
            status = 'sold',
            sold_amount = ?,
            sold_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
          AND tenant_id = ?
          AND is_shop_visible = 1
          AND COALESCE(stock_qty, 0) >= ?
          AND LOWER(TRIM(COALESCE(status, ''))) NOT IN (
              'sold',
              'sold out',
              'released',
              'reserved',
              'unavailable'
          )
    ");

    $markSoldStmt->execute([
        $totalAmount,
        $productId,
        $tenantId,
        $quantity,
    ]);

    if ($markSoldStmt->rowCount() !== 1) {
        $pdo->rollBack();

        respond(409, [
            'success' => false,
            'message' => 'Sorry, this item is already sold out.',
        ]);
    }

    $orderNumber = 'SHOP-' . date('YmdHis') . '-' . $customerId . '-' . random_int(1000, 9999);

    $customerName = trim((string)($customer['full_name'] ?? ''));

    if ($customerName === '') {
        $customerName = $customerNameFromApp;
    }

    $customerEmail = trim((string)($customer['email'] ?? ''));
    $customerPhone = trim((string)($customer['contact_number'] ?? ''));

    /*
      shop_orders.user_id is NOT NULL.
      This uses customer_id as user_id for mobile checkout.
    */
    $userId = $customerId;

    $orderStmt = $pdo->prepare("
        INSERT INTO shop_orders (
            tenant_id,
            customer_id,
            customer_name,
            customer_email,
            customer_phone,
            shipping_full_name,
            shipping_street_address,
            shipping_city,
            shipping_postal_code,
            user_id,
            order_number,
            subtotal,
            shipping_fee,
            tax_amount,
            discount_amount,
            total_amount,
            payment_method,
            payment_status,
            payment_provider,
            status,
            order_status,
            fulfillment_status,
            notes,
            created_at,
            updated_at
        ) VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            'paymongo',
            'pending',
            'paymongo',
            'pending',
            'pending',
            'pending',
            ?,
            NOW(),
            NOW()
        )
    ");

    $orderStmt->execute([
        $tenantId,
        $customerId,
        $customerName,
        $customerEmail,
        $customerPhone,
        $customerName,
        $streetAddress,
        $city,
        $postalCode,
        $userId,
        $orderNumber,
        $subtotal,
        $shippingFee,
        $taxAmount,
        $discountAmount,
        $totalAmount,
        'Created from mobile PayMongo checkout.',
    ]);

    $orderId = intval($pdo->lastInsertId());

    $itemName = trim((string)($item['item_name'] ?? 'Shop Item'));
    $itemCategory = trim((string)($item['item_category'] ?? 'Shop'));
    $itemImage = trim((string)($item['item_photo_path'] ?? ''));

    $orderItemStmt = $pdo->prepare("
        INSERT INTO shop_order_items (
            tenant_id,
            order_id,
            inventory_item_id,
            product_name,
            product_category,
            product_image_url,
            quantity,
            unit_price,
            line_total,
            item_status,
            created_at
        ) VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            'sold',
            NOW()
        )
    ");

    $orderItemStmt->execute([
        $tenantId,
        $orderId,
        $productId,
        $itemName,
        $itemCategory,
        $itemImage,
        $quantity,
        $unitPrice,
        $subtotal,
    ]);

    $amountInCentavos = intval(round($totalAmount * 100));

    if ($amountInCentavos <= 0) {
        $pdo->rollBack();

        respond(400, [
            'success' => false,
            'message' => 'Invalid payment amount.',
        ]);
    }

    $successUrl = buildUrlWithQuery(PAYMONGO_SUCCESS_URL, [
        'type' => 'shop',
        'tenant_id' => $tenantId,
        'customer_id' => $customerId,
        'order_id' => $orderId,
    ]);

    $cancelUrl = buildUrlWithQuery(PAYMONGO_CANCEL_URL, [
        'type' => 'shop',
        'tenant_id' => $tenantId,
        'customer_id' => $customerId,
        'order_id' => $orderId,
    ]);

    $payload = [
        'data' => [
            'attributes' => [
                'send_email_receipt' => true,
                'show_description' => true,
                'show_line_items' => true,
                'description' => 'PawnHub shop purchase: ' . $itemName,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'payment_method_types' => [
                    'card',
                    'gcash',
                    'paymaya',
                ],
                'billing' => [
                    'name' => $customerName,
                ],
                'line_items' => [
                    [
                        'currency' => 'PHP',
                        'amount' => $amountInCentavos,
                        'name' => $itemName,
                        'description' => $itemCategory,
                        'quantity' => $quantity,
                    ],
                ],
                'metadata' => [
                    'payment_type' => 'shop',
                    'tenant_id' => strval($tenantId),
                    'customer_id' => strval($customerId),
                    'order_id' => strval($orderId),
                    'order_number' => $orderNumber,
                    'inventory_item_id' => strval($productId),
                    'product_name' => $itemName,
                    'payment_amount' => strval($totalAmount),
                    'quantity' => strval($quantity),
                ],
            ],
        ],
    ];

    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);

    $rawResponse = curl_exec($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($curlError) {
        $pdo->rollBack();

        respond(500, [
            'success' => false,
            'message' => 'Unable to connect to PayMongo.',
            'error' => $curlError,
        ]);
    }

    $response = json_decode((string)$rawResponse, true);

    if (!is_array($response)) {
        $pdo->rollBack();

        respond(500, [
            'success' => false,
            'message' => 'Invalid PayMongo response.',
            'raw_response' => $rawResponse,
        ]);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $pdo->rollBack();

        respond($httpCode > 0 ? $httpCode : 500, [
            'success' => false,
            'message' => $response['errors'][0]['detail'] ?? 'PayMongo checkout creation failed.',
            'paymongo_response' => $response,
        ]);
    }

    $checkoutSessionId = $response['data']['id'] ?? '';
    $checkoutUrl = $response['data']['attributes']['checkout_url'] ?? '';

    if (!$checkoutSessionId || !$checkoutUrl) {
        $pdo->rollBack();

        respond(500, [
            'success' => false,
            'message' => 'PayMongo did not return a checkout URL.',
            'paymongo_response' => $response,
        ]);
    }

    $updateOrderStmt = $pdo->prepare("
        UPDATE shop_orders
        SET
            payment_reference_number = ?,
            updated_at = NOW()
        WHERE id = ?
          AND tenant_id = ?
    ");

    $updateOrderStmt->execute([
        $checkoutSessionId,
        $orderId,
        $tenantId,
    ]);

    $pdo->commit();

    respond(200, [
        'success' => true,
        'checkout_session_id' => $checkoutSessionId,
        'checkout_url' => $checkoutUrl,
        'order_id' => $orderId,
        'order_number' => $orderNumber,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, [
        'success' => false,
        'message' => 'Server error.',
        'error' => $e->getMessage(),
    ]);
}
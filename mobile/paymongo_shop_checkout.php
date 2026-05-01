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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed',
    ]);
}

try {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

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
    $quantity = intval($input['quantity'] ?? 1);

    $streetAddress = trim((string)($input['street_address'] ?? ''));
    $city = trim((string)($input['city'] ?? ''));
    $postalCode = trim((string)($input['postal_code'] ?? ''));

    if ($tenantId <= 0 || $customerId <= 0 || $productId <= 0 || $quantity <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Missing tenant, customer, product, or quantity.',
        ]);
    }

    if (PAYMONGO_SECRET_KEY === '') {
        respond(500, [
            'success' => false,
            'message' => 'PayMongo secret key is not configured.',
        ]);
    }

    $pdo->beginTransaction();

    $customerStmt = $pdo->prepare("
        SELECT id, tenant_id, full_name, email, contact_number
        FROM mobile_customers
        WHERE id = :customer_id
          AND tenant_id = :tenant_id
          AND is_active = 1
        LIMIT 1
    ");

    $customerStmt->execute([
        ':customer_id' => $customerId,
        ':tenant_id' => $tenantId,
    ]);

    $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        $pdo->rollBack();

        respond(404, [
            'success' => false,
            'message' => 'Customer not found.',
        ]);
    }

    $itemStmt = $pdo->prepare("
        SELECT *
        FROM item_inventory
        WHERE id = :id
          AND tenant_id = :tenant_id
          AND is_shop_visible = 1
        LIMIT 1
        FOR UPDATE
    ");

    $itemStmt->execute([
        ':id' => $productId,
        ':tenant_id' => $tenantId,
    ]);

    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        $pdo->rollBack();

        respond(404, [
            'success' => false,
            'message' => 'Shop item not found.',
        ]);
    }

    $stockQty = intval($item['stock_qty'] ?? 0);
    $itemStatus = strtolower((string)($item['status'] ?? ''));

    if ($stockQty < $quantity || in_array($itemStatus, ['sold', 'released'], true)) {
        $pdo->rollBack();

        respond(400, [
            'success' => false,
            'message' => 'This item is no longer available.',
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

    $orderNumber = 'SHOP-' . date('YmdHis') . '-' . $customerId . '-' . random_int(1000, 9999);

    $customerName = trim((string)($customer['full_name'] ?? ''));

    if ($customerName === '') {
        $customerName = $customerNameFromApp;
    }

    $customerEmail = trim((string)($customer['email'] ?? ''));
    $customerPhone = trim((string)($customer['contact_number'] ?? ''));

    /*
      Your shop_orders.user_id is NOT NULL.
      I am using customer_id as user_id for mobile checkout.
      If your admin/staff users table uses a different ID, replace this.
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
            :tenant_id,
            :customer_id,
            :customer_name,
            :customer_email,
            :customer_phone,
            :shipping_full_name,
            :shipping_street_address,
            :shipping_city,
            :shipping_postal_code,
            :user_id,
            :order_number,
            :subtotal,
            :shipping_fee,
            :tax_amount,
            :discount_amount,
            :total_amount,
            'paymongo',
            'pending',
            'paymongo',
            'pending',
            'pending',
            'pending',
            :notes,
            NOW(),
            NOW()
        )
    ");

    $orderStmt->execute([
        ':tenant_id' => $tenantId,
        ':customer_id' => $customerId,
        ':customer_name' => $customerName,
        ':customer_email' => $customerEmail,
        ':customer_phone' => $customerPhone,
        ':shipping_full_name' => $customerName,
        ':shipping_street_address' => $streetAddress,
        ':shipping_city' => $city,
        ':shipping_postal_code' => $postalCode,
        ':user_id' => $userId,
        ':order_number' => $orderNumber,
        ':subtotal' => $subtotal,
        ':shipping_fee' => $shippingFee,
        ':tax_amount' => $taxAmount,
        ':discount_amount' => $discountAmount,
        ':total_amount' => $totalAmount,
        ':notes' => 'Created from mobile PayMongo checkout.',
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
            :tenant_id,
            :order_id,
            :inventory_item_id,
            :product_name,
            :product_category,
            :product_image_url,
            :quantity,
            :unit_price,
            :line_total,
            'active',
            NOW()
        )
    ");

    $orderItemStmt->execute([
        ':tenant_id' => $tenantId,
        ':order_id' => $orderId,
        ':inventory_item_id' => $productId,
        ':product_name' => $itemName,
        ':product_category' => $itemCategory,
        ':product_image_url' => $itemImage,
        ':quantity' => $quantity,
        ':unit_price' => $unitPrice,
        ':line_total' => $subtotal,
    ]);

    $amountInCentavos = intval(round($totalAmount * 100));

    $successUrl =
        PAYMONGO_SUCCESS_URL .
        '?type=shop' .
        '&tenant_id=' . urlencode((string)$tenantId) .
        '&customer_id=' . urlencode((string)$customerId) .
        '&order_id=' . urlencode((string)$orderId);

    $cancelUrl =
        PAYMONGO_CANCEL_URL .
        '&type=shop' .
        '&tenant_id=' . urlencode((string)$tenantId) .
        '&customer_id=' . urlencode((string)$customerId) .
        '&order_id=' . urlencode((string)$orderId);

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
                    'paymaya'
                ],
                'billing' => [
                    'name' => $customerName
                ],
                'line_items' => [
                    [
                        'currency' => 'PHP',
                        'amount' => $amountInCentavos,
                        'name' => $itemName,
                        'description' => $itemCategory,
                        'quantity' => $quantity
                    ]
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
                    'quantity' => strval($quantity)
                ]
            ]
        ]
    ];

    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':')
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $rawResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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

    $response = json_decode($rawResponse, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $pdo->rollBack();

        respond($httpCode, [
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
        SET payment_reference_number = :payment_reference_number,
            updated_at = NOW()
        WHERE id = :id
          AND tenant_id = :tenant_id
    ");

    $updateOrderStmt->execute([
        ':payment_reference_number' => $checkoutSessionId,
        ':id' => $orderId,
        ':tenant_id' => $tenantId,
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
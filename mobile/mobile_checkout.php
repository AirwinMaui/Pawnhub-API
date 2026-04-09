<?php
ob_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'OPTIONS') {
    respond(200, [
        'success' => true,
        'message' => 'Preflight OK'
    ]);
}

if ($method !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed',
        'request_method' => $method
    ]);
}

try {
    error_log('CHECKOUT: started');
    error_log('CHECKOUT: before require db');

    require_once __DIR__ . '/../db.php';

    error_log('CHECKOUT: after require db');

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        respond(500, [
            'success' => false,
            'message' => 'PDO missing'
        ]);
    }

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    error_log('CHECKOUT: raw input = ' . $rawInput);

    if (!is_array($data)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid JSON payload'
        ]);
    }

    $customerId = (int)($data['customer_id'] ?? 0);
    $tenantId = (int)($data['tenant_id'] ?? 0);
    $productId = (int)($data['product_id'] ?? 0);
    $quantity = max(1, (int)($data['quantity'] ?? 1));

    $paymentMethod = trim((string)($data['payment_method'] ?? ''));
    $referenceNumber = trim((string)($data['reference_number'] ?? ''));
    $fullName = trim((string)($data['full_name'] ?? ''));
    $streetAddress = trim((string)($data['street_address'] ?? ''));
    $city = trim((string)($data['city'] ?? ''));
    $postalCode = trim((string)($data['postal_code'] ?? ''));

    if ($customerId <= 0 || $tenantId <= 0 || $productId <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Missing customer_id, tenant_id, or product_id'
        ]);
    }

    if ($paymentMethod === '') {
        respond(400, [
            'success' => false,
            'message' => 'Missing payment_method'
        ]);
    }

    $pdo->beginTransaction();
    error_log('CHECKOUT: transaction started');

    error_log('CHECKOUT: before customer query');

    $custStmt = $pdo->prepare("
        SELECT id, full_name, tenant_id
        FROM customers
        WHERE id = :customer_id
          AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $custStmt->execute([
        ':customer_id' => $customerId,
        ':tenant_id' => $tenantId,
    ]);
    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        $pdo->rollBack();
        respond(404, [
            'success' => false,
            'message' => 'Customer not found'
        ]);
    }

    error_log('CHECKOUT: customer found');

    error_log('CHECKOUT: before product query');

    $productStmt = $pdo->prepare("
        SELECT
            id,
            item_name,
            display_price,
            appraisal_value,
            stock_qty,
            is_shop_visible,
            status
        FROM item_inventory
        WHERE id = :product_id
          AND tenant_id = :tenant_id
        LIMIT 1
        FOR UPDATE
    ");
    $productStmt->execute([
        ':product_id' => $productId,
        ':tenant_id' => $tenantId,
    ]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        $pdo->rollBack();
        respond(404, [
            'success' => false,
            'message' => 'Product not found'
        ]);
    }

    error_log('CHECKOUT: product found');

    if ((int)$product['is_shop_visible'] !== 1) {
        $pdo->rollBack();
        respond(400, [
            'success' => false,
            'message' => 'Item is not available in shop'
        ]);
    }

    $currentStock = (int)($product['stock_qty'] ?? 0);

    if ($currentStock < $quantity) {
        $pdo->rollBack();
        respond(400, [
            'success' => false,
            'message' => 'Insufficient stock'
        ]);
    }

    $unitPrice = (float)(
        ((float)($product['display_price'] ?? 0) > 0)
            ? $product['display_price']
            : ($product['appraisal_value'] ?? 0)
    );

    if ($unitPrice <= 0) {
        $pdo->rollBack();
        respond(400, [
            'success' => false,
            'message' => 'Invalid item price'
        ]);
    }

    $lineTotal = $unitPrice * $quantity;
    $remainingStock = $currentStock - $quantity;
    $orderNumber = 'ORD-' . date('YmdHis') . '-' . mt_rand(1000, 9999);

    error_log('CHECKOUT: before shop_orders insert');

    $orderStmt = $pdo->prepare("
        INSERT INTO shop_orders (
            tenant_id,
            user_id,
            order_number,
            total_amount,
            status,
            created_at
        ) VALUES (
            :tenant_id,
            :user_id,
            :order_number,
            :total_amount,
            :status,
            NOW()
        )
    ");
    $orderStmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $customerId,
        ':order_number' => $orderNumber,
        ':total_amount' => $lineTotal,
        ':status' => 'paid',
    ]);

    $orderId = (int)$pdo->lastInsertId();
    error_log('CHECKOUT: order inserted id=' . $orderId);

    error_log('CHECKOUT: before shop_order_items insert');

    $orderItemStmt = $pdo->prepare("
        INSERT INTO shop_order_items (
            tenant_id,
            order_id,
            inventory_item_id,
            quantity,
            unit_price,
            line_total,
            created_at
        ) VALUES (
            :tenant_id,
            :order_id,
            :inventory_item_id,
            :quantity,
            :unit_price,
            :line_total,
            NOW()
        )
    ");
    $orderItemStmt->execute([
        ':tenant_id' => $tenantId,
        ':order_id' => $orderId,
        ':inventory_item_id' => $productId,
        ':quantity' => $quantity,
        ':unit_price' => $unitPrice,
        ':line_total' => $lineTotal,
    ]);

    error_log('CHECKOUT: order item inserted');

    error_log('CHECKOUT: before stock update');

    $updateStockStmt = $pdo->prepare("
        UPDATE item_inventory
        SET stock_qty = :stock_qty
        WHERE id = :product_id
          AND tenant_id = :tenant_id
    ");
    $updateStockStmt->execute([
        ':stock_qty' => $remainingStock,
        ':product_id' => $productId,
        ':tenant_id' => $tenantId,
    ]);

    error_log('CHECKOUT: stock updated');

    $pdo->commit();
    error_log('CHECKOUT: committed');

    respond(200, [
        'success' => true,
        'message' => 'Checkout successful',
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'product_id' => $productId,
        'product_name' => $product['item_name'],
        'quantity' => $quantity,
        'remaining_stock' => $remainingStock,
        'unit_price' => number_format($unitPrice, 2, '.', ''),
        'total_amount' => number_format($lineTotal, 2, '.', ''),
        'delivery' => [
            'full_name' => $fullName !== '' ? $fullName : ($customer['full_name'] ?? ''),
            'street_address' => $streetAddress,
            'city' => $city,
            'postal_code' => $postalCode,
            'payment_method' => $paymentMethod,
            'reference_number' => $referenceNumber,
        ],
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('CHECKOUT ERROR: ' . $e->getMessage());
    error_log('CHECKOUT FILE: ' . $e->getFile());
    error_log('CHECKOUT LINE: ' . $e->getLine());
    error_log('CHECKOUT TRACE: ' . $e->getTraceAsString());

    respond(500, [
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
}
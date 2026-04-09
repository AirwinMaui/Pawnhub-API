<?php
ob_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'OPTIONS') {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Preflight OK'
    ]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
        'request_method' => $method
    ]);
    exit;
}

try {
    require __DIR__ . '/../db.php';

    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $customerId = (int)($data['customer_id'] ?? 0);
    $tenantId = (int)($data['tenant_id'] ?? 0);
    $productId = (int)($data['product_id'] ?? 0);
    $quantity = max(1, (int)($data['quantity'] ?? 1));

    if ($customerId <= 0 || $tenantId <= 0 || $productId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing customer_id, tenant_id, or product_id'
        ]);
        exit;
    }

    $pdo->beginTransaction();

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
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Customer not found'
        ]);
        exit;
    }

    $productStmt = $pdo->prepare("
        SELECT id, item_name, display_price, stock_qty, is_shop_visible, status
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
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Product not found'
        ]);
        exit;
    }

    if ((int)$product['is_shop_visible'] !== 1) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Item is not available in shop'
        ]);
        exit;
    }

    $currentStock = (int)($product['stock_qty'] ?? 0);

    if ($currentStock < $quantity) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Insufficient stock'
        ]);
        exit;
    }

    $unitPrice = (float)($product['display_price'] ?? 0);

    if ($unitPrice <= 0) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid item price'
        ]);
        exit;
    }

    $lineTotal = $unitPrice * $quantity;
    $remainingStock = $currentStock - $quantity;
    $orderNumber = 'ORD-' . date('YmdHis') . '-' . mt_rand(1000, 9999);

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
            'paid',
            NOW()
        )
    ");
    $orderStmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $customerId,
        ':order_number' => $orderNumber,
        ':total_amount' => $lineTotal,
    ]);

    $orderId = (int)$pdo->lastInsertId();

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

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Checkout successful',
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'product_id' => $productId,
        'product_name' => $product['item_name'],
        'quantity' => $quantity,
        'remaining_stock' => $remainingStock,
        'unit_price' => $unitPrice,
        'total_amount' => $lineTotal,
    ]);
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage(),
    ]);
    exit;
}
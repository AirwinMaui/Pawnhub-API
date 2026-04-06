<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

require __DIR__ . '/../db.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $customerId = (int)($data['customer_id'] ?? 0);
    $tenantId = (int)($data['tenant_id'] ?? 0);
    $productId = (int)($data['product_id'] ?? 0);
    $quantity = max(1, (int)($data['quantity'] ?? 1));
    $paymentMethod = trim($data['payment_method'] ?? '');
    $referenceNumber = trim($data['reference_number'] ?? '');
    $fullName = trim($data['full_name'] ?? '');
    $streetAddress = trim($data['street_address'] ?? '');
    $city = trim($data['city'] ?? '');
    $postalCode = trim($data['postal_code'] ?? '');

    if ($customerId <= 0 || $tenantId <= 0 || $productId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing customer_id, tenant_id, or product_id'
        ]);
        exit;
    }

    if ($paymentMethod === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing payment_method'
        ]);
        exit;
    }

    $pdo->beginTransaction();

    $custStmt = $pdo->prepare("
        SELECT id, full_name, contact_number
        FROM customers
        WHERE id = :customer_id AND tenant_id = :tenant_id
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
        SELECT id, name, price, stock
        FROM products
        WHERE id = :product_id AND tenant_id = :tenant_id
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

    $currentStock = (int)($product['stock'] ?? 0);

    if ($currentStock < $quantity) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Insufficient stock'
        ]);
        exit;
    }

    $unitPrice = (float)($product['price'] ?? 0);
    $totalAmount = $unitPrice * $quantity;
    $remainingStock = $currentStock - $quantity;

    $updateStockStmt = $pdo->prepare("
        UPDATE products
        SET stock = :stock
        WHERE id = :product_id AND tenant_id = :tenant_id
    ");
    $updateStockStmt->execute([
        ':stock' => $remainingStock,
        ':product_id' => $productId,
        ':tenant_id' => $tenantId,
    ]);

    $orderStmt = $pdo->prepare("
        INSERT INTO orders (
            tenant_id,
            customer_id,
            product_id,
            quantity,
            unit_price,
            total_amount,
            payment_method,
            reference_number,
            full_name,
            street_address,
            city,
            postal_code,
            status,
            created_at
        ) VALUES (
            :tenant_id,
            :customer_id,
            :product_id,
            :quantity,
            :unit_price,
            :total_amount,
            :payment_method,
            :reference_number,
            :full_name,
            :street_address,
            :city,
            :postal_code,
            'paid',
            NOW()
        )
    ");
    $orderStmt->execute([
        ':tenant_id' => $tenantId,
        ':customer_id' => $customerId,
        ':product_id' => $productId,
        ':quantity' => $quantity,
        ':unit_price' => $unitPrice,
        ':total_amount' => $totalAmount,
        ':payment_method' => $paymentMethod,
        ':reference_number' => $referenceNumber,
        ':full_name' => $fullName !== '' ? $fullName : ($customer['full_name'] ?? ''),
        ':street_address' => $streetAddress,
        ':city' => $city,
        ':postal_code' => $postalCode,
    ]);

    $orderId = $pdo->lastInsertId();

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Checkout successful',
        'order_id' => $orderId,
        'product_id' => $productId,
        'remaining_stock' => $remainingStock,
        'total_amount' => $totalAmount,
    ]);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
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
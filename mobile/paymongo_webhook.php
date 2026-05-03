<?php
declare(strict_types=1);
 
// paymongo_webhook.php
 
ob_start();
 
ini_set('display_errors', '0');
error_reporting(E_ALL);
 
header('Content-Type: application/json; charset=UTF-8');
 
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/paymongo_config.php';
 
function respond(int $statusCode, array $payload): void
{
    if (ob_get_length()) {
        ob_clean();
    }
 
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
 
function verifyPayMongoSignature(string $body, string $sigHeader, string $secret): bool
{
    if ($body === '' || $sigHeader === '' || $secret === '') {
        return false;
    }
 
    $parts = [];
 
    foreach (explode(',', $sigHeader) as $item) {
        $pair = explode('=', trim($item), 2);
 
        if (count($pair) === 2) {
            $parts[$pair[0]] = $pair[1];
        }
    }
 
    $timestamp = $parts['t'] ?? '';
    $testSignature = $parts['te'] ?? '';
    $liveSignature = $parts['li'] ?? '';
 
    if ($timestamp === '') {
        return false;
    }
 
    $signedPayload = $timestamp . '.' . $body;
    $computedSignature = hash_hmac('sha256', $signedPayload, $secret);
    $providedSignature = $testSignature ?: $liveSignature;
 
    if ($providedSignature === '') {
        return false;
    }
 
    return hash_equals($computedSignature, $providedSignature);
}
 
try {
    $rawBody = file_get_contents('php://input') ?: '';
    $sigHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
 
    if (!verifyPayMongoSignature($rawBody, $sigHeader, PAYMONGO_WEBHOOK_SECRET)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid PayMongo signature.',
        ]);
    }
 
    $event = json_decode($rawBody, true);
 
    if (!is_array($event)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid webhook payload.',
        ]);
    }
 
    $eventType = $event['data']['attributes']['type'] ?? '';
 
    if ($eventType !== 'checkout_session.payment.paid') {
        respond(200, [
            'success' => true,
            'message' => 'Webhook received but ignored.',
            'event_type' => $eventType,
        ]);
    }
 
    $checkoutSession = $event['data']['attributes']['data'] ?? [];
    $sessionId = $checkoutSession['id'] ?? '';
    $attributes = $checkoutSession['attributes'] ?? [];
    $metadata = $attributes['metadata'] ?? [];
 
    $paymentType = strtolower((string)($metadata['payment_type'] ?? ''));
 
    /*
     * SHOP PAYMENT HANDLER
     * Handles payments created by mobile/paymongo_shop_checkout.php.
     */
    if ($paymentType === 'shop') {
        $tenantId = intval($metadata['tenant_id'] ?? 0);
        $customerId = intval($metadata['customer_id'] ?? 0);
        $orderId = intval($metadata['order_id'] ?? 0);
        $inventoryItemId = intval($metadata['inventory_item_id'] ?? 0);
        $paymentAmount = floatval($metadata['payment_amount'] ?? 0);
        $quantity = intval($metadata['quantity'] ?? 1);
 
        if ($quantity <= 0) {
            $quantity = 1;
        }
 
        if (
            $tenantId <= 0 ||
            $customerId <= 0 ||
            $orderId <= 0 ||
            $inventoryItemId <= 0 ||
            $sessionId === ''
        ) {
            respond(400, [
                'success' => false,
                'message' => 'Missing shop payment metadata.',
                'metadata' => $metadata,
                'session_id' => $sessionId,
            ]);
        }
 
        $pdo->beginTransaction();
 
        $orderStmt = $pdo->prepare("
            SELECT *
            FROM shop_orders
            WHERE id = :id
              AND tenant_id = :tenant_id
              AND customer_id = :customer_id
            LIMIT 1
            FOR UPDATE
        ");
 
        $orderStmt->execute([
            ':id' => $orderId,
            ':tenant_id' => $tenantId,
            ':customer_id' => $customerId,
        ]);
 
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
 
        if (!$order) {
            $pdo->rollBack();
 
            respond(404, [
                'success' => false,
                'message' => 'Shop order not found.',
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'tenant_id' => $tenantId,
            ]);
        }
 
        $orderTotal = floatval($order['total_amount'] ?? 0);
 
        if ($paymentAmount <= 0) {
            $paymentAmount = $orderTotal;
        }
 
        if ($orderTotal > 0 && $paymentAmount < $orderTotal) {
            $pdo->rollBack();
 
            respond(400, [
                'success' => false,
                'message' => 'PayMongo amount is less than shop order total.',
                'required_amount' => $orderTotal,
                'payment_amount' => $paymentAmount,
                'order_id' => $orderId,
            ]);
        }
 
        if (strtolower((string)($order['payment_status'] ?? '')) !== 'paid') {
            $updateOrderStmt = $pdo->prepare("
                UPDATE shop_orders
                SET payment_status = 'paid',
                    payment_method = 'paymongo',
                    payment_provider = 'paymongo',
                    payment_reference_number = :payment_reference_number,
                    paid_at = NOW(),
                    status = 'paid',
                    order_status = 'paid',
                    updated_at = NOW()
                WHERE id = :id
                  AND tenant_id = :tenant_id
            ");
 
            $updateOrderStmt->execute([
                ':payment_reference_number' => $sessionId,
                ':id' => $orderId,
                ':tenant_id' => $tenantId,
            ]);
 
            $updateItemStmt = $pdo->prepare("
                UPDATE item_inventory
                SET stock_qty = GREATEST(stock_qty - :quantity_stock, 0),
                    status = CASE
                        WHEN GREATEST(stock_qty - :quantity_status, 0) <= 0 THEN 'sold'
                        ELSE status
                    END,
                    is_shop_visible = CASE
                        WHEN GREATEST(stock_qty - :quantity_visible, 0) <= 0 THEN 0
                        ELSE is_shop_visible
                    END,
                    sold_amount = :sold_amount,
                    sold_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
                  AND tenant_id = :tenant_id
            ");
 
            $updateItemStmt->execute([
                ':quantity_stock' => $quantity,
                ':quantity_status' => $quantity,
                ':quantity_visible' => $quantity,
                ':sold_amount' => $paymentAmount,
                ':id' => $inventoryItemId,
                ':tenant_id' => $tenantId,
            ]);
 
            $updateOrderItemStmt = $pdo->prepare("
                UPDATE shop_order_items
                SET item_status = 'paid'
                WHERE order_id = :order_id
                  AND tenant_id = :tenant_id
                  AND inventory_item_id = :inventory_item_id
            ");
 
            $updateOrderItemStmt->execute([
                ':order_id' => $orderId,
                ':tenant_id' => $tenantId,
                ':inventory_item_id' => $inventoryItemId,
            ]);
        }
 
        try {
            $logStmt = $pdo->prepare("
                INSERT INTO payment_logs (
                    tenant_id,
                    customer_id,
                    ticket_no,
                    session_id,
                    amount,
                    status,
                    created_at
                ) VALUES (
                    :tenant_id,
                    :customer_id,
                    :ticket_no,
                    :session_id,
                    :amount,
                    'paid',
                    NOW()
                )
            ");
 
            $logStmt->execute([
                ':tenant_id' => $tenantId,
                ':customer_id' => $customerId,
                ':ticket_no' => 'SHOP-ORDER-' . $orderId,
                ':session_id' => $sessionId,
                ':amount' => $paymentAmount,
            ]);
        } catch (Throwable $ignored) {
            // Ignore if payment_logs does not exist or has a different schema.
        }
 
        $pdo->commit();
 
        // ── Notify tenant admin + managers of shop sale ───────
        try {
            $itemInfoStmt = $pdo->prepare("
                SELECT item_name, stock_qty
                FROM item_inventory
                WHERE id = ? AND tenant_id = ?
                LIMIT 1
            ");
            $itemInfoStmt->execute([$inventoryItemId, $tenantId]);
            $itemInfo = $itemInfoStmt->fetch(PDO::FETCH_ASSOC);
 
            if ($itemInfo) {
                $soldItemName = trim((string)($itemInfo['item_name'] ?? 'Shop Item'));
                $newStock     = max(0, (int)($itemInfo['stock_qty'] ?? 0));
 
                if ($newStock === 0) {
                    $notifTitle   = 'Item Sold — Out of Stock';
                    $notifMessage = "\"$soldItemName\" was just purchased. ⚠️ Item is now OUT OF STOCK.";
                    $notifType    = 'out_of_stock';
                    $notifIcon    = 'remove_shopping_cart';
                } elseif ($newStock <= 2) {
                    $notifTitle   = 'Item Sold — Low Stock';
                    $notifMessage = "\"$soldItemName\" was just purchased. Only $newStock unit" . ($newStock > 1 ? 's' : '') . ' left in stock.';
                    $notifType    = 'low_stock';
                    $notifIcon    = 'inventory_2';
                } else {
                    $notifTitle   = 'Shop Sale';
                    $notifMessage = "\"$soldItemName\" was just purchased. Stock remaining: $newStock.";
                    $notifType    = 'sale';
                    $notifIcon    = 'storefront';
                }
 
                $recipientsStmt = $pdo->prepare("
                    SELECT id FROM users
                    WHERE tenant_id = ?
                      AND role IN ('admin', 'manager')
                      AND status = 'approved'
                      AND is_suspended = 0
                ");
                $recipientsStmt->execute([$tenantId]);
                $recipients = $recipientsStmt->fetchAll(PDO::FETCH_COLUMN);
 
                if (!empty($recipients)) {
                    $notifInsert = $pdo->prepare("
                        INSERT INTO tenant_notifications
                            (tenant_id, user_id, type, icon, title, message,
                             entity_type, entity_id, is_read, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'shop_item', ?, 0, NOW())
                    ");
                    foreach ($recipients as $uid) {
                        $notifInsert->execute([
                            $tenantId,
                            $uid,
                            $notifType,
                            $notifIcon,
                            $notifTitle,
                            $notifMessage,
                            $inventoryItemId,
                        ]);
                    }
                }
            }
        } catch (Throwable $notifErr) {
            error_log('[Webhook] Shop sale notification error: ' . $notifErr->getMessage());
        }
 
        respond(200, [
            'success' => true,
            'message' => 'Shop order marked as paid.',
            'order_id' => $orderId,
            'inventory_item_id' => $inventoryItemId,
            'session_id' => $sessionId,
            'payment_amount' => $paymentAmount,
        ]);
    }
 
    /*
     * LOAN PAYMENT HANDLER
     * Handles payments created by mobile/paymongo_mobile_checkout.php.
     */
    $tenantId = (int)($metadata['tenant_id'] ?? 0);
    $customerId = (int)($metadata['customer_id'] ?? 0);
    $ticketNo = trim((string)($metadata['ticket_no'] ?? ''));
    $paymentAmount = (float)($metadata['payment_amount'] ?? 0);
 
    if ($tenantId <= 0 || $customerId <= 0 || $ticketNo === '' || $sessionId === '') {
        respond(400, [
            'success' => false,
            'message' => 'Missing required PayMongo loan metadata.',
            'metadata' => $metadata,
            'session_id' => $sessionId,
        ]);
    }
 
    $pdo->beginTransaction();
 
    $customerStmt = $pdo->prepare("
        SELECT id, tenant_id, full_name, contact_number
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
            'message' => 'Customer not found for PayMongo payment.',
            'customer_id' => $customerId,
            'tenant_id' => $tenantId,
            'ticket_no' => $ticketNo,
        ]);
    }
 
    $transactionStmt = $pdo->prepare("
        SELECT *
        FROM pawn_transactions
        WHERE tenant_id = :tenant_id
          AND ticket_no = :ticket_no
          AND (
                customer_id = :customer_id
                OR contact_number = :contact_number
              )
        LIMIT 1
        FOR UPDATE
    ");
 
    $transactionStmt->execute([
        ':tenant_id' => $tenantId,
        ':ticket_no' => $ticketNo,
        ':customer_id' => $customerId,
        ':contact_number' => $customer['contact_number'],
    ]);
 
    $transaction = $transactionStmt->fetch(PDO::FETCH_ASSOC);
 
    if (!$transaction) {
        $pdo->rollBack();
 
        respond(404, [
            'success' => false,
            'message' => 'Loan not found for this PayMongo payment.',
            'ticket_no' => $ticketNo,
            'customer_id' => $customerId,
            'tenant_id' => $tenantId,
        ]);
    }
 
    $currentStatus = strtolower((string)($transaction['status'] ?? ''));
 
    if (in_array($currentStatus, ['paid', 'redeemed', 'closed', 'completed'], true)) {
        $pdo->commit();
 
        respond(200, [
            'success' => true,
            'message' => 'Loan is already paid.',
            'ticket_no' => $ticketNo,
            'session_id' => $sessionId,
        ]);
    }
 
    $totalRedeem = (float)($transaction['total_redeem'] ?? 0);
 
    if ($paymentAmount <= 0) {
        $paymentAmount = $totalRedeem;
    }
 
    if ($totalRedeem > 0 && $paymentAmount < $totalRedeem) {
        $pdo->rollBack();
 
        respond(400, [
            'success' => false,
            'message' => 'PayMongo amount is less than total redeem amount.',
            'required_amount' => $totalRedeem,
            'payment_amount' => $paymentAmount,
            'ticket_no' => $ticketNo,
        ]);
    }
 
    $updateStmt = $pdo->prepare("
        UPDATE pawn_transactions
        SET status = 'paid',
            updated_at = NOW()
        WHERE id = :id
          AND tenant_id = :tenant_id
    ");
 
    $updateStmt->execute([
        ':id' => $transaction['id'],
        ':tenant_id' => $tenantId,
    ]);
 
    try {
        $paymentStmt = $pdo->prepare("
            INSERT INTO pawn_payments (
                tenant_id,
                pawn_transaction_id,
                ticket_no,
                customer_id,
                customer_name,
                contact_number,
                payment_amount,
                payment_method,
                notes,
                created_at
            ) VALUES (
                :tenant_id,
                :pawn_transaction_id,
                :ticket_no,
                :customer_id,
                :customer_name,
                :contact_number,
                :payment_amount,
                :payment_method,
                :notes,
                NOW()
            )
        ");
 
        $paymentStmt->execute([
            ':tenant_id' => $tenantId,
            ':pawn_transaction_id' => $transaction['id'],
            ':ticket_no' => $ticketNo,
            ':customer_id' => $customerId,
            ':customer_name' => $customer['full_name'],
            ':contact_number' => $customer['contact_number'],
            ':payment_amount' => $paymentAmount,
            ':payment_method' => 'paymongo',
            ':notes' => 'PayMongo Checkout Session: ' . $sessionId,
        ]);
    } catch (Throwable $ignored) {
        // Ignore if pawn_payments table does not exist or has a different schema.
    }
 
    try {
        $logStmt = $pdo->prepare("
            INSERT INTO payment_logs (
                tenant_id,
                customer_id,
                ticket_no,
                session_id,
                amount,
                status,
                created_at
            ) VALUES (
                :tenant_id,
                :customer_id,
                :ticket_no,
                :session_id,
                :amount,
                'paid',
                NOW()
            )
        ");
 
        $logStmt->execute([
            ':tenant_id' => $tenantId,
            ':customer_id' => $customerId,
            ':ticket_no' => $ticketNo,
            ':session_id' => $sessionId,
            ':amount' => $paymentAmount,
        ]);
    } catch (Throwable $ignored) {
        // Ignore if payment_logs does not exist or has a different schema.
    }
 
    $pdo->commit();
 
    respond(200, [
        'success' => true,
        'message' => 'Loan marked as paid from PayMongo webhook.',
        'ticket_no' => $ticketNo,
        'session_id' => $sessionId,
        'payment_amount' => $paymentAmount,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
 
    error_log('PayMongo webhook error: ' . $e->getMessage());
 
    respond(500, [
        'success' => false,
        'message' => 'PayMongo webhook server error.',
        'error' => $e->getMessage(),
    ]);
}
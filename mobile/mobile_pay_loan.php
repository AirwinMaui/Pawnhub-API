<?php
declare(strict_types=1);

ob_start();

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../db.php';

function respond(int $statusCode, array $payload): void
{
    if (ob_get_length()) {
        ob_clean();
    }

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
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid JSON body',
        ]);
    }

    $customerId = (int)($data['customer_id'] ?? $data['customerId'] ?? 0);
    $tenantId = (int)($data['tenant_id'] ?? $data['tenantId'] ?? 0);
    $ticketNo = trim((string)($data['ticket_no'] ?? $data['ticketNo'] ?? ''));
    $paymentAmount = (float)($data['payment_amount'] ?? $data['paymentAmount'] ?? 0);
    $paymentMethod = trim((string)($data['payment_method'] ?? $data['paymentMethod'] ?? 'cash'));
    $notes = trim((string)($data['notes'] ?? ''));

    if ($customerId <= 0 || $tenantId <= 0 || $ticketNo === '') {
        respond(400, [
            'success' => false,
            'message' => 'Missing customer_id, tenant_id, or ticket_no',
            'received' => $data,
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
            'message' => 'Customer not found',
            'debug' => [
                'customer_id' => $customerId,
                'tenant_id' => $tenantId,
            ],
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
            'message' => 'Loan not found for this customer',
            'debug' => [
                'ticket_no' => $ticketNo,
                'customer_id' => $customerId,
                'contact_number' => $customer['contact_number'],
            ],
        ]);
    }

    $currentStatus = strtolower((string)($transaction['status'] ?? ''));

    if (in_array($currentStatus, ['paid', 'redeemed', 'closed', 'completed'], true)) {
        $pdo->commit();

        respond(200, [
            'success' => true,
            'message' => 'Loan is already paid',
            'ticket_no' => $ticketNo,
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
            'message' => 'Payment amount is less than total redeem amount',
            'required_amount' => $totalRedeem,
            'payment_amount' => $paymentAmount,
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

    /*
     * Optional payment history insert.
     * This will only run if you have a pawn_payments table.
     */
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
            ':payment_method' => $paymentMethod,
            ':notes' => $notes,
        ]);
    } catch (Throwable $ignored) {
        /*
         * Ignore if pawn_payments table does not exist.
         * The loan status update is still valid.
         */
    }

    $pdo->commit();

    respond(200, [
        'success' => true,
        'message' => 'Loan paid successfully',
        'ticket_no' => $ticketNo,
        'payment_amount' => $paymentAmount,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, [
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage(),
    ]);
}
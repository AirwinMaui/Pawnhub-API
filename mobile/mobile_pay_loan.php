<?php
// mobile_pay_loan.php

header('Content-Type: application/json');

require_once dirname(__DIR__) . '/db.php';

$rawInput = file_get_contents('php://input');
$input    = json_decode($rawInput, true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}

$tenantId      = intval($input['tenant_id']      ?? 0);
$customerId    = intval($input['customer_id']    ?? 0);
$ticketNo      = trim($input['ticket_no']        ?? '');
$paymentAmount = floatval($input['payment_amount'] ?? 0);
$paymentMethod = strtolower(trim($input['payment_method'] ?? 'paymongo'));
$customerName  = trim($input['customer_name']    ?? '');
$notes         = trim($input['notes']            ?? '');

$allowedMethods = ['paymongo', 'partial', 'extension', 'cash'];
if (!in_array($paymentMethod, $allowedMethods, true)) {
    $paymentMethod = 'paymongo';
}

if (!$tenantId || !$customerId || !$ticketNo || $paymentAmount <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields: tenant_id, customer_id, ticket_no, payment_amount.',
    ]);
    exit;
}

try {
    // Fetch the loan
    $stmt = $pdo->prepare("
        SELECT *
        FROM pawn_transactions
        WHERE tenant_id = :tenant_id
          AND ticket_no = :ticket_no
        LIMIT 1
    ");
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':ticket_no' => $ticketNo,
    ]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$loan) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Loan not found.']);
        exit;
    }

    $currentStatus = strtolower((string)($loan['status'] ?? ''));

    if (in_array($currentStatus, ['paid', 'redeemed', 'closed', 'completed'], true)) {
        echo json_encode(['success' => true, 'message' => 'Loan is already paid.']);
        exit;
    }

    $totalRedeem = floatval($loan['total_redeem'] ?? 0);

    // ── Validate per payment method ───────────────────────────────
    // Only full payment needs to meet or exceed total_redeem.
    // Partial (installment) and extension are intentionally less.
    if (
        in_array($paymentMethod, ['paymongo', 'cash'], true) &&
        $totalRedeem > 0 &&
        $paymentAmount < $totalRedeem
    ) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Payment amount is less than total redeem amount.',
        ]);
        exit;
    }

    $pdo->beginTransaction();

    switch ($paymentMethod) {

        case 'extension':
            // Extend maturity by 30 days; loan stays active.
            $currentMaturity = $loan['maturity_date'];
            $newMaturity     = date('Y-m-d', strtotime('+30 days', strtotime($currentMaturity)));

            $pdo->prepare("
                UPDATE pawn_transactions
                SET maturity_date = :maturity_date,
                    updated_at    = NOW()
                WHERE id        = :id
                  AND tenant_id = :tenant_id
            ")->execute([
                ':maturity_date' => $newMaturity,
                ':id'            => $loan['id'],
                ':tenant_id'     => $tenantId,
            ]);

            $successMessage = "Loan ticket #{$ticketNo} extended to {$newMaturity}.";
            $action         = 'renew';
            break;

        case 'partial':
            // Subtract installment from outstanding balance.
            $newBalance = max(0, $totalRedeem - $paymentAmount);

            $pdo->prepare("
                UPDATE pawn_transactions
                SET total_redeem = :balance,
                    updated_at   = NOW()
                WHERE id        = :id
                  AND tenant_id = :tenant_id
            ")->execute([
                ':balance'   => $newBalance,
                ':id'        => $loan['id'],
                ':tenant_id' => $tenantId,
            ]);

            $successMessage = "Installment of ₱" . number_format($paymentAmount, 2) .
                              " applied. Remaining balance: ₱" . number_format($newBalance, 2) . ".";
            $action         = 'installment';
            break;

        default: // 'paymongo' or 'cash' — full payment
            $pdo->prepare("
                UPDATE pawn_transactions
                SET status     = 'paid',
                    updated_at = NOW()
                WHERE id        = :id
                  AND tenant_id = :tenant_id
            ")->execute([
                ':id'        => $loan['id'],
                ':tenant_id' => $tenantId,
            ]);

            $successMessage = "Loan ticket #{$ticketNo} marked as paid.";
            $action         = 'release';
            break;
    }

    // Log to payment_transactions
    try {
        $pdo->prepare("
            INSERT INTO payment_transactions (
                tenant_id, ticket_no, action,
                or_no, amount_due, cash_received, change_amount,
                staff_user_id, staff_username, staff_role,
                notes, created_at
            ) VALUES (
                :tenant_id, :ticket_no, :action,
                :or_no, :amount_due, :cash_received, 0,
                0, :staff_username, 'system',
                :notes, NOW()
            )
        ")->execute([
            ':tenant_id'      => $tenantId,
            ':ticket_no'      => $ticketNo,
            ':action'         => $action,
            ':or_no'          => 'MOBILE-' . strtoupper($paymentMethod) . '-' . time(),
            ':amount_due'     => $paymentAmount,
            ':cash_received'  => $paymentAmount,
            ':staff_username' => $paymentMethod === 'cash' ? 'Cash Payment' : 'PayMongo',
            ':notes'          => $notes ?: $successMessage,
        ]);
    } catch (Throwable $e) {
        error_log('[mobile_pay_loan] payment_transactions insert error: ' . $e->getMessage());
    }

    $pdo->commit();

    echo json_encode([
        'success'        => true,
        'message'        => $successMessage,
        'ticket_no'      => $ticketNo,
        'payment_method' => $paymentMethod,
        'payment_amount' => $paymentAmount,
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[mobile_pay_loan] error: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
    ]);
}
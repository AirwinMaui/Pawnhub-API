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
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require __DIR__ . '/../db.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $customerId = (int)($data['customer_id'] ?? 0);
    $tenantId = (int)($data['tenant_id'] ?? 0);
    $ticketNo = trim($data['ticket_no'] ?? '');
    $paymentAmount = (float)($data['payment_amount'] ?? 0);
    $paymentMethod = trim($data['payment_method'] ?? 'cash');
    $notes = trim($data['notes'] ?? '');

    if ($customerId <= 0 || $tenantId <= 0 || $ticketNo === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

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
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
        exit;
    }

    $pawnStmt = $pdo->prepare("
        SELECT id, ticket_no, status, loan_amount, interest_amount, total_redeem
        FROM pawn_transactions
        WHERE tenant_id = :tenant_id
          AND contact_number = :contact_number
          AND ticket_no = :ticket_no
        LIMIT 1
    ");
    $pawnStmt->execute([
        ':tenant_id' => $tenantId,
        ':contact_number' => $customer['contact_number'],
        ':ticket_no' => $ticketNo,
    ]);
    $pawn = $pawnStmt->fetch(PDO::FETCH_ASSOC);

    if (!$pawn) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Pawn transaction not found']);
        exit;
    }

    $currentStatus = strtolower((string)($pawn['status'] ?? ''));
    if (in_array($currentStatus, ['paid', 'redeemed', 'closed', 'completed'], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Loan already paid']);
        exit;
    }

    $amountDue = (float)($pawn['total_redeem'] ?? 0);
    if ($amountDue <= 0) {
        $amountDue = (float)($pawn['loan_amount'] ?? 0) + (float)($pawn['interest_amount'] ?? 0);
    }

    if ($paymentAmount <= 0) {
        $paymentAmount = $amountDue;
    }

    if ($paymentAmount < $amountDue) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Insufficient payment amount',
            'amount_due' => $amountDue,
        ]);
        exit;
    }

    $changeAmount = $paymentAmount - $amountDue;
    $orNo = 'OR-' . date('YmdHis') . '-' . mt_rand(100, 999);

    $pdo->beginTransaction();

    $insertPaymentStmt = $pdo->prepare("
        INSERT INTO payment_transactions (
            tenant_id,
            ticket_no,
            action,
            or_no,
            amount_due,
            cash_received,
            change_amount,
            staff_username,
            staff_role,
            new_ticket_no,
            created_at
        ) VALUES (
            :tenant_id,
            :ticket_no,
            :action,
            :or_no,
            :amount_due,
            :cash_received,
            :change_amount,
            :staff_username,
            :staff_role,
            :new_ticket_no,
            NOW()
        )
    ");
    $insertPaymentStmt->execute([
        ':tenant_id' => $tenantId,
        ':ticket_no' => $ticketNo,
        ':action' => 'redeem',
        ':or_no' => $orNo,
        ':amount_due' => $amountDue,
        ':cash_received' => $paymentAmount,
        ':change_amount' => $changeAmount,
        ':staff_username' => 'mobile-app',
        ':staff_role' => $paymentMethod !== '' ? $paymentMethod : 'mobile',
        ':new_ticket_no' => null,
    ]);

    $updatePawnStmt = $pdo->prepare("
        UPDATE pawn_transactions
        SET status = 'Paid'
        WHERE id = :id AND tenant_id = :tenant_id
        LIMIT 1
    ");
    $updatePawnStmt->execute([
        ':id' => $pawn['id'],
        ':tenant_id' => $tenantId,
    ]);

    $insertUpdateStmt = $pdo->prepare("
        INSERT INTO pawn_updates (
            ticket_no,
            update_type,
            message,
            created_at,
            is_read,
            read_at
        ) VALUES (
            :ticket_no,
            :update_type,
            :message,
            NOW(),
            0,
            NULL
        )
    ");
    $insertUpdateStmt->execute([
        ':ticket_no' => $ticketNo,
        ':update_type' => 'payment',
        ':message' => $notes !== ''
            ? 'Loan paid via mobile app. Notes: ' . $notes
            : 'Loan paid via mobile app.',
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Loan payment processed successfully',
        'ticket_no' => $ticketNo,
        'or_no' => $orNo,
        'amount_due' => $amountDue,
        'cash_received' => $paymentAmount,
        'change_amount' => $changeAmount,
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
        'error' => $e->getMessage()
    ]);
    exit;
}
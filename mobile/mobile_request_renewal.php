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
    $oldTicketNo = trim($data['old_ticket_no'] ?? '');
    $paymentMethod = trim($data['payment_method'] ?? '');
    $notes = trim($data['notes'] ?? '');

    if ($customerId <= 0 || $tenantId <= 0 || $oldTicketNo === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    $custStmt = $pdo->prepare("
        SELECT full_name, contact_number
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
        SELECT id, ticket_no
        FROM pawn_transactions
        WHERE tenant_id = :tenant_id
          AND contact_number = :contact_number
          AND ticket_no = :ticket_no
        LIMIT 1
    ");
    $pawnStmt->execute([
        ':tenant_id' => $tenantId,
        ':contact_number' => $customer['contact_number'],
        ':ticket_no' => $oldTicketNo,
    ]);
    $pawn = $pawnStmt->fetch(PDO::FETCH_ASSOC);

    if (!$pawn) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Pawn transaction not found']);
        exit;
    }

    $dupStmt = $pdo->prepare("
        SELECT id
        FROM renewal_requests
        WHERE tenant_id = :tenant_id
          AND old_ticket_no = :old_ticket_no
          AND verification_status = 'pending'
        LIMIT 1
    ");
    $dupStmt->execute([
        ':tenant_id' => $tenantId,
        ':old_ticket_no' => $oldTicketNo,
    ]);

    if ($dupStmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'A pending renewal request already exists']);
        exit;
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO renewal_requests (
            tenant_id,
            old_ticket_no,
            channel,
            payment_method,
            payment_status,
            verification_status,
            customer_name,
            contact_number,
            notes,
            created_at
        ) VALUES (
            :tenant_id,
            :old_ticket_no,
            'online',
            :payment_method,
            'pending',
            'pending',
            :customer_name,
            :contact_number,
            :notes,
            NOW()
        )
    ");

    $insertStmt->execute([
        ':tenant_id' => $tenantId,
        ':old_ticket_no' => $oldTicketNo,
        ':payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
        ':customer_name' => $customer['full_name'],
        ':contact_number' => $customer['contact_number'],
        ':notes' => $notes !== '' ? $notes : null,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Renewal request submitted successfully',
        'renewal_request_id' => (int)$pdo->lastInsertId(),
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
    exit;
}
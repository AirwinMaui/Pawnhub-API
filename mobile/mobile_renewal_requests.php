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

    if ($customerId <= 0 || $tenantId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing customer_id or tenant_id']);
        exit;
    }

    $custStmt = $pdo->prepare("
        SELECT contact_number
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

    $stmt = $pdo->prepare("
        SELECT
            id,
            old_ticket_no,
            channel,
            payment_method,
            payment_status,
            verification_status,
            customer_name,
            contact_number,
            proof_path,
            notes,
            paid_at,
            verified_at,
            new_ticket_no,
            created_at,
            updated_at
        FROM renewal_requests
        WHERE tenant_id = :tenant_id
          AND contact_number = :contact_number
        ORDER BY created_at DESC, id DESC
    ");
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':contact_number' => $customer['contact_number'],
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'renewal_requests' => $rows,
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
    exit;
}
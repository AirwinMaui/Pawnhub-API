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
    $status = trim($data['status'] ?? '');

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

    $sql = "
        SELECT
            id,
            ticket_no,
            item_category,
            item_description,
            loan_amount,
            interest_rate,
            interest_amount,
            total_redeem,
            pawn_date,
            maturity_date,
            expiry_date,
            auction_status,
            status,
            created_at,
            renewed_from_ticket_no,
            renewed_to_ticket_no
        FROM pawn_transactions
        WHERE tenant_id = :tenant_id
          AND contact_number = :contact_number
    ";

    $params = [
        ':tenant_id' => $tenantId,
        ':contact_number' => $customer['contact_number'],
    ];

    if ($status !== '') {
        $sql .= " AND LOWER(status) = LOWER(:status)";
        $params[':status'] = $status;
    }

    $sql .= " ORDER BY created_at DESC, id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'transactions' => $rows,
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
    exit;
}
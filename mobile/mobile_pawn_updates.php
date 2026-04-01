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
            pu.id,
            pu.ticket_no,
            pu.update_type,
            pu.message,
            pu.created_at,
            pu.is_read,
            pu.read_at
        FROM pawn_updates pu
        JOIN pawn_transactions p ON pu.ticket_no = p.ticket_no
        WHERE p.tenant_id = :tenant_id
          AND p.contact_number = :contact_number
    ";

    $params = [
        ':tenant_id' => $tenantId,
        ':contact_number' => $customer['contact_number'],
    ];

    if ($ticketNo !== '') {
        $sql .= " AND pu.ticket_no = :ticket_no";
        $params[':ticket_no'] = $ticketNo;
    }

    $sql .= " ORDER BY pu.created_at DESC, pu.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'updates' => $updates,
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
    exit;
}
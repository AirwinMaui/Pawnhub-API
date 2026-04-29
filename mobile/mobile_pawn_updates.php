<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../db.php';

function respond(int $statusCode, array $payload): void
{
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
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid JSON body',
        ]);
    }

    $customerId = (int)($data['customer_id'] ?? $data['customerId'] ?? 0);
    $tenantId = (int)($data['tenant_id'] ?? $data['tenantId'] ?? 0);
    $ticketNo = trim((string)($data['ticket_no'] ?? $data['ticketNo'] ?? ''));

    if ($customerId <= 0 || $tenantId <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Missing customer_id or tenant_id',
        ]);
    }

    $custStmt = $pdo->prepare("
        SELECT id, tenant_id, full_name, contact_number
        FROM mobile_customers
        WHERE id = :customer_id
          AND tenant_id = :tenant_id
          AND is_active = 1
        LIMIT 1
    ");

    $custStmt->execute([
        ':customer_id' => $customerId,
        ':tenant_id' => $tenantId,
    ]);

    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        respond(404, [
            'success' => false,
            'message' => 'Customer not found',
        ]);
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
        LEFT JOIN pawn_transactions pt
            ON pt.ticket_no = pu.ticket_no
           AND pt.tenant_id = pu.tenant_id
        LEFT JOIN pawn_requests pr
            ON pr.request_no = pu.ticket_no
           AND pr.tenant_id = pu.tenant_id
        WHERE pu.tenant_id = :tenant_id
          AND (
                pt.contact_number = :contact_number
             OR pr.contact_number = :contact_number
          )
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

    respond(200, [
        'success' => true,
        'updates' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Throwable $e) {
    respond(500, [
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage(),
    ]);
}
<?php
declare(strict_types=1);

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

    if ($customerId <= 0 || $tenantId <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Missing customer_id or tenant_id',
        ]);
    }

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
        respond(404, [
            'success' => false,
            'message' => 'Customer not found',
        ]);
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            ticket_no,
            customer_name,
            item_category,
            item_description,
            item_condition,
            serial_number,
            appraisal_value,
            loan_amount,
            interest_rate,
            interest_amount,
            total_redeem,
            pawn_date,
            maturity_date,
            expiry_date,
            auction_status,
            status,
            item_photo_path,
            created_at
        FROM pawn_transactions
        WHERE tenant_id = :tenant_id
          AND contact_number = :contact_number
        ORDER BY created_at DESC
    ");

    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':contact_number' => $customer['contact_number'],
    ]);

    respond(200, [
        'success' => true,
        'transactions' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Throwable $e) {
    respond(500, [
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage(),
    ]);
}
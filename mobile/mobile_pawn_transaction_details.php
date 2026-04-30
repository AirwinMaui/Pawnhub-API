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

    if ($customerId <= 0 || $tenantId <= 0 || $ticketNo === '') {
        respond(400, [
            'success' => false,
            'message' => 'Missing customer_id, tenant_id, or ticket_no',
            'received' => $data,
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
            'debug' => [
                'customer_id' => $customerId,
                'tenant_id' => $tenantId,
            ],
        ]);
    }

    $transactionStmt = $pdo->prepare("
        SELECT
            id,
            tenant_id,
            request_no,
            ticket_no,
            customer_id,
            customer_name,
            contact_number,
            email,
            address,
            item_category,
            item_description,
            item_condition,
            item_weight,
            item_karat,
            serial_number,
            appraisal_value,
            loan_amount,
            interest_rate,
            claim_term,
            interest_amount,
            total_redeem,
            pawn_date,
            maturity_date,
            expiry_date,
            auction_eligible,
            auction_status,
            status,
            item_photo_path,
            created_at,
            updated_at
        FROM pawn_transactions
        WHERE tenant_id = :tenant_id
          AND ticket_no = :ticket_no
          AND (
                customer_id = :customer_id
                OR contact_number = :contact_number
              )
        LIMIT 1
    ");

    $transactionStmt->execute([
        ':tenant_id' => $tenantId,
        ':ticket_no' => $ticketNo,
        ':customer_id' => $customerId,
        ':contact_number' => $customer['contact_number'],
    ]);

    $transaction = $transactionStmt->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
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

    respond(200, [
        'success' => true,
        'transaction' => $transaction,
    ]);
} catch (Throwable $e) {
    respond(500, [
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage(),
    ]);
}
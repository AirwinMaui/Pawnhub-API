<?php
ob_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'OPTIONS') {
    respond(200, [
        'success' => true,
        'message' => 'Preflight OK'
    ]);
}

if ($method !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed',
        'request_method' => $method
    ]);
}

try {
    require_once __DIR__ . '/../db.php';

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        respond(500, [
            'success' => false,
            'message' => 'PDO missing'
        ]);
    }

    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $customerId = (int)($data['customerId'] ?? $data['customer_id'] ?? 0);
    $tenantId = (int)($data['tenantId'] ?? $data['tenant_id'] ?? 0);

    if ($customerId <= 0 || $tenantId <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Missing customerId or tenantId',
            'debug' => [
                'customerId' => $customerId,
                'tenantId' => $tenantId,
                'received' => $data
            ]
        ]);
    }

    $customerStmt = $pdo->prepare("
        SELECT
            c.id,
            c.tenant_id,
            c.full_name,
            c.username,
            c.contact_number,
            c.email,
            c.profile_photo,
            t.business_name,
            t.tenant_code
        FROM mobile_customers c
        JOIN tenants t ON c.tenant_id = t.id
        WHERE c.id = :customer_id
          AND c.tenant_id = :tenant_id
          AND c.is_active = 1
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
                'customerId' => $customerId,
                'tenantId' => $tenantId
            ]
        ]);
    }

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_transactions,
            SUM(CASE WHEN LOWER(status) IN ('stored', 'active', 'pawned') THEN 1 ELSE 0 END) AS active_transactions,
            SUM(CASE WHEN LOWER(status) = 'redeemed' THEN 1 ELSE 0 END) AS redeemed_transactions,
            SUM(
                CASE
                    WHEN expiry_date < CURDATE()
                     AND LOWER(status) NOT IN ('redeemed', 'released')
                    THEN 1
                    ELSE 0
                END
            ) AS overdue_transactions,
            COALESCE(
                SUM(
                    CASE
                        WHEN LOWER(status) IN ('stored', 'active', 'pawned')
                        THEN loan_amount
                        ELSE 0
                    END
                ),
                0
            ) AS total_active_loan_amount
        FROM pawn_transactions
        WHERE tenant_id = :tenant_id
          AND contact_number = :contact_number
    ");

    $summaryStmt->execute([
        ':tenant_id' => $tenantId,
        ':contact_number' => $customer['contact_number'],
    ]);

    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $renewalStmt = $pdo->prepare("
        SELECT COUNT(*) AS pending_renewals
        FROM renewal_requests
        WHERE tenant_id = :tenant_id
          AND contact_number = :contact_number
          AND verification_status = 'pending'
    ");

    $renewalStmt->execute([
        ':tenant_id' => $tenantId,
        ':contact_number' => $customer['contact_number'],
    ]);

    $renewal = $renewalStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    respond(200, [
        'success' => true,
        'customer' => [
            'id' => (int)$customer['id'],
            'tenant_id' => (int)$customer['tenant_id'],
            'name' => $customer['full_name'],
            'username' => $customer['username'],
            'contact_number' => $customer['contact_number'],
            'email' => $customer['email'],
            'profile_photo' => $customer['profile_photo'],
        ],
        'tenant' => [
            'id' => $tenantId,
            'tenant_code' => (string)$customer['tenant_code'],
            'name' => $customer['business_name'],
        ],
        'summary' => [
            'total_transactions' => (int)($summary['total_transactions'] ?? 0),
            'active_transactions' => (int)($summary['active_transactions'] ?? 0),
            'redeemed_transactions' => (int)($summary['redeemed_transactions'] ?? 0),
            'overdue_transactions' => (int)($summary['overdue_transactions'] ?? 0),
            'pending_renewals' => (int)($renewal['pending_renewals'] ?? 0),
            'total_active_loan_amount' => (float)($summary['total_active_loan_amount'] ?? 0),
        ],
    ]);

} catch (Throwable $e) {
    error_log('DASHBOARD ERROR: ' . $e->getMessage());
    error_log('DASHBOARD FILE: ' . $e->getFile());
    error_log('DASHBOARD LINE: ' . $e->getLine());

    respond(500, [
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
}
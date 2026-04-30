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

    $customerId = (int)($data['customerId'] ?? $data['customer_id'] ?? 0);
    $tenantId = (int)($data['tenantId'] ?? $data['tenant_id'] ?? 0);

    if ($customerId <= 0 || $tenantId <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Missing customerId or tenantId',
            'received' => $data,
        ]);
    }

    $customerStmt = $pdo->prepare("
        SELECT
            c.*,
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
        ]);
    }

    $contactNumber = (string)($customer['contact_number'] ?? '');

    /*
     * Dashboard summary.
     * Matches by customer_id OR contact_number because older records may not have customer_id.
     */
    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_transactions,

            SUM(
                CASE
                    WHEN LOWER(COALESCE(status, '')) IN ('active', 'pawned', 'stored')
                    THEN 1 ELSE 0
                END
            ) AS active_transactions,

            SUM(
                CASE
                    WHEN LOWER(COALESCE(status, '')) IN ('paid', 'redeemed', 'closed', 'completed')
                    THEN 1 ELSE 0
                END
            ) AS redeemed_transactions,

            SUM(
                CASE
                    WHEN LOWER(COALESCE(status, '')) IN ('active', 'pawned', 'stored')
                     AND maturity_date < CURDATE()
                    THEN 1 ELSE 0
                END
            ) AS overdue_transactions,

            SUM(
                CASE
                    WHEN LOWER(COALESCE(status, '')) IN ('active', 'pawned', 'stored')
                    THEN COALESCE(total_redeem, loan_amount, 0)
                    ELSE 0
                END
            ) AS total_active_loan_amount
        FROM pawn_transactions
        WHERE tenant_id = :tenant_id
          AND (
                customer_id = :customer_id
                OR contact_number = :contact_number
              )
    ");

    $summaryStmt->execute([
        ':tenant_id' => $tenantId,
        ':customer_id' => $customerId,
        ':contact_number' => $contactNumber,
    ]);

    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    /*
     * Pending requests.
     */
    $pendingStmt = $pdo->prepare("
        SELECT
            id,
            request_no,
            item_category,
            item_description,
            item_condition,
            status,
            created_at
        FROM pawn_requests
        WHERE tenant_id = :tenant_id
          AND customer_id = :customer_id
          AND status = 'pending'
        ORDER BY created_at DESC
        LIMIT 5
    ");

    $pendingStmt->execute([
        ':tenant_id' => $tenantId,
        ':customer_id' => $customerId,
    ]);

    $pendingRequests = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Recent transactions.
     * This is the missing part needed by CustomerPortalScreen.tsx.
     */
    $transactionsStmt = $pdo->prepare("
        SELECT
            id,
            ticket_no,
            item_category,
            item_description,
            loan_amount,
            total_redeem,
            status,
            maturity_date,
            created_at
        FROM pawn_transactions
        WHERE tenant_id = :tenant_id
          AND (
                customer_id = :customer_id
                OR contact_number = :contact_number
              )
        ORDER BY created_at DESC
        LIMIT 5
    ");

    $transactionsStmt->execute([
        ':tenant_id' => $tenantId,
        ':customer_id' => $customerId,
        ':contact_number' => $contactNumber,
    ]);

    $transactions = $transactionsStmt->fetchAll(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,

        'customer' => [
            'id' => (int)$customer['id'],
            'name' => $customer['full_name'] ?? '',
            'contact_number' => $contactNumber,
        ],

        'tenant' => [
            'id' => $tenantId,
            'name' => $customer['business_name'] ?? '',
            'tenant_code' => $customer['tenant_code'] ?? null,
        ],

        'summary' => [
            'total_transactions' => (int)($summary['total_transactions'] ?? 0),
            'active_transactions' => (int)($summary['active_transactions'] ?? 0),
            'redeemed_transactions' => (int)($summary['redeemed_transactions'] ?? 0),
            'overdue_transactions' => (int)($summary['overdue_transactions'] ?? 0),
            'pending_renewals' => 0,
            'pending_requests' => count($pendingRequests),
            'total_active_loan_amount' => (float)($summary['total_active_loan_amount'] ?? 0),
        ],

        'pending_requests' => $pendingRequests,
        'transactions' => $transactions,
    ]);
} catch (Throwable $e) {
    respond(500, [
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage(),
    ]);
}
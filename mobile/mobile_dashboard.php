<?php
ob_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

require __DIR__ . '/../db.php';

try {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $customerId = (int)($data['customer_id'] ?? 0);
    $tenantId = (int)($data['tenant_id'] ?? 0);

    if ($customerId <= 0 || $tenantId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing customer_id or tenant_id'
        ]);
        exit;
    }

    $customerStmt = $pdo->prepare("
        SELECT c.contact_number, c.email, t.business_name, t.tenant_code
        FROM customers c
        JOIN tenants t ON c.tenant_id = t.id
        WHERE c.id = :customer_id
          AND c.tenant_id = :tenant_id
        LIMIT 1
    ");
    $customerStmt->execute([
        ':customer_id' => $customerId,
        ':tenant_id' => $tenantId,
    ]);
    $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Customer not found'
        ]);
        exit;
    }

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_transactions,
            SUM(CASE WHEN LOWER(status) IN ('stored', 'active') THEN 1 ELSE 0 END) AS active_transactions,
            SUM(CASE WHEN LOWER(status) = 'redeemed' THEN 1 ELSE 0 END) AS redeemed_transactions,
            SUM(CASE WHEN expiry_date < CURDATE() AND LOWER(status) NOT IN ('redeemed', 'released') THEN 1 ELSE 0 END) AS overdue_transactions,
            COALESCE(SUM(CASE WHEN LOWER(status) IN ('stored', 'active') THEN loan_amount ELSE 0 END), 0) AS total_active_loan_amount
        FROM pawn_transactions
        WHERE tenant_id = :tenant_id
          AND contact_number = :contact_number
    ");
    $summaryStmt->execute([
        ':tenant_id' => $tenantId,
        ':contact_number' => $customer['contact_number'],
    ]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

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
    $renewal = $renewalStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'tenant' => [
            'tenant_code' => (int)$customer['tenant_code'],
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
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage(),
    ]);
    exit;
}
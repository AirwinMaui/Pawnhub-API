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
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

try {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $customerId = (int)($data['customerId'] ?? 0);
    $tenantId = (int)($data['tenantId'] ?? 0);

    if ($customerId <= 0 || $tenantId <= 0) {
        respond(400, ['success' => false, 'message' => 'Missing params']);
    }

    // ✅ FIX: use mobile_customers
    $stmt = $pdo->prepare("
        SELECT c.*, t.business_name, t.tenant_code
        FROM mobile_customers c
        JOIN tenants t ON c.tenant_id = t.id
        WHERE c.id = ? AND c.tenant_id = ? AND c.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$customerId, $tenantId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        respond(404, ['success' => false, 'message' => 'Customer not found']);
    }

    // Loan summary
    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_transactions,
            SUM(CASE WHEN status IN ('active','pawned','stored') THEN 1 ELSE 0 END) AS active_transactions,
            SUM(CASE WHEN status = 'redeemed' THEN 1 ELSE 0 END) AS redeemed_transactions
        FROM pawn_transactions
        WHERE tenant_id = ?
          AND contact_number = ?
    ");
    $summaryStmt->execute([$tenantId, $customer['contact_number']]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    // ✅ NEW: pending pawn requests
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
        WHERE tenant_id = ?
          AND customer_id = ?
          AND status = 'pending'
        ORDER BY created_at DESC
    ");
    $pendingStmt->execute([$tenantId, $customerId]);
    $pending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,
        'customer' => [
            'id' => (int)$customer['id'],
            'name' => $customer['full_name'],
        ],
        'tenant' => [
            'id' => $tenantId,
            'name' => $customer['business_name'],
        ],
        'summary' => [
            'total_transactions' => (int)($summary['total_transactions'] ?? 0),
            'active_transactions' => (int)($summary['active_transactions'] ?? 0),
            'redeemed_transactions' => (int)($summary['redeemed_transactions'] ?? 0),
            'pending_requests' => count($pending),
        ],
        'pending_requests' => $pending,
    ]);

} catch (Throwable $e) {
    respond(500, [
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage(),
    ]);
}
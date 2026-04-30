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

function generateTicketNo(PDO $pdo, int $tenantId): string
{
    $prefix = 'PN-' . date('Ymd') . '-';

    for ($i = 0; $i < 10; $i++) {
        $ticketNo = $prefix . random_int(1000, 9999);

        $stmt = $pdo->prepare("
            SELECT id
            FROM pawn_transactions
            WHERE tenant_id = :tenant_id
              AND ticket_no = :ticket_no
            LIMIT 1
        ");

        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':ticket_no' => $ticketNo,
        ]);

        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            return $ticketNo;
        }
    }

    return $prefix . time();
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
    $requestNo = trim((string)($data['request_no'] ?? $data['requestNo'] ?? ''));
    $action = strtolower(trim((string)($data['action'] ?? '')));

    if ($customerId <= 0 || $tenantId <= 0 || $requestNo === '') {
        respond(400, [
            'success' => false,
            'message' => 'Missing customer_id, tenant_id, or request_no',
        ]);
    }

    if (!in_array($action, ['accept', 'decline', 'reject'], true)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid action',
        ]);
    }

    if ($action === 'reject') {
        $action = 'decline';
    }

    $pdo->beginTransaction();

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
        $pdo->rollBack();
        respond(404, [
            'success' => false,
            'message' => 'Customer not found',
        ]);
    }

    $requestStmt = $pdo->prepare("
        SELECT *
        FROM pawn_requests
        WHERE tenant_id = :tenant_id
          AND customer_id = :customer_id
          AND request_no = :request_no
        LIMIT 1
        FOR UPDATE
    ");

    $requestStmt->execute([
        ':tenant_id' => $tenantId,
        ':customer_id' => $customerId,
        ':request_no' => $requestNo,
    ]);

    $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        $pdo->rollBack();
        respond(404, [
            'success' => false,
            'message' => 'Pawn request not found',
        ]);
    }

    if ($request['status'] !== 'approved') {
        $pdo->rollBack();
        respond(400, [
            'success' => false,
            'message' => 'This request is not yet approved for customer response',
            'status' => $request['status'],
        ]);
    }

    if ($action === 'decline') {
        $updateStmt = $pdo->prepare("
            UPDATE pawn_requests
            SET status = 'rejected',
                updated_at = NOW()
            WHERE id = :id
              AND tenant_id = :tenant_id
        ");

        $updateStmt->execute([
            ':id' => $request['id'],
            ':tenant_id' => $tenantId,
        ]);

        $pdo->commit();

        respond(200, [
            'success' => true,
            'message' => 'Offer rejected successfully',
        ]);
    }

    $existingStmt = $pdo->prepare("
        SELECT id, ticket_no
        FROM pawn_transactions
        WHERE tenant_id = :tenant_id
          AND request_no = :request_no
        LIMIT 1
    ");

    $existingStmt->execute([
        ':tenant_id' => $tenantId,
        ':request_no' => $requestNo,
    ]);

    $existingTransaction = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingTransaction) {
        $pdo->commit();

        respond(200, [
            'success' => true,
            'message' => 'Offer already accepted',
            'ticket_no' => $existingTransaction['ticket_no'],
        ]);
    }

    $offerAmount = (float)($request['offer_amount'] ?? 0);
    $appraisalValue = (float)($request['appraisal_value'] ?? 0);
    $interestRate = (float)($request['interest_rate'] ?? 0);

    if ($offerAmount <= 0) {
        $pdo->rollBack();
        respond(400, [
            'success' => false,
            'message' => 'Invalid offer amount',
        ]);
    }

    $interestAmount = round($offerAmount * ($interestRate / 100), 2);
    $totalRedeem = $offerAmount + $interestAmount;

    $pawnDate = date('Y-m-d');
    $maturityDate = date('Y-m-d', strtotime('+30 days'));
    $expiryDate = date('Y-m-d', strtotime('+90 days'));

    if (!empty($request['claim_term'])) {
        $claimTerm = strtolower((string)$request['claim_term']);

        if (str_contains($claimTerm, '60')) {
            $maturityDate = date('Y-m-d', strtotime('+60 days'));
            $expiryDate = date('Y-m-d', strtotime('+120 days'));
        } elseif (str_contains($claimTerm, '90')) {
            $maturityDate = date('Y-m-d', strtotime('+90 days'));
            $expiryDate = date('Y-m-d', strtotime('+150 days'));
        }
    }

    $ticketNo = generateTicketNo($pdo, $tenantId);

    $insertStmt = $pdo->prepare("
        INSERT INTO pawn_transactions (
            tenant_id,
            request_no,
            ticket_no,
            customer_id,
            customer_name,
            contact_number,
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
            created_at,
            updated_at
        ) VALUES (
            :tenant_id,
            :request_no,
            :ticket_no,
            :customer_id,
            :customer_name,
            :contact_number,
            :item_category,
            :item_description,
            :item_condition,
            :serial_number,
            :appraisal_value,
            :loan_amount,
            :interest_rate,
            :interest_amount,
            :total_redeem,
            :pawn_date,
            :maturity_date,
            :expiry_date,
            'none',
            'active',
            :item_photo_path,
            NOW(),
            NOW()
        )
    ");

    $insertStmt->execute([
        ':tenant_id' => $tenantId,
        ':request_no' => $requestNo,
        ':ticket_no' => $ticketNo,
        ':customer_id' => $customerId,
        ':customer_name' => $request['customer_name'] ?: $customer['full_name'],
        ':contact_number' => $request['contact_number'] ?: $customer['contact_number'],
        ':item_category' => $request['item_category'],
        ':item_description' => $request['item_description'],
        ':item_condition' => $request['item_condition'],
        ':serial_number' => $request['serial_number'],
        ':appraisal_value' => $appraisalValue,
        ':loan_amount' => $offerAmount,
        ':interest_rate' => $interestRate,
        ':interest_amount' => $interestAmount,
        ':total_redeem' => $totalRedeem,
        ':pawn_date' => $pawnDate,
        ':maturity_date' => $maturityDate,
        ':expiry_date' => $expiryDate,
        ':item_photo_path' => $request['front_photo_path'] ?? null,
    ]);

    $updateRequestStmt = $pdo->prepare("
        UPDATE pawn_requests
        SET status = 'customer_accepted',
            updated_at = NOW()
        WHERE id = :id
          AND tenant_id = :tenant_id
    ");

    $updateRequestStmt->execute([
        ':id' => $request['id'],
        ':tenant_id' => $tenantId,
    ]);

    $pdo->commit();

    respond(200, [
        'success' => true,
        'message' => 'Offer accepted successfully',
        'ticket_no' => $ticketNo,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, [
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage(),
    ]);
}
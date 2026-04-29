<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

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

function getBearerToken(): string
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed',
    ]);
}

try {
    $token = getBearerToken();

    if ($token === '') {
        respond(401, [
            'success' => false,
            'message' => 'Unauthorized. Token required.',
        ]);
    }

    $authStmt = $pdo->prepare("
        SELECT
            mct.token,
            mct.customer_id,
            mct.expires_at,
            mc.id AS mobile_customer_id,
            mc.tenant_id,
            mc.full_name,
            mc.contact_number,
            mc.username
        FROM mobile_customer_tokens mct
        JOIN mobile_customers mc ON mc.id = mct.customer_id
        WHERE mct.token = :token
          AND mc.is_active = 1
          AND (mct.expires_at IS NULL OR mct.expires_at > NOW())
        LIMIT 1
    ");

    $authStmt->execute([
        ':token' => $token,
    ]);

    $auth = $authStmt->fetch(PDO::FETCH_ASSOC);

    if (!$auth) {
        respond(401, [
            'success' => false,
            'message' => 'Invalid or expired token.',
        ]);
    }

    $customerId = (int)$auth['mobile_customer_id'];
    $tenantId = (int)$auth['tenant_id'];

    $body = json_decode(file_get_contents('php://input'), true);

    if (!is_array($body)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid JSON body',
        ]);
    }

    $requestNo = trim((string)($body['request_no'] ?? $body['requestNo'] ?? ''));
    $action = strtolower(trim((string)($body['action'] ?? '')));

    if ($requestNo === '' || !in_array($action, ['accept', 'decline'], true)) {
        respond(422, [
            'success' => false,
            'message' => 'request_no and action accept|decline are required.',
        ]);
    }

    $pdo->beginTransaction();

    $reqStmt = $pdo->prepare("
        SELECT *
        FROM pawn_requests
        WHERE request_no = :request_no
          AND tenant_id = :tenant_id
          AND customer_id = :customer_id
        LIMIT 1
        FOR UPDATE
    ");

    $reqStmt->execute([
        ':request_no' => $requestNo,
        ':tenant_id' => $tenantId,
        ':customer_id' => $customerId,
    ]);

    $pr = $reqStmt->fetch(PDO::FETCH_ASSOC);

    if (!$pr) {
        $pdo->rollBack();
        respond(404, [
            'success' => false,
            'message' => 'Pawn request not found.',
        ]);
    }

    if ($pr['status'] !== 'approved') {
        $pdo->rollBack();

        $friendly = match ($pr['status']) {
            'pending' => 'No offer has been sent yet. Please wait for staff to review your request.',
            'customer_accepted' => 'You already accepted this offer.',
            'rejected' => 'This request has been rejected or declined.',
            'cancelled' => 'This request has already been cancelled.',
            default => 'This request cannot be responded to at this time.',
        };

        respond(409, [
            'success' => false,
            'message' => $friendly,
            'status' => $pr['status'],
        ]);
    }

    if ($action === 'decline') {
        $updateStmt = $pdo->prepare("
            UPDATE pawn_requests
            SET status = 'rejected',
                updated_at = NOW()
            WHERE id = :id
        ");

        $updateStmt->execute([
            ':id' => $pr['id'],
        ]);

        try {
            $updateLogStmt = $pdo->prepare("
                INSERT INTO pawn_updates (
                    tenant_id,
                    ticket_no,
                    update_type,
                    message,
                    created_at
                ) VALUES (
                    :tenant_id,
                    :ticket_no,
                    'CUSTOMER_DECLINED',
                    :message,
                    NOW()
                )
            ");

            $updateLogStmt->execute([
                ':tenant_id' => $tenantId,
                ':ticket_no' => $pr['request_no'],
                ':message' => "Customer {$auth['full_name']} declined the loan offer for request {$pr['request_no']}.",
            ]);
        } catch (Throwable $e) {
            error_log('PAWN UPDATE LOG ERROR: ' . $e->getMessage());
        }

        $pdo->commit();

        respond(200, [
            'success' => true,
            'message' => 'You have declined the loan offer. Your request has been closed.',
            'data' => [
                'request_no' => $pr['request_no'],
                'status' => 'rejected',
            ],
        ]);
    }

    $offerAmount = (float)($pr['offer_amount'] ?? 0);
    $interestRate = (float)($pr['interest_rate'] ?? 0);
    $appraisalValue = (float)($pr['appraisal_value'] ?? 0);

    if ($offerAmount <= 0) {
        $pdo->rollBack();
        respond(409, [
            'success' => false,
            'message' => 'This offer has no valid loan amount.',
        ]);
    }

    $interestAmount = $offerAmount * $interestRate;
    $totalRedeem = $offerAmount + $interestAmount;

    $pawnDate = date('Y-m-d');

    $claimTerm = trim((string)($pr['claim_term'] ?? ''));

    if ($claimTerm !== '' && preg_match('/(\d+)/', $claimTerm, $matches)) {
        $termDays = max(1, (int)$matches[1]);
    } else {
        $termDays = 30;
    }

    $maturityDate = date('Y-m-d', strtotime("+{$termDays} days"));
    $expiryDate = date('Y-m-d', strtotime("+60 days"));

    $ticketNo = 'PT-' . date('Ymd') . '-' . random_int(1000, 9999);

    $existingTicketStmt = $pdo->prepare("
        SELECT id
        FROM pawn_transactions
        WHERE tenant_id = :tenant_id
          AND request_no = :request_no
        LIMIT 1
    ");

    $existingTicketStmt->execute([
        ':tenant_id' => $tenantId,
        ':request_no' => $pr['request_no'],
    ]);

    if ($existingTicketStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->rollBack();
        respond(409, [
            'success' => false,
            'message' => 'This request was already converted to an active loan.',
        ]);
    }

    $insertTxnStmt = $pdo->prepare("
        INSERT INTO pawn_transactions (
            tenant_id,
            request_no,
            ticket_no,
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

    $insertTxnStmt->execute([
        ':tenant_id' => $tenantId,
        ':request_no' => $pr['request_no'],
        ':ticket_no' => $ticketNo,
        ':customer_name' => $pr['customer_name'],
        ':contact_number' => $pr['contact_number'],
        ':item_category' => $pr['item_category'],
        ':item_description' => $pr['item_description'],
        ':item_condition' => $pr['item_condition'],
        ':serial_number' => $pr['serial_number'],
        ':appraisal_value' => $appraisalValue,
        ':loan_amount' => $offerAmount,
        ':interest_rate' => $interestRate,
        ':interest_amount' => $interestAmount,
        ':total_redeem' => $totalRedeem,
        ':pawn_date' => $pawnDate,
        ':maturity_date' => $maturityDate,
        ':expiry_date' => $expiryDate,
        ':item_photo_path' => $pr['front_photo_path'],
    ]);

    $transactionId = (int)$pdo->lastInsertId();

    $updateReqStmt = $pdo->prepare("
        UPDATE pawn_requests
        SET status = 'customer_accepted',
            ticket_no = :ticket_no,
            updated_at = NOW()
        WHERE id = :id
    ");

    $updateReqStmt->execute([
        ':ticket_no' => $ticketNo,
        ':id' => $pr['id'],
    ]);

    try {
        $updateLogStmt = $pdo->prepare("
            INSERT INTO pawn_updates (
                tenant_id,
                ticket_no,
                update_type,
                message,
                created_at
            ) VALUES (
                :tenant_id,
                :ticket_no,
                'CUSTOMER_ACCEPTED',
                :message,
                NOW()
            )
        ");

        $updateLogStmt->execute([
            ':tenant_id' => $tenantId,
            ':ticket_no' => $ticketNo,
            ':message' => "Customer {$auth['full_name']} accepted the loan offer of ₱" . number_format($offerAmount, 2) . ". Loan is now active.",
        ]);
    } catch (Throwable $e) {
        error_log('PAWN UPDATE LOG ERROR: ' . $e->getMessage());
    }

    $pdo->commit();

    respond(200, [
        'success' => true,
        'message' => 'You have accepted the loan offer. Your loan is now active.',
        'data' => [
            'transaction_id' => $transactionId,
            'request_no' => $pr['request_no'],
            'ticket_no' => $ticketNo,
            'status' => 'active',
            'offer_amount' => $offerAmount,
            'interest_rate' => $interestRate,
            'interest_amount' => round($interestAmount, 2),
            'total_redeem' => round($totalRedeem, 2),
            'pawn_date' => $pawnDate,
            'maturity_date' => $maturityDate,
            'expiry_date' => $expiryDate,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('PAWN OFFER RESPOND ERROR: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage(),
    ]);
}
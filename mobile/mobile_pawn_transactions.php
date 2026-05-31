<?php
// mobile_pawn_transactions.php
// Returns all pawn transactions and pending/approved pawn requests for a customer.
// Before returning, silently accrues interest on any overdue active loans
// (maturity_date has passed and status is still active).

header('Content-Type: application/json');

require_once dirname(__DIR__) . '/db.php';

$rawInput = file_get_contents('php://input');
$input    = json_decode($rawInput, true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}

$tenantId   = intval($input['tenant_id']   ?? 0);
$customerId = intval($input['customer_id'] ?? 0);

if (!$tenantId || !$customerId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing tenant_id or customer_id.']);
    exit;
}

try {

    // ─────────────────────────────────────────────────────────────────────
    // STEP 1 — Auto-accrue interest on overdue active loans.
    //
    // Triggered every time the customer opens the app instead of a cron job.
    // Logic:
    //   • Find active loans where maturity_date < TODAY (overdue).
    //   • For each one, add (interest_rate × loan_amount) to total_redeem.
    //   • Roll maturity_date forward by 30 days.
    //   • Repeat until maturity_date >= TODAY (handles months of inactivity).
    //   • Log each accrual to payment_transactions so there is an audit trail.
    // ─────────────────────────────────────────────────────────────────────

    $overdueStmt = $pdo->prepare("
        SELECT id, ticket_no, loan_amount, interest_rate, interest_amount,
               total_redeem, maturity_date
        FROM pawn_transactions
        WHERE tenant_id    = :tenant_id
          AND customer_id  = :customer_id
          AND status NOT IN ('paid', 'redeemed', 'closed', 'completed')
          AND maturity_date < CURDATE()
    ");

    $overdueStmt->execute([
        ':tenant_id'   => $tenantId,
        ':customer_id' => $customerId,
    ]);

    $overdueLoans = $overdueStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($overdueLoans as $loan) {
        $loanAmount     = floatval($loan['loan_amount']     ?? 0);
        $interestRate   = floatval($loan['interest_rate']   ?? 0);
        $interestAmount = floatval($loan['interest_amount'] ?? 0);
        $totalRedeem    = floatval($loan['total_redeem']    ?? 0);
        $maturityDate   = $loan['maturity_date'];

        // Use the stored interest_amount if available;
        // otherwise compute from rate × principal.
        $monthlyInterest = $interestAmount > 0
            ? $interestAmount
            : round($loanAmount * $interestRate, 2);

        if ($monthlyInterest <= 0 || $loanAmount <= 0) {
            continue;
        }

        $today      = new DateTime('today');
        $maturity   = new DateTime($maturityDate);
        $accruals   = 0;
        $totalAdded = 0.0;

        // Roll forward one month at a time until maturity >= today.
        while ($maturity < $today) {
            $maturity->modify('+30 days');
            $totalRedeem += $monthlyInterest;
            $totalAdded  += $monthlyInterest;
            $accruals++;
        }

        if ($accruals === 0) {
            continue;
        }

        $newMaturity = $maturity->format('Y-m-d');

        // Update the loan record.
        $pdo->prepare("
            UPDATE pawn_transactions
            SET total_redeem  = :total_redeem,
                maturity_date = :maturity_date,
                updated_at    = NOW()
            WHERE id        = :id
              AND tenant_id = :tenant_id
        ")->execute([
            ':total_redeem'  => round($totalRedeem, 2),
            ':maturity_date' => $newMaturity,
            ':id'            => $loan['id'],
            ':tenant_id'     => $tenantId,
        ]);

        // Audit log — one entry per accrual cycle.
        try {
            $note = "Auto-accrued {$accruals} month(s) of interest "
                  . "(\xE2\x82\xB1" . number_format($totalAdded, 2) . " total). "
                  . "New maturity: {$newMaturity}.";

            $pdo->prepare("
                INSERT INTO payment_transactions (
                    tenant_id, ticket_no, action,
                    or_no, amount_due, cash_received, change_amount,
                    staff_user_id, staff_username, staff_role,
                    notes, created_at
                ) VALUES (
                    :tenant_id, :ticket_no, 'interest_accrual',
                    :or_no, :amount_due, 0, 0,
                    0, 'System', 'system',
                    :notes, NOW()
                )
            ")->execute([
                ':tenant_id'  => $tenantId,
                ':ticket_no'  => $loan['ticket_no'],
                ':or_no'      => 'AUTO-ACCRUE-' . $loan['id'] . '-' . time(),
                ':amount_due' => round($totalAdded, 2),
                ':notes'      => $note,
            ]);
        } catch (Throwable $logErr) {
            error_log('[mobile_pawn_transactions] accrual log error: ' . $logErr->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // STEP 2 — Fetch transactions (now with up-to-date balances).
    // ─────────────────────────────────────────────────────────────────────

    $transStmt = $pdo->prepare("
        SELECT
            id,
            NULL            AS request_no,
            ticket_no,
            item_category,
            item_description,
            loan_amount,
            interest_rate,
            interest_amount,
            total_redeem,
            pawn_date,
            maturity_date,
            expiry_date,
            auction_status,
            status,
            created_at,
            renewed_from_ticket_no,
            renewed_to_ticket_no
        FROM pawn_transactions
        WHERE tenant_id   = :tenant_id
          AND customer_id = :customer_id
        ORDER BY created_at DESC
    ");

    $transStmt->execute([
        ':tenant_id'   => $tenantId,
        ':customer_id' => $customerId,
    ]);

    $transactions = $transStmt->fetchAll(PDO::FETCH_ASSOC);

    // ─────────────────────────────────────────────────────────────────────
    // STEP 3 — Fetch pending / approved pawn requests.
    // ─────────────────────────────────────────────────────────────────────

    $reqStmt = $pdo->prepare("
        SELECT
            id,
            request_no,
            customer_id,
            customer_name,
            contact_number,
            item_category,
            item_description,
            item_condition,
            serial_number,
            appraisal_value,
            offer_amount,
            interest_rate,
            claim_term,
            status,
            remarks,
            created_at,
            updated_at
        FROM pawn_requests
        WHERE tenant_id   = :tenant_id
          AND customer_id = :customer_id
          AND status IN ('pending', 'approved')
        ORDER BY created_at DESC
    ");

    $reqStmt->execute([
        ':tenant_id'   => $tenantId,
        ':customer_id' => $customerId,
    ]);

    $requests = $reqStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'      => true,
        'transactions' => $transactions,
        'requests'     => $requests,
    ]);

} catch (Throwable $e) {
    error_log('[mobile_pawn_transactions] error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
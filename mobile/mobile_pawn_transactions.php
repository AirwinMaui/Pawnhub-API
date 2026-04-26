<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../db.php';

try {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!$data) {
        throw new Exception("Invalid JSON body");
    }

    $tenantId = (int)($data['tenantId'] ?? 1);
    $customerName = trim($data['fullName'] ?? '');
    $category = trim($data['category'] ?? '');
    $model = trim($data['model'] ?? '');
    $condition = trim($data['condition'] ?? '');
    $specs = trim($data['specs'] ?? '');
    $loanAmount = (float)($data['loanAmount'] ?? 0);
    $photoPath = trim($data['frontPhoto'] ?? '');

    if ($category === '' || $model === '' || $loanAmount <= 0) {
        throw new Exception("Missing required loan details");
    }

    $ticketNo = 'PN-' . date('Ymd') . '-' . random_int(1000, 9999);

    $interestRate = 3.00;
    $interestAmount = round($loanAmount * 0.03, 2);
    $totalRedeem = round($loanAmount + $interestAmount, 2);

    $pawnDate = date('Y-m-d');
    $maturityDate = date('Y-m-d', strtotime('+30 days'));
    $expiryDate = date('Y-m-d', strtotime('+60 days'));

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO pawn_transactions (
            tenant_id,
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
            auction_eligible,
            auction_status,
            status,
            created_by,
            created_at,
            item_photo_path
        ) VALUES (
            :tenant_id,
            :ticket_no,
            :customer_name,
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
            0,
            'none',
            'active',
            0,
            NOW(),
            :item_photo_path
        )
    ");

    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':ticket_no' => $ticketNo,
        ':customer_name' => $customerName,
        ':item_category' => $category,
        ':item_description' => $model,
        ':item_condition' => $condition,
        ':serial_number' => $specs,
        ':appraisal_value' => $loanAmount,
        ':loan_amount' => $loanAmount,
        ':interest_rate' => $interestRate,
        ':interest_amount' => $interestAmount,
        ':total_redeem' => $totalRedeem,
        ':pawn_date' => $pawnDate,
        ':maturity_date' => $maturityDate,
        ':expiry_date' => $expiryDate,
        ':item_photo_path' => $photoPath,
    ]);

    $pawnId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT INTO item_inventory (
            tenant_id,
            pawn_id,
            ticket_no,
            item_name,
            item_category,
            serial_no,
            condition_notes,
            appraisal_value,
            loan_amount,
            status,
            received_at,
            item_photo_path,
            is_shop_visible,
            is_featured,
            stock_qty,
            sort_order
        ) VALUES (
            :tenant_id,
            :pawn_id,
            :ticket_no,
            :item_name,
            :item_category,
            :serial_no,
            :condition_notes,
            :appraisal_value,
            :loan_amount,
            'pawned',
            NOW(),
            :item_photo_path,
            0,
            0,
            1,
            0
        )
    ");

    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':pawn_id' => $pawnId,
        ':ticket_no' => $ticketNo,
        ':item_name' => $model,
        ':item_category' => $category,
        ':serial_no' => $specs,
        ':condition_notes' => $condition,
        ':appraisal_value' => $loanAmount,
        ':loan_amount' => $loanAmount,
        ':item_photo_path' => $photoPath,
    ]);

    $stmt = $pdo->prepare("
        INSERT INTO pawn_updates (
            ticket_no,
            update_type,
            message,
            created_at,
            is_read
        ) VALUES (
            :ticket_no,
            'created',
            'New pawn loan created',
            NOW(),
            0
        )
    ");

    $stmt->execute([
        ':ticket_no' => $ticketNo,
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Pawn loan created successfully',
        'pawn_id' => $pawnId,
        'ticket_no' => $ticketNo,
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('CREATE PAWN ERROR: ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
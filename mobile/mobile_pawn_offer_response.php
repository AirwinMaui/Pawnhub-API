<?php
declare(strict_types=1);

ob_start();

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

register_shutdown_function(function () {
    $error = error_get_last();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_length()) {
            ob_clean();
        }

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Fatal server error',
            'error' => $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
});

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

function generateTicketNo(PDO $pdo, int $tenantId): string
{
    $prefix = 'TP-' . date('Ymd') . '-';

    for ($i = 0; $i < 10; $i++) {
        $ticketNo = $prefix . strtoupper(bin2hex(random_bytes(3)));

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

function getStringValue(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }

    return $default;
}

function getNullableStringValue(array $row, array $keys): ?string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }

    return null;
}

function getNullableDateValue(array $row, array $keys): ?string
{
    foreach ($keys as $key) {
        if (!isset($row[$key]) || trim((string)$row[$key]) === '') {
            continue;
        }

        $timestamp = strtotime((string)$row[$key]);

        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
    }

    return null;
}

function getIntValue(array $row, array $keys, int $default = 1): int
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && (int)$row[$key] > 0) {
            return (int)$row[$key];
        }
    }

    return $default;
}

function calculateDates(?string $claimTerm): array
{
    $pawnDate = date('Y-m-d');
    $maturityDays = 30;
    $expiryExtraDays = 60;

    $term = strtolower(trim((string)$claimTerm));

    if ($term !== '') {
        if (preg_match('/(\d+)/', $term, $matches)) {
            $days = (int)$matches[1];

            if ($days > 0) {
                $maturityDays = $days;
            }
        }
    }

    $maturityDate = date('Y-m-d', strtotime('+' . $maturityDays . ' days'));
    $expiryDate = date('Y-m-d', strtotime('+' . ($maturityDays + $expiryExtraDays) . ' days'));

    return [
        'pawn_date' => $pawnDate,
        'maturity_date' => $maturityDate,
        'expiry_date' => $expiryDate,
    ];
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
            'raw' => $raw,
        ]);
    }

    $customerId = (int)($data['customer_id'] ?? $data['customerId'] ?? 0);
    $tenantId = (int)($data['tenant_id'] ?? $data['tenantId'] ?? 0);
    $requestNo = trim((string)($data['request_no'] ?? $data['requestNo'] ?? ''));
    $action = strtolower(trim((string)($data['action'] ?? '')));

    if ($action === 'reject') {
        $action = 'decline';
    }

    if ($customerId <= 0 || $tenantId <= 0 || $requestNo === '') {
        respond(400, [
            'success' => false,
            'message' => 'Missing customer_id, tenant_id, or request_no',
            'received' => $data,
        ]);
    }

    if (!in_array($action, ['accept', 'decline'], true)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid action',
            'action' => $action,
        ]);
    }

    $pdo->beginTransaction();

    /*
     * Get customer data.
     * SELECT * is used to avoid crashes if your mobile_customers columns differ.
     */
    $customerStmt = $pdo->prepare("
        SELECT *
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

    /*
     * Get the approved pawn request.
     */
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

    if ((string)$request['status'] !== 'approved') {
        $pdo->rollBack();

        respond(400, [
            'success' => false,
            'message' => 'This request is not approved for customer response',
            'current_status' => $request['status'],
        ]);
    }

    /*
     * Customer rejects the offer.
     * No pawn transaction is created.
     */
    if ($action === 'decline') {
        $rejectStmt = $pdo->prepare("
            UPDATE pawn_requests
            SET status = 'rejected',
                updated_at = NOW()
            WHERE id = :id
              AND tenant_id = :tenant_id
        ");

        $rejectStmt->execute([
            ':id' => $request['id'],
            ':tenant_id' => $tenantId,
        ]);

        $pdo->commit();

        respond(200, [
            'success' => true,
            'message' => 'Offer rejected successfully',
        ]);
    }

    /*
     * Prevent duplicate transaction creation.
     */
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
            'message' => 'Offer already accepted',
            'ticket_no' => $existingTransaction['ticket_no'],
        ]);
    }

    /*
     * Customer accepts the offer.
     * A real pawn transaction is created here.
     */
    $offerAmount = (float)($request['offer_amount'] ?? 0);
    $appraisalValue = (float)($request['appraisal_value'] ?? 0);
    $interestRate = (float)($request['interest_rate'] ?? 0);

    if ($offerAmount <= 0) {
        $pdo->rollBack();

        respond(400, [
            'success' => false,
            'message' => 'Invalid offer amount',
            'offer_amount' => $request['offer_amount'] ?? null,
        ]);
    }

    $interestAmount = round($offerAmount * ($interestRate / 100), 2);
    $totalRedeem = round($offerAmount + $interestAmount, 2);

    $dates = calculateDates($request['claim_term'] ?? null);

    $pawnDate = $dates['pawn_date'];
    $maturityDate = $dates['maturity_date'];
    $expiryDate = $dates['expiry_date'];

    $ticketNo = generateTicketNo($pdo, $tenantId);

    $customerName = getStringValue(
        $request,
        ['customer_name'],
        getStringValue($customer, ['full_name', 'name', 'customer_name'], 'Customer')
    );

    $contactNumber = getStringValue(
        $request,
        ['contact_number'],
        getStringValue($customer, ['contact_number', 'phone', 'mobile_number'], '')
    );

    $email = getNullableStringValue($customer, ['email']);
    $address = getStringValue($customer, ['address', 'home_address', 'complete_address'], 'N/A');

    $sourceOfIncome = getNullableStringValue($customer, ['source_of_income']);
    $natureOfWork = getNullableStringValue($customer, ['nature_of_work']);
    $occupation = getNullableStringValue($customer, ['occupation']);
    $businessOfficeSchool = getNullableStringValue($customer, ['business_office_school']);
    $birthdate = getNullableDateValue($customer, ['birthdate', 'date_of_birth']);
    $gender = getNullableStringValue($customer, ['gender']);
    $nationality = getNullableStringValue($customer, ['nationality']);
    $birthplace = getNullableStringValue($customer, ['birthplace', 'place_of_birth']);

    $validIdType = getStringValue($customer, ['valid_id_type', 'id_type'], 'N/A');
    $validIdNumber = getStringValue($customer, ['valid_id_number', 'id_number'], 'N/A');

    $customerPhotoPath = getNullableStringValue($customer, ['customer_photo_path', 'photo_path']);
    $signaturePath = getNullableStringValue($customer, ['signature_path']);

    $itemCategory = getStringValue($request, ['item_category'], 'Uncategorized');
    $itemDescription = getStringValue($request, ['item_description'], 'No description');
    $itemCondition = getStringValue($request, ['item_condition'], 'Good');

    $itemWeight = isset($request['item_weight']) && $request['item_weight'] !== ''
        ? (float)$request['item_weight']
        : null;

    $itemKarat = getNullableStringValue($request, ['item_karat']);
    $serialNumber = getNullableStringValue($request, ['serial_number']);

    $createdBy = getIntValue($request, ['assigned_staff_id', 'created_by', 'appraised_by', 'approved_by'], 1);

    $assignedStaffId = getIntValue($request, ['assigned_staff_id', 'appraised_by', 'approved_by'], 0);
    $assignedStaffIdValue = $assignedStaffId > 0 ? $assignedStaffId : null;

    $itemPhotoPath = getNullableStringValue($request, [
        'front_photo_path',
        'item_photo_path',
        'detail_photo_path',
        'back_photo_path',
    ]);

    $insertStmt = $pdo->prepare("
        INSERT INTO pawn_transactions (
            tenant_id,
            request_no,
            ticket_no,
            customer_id,
            customer_name,
            contact_number,
            email,
            address,
            source_of_income,
            nature_of_work,
            occupation,
            business_office_school,
            birthdate,
            gender,
            nationality,
            birthplace,
            valid_id_type,
            valid_id_number,
            customer_photo_path,
            signature_path,
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
            created_by,
            assigned_staff_id,
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
            :email,
            :address,
            :source_of_income,
            :nature_of_work,
            :occupation,
            :business_office_school,
            :birthdate,
            :gender,
            :nationality,
            :birthplace,
            :valid_id_type,
            :valid_id_number,
            :customer_photo_path,
            :signature_path,
            :item_category,
            :item_description,
            :item_condition,
            :item_weight,
            :item_karat,
            :serial_number,
            :appraisal_value,
            :loan_amount,
            :interest_rate,
            :claim_term,
            :interest_amount,
            :total_redeem,
            :pawn_date,
            :maturity_date,
            :expiry_date,
            1,
            'none',
            'active',
            :created_by,
            :assigned_staff_id,
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

        ':customer_name' => $customerName,
        ':contact_number' => $contactNumber,
        ':email' => $email,
        ':address' => $address,
        ':source_of_income' => $sourceOfIncome,
        ':nature_of_work' => $natureOfWork,
        ':occupation' => $occupation,
        ':business_office_school' => $businessOfficeSchool,
        ':birthdate' => $birthdate,
        ':gender' => $gender,
        ':nationality' => $nationality,
        ':birthplace' => $birthplace,
        ':valid_id_type' => $validIdType,
        ':valid_id_number' => $validIdNumber,
        ':customer_photo_path' => $customerPhotoPath,
        ':signature_path' => $signaturePath,

        ':item_category' => $itemCategory,
        ':item_description' => $itemDescription,
        ':item_condition' => $itemCondition,
        ':item_weight' => $itemWeight,
        ':item_karat' => $itemKarat,
        ':serial_number' => $serialNumber,

        ':appraisal_value' => $appraisalValue,
        ':loan_amount' => $offerAmount,
        ':interest_rate' => $interestRate,
        ':claim_term' => $request['claim_term'] ?? null,
        ':interest_amount' => $interestAmount,
        ':total_redeem' => $totalRedeem,
        ':pawn_date' => $pawnDate,
        ':maturity_date' => $maturityDate,
        ':expiry_date' => $expiryDate,

        ':created_by' => $createdBy,
        ':assigned_staff_id' => $assignedStaffIdValue,
        ':item_photo_path' => $itemPhotoPath,
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
<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../db.php';

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function uploadImage(string $fieldName): ?string
{
    if (
        !isset($_FILES[$fieldName]) ||
        !is_array($_FILES[$fieldName]) ||
        ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Upload failed for {$fieldName}");
    }

    $uploadDir = __DIR__ . '/../uploads/pawn_requests/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $extension = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($extension, $allowed, true)) {
        throw new Exception("Invalid image type for {$fieldName}");
    }

    $filename = $fieldName . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    $targetPath = $uploadDir . $filename;

    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetPath)) {
        throw new Exception("Could not save {$fieldName}");
    }

    return 'uploads/pawn_requests/' . $filename;
}

try {
    $tenantId = (int)($_POST['tenantId'] ?? $_POST['tenant_id'] ?? 0);
    $customerId = (int)($_POST['customerId'] ?? $_POST['customer_id'] ?? 0);

    $category = trim((string)($_POST['category'] ?? ''));
    $model = trim((string)($_POST['model'] ?? $_POST['description'] ?? ''));
    $condition = trim((string)($_POST['condition'] ?? ''));
    $specs = trim((string)($_POST['specs'] ?? $_POST['serial_number'] ?? ''));

    if ($tenantId <= 0 || $customerId <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Missing tenantId or customerId',
        ]);
    }

    if ($category === '' || $model === '' || $condition === '') {
        respond(400, [
            'success' => false,
            'message' => 'Missing required item details',
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
        ]);
    }

    $frontPhoto = uploadImage('frontPhoto');
    $backPhoto = uploadImage('backPhoto');
    $detailPhoto = uploadImage('detailPhoto');

    $requestNo = 'REQ-' . date('Ymd') . '-' . random_int(1000, 9999);

    $stmt = $pdo->prepare("
        INSERT INTO pawn_requests (
            tenant_id,
            customer_id,
            customer_name,
            contact_number,
            request_no,
            item_category,
            item_description,
            item_condition,
            serial_number,
            front_photo_path,
            back_photo_path,
            detail_photo_path,
            status,
            created_at,
            updated_at
        ) VALUES (
            :tenant_id,
            :customer_id,
            :customer_name,
            :contact_number,
            :request_no,
            :item_category,
            :item_description,
            :item_condition,
            :serial_number,
            :front_photo_path,
            :back_photo_path,
            :detail_photo_path,
            'pending',
            NOW(),
            NOW()
        )
    ");

    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':customer_id' => $customerId,
        ':customer_name' => $customer['full_name'],
        ':contact_number' => $customer['contact_number'],
        ':request_no' => $requestNo,
        ':item_category' => $category,
        ':item_description' => $model,
        ':item_condition' => $condition,
        ':serial_number' => $specs,
        ':front_photo_path' => $frontPhoto,
        ':back_photo_path' => $backPhoto,
        ':detail_photo_path' => $detailPhoto,
    ]);

    respond(201, [
        'success' => true,
        'message' => 'Pawn request submitted successfully',
        'request_id' => (int)$pdo->lastInsertId(),
        'request_no' => $requestNo,
    ]);
} catch (Throwable $e) {
    respond(500, [
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage(),
    ]);
}
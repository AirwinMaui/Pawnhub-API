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
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed',
    ]);
}

require_once __DIR__ . '/../db.php';

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function uploadImageToAzure(
    string $fieldName,
    int $tenantId,
    string $requestNo,
    string $imageName
): ?string {
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

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $tmpPath = $_FILES[$fieldName]['tmp_name'];
    $mimeType = mime_content_type($tmpPath) ?: ($_FILES[$fieldName]['type'] ?? 'image/jpeg');

    if (!isset($allowedTypes[$mimeType])) {
        throw new Exception("Invalid image type for {$fieldName}");
    }

    $storageAccount = getenv('AZURE_STORAGE_ACCOUNT') ?: 'pawnhubstorage';
    $container = getenv('AZURE_STORAGE_CONTAINER') ?: 'item-images';
    $sasToken = getenv('AZURE_STORAGE_SAS_TOKEN');

    if (!$sasToken) {
        throw new Exception('Missing AZURE_STORAGE_SAS_TOKEN');
    }

    $sasToken = ltrim($sasToken, '?');

    $extension = $allowedTypes[$mimeType];
    $safeRequestNo = preg_replace('/[^A-Za-z0-9_-]/', '-', $requestNo);

    $blobName = "tenants/{$tenantId}/pawn-requests/{$safeRequestNo}/{$imageName}.{$extension}";
    $encodedBlobName = str_replace('%2F', '/', rawurlencode($blobName));

    $uploadUrl = "https://{$storageAccount}.blob.core.windows.net/{$container}/{$encodedBlobName}?{$sasToken}";
    $publicUrl = "https://{$storageAccount}.blob.core.windows.net/{$container}/{$encodedBlobName}";

    $fileContent = file_get_contents($tmpPath);

    if ($fileContent === false) {
        throw new Exception("Unable to read uploaded file for {$fieldName}");
    }

    $ch = curl_init($uploadUrl);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $fileContent,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'x-ms-blob-type: BlockBlob',
            'Content-Type: ' . $mimeType,
            'Content-Length: ' . strlen($fileContent),
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log("AZURE UPLOAD ERROR {$fieldName}: HTTP {$httpCode} {$curlError}");
        error_log("AZURE RESPONSE {$fieldName}: " . (string)$response);
        throw new Exception("Azure upload failed for {$fieldName}. HTTP {$httpCode}");
    }

    return $publicUrl;
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

    if (
        !isset($_FILES['frontPhoto']) ||
        !isset($_FILES['backPhoto']) ||
        !isset($_FILES['detailPhoto'])
    ) {
        respond(400, [
            'success' => false,
            'message' => 'Front, back, and detail photos are required',
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

    $requestNo = 'REQ-' . date('Ymd') . '-' . random_int(1000, 9999);

    $frontPhoto = uploadImageToAzure('frontPhoto', $tenantId, $requestNo, 'front');
    $backPhoto = uploadImageToAzure('backPhoto', $tenantId, $requestNo, 'back');
    $detailPhoto = uploadImageToAzure('detailPhoto', $tenantId, $requestNo, 'detail');

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
        'message' => 'Pawn request submitted successfully. Please wait for admin review.',
        'request_id' => (int)$pdo->lastInsertId(),
        'request_no' => $requestNo,
        'status' => 'pending',
        'photos' => [
            'front' => $frontPhoto,
            'back' => $backPhoto,
            'detail' => $detailPhoto,
        ],
    ]);
} catch (Throwable $e) {
    error_log('CREATE PAWN REQUEST ERROR: ' . $e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage(),
    ]);
}
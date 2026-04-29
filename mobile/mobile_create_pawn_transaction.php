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

function cleanBase64Image(string $base64): string
{
    if (str_contains($base64, ',')) {
        $parts = explode(',', $base64, 2);
        return $parts[1];
    }

    return $base64;
}

function uploadBase64ToAzure(
    string $base64,
    int $tenantId,
    string $requestNo,
    string $imageName
): string {
    $storageAccount = getenv('AZURE_STORAGE_ACCOUNT') ?: 'pawnhubstorage';
    $container = getenv('AZURE_STORAGE_CONTAINER') ?: 'item-images';
    $sasToken = getenv('AZURE_STORAGE_SAS_TOKEN');

    if (!$sasToken) {
        throw new Exception('Missing AZURE_STORAGE_SAS_TOKEN');
    }

    $base64 = cleanBase64Image($base64);
    $fileContent = base64_decode($base64, true);

    if ($fileContent === false) {
        throw new Exception("Invalid base64 image for {$imageName}");
    }

    $sasToken = ltrim($sasToken, '?');

    $safeRequestNo = preg_replace('/[^A-Za-z0-9_-]/', '-', $requestNo);
    $blobName = "tenants/{$tenantId}/pawn-requests/{$safeRequestNo}/{$imageName}.jpg";
    $encodedBlobName = str_replace('%2F', '/', rawurlencode($blobName));

    $uploadUrl = "https://{$storageAccount}.blob.core.windows.net/{$container}/{$encodedBlobName}?{$sasToken}";
    $publicUrl = "https://{$storageAccount}.blob.core.windows.net/{$container}/{$encodedBlobName}";

    $ch = curl_init($uploadUrl);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $fileContent,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'x-ms-blob-type: BlockBlob',
            'Content-Type: image/jpeg',
            'Content-Length: ' . strlen($fileContent),
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log("AZURE BASE64 UPLOAD ERROR {$imageName}: HTTP {$httpCode} {$curlError}");
        error_log("AZURE BASE64 RESPONSE {$imageName}: " . (string)$response);
        throw new Exception("Azure upload failed for {$imageName}. HTTP {$httpCode}");
    }

    return $publicUrl;
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

    $tenantId = (int)($data['tenantId'] ?? $data['tenant_id'] ?? 0);
    $customerId = (int)($data['customerId'] ?? $data['customer_id'] ?? 0);

    $category = trim((string)($data['category'] ?? ''));
    $model = trim((string)($data['model'] ?? $data['description'] ?? ''));
    $condition = trim((string)($data['condition'] ?? ''));
    $specs = trim((string)($data['specs'] ?? $data['serial_number'] ?? ''));

    $frontBase64 = (string)($data['frontPhotoBase64'] ?? '');
    $backBase64 = (string)($data['backPhotoBase64'] ?? '');
    $detailBase64 = (string)($data['detailPhotoBase64'] ?? '');

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

    if ($frontBase64 === '' || $backBase64 === '' || $detailBase64 === '') {
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

    $frontPhoto = uploadBase64ToAzure($frontBase64, $tenantId, $requestNo, 'front');
    $backPhoto = uploadBase64ToAzure($backBase64, $tenantId, $requestNo, 'back');
    $detailPhoto = uploadBase64ToAzure($detailBase64, $tenantId, $requestNo, 'detail');

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
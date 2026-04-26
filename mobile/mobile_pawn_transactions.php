<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../db.php';

function uploadToAzureBlob(array $file, string $folder): ?string
{
    $account = getenv('AZURE_STORAGE_ACCOUNT');
    $key = getenv('AZURE_STORAGE_KEY');
    $container = getenv('AZURE_STORAGE_CONTAINER') ?: 'pawn-items';

    if (!$account || !$key) {
        throw new Exception('Azure Blob Storage credentials missing');
    }

    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }

    $originalName = $file['name'] ?? 'image.jpg';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!$extension) {
        $extension = 'jpg';
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($extension, $allowed, true)) {
        $extension = 'jpg';
    }

    $content = file_get_contents($file['tmp_name']);

    if ($content === false) {
        throw new Exception('Unable to read uploaded image');
    }

    $contentLength = strlen($content);
    $contentType = $file['type'] ?? 'image/jpeg';

    if (!$contentType || $contentType === 'application/octet-stream') {
        $contentType = 'image/jpeg';
    }

    $blobName =
        trim($folder, '/') .
        '/' .
        date('Y/m/d') .
        '/' .
        uniqid('pawn_', true) .
        '.' .
        $extension;

    $date = gmdate('D, d M Y H:i:s \G\M\T');
    $version = '2020-10-02';

    $encodedBlobName = str_replace('%2F', '/', rawurlencode($blobName));
    $url = "https://{$account}.blob.core.windows.net/{$container}/{$encodedBlobName}";

    $canonicalizedHeaders =
        "x-ms-blob-type:BlockBlob\n" .
        "x-ms-date:{$date}\n" .
        "x-ms-version:{$version}\n";

    $canonicalizedResource = "/{$account}/{$container}/{$blobName}";

    $stringToSign =
        "PUT\n" .
        "\n" .
        "\n" .
        $contentLength . "\n" .
        "\n" .
        $contentType . "\n" .
        "\n" .
        "\n" .
        "\n" .
        "\n" .
        "\n" .
        "\n" .
        $canonicalizedHeaders .
        $canonicalizedResource;

    $signature = base64_encode(
        hash_hmac('sha256', $stringToSign, base64_decode($key), true)
    );

    $headers = [
        "Authorization: SharedKey {$account}:{$signature}",
        "x-ms-blob-type: BlockBlob",
        "x-ms-date: {$date}",
        "x-ms-version: {$version}",
        "Content-Type: {$contentType}",
        "Content-Length: {$contentLength}",
    ];

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $content,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception('Azure Blob upload failed: ' . $error);
    }

    curl_close($ch);

    if ($statusCode < 200 || $statusCode >= 300) {
        error_log('AZURE BLOB ERROR RESPONSE: ' . $response);
        throw new Exception('Azure Blob upload failed. HTTP status: ' . $statusCode);
    }

    return $url;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tenantId = (int)($_POST['tenantId'] ?? 1);
        $customerId = trim($_POST['customerId'] ?? '');
        $customerName = trim($_POST['fullName'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $condition = trim($_POST['condition'] ?? '');
        $specs = trim($_POST['specs'] ?? '');

        if ($category === '' || $model === '') {
            throw new Exception('Missing required item details');
        }

        if (
            !isset($_FILES['frontPhoto']) ||
            !isset($_FILES['backPhoto']) ||
            !isset($_FILES['detailPhoto'])
        ) {
            throw new Exception('Front, back, and detail photos are required');
        }

        $frontPhotoUrl = uploadToAzureBlob($_FILES['frontPhoto'], 'pawn-items/front');
        $backPhotoUrl = uploadToAzureBlob($_FILES['backPhoto'], 'pawn-items/back');
        $detailPhotoUrl = uploadToAzureBlob($_FILES['detailPhoto'], 'pawn-items/detail');

        if (!$frontPhotoUrl || !$backPhotoUrl || !$detailPhotoUrl) {
            throw new Exception('Unable to upload all item photos');
        }

        $ticketNo = 'PN-' . date('Ymd') . '-' . random_int(1000, 9999);

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
                NULL,
                NULL,
                NULL,
                NULL,
                NULL,
                CURDATE(),
                NULL,
                NULL,
                0,
                'none',
                'pending',
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
            ':item_photo_path' => $frontPhotoUrl,
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
                NULL,
                NULL,
                'pending',
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
            ':item_photo_path' => $frontPhotoUrl,
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
                'new_request',
                'New pawn request submitted from mobile. Admin review required.',
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
            'message' => 'Pawn request submitted',
            'ticket_no' => $ticketNo,
            'pawn_id' => $pawnId,
            'status' => 'pending',
            'photos' => [
                'front' => $frontPhotoUrl,
                'back' => $backPhotoUrl,
                'detail' => $detailPhotoUrl,
            ],
        ]);

        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $tenantId = $_GET['tenantId'] ?? null;

        if (!$tenantId) {
            throw new Exception('tenantId is required');
        }

        $stmt = $pdo->prepare("
            SELECT
                id,
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
                status,
                item_photo_path,
                created_at
            FROM pawn_transactions
            WHERE tenant_id = :tenant_id
            ORDER BY created_at DESC
        ");

        $stmt->execute([
            ':tenant_id' => $tenantId,
        ]);

        echo json_encode([
            'success' => true,
            'data' => $stmt->fetchAll(),
        ]);

        exit;
    }

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('MOBILE PAWN TRANSACTIONS ERROR: ' . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
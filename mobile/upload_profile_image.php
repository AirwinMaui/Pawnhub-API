<?php
declare(strict_types=1);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    exit;
}

require_once __DIR__ . "/../db.php";

function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function getEnvValue(string $key): string
{
    $value = getenv($key);

    if ($value === false || trim($value) === "") {
        throw new RuntimeException("Missing environment variable: {$key}");
    }

    return trim($value);
}

function getImageExtension(string $mimeType): string
{
    $allowed = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp",
    ];

    if (!isset($allowed[$mimeType])) {
        throw new RuntimeException("Only JPG, PNG, and WEBP images are allowed.");
    }

    return $allowed[$mimeType];
}

function normalizeSasToken(string $sasToken): string
{
    return str_starts_with($sasToken, "?") ? substr($sasToken, 1) : $sasToken;
}

function buildBlobUrl(string $baseUrl, string $container, string $blobName): string
{
    $baseUrl = rtrim($baseUrl, "/");
    $container = trim($container, "/");

    $encodedBlobName = implode(
        "/",
        array_map("rawurlencode", explode("/", $blobName))
    );

    return "{$baseUrl}/{$container}/{$encodedBlobName}";
}

function uploadToAzureBlobWithSas(
    string $tmpPath,
    string $mimeType,
    string $blobName
): string {
    $baseUrl = getEnvValue("AZURE_BLOB_BASE_URL");
    $container = getEnvValue("AZURE_BLOB_CONTAINER");
    $sasToken = normalizeSasToken(getEnvValue("AZURE_STORAGE_SAS_TOKEN"));

    $blobUrl = buildBlobUrl($baseUrl, $container, $blobName);
    $uploadUrl = "{$blobUrl}?{$sasToken}";

    $fileContents = file_get_contents($tmpPath);

    if ($fileContents === false) {
        throw new RuntimeException("Unable to read uploaded image.");
    }

    $headers = [
        "x-ms-blob-type: BlockBlob",
        "Content-Type: {$mimeType}",
        "Content-Length: " . strlen($fileContents),
    ];

    $ch = curl_init($uploadUrl);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $fileContents,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        throw new RuntimeException("Azure Blob upload failed: {$error}");
    }

    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException(
            "Azure Blob upload failed with status {$statusCode}: {$response}"
        );
    }

    /*
      If your container is public, save only $blobUrl.
      If your container is private, the mobile app needs a SAS URL to display it.
    */
    return "{$blobUrl}?{$sasToken}";
}

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        jsonResponse([
            "success" => false,
            "message" => "POST method required.",
        ], 405);
    }

    $customerId = (int) ($_POST["customer_id"] ?? $_POST["customerId"] ?? 0);
    $tenantId = (int) ($_POST["tenant_id"] ?? $_POST["tenantId"] ?? 0);

    if ($customerId <= 0 || $tenantId <= 0) {
        jsonResponse([
            "success" => false,
            "message" => "Missing customerId or tenantId.",
        ], 400);
    }

    if (!isset($_FILES["profile_image"])) {
        jsonResponse([
            "success" => false,
            "message" => "No image uploaded.",
        ], 400);
    }

    $file = $_FILES["profile_image"];

    if ($file["error"] !== UPLOAD_ERR_OK) {
        jsonResponse([
            "success" => false,
            "message" => "Upload failed.",
        ], 400);
    }

    $maxBytes = 5 * 1024 * 1024;

    if ((int) $file["size"] > $maxBytes) {
        jsonResponse([
            "success" => false,
            "message" => "Image is too large. Maximum size is 5MB.",
        ], 400);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file["tmp_name"]);

    if (!$mimeType) {
        jsonResponse([
            "success" => false,
            "message" => "Unable to detect image type.",
        ], 400);
    }

    $extension = getImageExtension($mimeType);

    $fileName = "profile_" . bin2hex(random_bytes(12)) . "." . $extension;
    $blobName = "tenant-{$tenantId}/customer-{$customerId}/{$fileName}";

    $profileImageUrl = uploadToAzureBlobWithSas(
        $file["tmp_name"],
        $mimeType,
        $blobName
    );

    $stmt = $pdo->prepare("
        UPDATE mobile_customers
        SET profile_image_url = :profile_image_url
        WHERE id = :customer_id
          AND tenant_id = :tenant_id
    ");

    $stmt->execute([
        ":profile_image_url" => $profileImageUrl,
        ":customer_id" => $customerId,
        ":tenant_id" => $tenantId,
    ]);

    if ($stmt->rowCount() === 0) {
        jsonResponse([
            "success" => false,
            "message" => "Customer not found or profile image was not updated.",
        ], 404);
    }

    jsonResponse([
        "success" => true,
        "message" => "Profile image uploaded.",
        "profile_image_url" => $profileImageUrl,
    ]);
} catch (Throwable $error) {
    jsonResponse([
        "success" => false,
        "message" => $error->getMessage(),
    ], 500);
}
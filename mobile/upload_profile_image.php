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

    $allowedMimeTypes = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp",
    ];

    $mimeType = mime_content_type($file["tmp_name"]);

    if (!isset($allowedMimeTypes[$mimeType])) {
        jsonResponse([
            "success" => false,
            "message" => "Only JPG, PNG, and WEBP images are allowed.",
        ], 400);
    }

    $extension = $allowedMimeTypes[$mimeType];
    $uploadDir = __DIR__ . "/profile_uploads";

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $fileName = "customer_{$customerId}_tenant_{$tenantId}_" . time() . "." . $extension;
    $targetPath = $uploadDir . "/" . $fileName;

    if (!move_uploaded_file($file["tmp_name"], $targetPath)) {
        jsonResponse([
            "success" => false,
            "message" => "Unable to save uploaded image.",
        ], 500);
    }

    $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
    $host = $_SERVER["HTTP_HOST"];
    $imageUrl = "{$scheme}://{$host}/mobile/profile_uploads/{$fileName}";

    $stmt = $pdo->prepare("
        UPDATE mobile_customers
        SET profile_image_url = :profile_image_url
        WHERE id = :customer_id
          AND tenant_id = :tenant_id
    ");

    $stmt->execute([
        ":profile_image_url" => $imageUrl,
        ":customer_id" => $customerId,
        ":tenant_id" => $tenantId,
    ]);

    jsonResponse([
        "success" => true,
        "message" => "Profile image uploaded.",
        "profile_image_url" => $imageUrl,
    ]);
} catch (Throwable $error) {
    jsonResponse([
        "success" => false,
        "message" => $error->getMessage(),
    ], 500);
}
<?php
declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed",
    ]);
    exit;
}

require __DIR__ . "/../db.php";

function envValue(string $key): string
{
    $value = getenv($key);
    return $value === false ? "" : trim($value);
}

function normalizeSasToken(string $sasToken): string
{
    $sasToken = trim($sasToken);

    if ($sasToken === "") {
        return "";
    }

    return substr($sasToken, 0, 1) === "?" ? substr($sasToken, 1) : $sasToken;
}

function appendSasToken(string $url): string
{
    $sasToken = normalizeSasToken(envValue("AZURE_STORAGE_SAS_TOKEN"));

    if ($sasToken === "") {
        return $url;
    }

    $cleanUrl = strtok($url, "?");

    return $cleanUrl . "?" . $sasToken;
}

function buildProfilePhotoUrl(?string $profilePhoto): ?string
{
    if (!$profilePhoto) {
        return null;
    }

    $profilePhoto = trim($profilePhoto);

    if ($profilePhoto === "") {
        return null;
    }

    if (
        stripos($profilePhoto, "http://") === 0 ||
        stripos($profilePhoto, "https://") === 0
    ) {
        return appendSasToken($profilePhoto);
    }

    $baseUrl = rtrim(envValue("AZURE_BLOB_BASE_URL"), "/");
    $container = trim(envValue("AZURE_BLOB_CONTAINER"), "/");

    if ($baseUrl === "" || $container === "") {
        return $profilePhoto;
    }

    $blobPath = ltrim($profilePhoto, "/");

    if (strpos($blobPath, $container . "/") === 0) {
        $blobPath = substr($blobPath, strlen($container) + 1);
    }

    $encodedBlobPath = implode(
        "/",
        array_map("rawurlencode", explode("/", $blobPath))
    );

    return appendSasToken("{$baseUrl}/{$container}/{$encodedBlobPath}");
}

try {
    $rawInput = file_get_contents("php://input");
    $data = json_decode($rawInput, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Invalid JSON body",
        ]);
        exit;
    }

    $customerId = (int)($data["customer_id"] ?? $data["customerId"] ?? 0);
    $tenantId = (int)($data["tenant_id"] ?? $data["tenantId"] ?? 0);

    if ($customerId <= 0 || $tenantId <= 0) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Missing customer_id or tenant_id",
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.full_name,
            c.username,
            c.contact_number,
            c.email,
            c.birthdate,
            c.address,
            c.gender,
            c.nationality,
            c.created_at,
            c.profile_photo,
            t.id AS tenant_id,
            t.tenant_code,
            t.business_name,
            t.slug,
            ts.primary_color,
            ts.secondary_color,
            ts.accent_color,
            ts.logo_text,
            ts.logo_url,
            ts.system_name,
            ts.bg_image_url
        FROM mobile_customers c
        JOIN tenants t ON c.tenant_id = t.id
        LEFT JOIN tenant_settings ts ON ts.tenant_id = t.id
        WHERE c.id = :customer_id
          AND c.tenant_id = :tenant_id
          AND c.is_active = 1
        LIMIT 1
    ");

    $stmt->execute([
        ":customer_id" => $customerId,
        ":tenant_id" => $tenantId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Customer not found",
        ]);
        exit;
    }

    $profileImageUrl = buildProfilePhotoUrl($row["profile_photo"] ?? null);

    echo json_encode([
        "success" => true,
        "customer" => [
            "id" => (int)$row["id"],
            "full_name" => $row["full_name"],
            "username" => $row["username"],
            "contact_number" => $row["contact_number"],
            "email" => $row["email"],
            "birthdate" => $row["birthdate"],
            "address" => $row["address"],
            "gender" => $row["gender"],
            "nationality" => $row["nationality"],
            "birthplace" => null,
            "registered_at" => $row["created_at"],
            "profile_photo" => $profileImageUrl,
            "profile_image_url" => $profileImageUrl,
        ],
        "tenant" => [
            "id" => (int)$row["tenant_id"],
            "tenant_code" => $row["tenant_code"],
            "name" => $row["business_name"],
            "slug" => $row["slug"],
        ],
        "theme" => [
            "primary_color" => $row["primary_color"],
            "secondary_color" => $row["secondary_color"],
            "accent_color" => $row["accent_color"],
            "logo_text" => $row["logo_text"],
            "logo_url" => $row["logo_url"],
            "system_name" => $row["system_name"],
            "bg_image_url" => $row["bg_image_url"],
        ],
    ]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error" => $e->getMessage(),
    ]);
    exit;
}
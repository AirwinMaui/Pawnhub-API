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

try {
    $data = json_decode(file_get_contents("php://input"), true);

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

    $fullName = trim((string)($data["full_name"] ?? ""));
    $username = trim((string)($data["username"] ?? ""));
    $email = trim((string)($data["email"] ?? ""));
    $contactNumber = trim((string)($data["contact_number"] ?? ""));
    $birthdate = trim((string)($data["birthdate"] ?? ""));
    $address = trim((string)($data["address"] ?? ""));
    $gender = trim((string)($data["gender"] ?? ""));
    $nationality = trim((string)($data["nationality"] ?? ""));

    if ($customerId <= 0 || $tenantId <= 0) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Missing customer_id or tenant_id",
        ]);
        exit;
    }

    if ($fullName === "") {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Full name is required",
        ]);
        exit;
    }

    if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Invalid email address",
        ]);
        exit;
    }

    if ($birthdate !== "" && !preg_match("/^\d{4}-\d{2}-\d{2}$/", $birthdate)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Birthdate must be in YYYY-MM-DD format",
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE mobile_customers
        SET
            full_name = :full_name,
            username = :username,
            email = :email,
            contact_number = :contact_number,
            birthdate = :birthdate,
            address = :address,
            gender = :gender,
            nationality = :nationality,
            updated_at = NOW()
        WHERE id = :customer_id
          AND tenant_id = :tenant_id
          AND is_active = 1
    ");

    $stmt->execute([
        ":full_name" => $fullName,
        ":username" => $username !== "" ? $username : null,
        ":email" => $email !== "" ? $email : null,
        ":contact_number" => $contactNumber !== "" ? $contactNumber : null,
        ":birthdate" => $birthdate !== "" ? $birthdate : null,
        ":address" => $address !== "" ? $address : null,
        ":gender" => $gender !== "" ? $gender : null,
        ":nationality" => $nationality !== "" ? $nationality : null,
        ":customer_id" => $customerId,
        ":tenant_id" => $tenantId,
    ]);

    $selectStmt = $pdo->prepare("
        SELECT
            id,
            full_name,
            username,
            contact_number,
            email,
            birthdate,
            address,
            gender,
            nationality,
            created_at,
            profile_photo
        FROM mobile_customers
        WHERE id = :customer_id
          AND tenant_id = :tenant_id
          AND is_active = 1
        LIMIT 1
    ");

    $selectStmt->execute([
        ":customer_id" => $customerId,
        ":tenant_id" => $tenantId,
    ]);

    $customer = $selectStmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Customer not found",
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Profile updated successfully",
        "customer" => [
            "id" => (int)$customer["id"],
            "full_name" => $customer["full_name"],
            "username" => $customer["username"],
            "contact_number" => $customer["contact_number"],
            "email" => $customer["email"],
            "birthdate" => $customer["birthdate"],
            "address" => $customer["address"],
            "gender" => $customer["gender"],
            "nationality" => $customer["nationality"],
            "registered_at" => $customer["created_at"],
            "profile_photo" => $customer["profile_photo"],
            "profile_image_url" => $customer["profile_photo"],
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
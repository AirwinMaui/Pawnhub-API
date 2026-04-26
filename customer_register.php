<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

ini_set("display_errors", "0");
error_reporting(E_ALL);

require_once __DIR__ . "/db.php";

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(405, [
        "success" => false,
        "message" => "Method not allowed",
    ]);
}

$raw = file_get_contents("php://input");
$data = [];

if (!empty($_POST)) {
    $data = $_POST;
} elseif ($raw !== false && trim($raw) !== "") {
    $json = json_decode($raw, true);

    if (is_array($json)) {
        $data = $json;
    } else {
        parse_str($raw, $data);
    }
}

$accessCode = trim((string)($data["access_code"] ?? ""));
$fullName = trim((string)($data["full_name"] ?? $data["fullname"] ?? ""));
$email = trim((string)($data["email"] ?? ""));
$username = trim((string)($data["username"] ?? ""));
$password = trim((string)($data["password"] ?? ""));
$contact = trim((string)($data["contact_number"] ?? $data["phone"] ?? ""));

$birthdate = trim((string)($data["birthdate"] ?? ""));
$address = trim((string)($data["address"] ?? ""));
$gender = trim((string)($data["gender"] ?? ""));
$nationality = trim((string)($data["nationality"] ?? "Filipino"));

if ($accessCode === "") {
    respond(400, [
        "success" => false,
        "message" => "Access code is required.",
    ]);
}

if ($fullName === "") {
    respond(400, [
        "success" => false,
        "message" => "Full name is required.",
    ]);
}

if ($email === "") {
    respond(400, [
        "success" => false,
        "message" => "Email is required.",
    ]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(400, [
        "success" => false,
        "message" => "Invalid email format.",
    ]);
}

if ($password === "") {
    respond(400, [
        "success" => false,
        "message" => "Password is required.",
    ]);
}

if (strlen($password) < 8) {
    respond(400, [
        "success" => false,
        "message" => "Password must be at least 8 characters.",
    ]);
}

if ($username === "") {
    $username = $email;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            ts.tenant_id,
            t.business_name,
            t.status
        FROM tenant_settings ts
        INNER JOIN tenants t ON t.id = ts.tenant_id
        WHERE ts.access_code = ?
        LIMIT 1
    ");
    $stmt->execute([$accessCode]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant) {
        respond(404, [
            "success" => false,
            "message" => "Invalid access code.",
        ]);
    }

    if (($tenant["status"] ?? "") === "inactive") {
        respond(403, [
            "success" => false,
            "message" => "This branch is currently inactive.",
        ]);
    }

    $tenantId = (int)$tenant["tenant_id"];

    $chk = $pdo->prepare("
        SELECT id
        FROM mobile_customers
        WHERE tenant_id = ? AND username = ?
        LIMIT 1
    ");
    $chk->execute([$tenantId, $username]);

    if ($chk->fetch()) {
        respond(409, [
            "success" => false,
            "message" => "Username already taken. Please choose another.",
        ]);
    }

    $chkEmail = $pdo->prepare("
        SELECT id
        FROM mobile_customers
        WHERE tenant_id = ? AND email = ?
        LIMIT 1
    ");
    $chkEmail->execute([$tenantId, $email]);

    if ($chkEmail->fetch()) {
        respond(409, [
            "success" => false,
            "message" => "Email already registered in this tenant.",
        ]);
    }

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $ins = $pdo->prepare("
        INSERT INTO mobile_customers
            (
                tenant_id,
                full_name,
                username,
                email,
                password,
                contact_number,
                birthdate,
                address,
                gender,
                nationality,
                created_at
            )
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $ins->execute([
        $tenantId,
        $fullName,
        $username,
        $email,
        $hashedPassword,
        $contact !== "" ? $contact : null,
        $birthdate !== "" ? $birthdate : null,
        $address !== "" ? $address : null,
        $gender !== "" ? $gender : null,
        $nationality,
    ]);

    $newId = (int)$pdo->lastInsertId();

    try {
        $audit = $pdo->prepare("
            INSERT INTO audit_logs
                (
                    tenant_id,
                    actor_user_id,
                    actor_username,
                    actor_role,
                    action,
                    entity_type,
                    entity_id,
                    message,
                    ip_address,
                    created_at
                )
            VALUES
                (?, ?, ?, 'mobile_customer', 'MOBILE_CUSTOMER_REGISTER', 'mobile_customer', ?, ?, ?, NOW())
        ");

        $audit->execute([
            $tenantId,
            $newId,
            $username,
            (string)$newId,
            "Mobile customer registered: " . $fullName,
            $_SERVER["REMOTE_ADDR"] ?? "::1",
        ]);
    } catch (Throwable $e) {
        // Audit log is optional.
    }

    respond(201, [
        "success" => true,
        "message" => "Registration successful! You can now log in.",
        "customer" => [
            "id" => $newId,
            "tenant_id" => $tenantId,
            "username" => $username,
            "fullname" => $fullName,
            "email" => $email,
            "tenant" => [
                "id" => $tenantId,
                "name" => $tenant["business_name"],
            ],
        ],
    ]);
} catch (Throwable $e) {
    respond(500, [
        "success" => false,
        "message" => "Registration failed.",
        "error" => $e->getMessage(),
    ]);
}
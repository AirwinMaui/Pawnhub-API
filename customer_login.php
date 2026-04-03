<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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

// ── Only accept POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

// ── Parse request body ────────────────────────────────────────────
$raw  = file_get_contents("php://input");
$data = [];

if (!empty($_POST)) {
    $data = $_POST;
} elseif ($raw !== false && trim($raw) !== '') {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $data = $json;
    } else {
        parse_str($raw, $data);
    }
}

// ── Required fields ───────────────────────────────────────────────
$tenantId   = isset($data['tenant_id']) && is_numeric($data['tenant_id']) ? (int)$data['tenant_id'] : null;
$fullName   = trim((string)($data['full_name']      ?? $data['fullname'] ?? ''));
$email      = trim((string)($data['email']          ?? ''));
$username   = trim((string)($data['username']       ?? ''));
$password   = trim((string)($data['password']       ?? ''));
$contact    = trim((string)($data['contact_number'] ?? $data['phone'] ?? ''));

// ── Optional fields ───────────────────────────────────────────────
$birthdate  = trim((string)($data['birthdate']      ?? ''));
$address    = trim((string)($data['address']        ?? ''));
$gender     = trim((string)($data['gender']         ?? ''));
$nationality = trim((string)($data['nationality']   ?? 'Filipino'));

// ── Validate required ─────────────────────────────────────────────
if (!$tenantId) {
    respond(400, ['success' => false, 'message' => 'tenant_id is required']);
}
if ($fullName === '') {
    respond(400, ['success' => false, 'message' => 'full_name is required']);
}
if ($username === '') {
    respond(400, ['success' => false, 'message' => 'username is required']);
}
if ($password === '') {
    respond(400, ['success' => false, 'message' => 'password is required']);
}
if (strlen($password) < 8) {
    respond(400, ['success' => false, 'message' => 'Password must be at least 8 characters']);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(400, ['success' => false, 'message' => 'Invalid email format']);
}

// ── Check tenant exists and is active ────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT id, business_name, status FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();

    if (!$tenant) {
        respond(404, ['success' => false, 'message' => 'Tenant not found']);
    }
    if ($tenant['status'] === 'inactive') {
        respond(403, ['success' => false, 'message' => 'This branch is currently inactive']);
    }
} catch (Throwable $e) {
    respond(500, ['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
}

// ── Check username uniqueness (within tenant) ─────────────────────
try {
    $chk = $pdo->prepare("
        SELECT id FROM mobile_customers
        WHERE tenant_id = ? AND username = ?
        LIMIT 1
    ");
    $chk->execute([$tenantId, $username]);
    if ($chk->fetch()) {
        respond(409, ['success' => false, 'message' => 'Username already taken. Please choose another.']);
    }

    // Also check email uniqueness if provided
    if ($email !== '') {
        $chkEmail = $pdo->prepare("
            SELECT id FROM mobile_customers
            WHERE tenant_id = ? AND email = ?
            LIMIT 1
        ");
        $chkEmail->execute([$tenantId, $email]);
        if ($chkEmail->fetch()) {
            respond(409, ['success' => false, 'message' => 'Email already registered.']);
        }
    }
} catch (Throwable $e) {
    // Table might not exist yet — try to create it
    if (str_contains($e->getMessage(), "doesn't exist") || str_contains($e->getMessage(), "Table")) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS mobile_customers (
                    id              INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id       INT NOT NULL,
                    full_name       VARCHAR(200) NOT NULL,
                    username        VARCHAR(100) NOT NULL,
                    email           VARCHAR(200) DEFAULT NULL,
                    password        VARCHAR(255) NOT NULL,
                    contact_number  VARCHAR(30)  DEFAULT NULL,
                    birthdate       DATE         DEFAULT NULL,
                    address         TEXT         DEFAULT NULL,
                    gender          VARCHAR(20)  DEFAULT NULL,
                    nationality     VARCHAR(50)  DEFAULT 'Filipino',
                    profile_photo   VARCHAR(500) DEFAULT NULL,
                    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
                    last_login_at   DATETIME     DEFAULT NULL,
                    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_tenant_username (tenant_id, username),
                    INDEX idx_tenant_email    (tenant_id, email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (Throwable $ce) {
            respond(500, ['success' => false, 'message' => 'Could not initialize customer table', 'error' => $ce->getMessage()]);
        }
    } else {
        respond(500, ['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
    }
}

// ── Insert new mobile customer ─────────────────────────────────────
try {
    $hashed = password_hash($password, PASSWORD_BCRYPT);

    $ins = $pdo->prepare("
        INSERT INTO mobile_customers
            (tenant_id, full_name, username, email, password, contact_number, birthdate, address, gender, nationality, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $ins->execute([
        $tenantId,
        $fullName,
        $username,
        $email ?: null,
        $hashed,
        $contact ?: null,
        ($birthdate !== '' ? $birthdate : null),
        $address ?: null,
        $gender ?: null,
        $nationality,
    ]);

    $newId = (int) $pdo->lastInsertId();

    // ── Audit log (best-effort) ───────────────────────────────────
    try {
        $pdo->prepare("
            INSERT INTO audit_logs
                (tenant_id, actor_user_id, actor_username, actor_role, action, entity_type, entity_id, message, ip_address, created_at)
            VALUES (?, ?, ?, 'mobile_customer', 'MOBILE_CUSTOMER_REGISTER', 'mobile_customer', ?, ?, ?, NOW())
        ")->execute([
            $tenantId,
            $newId,
            $username,
            (string)$newId,
            "Mobile customer registered: $fullName",
            $_SERVER['REMOTE_ADDR'] ?? '::1',
        ]);
    } catch (Throwable $e) {
        // Non-fatal
    }

    respond(201, [
        'success'     => true,
        'message'     => 'Registration successful! You can now log in.',
        'customer'    => [
            'id'       => $newId,
            'username' => $username,
            'fullname' => $fullName,
            'email'    => $email,
            'tenant'   => [
                'id'   => $tenantId,
                'name' => $tenant['business_name'],
            ],
        ],
    ]);
} catch (Throwable $e) {
    respond(500, [
        'success' => false,
        'message' => 'Registration failed',
        'error'   => $e->getMessage(),
    ]);
}

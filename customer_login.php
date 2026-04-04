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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Method not allowed']);
}

// Parse request body
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

$username = trim((string)($data['username'] ?? ''));
$password = trim((string)($data['password'] ?? ''));

if ($username === '' || $password === '') {
    respond(400, ['success' => false, 'message' => 'Username and password are required']);
}

try {
    // Find customer first and auto-detect tenant_id from the customer record
    $stmt = $pdo->prepare("
        SELECT *
        FROM mobile_customers
        WHERE username = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $customer = $stmt->fetch();

    if (!$customer) {
        respond(401, ['success' => false, 'message' => 'Invalid username or password']);
    }

    $tenantId = (int)$customer['tenant_id'];

    // Verify tenant
    $tStmt = $pdo->prepare("
        SELECT id, business_name, status
        FROM tenants
        WHERE id = ?
        LIMIT 1
    ");
    $tStmt->execute([$tenantId]);
    $tenant = $tStmt->fetch();

    if (!$tenant) {
        respond(404, ['success' => false, 'message' => 'Tenant not found']);
    }

    if (($tenant['status'] ?? '') === 'inactive') {
        respond(403, ['success' => false, 'message' => 'This branch is currently inactive']);
    }

    // Verify password
    if (!password_verify($password, $customer['password'])) {
        respond(401, ['success' => false, 'message' => 'Invalid username or password']);
    }

    // Update last login
    $pdo->prepare("
        UPDATE mobile_customers
        SET last_login_at = NOW()
        WHERE id = ?
    ")->execute([$customer['id']]);

    // Generate token
    $token = bin2hex(random_bytes(32));
    $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mobile_customer_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                tenant_id INT NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_token (token),
                INDEX idx_customer (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->prepare("
            INSERT INTO mobile_customer_tokens (customer_id, tenant_id, token, expires_at)
            VALUES (?, ?, ?, ?)
        ")->execute([
            $customer['id'],
            $tenantId,
            $token,
            $expiry
        ]);
    } catch (Throwable $e) {
        // Non-fatal: still allow login even if token table insert fails
        $token = null;
    }

    // Audit log
    try {
        $pdo->prepare("
            INSERT INTO audit_logs
                (tenant_id, actor_user_id, actor_username, actor_role, action, entity_type, entity_id, message, ip_address, created_at)
            VALUES (?, ?, ?, 'mobile_customer', 'MOBILE_CUSTOMER_LOGIN', 'mobile_customer', ?, ?, ?, NOW())
        ")->execute([
            $tenantId,
            $customer['id'],
            $username,
            (string)$customer['id'],
            "Mobile customer logged in: " . $customer['full_name'],
            $_SERVER['REMOTE_ADDR'] ?? '::1',
        ]);
    } catch (Throwable $e) {
        // Ignore audit log errors
    }

    respond(200, [
        'success' => true,
        'message' => 'Login successful',
        'token' => $token,
        'expires_at' => $expiry,
        'customer' => [
            'id' => (int)$customer['id'],
            'username' => $customer['username'],
            'fullname' => $customer['full_name'],
            'email' => $customer['email'] ?? '',
            'contact_number' => $customer['contact_number'] ?? '',
            'profile_photo' => $customer['profile_photo'] ?? null,
            'tenant' => [
                'id' => $tenantId,
                'name' => $tenant['business_name'],
            ],
        ],
    ]);
} catch (Throwable $e) {
    respond(500, [
        'success' => false,
        'message' => 'Login failed',
        'error' => $e->getMessage(),
    ]);
}
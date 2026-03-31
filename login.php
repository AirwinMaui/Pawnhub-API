<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit;
}
require __DIR__ . '/db.php';

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON body',
        ]);
        exit;
    }

    $username = strtolower(trim($data['username'] ?? ''));
    $password = trim($data['password'] ?? '');

    if ($username === '' || $password === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing credentials',
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.tenant_id,
            c.full_name,
            c.username,
            c.contact_number,
            c.email,
            c.is_active,
            c.password_hash,
            c.registered_at,
            t.business_name AS tenant_name,
            t.slug AS tenant_slug
        FROM customers c
        LEFT JOIN tenants t ON c.tenant_id = t.id
        WHERE c.username = :username
        LIMIT 1
    ");

    $stmt->execute([
        ':username' => $username,
    ]);

    $customer = $stmt->fetch();

    if (!$customer) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Customer not found',
        ]);
        exit;
    }

    if ((int)$customer['is_active'] !== 1) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Customer account is inactive',
        ]);
        exit;
    }

    if (empty($customer['password_hash'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Customer account has no password set',
        ]);
        exit;
    }

    if (!password_verify($password, $customer['password_hash'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Incorrect password',
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'customer' => [
            'id' => $customer['id'],
            'tenant_id' => $customer['tenant_id'],
            'full_name' => $customer['full_name'],
            'username' => $customer['username'],
            'contact_number' => $customer['contact_number'],
            'email' => $customer['email'],
            'registered_at' => $customer['registered_at'],
            'tenant_name' => $customer['tenant_name'],
            'tenant_slug' => $customer['tenant_slug'],
        ],
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage(),
    ]);
    exit;
}
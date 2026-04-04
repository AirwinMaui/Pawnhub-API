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
        'message' => 'Method not allowed'
    ]);
    exit;
}

require __DIR__ . '/../db.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $customerId = (int)($data['customer_id'] ?? 0);
    $tenantId = (int)($data['tenant_id'] ?? 0);

    if ($customerId <= 0 || $tenantId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing customer_id or tenant_id'
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
        ':customer_id' => $customerId,
        ':tenant_id' => $tenantId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Customer not found'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'customer' => [
            'id' => (int)$row['id'],
            'full_name' => $row['full_name'],
            'username' => $row['username'],
            'contact_number' => $row['contact_number'],
            'email' => $row['email'],
            'birthdate' => $row['birthdate'],
            'address' => $row['address'],
            'gender' => $row['gender'],
            'nationality' => $row['nationality'],
            'birthplace' => null,
            'registered_at' => $row['created_at'],
            'profile_photo' => $row['profile_photo'],
        ],
        'tenant' => [
            'id' => (int)$row['tenant_id'],
            'tenant_code' => $row['tenant_code'],
            'name' => $row['business_name'],
            'slug' => $row['slug'],
        ],
        'theme' => [
            'primary_color' => $row['primary_color'],
            'secondary_color' => $row['secondary_color'],
            'accent_color' => $row['accent_color'],
            'logo_text' => $row['logo_text'],
            'logo_url' => $row['logo_url'],
            'system_name' => $row['system_name'],
            'bg_image_url' => $row['bg_image_url'],
        ],
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage()
    ]);
    exit;
}
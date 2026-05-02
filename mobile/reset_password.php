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
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require __DIR__ . '/../db.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $username = trim($data['username'] ?? '');
    $resetCode = trim($data['reset_code'] ?? '');
    $newPassword = trim($data['new_password'] ?? '');
    $confirmPassword = trim($data['confirm_password'] ?? '');

    if ($username === '' || $resetCode === '' || $newPassword === '' || $confirmPassword === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
        exit;
    }

    if (strlen($newPassword) < 8) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, tenant_id
        FROM mobile_customers
        WHERE LOWER(TRIM(username)) = LOWER(TRIM(:username))
        LIMIT 1
    ");

    $stmt->execute([
        ':username' => $username,
    ]);

    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired reset code']);
        exit;
    }

    $resetStmt = $pdo->prepare("
        SELECT id, code_hash
        FROM customer_password_resets
        WHERE customer_id = :customer_id
          AND tenant_id = :tenant_id
          AND used = 0
          AND expires_at > NOW()
        ORDER BY created_at DESC
        LIMIT 1
    ");

    $resetStmt->execute([
        ':customer_id' => $customer['id'],
        ':tenant_id' => $customer['tenant_id'],
    ]);

    $reset = $resetStmt->fetch(PDO::FETCH_ASSOC);

    if (!$reset || !password_verify($resetCode, $reset['code_hash'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired reset code']);
        exit;
    }

    $pdo->beginTransaction();

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

    $updateStmt = $pdo->prepare("
        UPDATE mobile_customers
        SET password_hash = :password_hash
        WHERE id = :customer_id
          AND tenant_id = :tenant_id
    ");

    $updateStmt->execute([
        ':password_hash' => $newHash,
        ':customer_id' => $customer['id'],
        ':tenant_id' => $customer['tenant_id'],
    ]);

    $usedStmt = $pdo->prepare("
        UPDATE customer_password_resets
        SET used = 1
        WHERE id = :id
    ");

    $usedStmt->execute([
        ':id' => $reset['id'],
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Password reset successfully',
    ]);
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Mobile reset password error: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
    ]);
    exit;
}
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

    $customerStmt = $pdo->prepare("
        SELECT id, tenant_id
        FROM customers
        WHERE username = :username
        LIMIT 1
    ");

    $customerStmt->execute([
        ':username' => $username,
    ]);

    $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid reset code']);
        exit;
    }

    $codeStmt = $pdo->prepare("
        SELECT id, code_hash
        FROM password_reset_codes
        WHERE customer_id = :customer_id
          AND tenant_id = :tenant_id
          AND used_at IS NULL
          AND expires_at > UTC_TIMESTAMP()
        ORDER BY created_at DESC
        LIMIT 1
    ");

    $codeStmt->execute([
        ':customer_id' => $customer['id'],
        ':tenant_id' => $customer['tenant_id'],
    ]);

    $resetRow = $codeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$resetRow || !password_verify($resetCode, $resetRow['code_hash'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired reset code']);
        exit;
    }

    $pdo->beginTransaction();

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $updatePasswordStmt = $pdo->prepare("
        UPDATE customers
        SET password_hash = :password_hash
        WHERE id = :customer_id
          AND tenant_id = :tenant_id
    ");

    $updatePasswordStmt->execute([
        ':password_hash' => $newHash,
        ':customer_id' => $customer['id'],
        ':tenant_id' => $customer['tenant_id'],
    ]);

    $markUsedStmt = $pdo->prepare("
        UPDATE password_reset_codes
        SET used_at = UTC_TIMESTAMP()
        WHERE id = :id
    ");

    $markUsedStmt->execute([
        ':id' => $resetRow['id'],
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Password reset successfully',
    ]);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
    ]);
    exit;
}
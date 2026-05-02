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
require __DIR__ . '/../mailer.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $username = trim($data['username'] ?? '');

    if ($username === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Username is required']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT c.id, c.tenant_id, c.username, c.email, t.business_name
        FROM customers c
        LEFT JOIN tenants t ON c.tenant_id = t.id
        WHERE c.username = :username
        LIMIT 1
    ");

    $stmt->execute([
        ':username' => $username,
    ]);

    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($customer) {
    error_log('Forgot password customer found. ID: ' . $customer['id'] . ' Email: ' . ($customer['email'] ?? 'NO EMAIL'));
} else {
    error_log('Forgot password customer not found for username: ' . $username);
}

    if (!$customer) {
        echo json_encode([
            'success' => true,
            'message' => 'If the account exists, a reset code has been sent.',
        ]);
        exit;
    }

    if (empty($customer['email'])) {
        echo json_encode([
            'success' => true,
            'message' => 'If the account exists, a reset code has been sent.',
        ]);
        exit;
    }

    $resetCode = (string)random_int(100000, 999999);
    $codeHash = password_hash($resetCode, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    $pdo->prepare("
        UPDATE customer_password_resets
        SET used = 1
        WHERE customer_id = ?
          AND tenant_id = ?
          AND used = 0
    ")->execute([
        $customer['id'],
        $customer['tenant_id'],
    ]);

    $pdo->prepare("
        INSERT INTO customer_password_resets
            (customer_id, tenant_id, code_hash, expires_at, used, created_at)
        VALUES
            (?, ?, ?, ?, 0, NOW())
    ")->execute([
        $customer['id'],
        $customer['tenant_id'],
        $codeHash,
        $expiresAt,
    ]);

    $business = $customer['business_name'] ?? 'PawnHub';
    $customerName = $customer['username'] ?? 'Customer';

    $safeName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
    $safeBusiness = htmlspecialchars($business, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($resetCode, ENT_QUOTES, 'UTF-8');

    $html = '
<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;background:#f8fafc;margin:0;padding:30px;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
    <div style="background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:28px 32px;">
      <div style="font-size:1.3rem;font-weight:800;color:#fff;">Reset Your Password</div>
      <div style="font-size:.85rem;color:rgba(255,255,255,.7);margin-top:4px;">' . $safeBusiness . ' — PawnHub</div>
    </div>

    <div style="padding:28px 32px;">
      <p style="font-size:.95rem;color:#374151;margin-bottom:8px;">Hi <strong>' . $safeName . '</strong>,</p>

      <p style="font-size:.88rem;color:#6b7280;line-height:1.6;margin-bottom:20px;">
        We received a request to reset your Pawnhub customer account password.
        Enter the reset code below in the mobile app.
      </p>

      <div style="font-size:32px;font-weight:800;letter-spacing:6px;color:#1e3a8a;background:#eff6ff;border-radius:12px;padding:18px 24px;text-align:center;margin:22px 0;">
        ' . $safeCode . '
      </div>

      <p style="font-size:.78rem;color:#9ca3af;margin-top:20px;line-height:1.6;">
        This code expires in <strong>15 minutes</strong>.
        If you did not request this, you can safely ignore this email.
      </p>

      <hr style="border:none;border-top:1px solid #f1f5f9;margin:20px 0;">

      <p style="font-size:.75rem;color:#d1d5db;">PawnHub · ' . $safeBusiness . '</p>
    </div>
  </div>
</body>
</html>';

    
    error_log('Attempting to send reset email to: ' . $customer['email']);

    $sent = sendMail(
        $customer['email'],
        $customerName,
        'PawnHub — Password Reset Code',
        $html
    );
    error_log('Reset email send result: ' . ($sent ? 'success' : 'failed'));

    if (!$sent) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Could not send reset email. Please try again later.',
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'A reset code has been sent to your email.',
    ]);
    exit;

} catch (Throwable $e) {
    error_log('Mobile forgot password error: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
    ]);
    exit;
}
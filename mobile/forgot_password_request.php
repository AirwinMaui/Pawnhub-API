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

    if ($username === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Username is required']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, tenant_id, fullname, email
        FROM customers
        WHERE username = :username
        LIMIT 1
    ");

    $stmt->execute([
        ':username' => $username,
    ]);

    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    /*
      Generic success response prevents people from checking
      which usernames exist in your system.
    */
    if (!$customer) {
        echo json_encode([
            'success' => true,
            'message' => 'If the account exists, a reset code has been sent.',
        ]);
        exit;
    }

    $resetCode = (string)random_int(100000, 999999);
    $codeHash = password_hash($resetCode, PASSWORD_DEFAULT);

    $deleteOldStmt = $pdo->prepare("
        UPDATE password_reset_codes
        SET used_at = UTC_TIMESTAMP()
        WHERE customer_id = :customer_id
          AND tenant_id = :tenant_id
          AND used_at IS NULL
    ");

    $deleteOldStmt->execute([
        ':customer_id' => $customer['id'],
        ':tenant_id' => $customer['tenant_id'],
    ]);

    $insertStmt = $pdo->prepare("
        INSERT INTO password_reset_codes
            (customer_id, tenant_id, code_hash, expires_at)
        VALUES
            (:customer_id, :tenant_id, :code_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))
    ");

    $insertStmt->execute([
        ':customer_id' => $customer['id'],
        ':tenant_id' => $customer['tenant_id'],
        ':code_hash' => $codeHash,
    ]);

    /*
      Best option: send the reset code by email.
      This requires the customers table to have an email column.
      On Azure, PHP mail() may not work unless mail is configured.
      For production, use SMTP or SendGrid.
    */
    if (!empty($customer['email'])) {
        $subject = 'Your Pawnhub password reset code';
        $message = "Hello " . $customer['fullname'] . ",\n\n";
        $message .= "Your Pawnhub password reset code is: " . $resetCode . "\n\n";
        $message .= "This code expires in 15 minutes.\n\n";
        $message .= "If you did not request this, you can ignore this email.";

        @mail($customer['email'], $subject, $message);
    }

    $response = [
        'success' => true,
        'message' => 'If the account exists, a reset code has been sent.',
    ];

    /*
      For local testing only.
      Remove this before production, or set APP_ENV=production.
    */
    if (getenv('APP_ENV') !== 'production') {
        $response['debug_code'] = $resetCode;
    }

    echo json_encode($response);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
    ]);
    exit;
}
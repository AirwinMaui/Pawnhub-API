<?php
// paymongo_webhook.php

header('Content-Type: application/json');

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/paymongo_config.php';

$rawBody = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

if (!verifyPayMongoSignature($rawBody, $sigHeader, PAYMONGO_WEBHOOK_SECRET)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid signature.'
    ]);
    exit;
}

$event = json_decode($rawBody, true);

if (!$event) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid webhook payload.'
    ]);
    exit;
}

$eventType = $event['data']['attributes']['type'] ?? '';

if ($eventType === 'checkout_session.payment.paid') {
    $checkoutSession = $event['data']['attributes']['data'] ?? [];
    $sessionId = $checkoutSession['id'] ?? '';
    $metadata = $checkoutSession['attributes']['metadata'] ?? [];

    $tenantId = intval($metadata['tenant_id'] ?? 0);
    $customerId = intval($metadata['customer_id'] ?? 0);
    $ticketNo = trim($metadata['ticket_no'] ?? '');
    $paymentAmount = floatval($metadata['payment_amount'] ?? 0);

    if ($tenantId && $customerId && $ticketNo && $sessionId) {
        /*
          Save webhook event / update payment log.

          Adjust table names based on your database.
        */

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO payment_logs
                    (tenant_id, customer_id, ticket_no, session_id, amount, status, created_at)
                VALUES
                    (?, ?, ?, ?, ?, 'paid', NOW())
            ");
            $stmt->execute([
                $tenantId,
                $customerId,
                $ticketNo,
                $sessionId,
                $paymentAmount
            ]);

            /*
              Recommended:
              Move the payment logic from mobile_pay_loan.php into a shared function,
              then call it here.

              Example table names below are placeholders. Replace them with your real
              pawn transaction table and payment table names.

              $stmt = $pdo->prepare("
                  UPDATE pawn_transactions
                  SET status = 'Paid',
                      paid_at = NOW()
                  WHERE tenant_id = ?
                    AND customer_id = ?
                    AND ticket_no = ?
                    AND LOWER(status) != 'paid'
              ");
              $stmt->execute([$tenantId, $customerId, $ticketNo]);
            */

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Webhook database update failed.',
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }
}

http_response_code(200);
echo json_encode([
    'received' => true
]);
exit;

function verifyPayMongoSignature($body, $sigHeader, $secret) {
    if (!$body || !$sigHeader || !$secret) {
        return false;
    }

    $parts = [];

    foreach (explode(',', $sigHeader) as $item) {
        $pair = explode('=', trim($item), 2);

        if (count($pair) === 2) {
            $parts[$pair[0]] = $pair[1];
        }
    }

    $timestamp = $parts['t'] ?? '';
    $testSignature = $parts['te'] ?? '';
    $liveSignature = $parts['li'] ?? '';

    if (!$timestamp) {
        return false;
    }

    $signedPayload = $timestamp . '.' . $body;
    $computedSignature = hash_hmac('sha256', $signedPayload, $secret);

    $providedSignature = $testSignature ?: $liveSignature;

    if (!$providedSignature) {
        return false;
    }

    return hash_equals($computedSignature, $providedSignature);
}
<?php
// paymongo_mobile_checkout.php

header('Content-Type: application/json');

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/paymongo_config.php';

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON body.'
    ]);
    exit;
}

$tenantId = intval($input['tenant_id'] ?? 0);
$customerId = intval($input['customer_id'] ?? 0);
$ticketNo = trim($input['ticket_no'] ?? '');
$amount = floatval($input['payment_amount'] ?? 0);
$customerName = trim($input['customer_name'] ?? 'PawnHub Customer');

if (!$tenantId || !$customerId || !$ticketNo || $amount <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing tenant, customer, ticket, or payment amount.'
    ]);
    exit;
}

/*
  Recommended:
  Do not trust the amount from the app.

  Look up the loan from your database using:
  - tenant_id
  - customer_id
  - ticket_no

  Then use the database total_redeem amount instead of the mobile amount.

  Example only:

  $stmt = $pdo->prepare("
      SELECT total_redeem, status
      FROM pawn_transactions
      WHERE tenant_id = ? AND customer_id = ? AND ticket_no = ?
      LIMIT 1
  ");
  $stmt->execute([$tenantId, $customerId, $ticketNo]);
  $loan = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$loan) {
      http_response_code(404);
      echo json_encode([
          'success' => false,
          'message' => 'Loan not found.'
      ]);
      exit;
  }

  if (strtolower($loan['status']) === 'paid') {
      http_response_code(400);
      echo json_encode([
          'success' => false,
          'message' => 'This loan is already paid.'
      ]);
      exit;
  }

  $amount = floatval($loan['total_redeem']);
*/

$amountInCentavos = intval(round($amount * 100));

$successUrl =
    PAYMONGO_SUCCESS_URL .
    '?tenant_id=' . urlencode($tenantId) .
    '&customer_id=' . urlencode($customerId) .
    '&ticket_no=' . urlencode($ticketNo);

$cancelUrl =
    PAYMONGO_CANCEL_URL .
    '?tenant_id=' . urlencode($tenantId) .
    '&customer_id=' . urlencode($customerId) .
    '&ticket_no=' . urlencode($ticketNo);

$payload = [
    'data' => [
        'attributes' => [
            'send_email_receipt' => true,
            'show_description' => true,
            'show_line_items' => true,
            'description' => 'PawnHub loan payment for Ticket #' . $ticketNo,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'payment_method_types' => [
                'card',
                'gcash',
                'paymaya'
            ],
            'billing' => [
                'name' => $customerName
            ],
            'line_items' => [
                [
                    'currency' => 'PHP',
                    'amount' => $amountInCentavos,
                    'name' => 'PawnHub Loan Payment',
                    'description' => 'Payment for Ticket #' . $ticketNo,
                    'quantity' => 1
                ]
            ],
            'metadata' => [
                'tenant_id' => strval($tenantId),
                'customer_id' => strval($customerId),
                'ticket_no' => $ticketNo,
                'payment_amount' => strval($amount)
            ]
        ]
    ]
];

$ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':')
    ],
    CURLOPT_POSTFIELDS => json_encode($payload)
]);

$rawResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to connect to PayMongo.',
        'error' => $curlError
    ]);
    exit;
}

$response = json_decode($rawResponse, true);

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'message' => $response['errors'][0]['detail'] ?? 'PayMongo checkout creation failed.',
        'paymongo_response' => $response
    ]);
    exit;
}

$checkoutSessionId = $response['data']['id'] ?? '';
$checkoutUrl = $response['data']['attributes']['checkout_url'] ?? '';

if (!$checkoutSessionId || !$checkoutUrl) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'PayMongo did not return a checkout URL.',
        'paymongo_response' => $response
    ]);
    exit;
}

/*
  Optional but recommended:
  Save checkout session before returning.

  Adjust table and column names based on your database.

  $stmt = $pdo->prepare("
      INSERT INTO payment_logs
          (tenant_id, customer_id, ticket_no, session_id, amount, status, created_at)
      VALUES
          (?, ?, ?, ?, ?, 'pending', NOW())
  ");
  $stmt->execute([$tenantId, $customerId, $ticketNo, $checkoutSessionId, $amount]);
*/

echo json_encode([
    'success' => true,
    'checkout_session_id' => $checkoutSessionId,
    'checkout_url' => $checkoutUrl
]);
exit;
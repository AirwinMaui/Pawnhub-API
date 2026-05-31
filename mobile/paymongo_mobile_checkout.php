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

$tenantId      = intval($input['tenant_id']      ?? 0);
$customerId    = intval($input['customer_id']    ?? 0);
$ticketNo      = trim($input['ticket_no']        ?? '');
$amount        = floatval($input['payment_amount'] ?? 0);
$customerName  = trim($input['customer_name']    ?? 'PawnHub Customer');
$paymentMethod = trim($input['payment_method']   ?? 'paymongo');

$allowedMethods = ['paymongo', 'partial', 'extension'];
if (!in_array($paymentMethod, $allowedMethods, true)) {
    $paymentMethod = 'paymongo';
}

if (
    !$tenantId   ||
    !$customerId ||
    !$ticketNo   ||
    $amount <= 0
) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing tenant, customer, ticket, or payment amount.'
    ]);
    exit;
}

/*
  Recommended: Look up the loan from your DB and use total_redeem (or
  interest_amount for extensions) instead of trusting the mobile amount.

  $stmt = $pdo->prepare("
      SELECT total_redeem, interest_amount, status
      FROM pawn_transactions
      WHERE tenant_id = ? AND customer_id = ? AND ticket_no = ?
      LIMIT 1
  ");
  $stmt->execute([$tenantId, $customerId, $ticketNo]);
  $loan = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$loan) { ... 404 ... }
  if (strtolower($loan['status']) === 'paid') { ... 400 ... }

  if ($paymentMethod === 'extension') {
      $amount = floatval($loan['interest_amount']);
  } else {
      $amount = floatval($loan['total_redeem']);
  }
*/

// Build a human-readable line-item label per payment type.
$lineItemLabels = [
    'paymongo'  => ['name' => 'PawnHub Loan Payment',      'desc' => 'Full payment for Ticket #' . $ticketNo],
    'partial'   => ['name' => 'PawnHub Installment Payment','desc' => 'Partial payment for Ticket #' . $ticketNo],
    'extension' => ['name' => 'PawnHub Loan Extension',    'desc' => 'Interest payment to extend Ticket #' . $ticketNo . ' by 30 days'],
];
$label = $lineItemLabels[$paymentMethod] ?? $lineItemLabels['paymongo'];

$amountInCentavos = intval(round($amount * 100));

$successUrl =
    PAYMONGO_SUCCESS_URL .
    '?tenant_id='   . urlencode((string)$tenantId) .
    '&customer_id=' . urlencode((string)$customerId) .
    '&ticket_no='   . urlencode($ticketNo);

$cancelUrl =
    PAYMONGO_CANCEL_URL .
    '?tenant_id='   . urlencode((string)$tenantId) .
    '&customer_id=' . urlencode((string)$customerId) .
    '&ticket_no='   . urlencode($ticketNo);

$payload = [
    'data' => [
        'attributes' => [
            'send_email_receipt'   => true,
            'show_description'     => true,
            'show_line_items'      => true,
            'description'          => $label['desc'],
            'success_url'          => $successUrl,
            'cancel_url'           => $cancelUrl,
            'payment_method_types' => ['card', 'gcash', 'paymaya'],
            'billing' => [
                'name' => $customerName,
            ],
            'line_items' => [
                [
                    'currency'    => 'PHP',
                    'amount'      => $amountInCentavos,
                    'name'        => $label['name'],
                    'description' => $label['desc'],
                    'quantity'    => 1,
                ],
            ],
            'metadata' => [
                'tenant_id'      => strval($tenantId),
                'customer_id'    => strval($customerId),
                'ticket_no'      => $ticketNo,
                'payment_amount' => strval($amount),
                // ✅ This is the critical addition — the webhook reads this
                // to decide whether to mark paid, subtract balance, or extend.
                'payment_method' => $paymentMethod,
            ],
        ],
    ],
];

$ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
]);

$rawResponse = curl_exec($ch);
$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError   = curl_error($ch);

curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to connect to PayMongo.',
        'error'   => $curlError,
    ]);
    exit;
}

$response = json_decode($rawResponse, true);

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code($httpCode);
    echo json_encode([
        'success'           => false,
        'message'           => $response['errors'][0]['detail'] ?? 'PayMongo checkout creation failed.',
        'paymongo_response' => $response,
    ]);
    exit;
}

$checkoutSessionId = $response['data']['id']                         ?? '';
$checkoutUrl       = $response['data']['attributes']['checkout_url'] ?? '';

if (!$checkoutSessionId || !$checkoutUrl) {
    http_response_code(500);
    echo json_encode([
        'success'           => false,
        'message'           => 'PayMongo did not return a checkout URL.',
        'paymongo_response' => $response,
    ]);
    exit;
}

/*
  Optional: save the pending session so you can reconcile later.

  $stmt = $pdo->prepare("
      INSERT INTO payment_logs
          (tenant_id, customer_id, ticket_no, session_id, amount, status, created_at)
      VALUES (?, ?, ?, ?, ?, 'pending', NOW())
  ");
  $stmt->execute([$tenantId, $customerId, $ticketNo, $checkoutSessionId, $amount]);
*/

echo json_encode([
    'success'             => true,
    'checkout_session_id' => $checkoutSessionId,
    'checkout_url'        => $checkoutUrl,
]);
exit;
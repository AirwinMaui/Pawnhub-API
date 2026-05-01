<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/paymongo_config.php';

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed',
    ]);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON body.',
    ]);
}

$tenantId = intval($input['tenant_id'] ?? 0);
$customerId = intval($input['customer_id'] ?? 0);
$customerName = trim((string)($input['customer_name'] ?? 'PawnHub Customer'));

$productId = trim((string)($input['product_id'] ?? ''));
$productName = trim((string)($input['product_name'] ?? 'Shop Item'));
$productCategory = trim((string)($input['product_category'] ?? 'Shop'));
$productImage = trim((string)($input['product_image'] ?? ''));

$amount = floatval($input['payment_amount'] ?? 0);
$quantity = intval($input['quantity'] ?? 1);

$streetAddress = trim((string)($input['street_address'] ?? ''));
$city = trim((string)($input['city'] ?? ''));
$postalCode = trim((string)($input['postal_code'] ?? ''));

if ($tenantId <= 0 || $customerId <= 0 || $productId === '' || $amount <= 0 || $quantity <= 0) {
    respond(400, [
        'success' => false,
        'message' => 'Missing tenant, customer, product, amount, or quantity.',
    ]);
}

/*
  Recommended:
  Do not trust product price from the app.

  Send me your product/shop tables and I can change this to:
  1. Look up product by product_id and tenant_id
  2. Use the database price
  3. Check if item is still available
  4. Create a pending order before PayMongo checkout
*/

$amountInCentavos = intval(round($amount * 100));

$successUrl =
    PAYMONGO_SUCCESS_URL .
    '?type=shop' .
    '&tenant_id=' . urlencode((string)$tenantId) .
    '&customer_id=' . urlencode((string)$customerId) .
    '&product_id=' . urlencode($productId);

$cancelUrl =
    PAYMONGO_CANCEL_URL .
    '&type=shop' .
    '&tenant_id=' . urlencode((string)$tenantId) .
    '&customer_id=' . urlencode((string)$customerId) .
    '&product_id=' . urlencode($productId);

$orderId = '';

/*
  Optional:
  Insert a pending shop order here after you send your order table schema.

  Example:
  $orderId = 'SHOP-' . date('YmdHis') . '-' . $customerId;
*/

$payload = [
    'data' => [
        'attributes' => [
            'send_email_receipt' => true,
            'show_description' => true,
            'show_line_items' => true,
            'description' => 'PawnHub shop purchase: ' . $productName,
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
                    'name' => $productName,
                    'description' => $productCategory,
                    'quantity' => $quantity
                ]
            ],
            'metadata' => [
                'payment_type' => 'shop',
                'tenant_id' => strval($tenantId),
                'customer_id' => strval($customerId),
                'customer_name' => $customerName,
                'product_id' => $productId,
                'product_name' => $productName,
                'product_category' => $productCategory,
                'product_image' => $productImage,
                'payment_amount' => strval($amount),
                'quantity' => strval($quantity),
                'street_address' => $streetAddress,
                'city' => $city,
                'postal_code' => $postalCode,
                'order_id' => $orderId
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
    respond(500, [
        'success' => false,
        'message' => 'Unable to connect to PayMongo.',
        'error' => $curlError,
    ]);
}

$response = json_decode($rawResponse, true);

if ($httpCode < 200 || $httpCode >= 300) {
    respond($httpCode, [
        'success' => false,
        'message' => $response['errors'][0]['detail'] ?? 'PayMongo checkout creation failed.',
        'paymongo_response' => $response,
    ]);
}

$checkoutSessionId = $response['data']['id'] ?? '';
$checkoutUrl = $response['data']['attributes']['checkout_url'] ?? '';

if (!$checkoutSessionId || !$checkoutUrl) {
    respond(500, [
        'success' => false,
        'message' => 'PayMongo did not return a checkout URL.',
        'paymongo_response' => $response,
    ]);
}

respond(200, [
    'success' => true,
    'checkout_session_id' => $checkoutSessionId,
    'checkout_url' => $checkoutUrl,
    'order_id' => $orderId,
]);
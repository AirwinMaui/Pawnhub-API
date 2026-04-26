<?php
header('Content-Type: application/json');

$uri = $_SERVER['REQUEST_URI'] ?? '';

if (strpos($uri, '/index.php/mobile/mobile_pawn_transactions.php') !== false) {
    require __DIR__ . '/mobile/mobile_pawn_transactions.php';
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'API is live'
]);
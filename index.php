<?php
header('Content-Type: application/json');

$path = $_SERVER['PATH_INFO'] ?? '';

if ($path === '/mobile/mobile_pawn_transactions.php') {
    require __DIR__ . '/mobile/mobile_pawn_transactions.php';
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'API is live'
]);
<?php

$action = $_GET['action'] ?? '';

if ($action === 'mobile_pawn_transactions') {
    require __DIR__ . '/mobile/mobile_pawn_transactions.php';
    exit;
}

echo 'API is live';
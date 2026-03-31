<?php
require __DIR__ . '/../db.php';

$stmt = $pdo->query("SELECT 1");
echo "DB connected!";
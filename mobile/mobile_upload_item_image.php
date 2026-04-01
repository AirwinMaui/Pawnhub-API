<?php
header("Content-Type: application/json");

if (!isset($_FILES["image"])) {
    echo json_encode([
        "success" => false,
        "message" => "No image uploaded."
    ]);
    exit;
}

$uploadDir = __DIR__ . "/../uploads/items/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$file = $_FILES["image"];
$originalName = basename($file["name"]);
$extension = pathinfo($originalName, PATHINFO_EXTENSION);
$newFileName = uniqid("item_", true) . "." . $extension;
$targetPath = $uploadDir . $newFileName;

if (move_uploaded_file($file["tmp_name"], $targetPath)) {
    $relativePath = "uploads/items/" . $newFileName;

    echo json_encode([
        "success" => true,
        "message" => "Image uploaded successfully.",
        "path" => $relativePath,
        "url" => "https://pawnhub-api-hqfkfxdaddhnfthf.southeastasia-01.azurewebsites.net/" . $relativePath
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Failed to upload image."
    ]);
}
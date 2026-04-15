<?php
// FILE: API/api_check_reference.php

error_reporting(E_ALL);
ini_set("display_errors", 0);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

include "connection.php";

$ref = isset($_GET["ref"]) ? trim($_GET["ref"]) : "";

// 🟢 ROBUST CLEANING: Strip all non-digits (Handles spaces, labels, split lines)
$ref = preg_replace('/\D/', '', $ref);

if (empty($ref)) {
    echo json_encode(["success" => false, "message" => "Reference number is required"]);
    exit();
}

// GCash Reference numbers are always 13 digits
if (strlen($ref) !== 13) {
    echo json_encode(["success" => false, "message" => "Invalid reference format. Must be 13 digits."]);
    exit();
}

$isDuplicate = false;

// Check Orders
$stmt1 = $conn->prepare("SELECT id FROM orders WHERE payment_reference = ? LIMIT 1");
$stmt1->bind_param("s", $ref);
$stmt1->execute();
if ($stmt1->get_result()->num_rows > 0) {
    $isDuplicate = true;
}
$stmt1->close();

if (!$isDuplicate) {
    // Check Bookings
    $stmt2 = $conn->prepare("SELECT id FROM bookings WHERE payment_reference = ? LIMIT 1");
    $stmt2->bind_param("s", $ref);
    $stmt2->execute();
    if ($stmt2->get_result()->num_rows > 0) {
        $isDuplicate = true;
    }
    $stmt2->close();
}

echo json_encode([
    "success" => true,
    "is_duplicate" => $isDuplicate,
    "message" => $isDuplicate ? "Reference number already used" : "Reference is unique"
]);

$conn->close();
?>
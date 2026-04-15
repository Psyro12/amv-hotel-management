<?php
// FILE: API/api_mark_order_read.php

// 🟢 SECURITY: Disable error display to prevent leakage
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 🟢 SECURITY: Restrict to POST requests only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

require 'connection.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// 1. Capture Input safely
$input = json_decode(file_get_contents("php://input"), true);
// Support both JSON body and standard POST parameters
$order_id = isset($input['order_id']) ? (int)$input['order_id'] : (isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0);

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Order ID']);
    exit();
}

// 2. Update Database (Prepared Statement)
$stmt = $conn->prepare("UPDATE orders SET is_read_by_user = 1 WHERE id = ?");

if ($stmt) {
    $stmt->bind_param("i", $order_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        // Log error internally
        error_log("Execute Error (Mark Order Read): " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Database update failed']);
    }
    $stmt->close();
} else {
    // Log error internally
    error_log("Prepare Error (Mark Order Read): " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'System error']);
}

$conn->close();
?>
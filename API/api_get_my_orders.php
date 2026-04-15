<?php
// FILE: API/api_get_my_orders.php

// 🟢 SECURITY: Disable error display to prevent path leakage
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

// 1. Capture Input (JSON first, then POST fallback)
$input = json_decode(file_get_contents("php://input"), true);
$uid = isset($input['uid']) ? trim($input['uid']) : (isset($_POST['uid']) ? trim($_POST['uid']) : '');

if (empty($uid)) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit();
}

$real_user_id = null;

// 2. RESOLVE USER ID (Prepared Statement)
// Try Firebase UID first
$stmt = $conn->prepare("SELECT id FROM users WHERE firebase_uid = ? LIMIT 1");
if (!$stmt) {
    error_log("Prepare Failed (UID Lookup): " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'System error']);
    exit();
}
$stmt->bind_param("s", $uid);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    $real_user_id = $row['id'];
} else {
    // Fallback: Try Standard ID
    $stmt2 = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
    if ($stmt2) {
        $stmt2->bind_param("s", $uid);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        if ($row2 = $res2->fetch_assoc()) {
            $real_user_id = $row2['id'];
        }
        $stmt2->close();
    }
}
$stmt->close();

if (empty($real_user_id)) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit();
}

// 3. FETCH ORDERS
$sql = "SELECT 
            id, 
            items, 
            total_price, 
            room_number, 
            payment_method, 
            notes, 
            order_date,        -- Used for 'New' calculation
            status,
            is_read_by_user    -- Used for hiding banner
        FROM orders 
        WHERE user_id = ? 
        ORDER BY order_date DESC";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("i", $real_user_id);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $orders = [];
        
        while ($row = $result->fetch_assoc()) {
            // Ensure numeric types for Flutter
            $row['id'] = (int)$row['id'];
            $row['total_price'] = (float)$row['total_price'];
            $orders[] = $row;
        }

        echo json_encode(['success' => true, 'data' => $orders]);
    } else {
        error_log("Execute Failed (Fetch Orders): " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Error retrieving orders']);
    }
    $stmt->close();
} else {
    error_log("Prepare Failed (Fetch Orders): " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'System error']);
}

$conn->close();
?>
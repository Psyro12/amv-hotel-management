<?php
// FILE: API/api_get_last_guest_info.php

// 🟢 SECURITY: Disable error display to prevent path leakage
error_reporting(E_ALL);
ini_set('display_errors', 0); 

// 🟢 SECURITY: Restrict to POST requests only
// This endpoint handles specific user data lookup, so POST is best practice.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

require 'connection.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// 1. Safe Input Handling
$input = json_decode(file_get_contents("php://input"), true);
// Prioritize JSON input, fallback to POST for flexibility
$uid = $input['uid'] ?? $_POST['uid'] ?? '';

// Basic Sanitization
$uid = trim(strip_tags($uid));

if (empty($uid)) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit();
}

// 2. Resolve User ID (Prepared Statement)
$stmt = $conn->prepare("SELECT id FROM users WHERE firebase_uid = ? LIMIT 1");

if (!$stmt) {
    error_log("Prepare Failed (User Lookup): " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'System error']);
    exit();
}

$stmt->bind_param("s", $uid);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    $user_id = $row['id'];
} else {
    // 🟢 SECURITY: Return success=false but don't crash
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit();
}
$stmt->close();

// 3. Fetch Latest Guest Info (Prepared Statement)
// We join 'booking_guests' with 'bookings' to find the most recent entry for this user
$sql = "SELECT bg.first_name, bg.last_name, bg.email, bg.phone, 
               bg.address, bg.nationality, bg.gender, bg.birthdate 
        FROM booking_guests bg
        JOIN bookings b ON bg.booking_id = b.id
        WHERE b.user_id = ? 
        ORDER BY b.created_at DESC 
        LIMIT 1";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            echo json_encode(['success' => true, 'data' => $row]);
        } else {
            // User exists but has no previous bookings
            echo json_encode(['success' => false, 'message' => 'No previous records']);
        }
    } else {
        error_log("Execute Failed (Guest Info): " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    $stmt->close();
} else {
    error_log("Prepare Failed (Guest Info): " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'System error']);
}

$conn->close();
?>
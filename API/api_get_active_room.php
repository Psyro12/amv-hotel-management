<?php
// FILE: API/api_get_active_room.php

// 🟢 SECURITY: Hide errors in production
error_reporting(E_ALL);
ini_set('display_errors', 0); 

// 🟢 SECURITY: Restrict Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

require 'connection.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Get Input
$input = json_decode(file_get_contents("php://input"), true);
$input_id = $input['user_id'] ?? $input['uid'] ?? '';

if (empty($input_id)) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit();
}

$real_user_id = null;

// 1. SMART LOOKUP (Find Real MySQL ID)
$stmt = $conn->prepare("SELECT id FROM users WHERE firebase_uid = ? OR id = ? LIMIT 1");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'System error']);
    exit();
}
$stmt->bind_param("ss", $input_id, $input_id);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    $real_user_id = $row['id'];
}
$stmt->close();

if (empty($real_user_id)) {
    echo json_encode(['success' => false, 'message' => 'User not found in database']);
    exit();
}

// 🟢 2. STRICT ROOM CHECK (Updated Statuses)
// We check for 'in_house' (lowercase) because that matches your dashboard logic.
// We kept 'In House' just in case you have legacy data.
$sql = "SELECT 
            b.id as booking_id,
            b.booking_reference,
            b.arrival_status,
            GROUP_CONCAT(br.room_name SEPARATOR ', ') as room_name
        FROM bookings b
        JOIN booking_rooms br ON b.id = br.booking_id
        WHERE b.user_id = ? 
        AND (
            b.arrival_status = 'in_house'  /* Standard Status */
            OR b.arrival_status = 'In House' 
            OR b.status = 'checked_in'
        )
        GROUP BY b.id
        LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'System error']);
    exit();
}

$stmt->bind_param("i", $real_user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    // Found a guest physically in the hotel
    echo json_encode([
        'success' => true,
        'found' => true,
        'data' => [
            'room_name' => $row['room_name'],
            'booking_id' => $row['booking_id'],
            'status' => 'in_house'
        ]
    ]);
} else {
    // User exists but has no active room
    echo json_encode([
        'success' => true, 
        'found' => false,  
        'message' => 'User is not currently marked as In House.'
    ]);
}

$stmt->close();
$conn->close();
?>
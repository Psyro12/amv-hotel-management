<?php
// FILE: API/api_get_my_bookings.php

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

// 1. Safe Input Handling
$input = json_decode(file_get_contents("php://input"), true);

$input_id = isset($input['user_id']) ? trim($input['user_id']) : (isset($input['uid']) ? trim($input['uid']) : '');
$email    = isset($input['email']) ? trim($input['email']) : '';
$type     = isset($input['type']) ? trim($input['type']) : 'active';

if (empty($input_id) && empty($email)) {
    echo json_encode(['success' => false, 'message' => 'User ID or Email is required']);
    exit();
}

$real_user_id = null;

// 🟢 2. SMART IDENTIFICATION LOGIC
// Check if input_id is a Firebase UID OR a MySQL ID
if (!empty($input_id)) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE firebase_uid = ? OR id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("ss", $input_id, $input_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $real_user_id = $row['id'];
        }
        $stmt->close();
    }
}

// Fallback: Try Email if ID failed
if (empty($real_user_id) && !empty($email)) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $real_user_id = $row['id'];
        }
        $stmt->close();
    }
}

// If user still not found
if (empty($real_user_id)) {
    echo json_encode(['success' => false, 'message' => 'User not found in database']);
    exit();
}

// 🟢 3. RETRIEVE BOOKINGS
// Using Prepared Statements for the main query
$sql = "SELECT 
            b.id, 
            b.booking_reference, 
            b.check_in, 
            b.check_out, 
            b.total_price, 
            b.status, 
            b.arrival_status,
            b.created_at,
            b.is_read_by_user,
            GROUP_CONCAT(br.room_name SEPARATOR ', ') as room_name,
            GROUP_CONCAT(br.room_name SEPARATOR ', ') as room_names
        FROM bookings b
        LEFT JOIN booking_rooms br ON b.id = br.booking_id
        WHERE b.user_id = ? ";

// Filter Logic
if ($type === 'active') {
    // Show current stays OR future bookings
    $sql .= " AND (
                (b.check_out >= CURDATE() AND b.status != 'cancelled')
                OR 
                b.arrival_status = 'in_house'
              ) ";
} else {
    // History
    $sql .= " AND (
                (b.check_out < CURDATE() AND b.arrival_status != 'in_house')
                OR 
                b.status IN ('cancelled', 'no-show', 'completed') 
                OR 
                b.arrival_status = 'Checked Out'
              ) ";
}

$sql .= " GROUP BY b.id ORDER BY b.check_in DESC";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("i", $real_user_id);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $bookings = [];
        
        while ($row = $result->fetch_assoc()) {
            // Ensure numeric values are actually numbers for Flutter
            $row['id'] = (int)$row['id'];
            $row['total_price'] = (float)$row['total_price'];
            $bookings[] = $row;
        }

        echo json_encode(['success' => true, 'data' => $bookings]);
    } else {
        error_log("Execute Error: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Error retrieving bookings']);
    }
    $stmt->close();
} else {
    error_log("Prepare Error: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'System error']);
}

$conn->close();
?>
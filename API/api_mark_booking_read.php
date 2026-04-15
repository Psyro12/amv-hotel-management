<?php
// FILE: API/api_mark_booking_read.php

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
$booking_id = isset($input['booking_id']) ? (int)$input['booking_id'] : 0;

if ($booking_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Booking ID']);
    exit();
}

// 2. Update Database (Prepared Statement)
$stmt = $conn->prepare("UPDATE bookings SET is_read_by_user = 1 WHERE id = ?");

if ($stmt) {
    $stmt->bind_param("i", $booking_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        // Log error internally
        error_log("Execute Error (Mark Booking Read): " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Database update failed']);
    }
    $stmt->close();
} else {
    // Log error internally
    error_log("Prepare Error (Mark Booking Read): " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'System error']);
}

$conn->close();
?>
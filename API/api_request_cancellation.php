<?php
// FILE: AMV_Project_exp/API/api_request_cancellation.php

error_reporting(E_ALL); 
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Asia/Manila');

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

require_once 'config.php';
require_once 'connection.php'; // Using connection.php which is standard in this API folder

// Get POST data
$data = json_decode(file_get_contents("php://input"), true);

$booking_id = isset($data['booking_id']) ? intval($data['booking_id']) : 0;
$reason = isset($data['reason']) ? trim($data['reason']) : '';

if (!$booking_id || empty($reason)) {
    echo json_encode(['success' => false, 'message' => 'Booking ID and reason are required.']);
    exit;
}

try {
    // 1. Check if booking exists and is not already cancelled
    $check_sql = "SELECT status, arrival_status, cancel_requested FROM bookings WHERE id = ?";
    $stmt = $conn->prepare($check_sql);
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit;
    }

    $booking = $result->fetch_assoc();

    if ($booking['status'] === 'cancelled' || $booking['arrival_status'] === 'cancelled') {
        echo json_encode(['success' => false, 'message' => 'Booking is already cancelled.']);
        exit;
    }

    if ($booking['cancel_requested'] == 1) {
        echo json_encode(['success' => false, 'message' => 'A cancellation request is already pending for this booking.']);
        exit;
    }

    // 2. Insert into cancellation_requests
    $insert_sql = "INSERT INTO cancellation_requests (booking_id, reason, status) VALUES (?, ?, 'pending')";
    $stmt_insert = $conn->prepare($insert_sql);
    if (!$stmt_insert) throw new Exception("Prepare insert failed: " . $conn->error);
    
    $stmt_insert->bind_param("is", $booking_id, $reason);

    if ($stmt_insert->execute()) {
        // 3. Update bookings table flag
        $update_sql = "UPDATE bookings SET cancel_requested = 1 WHERE id = ?";
        $stmt_update = $conn->prepare($update_sql);
        if ($stmt_update) {
            $stmt_update->bind_param("i", $booking_id);
            $stmt_update->execute();
            $stmt_update->close();
        }

        // 4. Trigger SSE Update for Admin
        $conn->query("INSERT INTO system_updates (category) VALUES ('cancellation_requests') 
                      ON DUPLICATE KEY UPDATE last_updated = CURRENT_TIMESTAMP");

        echo json_encode(['success' => true, 'message' => 'Cancellation request submitted successfully.']);
    } else {
        throw new Exception("Execute insert failed: " . $stmt_insert->error);
    }
} catch (Exception $e) {
    error_log("Cancellation Request Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
} finally {
    if (isset($stmt)) $stmt->close();
    if (isset($stmt_insert)) $stmt_insert->close();
    if (isset($conn)) $conn->close();
}
?>

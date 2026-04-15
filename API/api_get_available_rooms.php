<?php
// FILE: API/api_get_available_rooms.php

// 🟢 SECURITY: Disable error display to prevent path leakage
// This stops PHP from printing HTML errors that crash the Flutter JSON parser.
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 🟢 SECURITY: Restrict to GET requests only
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

// 1. Allow Mobile Access (CORS)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
date_default_timezone_set('Asia/Manila');

// 2. Connect to Database & Config
require 'connection.php'; 
require 'config.php'; 

// 3. Get Parameters (Check-in, Check-out)
$checkin = isset($_GET['checkin']) ? trim($_GET['checkin']) : '';
$checkout = isset($_GET['checkout']) ? trim($_GET['checkout']) : '';

// 🟢 SECURITY: Basic Date Validation
// If empty, return error immediately
if (!$checkin || !$checkout) {
    echo json_encode(["success" => false, "message" => "Dates are required"]);
    exit();
}

// 🟢 SECURITY: Validate Date Format (YYYY-MM-DD) to prevent SQL Injection
function isValidDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

if (!isValidDate($checkin) || !isValidDate($checkout)) {
    echo json_encode(["success" => false, "message" => "Invalid date format. Use YYYY-MM-DD."]);
    exit();
}

$available_rooms = [];

// 5. Select Active Rooms NOT in Booked List
$query = "
        SELECT * FROM rooms 
        WHERE is_active = 1 
        AND id NOT IN (
            SELECT br.room_id 
            FROM booking_rooms br
            JOIN bookings b ON br.booking_id = b.id
            WHERE b.status IN ('confirmed', 'pending')
            AND b.arrival_status != 'checked_out' 
            AND (
                ? < b.check_out AND ? > b.check_in
            )
        )
        ORDER BY name ASC
    ";
$stmt = $conn->prepare($query);

if ($stmt) {
    $stmt->bind_param("ss", $checkin, $checkout);

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            
            // 6. Handle Multiple Images (CSV logic)
            $rawPath = $row['image_path']; 
            
            if (strpos($rawPath, ',') !== false) {
                $parts = explode(',', $rawPath);
                $rawPath = trim($parts[0]); // Take the first image
            }
            
            // 🟢 2. Use Path from Config
            if (!empty($rawPath)) {
                $row['full_image_url'] = $ROOM_IMAGE_PATH . $rawPath;
            } else {
                $row['full_image_url'] = $DEFAULT_IMAGE; 
            }
            
            // Ensure numbers are numbers (for calculation)
            $row['price'] = (float)$row['price'];
            
            $available_rooms[] = $row;
        }

        echo json_encode([
            "success" => true,
            "data" => $available_rooms
        ]);
    } else {
        // 🟢 SECURITY: Don't show raw SQL errors
        error_log("Database Execute Error: " . $stmt->error);
        echo json_encode(["success" => false, "message" => "System error. Please try again later."]);
    }
    $stmt->close();
} else {
    // 🟢 SECURITY: Don't show raw SQL errors
    error_log("Database Prepare Error: " . $conn->error);
    echo json_encode(["success" => false, "message" => "System error. Please try again later."]);
}

$conn->close();
?>
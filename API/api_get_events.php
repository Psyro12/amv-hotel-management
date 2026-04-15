<?php
// FILE: API/api_get_events.php

// 🟢 SECURITY: Disable error display to prevent path leakage & JSON errors
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 🟢 SECURITY: Restrict to GET requests only
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
date_default_timezone_set('Asia/Manila');

require 'connection.php'; 
require 'config.php'; 

$events = [];

// 🟢 LOGIC: Only fetch Active events
// This ensures events you archived in the dashboard don't show up in the app.
$query = "SELECT * FROM hotel_events WHERE is_active = 1 ORDER BY event_date DESC";

$result = $conn->query($query);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        
        // Handle Image URL using Config
        if (!empty($row['image_path'])) {
            $row['full_image_url'] = $EVENT_IMAGE_PATH . $row['image_path'];
        } else {
            $row['full_image_url'] = $DEFAULT_IMAGE;
        }

        // Format Date
        $dateObj = new DateTime($row['event_date']);
        $row['formatted_date'] = $dateObj->format('M d, Y'); 

        // Clean Description
        $row['description'] = strip_tags($row['description']);

        $events[] = $row;
    }
    
    echo json_encode([
        "success" => true,
        "data" => $events
    ]);
} else {
    // 🟢 SECURITY: Log the actual error, but show a safe message to the app
    error_log("Database Error: " . $conn->error);
    echo json_encode([
        "success" => false, 
        "message" => "Unable to fetch events."
    ]);
}

$conn->close();
?>
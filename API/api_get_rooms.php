<?php
// FILE: API/api_get_rooms.php

// 🟢 SECURITY: Disable error display to prevent path leakage
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 🟢 SECURITY: Restrict to GET requests only
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
date_default_timezone_set('Asia/Manila');

require 'connection.php'; 
require 'config.php'; 

// 🟢 1. FETCH ALL AMENITIES FIRST (The Dictionary)
$amenityMap = [];
$amQuery = "SELECT id, title FROM amenities";
$amResult = mysqli_query($conn, $amQuery);

if ($amResult) {
    while ($row = mysqli_fetch_assoc($amResult)) {
        // Map ID to Title (e.g., 1 => "Free Wi-Fi")
        $amenityMap[$row['id']] = $row['title'];
    }
} else {
    // Log error but don't stop execution; rooms can still load without amenities
    error_log("Amenity Fetch Error: " . mysqli_error($conn));
}

// 🟢 2. SELECT ACTIVE ROOMS
$query = "SELECT id, name, description, price, image_path, bed_type, amenities FROM rooms WHERE is_active = 1 ORDER BY name ASC";
$result = mysqli_query($conn, $query);

$rooms_list = [];

if ($result) {
    if (mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            
            // --- Image Logic ---
            $rawPath = $row['image_path'];
            $all_image_urls = [];

            if (!empty($rawPath)) {
                if (strpos($rawPath, ',') !== false) {
                    $parts = explode(',', $rawPath);
                    foreach ($parts as $part) {
                        $all_image_urls[] = $ROOM_IMAGE_PATH . trim($part);
                    }
                    $mainImage = $all_image_urls[0]; 
                } else {
                    $mainImage = $ROOM_IMAGE_PATH . trim($rawPath);
                    $all_image_urls[] = $mainImage;
                }
            } else {
                $mainImage = $DEFAULT_IMAGE;
                $all_image_urls[] = $DEFAULT_IMAGE;
            }

            $row['full_image_url'] = $mainImage;
            $row['all_images'] = $all_image_urls;
            
            // --- 🟢 3. AMENITIES MAPPING LOGIC ---
            // Convert IDs "1,2,5" into Names "Wifi, Pool, AC"
            $amenityNames = [];
            if (!empty($row['amenities'])) {
                $ids = explode(',', $row['amenities']);
                foreach($ids as $id) {
                    $id = trim($id);
                    if(isset($amenityMap[$id])) {
                        $amenityNames[] = $amenityMap[$id];
                    }
                }
            }
            
            // Overwrite the 'amenities' field with the readable string for Flutter
            $row['amenities'] = implode(', ', $amenityNames); 

            // Format price (Ensure it is a number for safety, then string format)
            $row['price'] = (float)$row['price'];
            $row['formatted_price'] = number_format($row['price'], 0);

            $rooms_list[] = $row;
        }
        
        echo json_encode(["success" => true, "data" => $rooms_list]);

    } else {
        echo json_encode(["success" => false, "message" => "No rooms found"]);
    }
} else {
    // 🟢 SECURITY: Log error internally
    error_log("Database Error (Rooms): " . mysqli_error($conn));
    echo json_encode(["success" => false, "message" => "System error"]);
}

$conn->close();
?>
<?php
// FILE: API/api_get_food_menu.php

// 🟢 SECURITY: Hide system errors from output
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

$menu_items = [];

// 🟢 LOGIC FIX: Filter by 'is_active = 1'
// This ensures archived food items don't appear in the mobile app.
// Also added 'is_available' check just in case you want to hide out-of-stock items later.
$sql = "SELECT id, item_name, category, price, image_path 
        FROM food_menu 
        WHERE is_active = 1 
        ORDER BY category DESC, item_name ASC";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        
        // --- Image Logic ---
        $rawPath = $row['image_path'];
        
        // Handle comma-separated images (take the first one)
        if (!empty($rawPath) && strpos($rawPath, ',') !== false) {
            $parts = explode(',', $rawPath);
            $rawPath = trim($parts[0]);
        }

        // Use Path from Config
        if (!empty($rawPath)) {
            $row['full_image_url'] = $FOOD_IMAGE_PATH . $rawPath;
        } else {
            $row['full_image_url'] = $DEFAULT_IMAGE;
        }

        // Send empty description to prevent Flutter crash if your app expects this key
        $row['description'] = ""; 

        // --- Format Price ---
        $row['price'] = number_format((float)$row['price'], 2, '.', '');

        $menu_items[] = $row;
    }
    
    echo json_encode(["success" => true, "data" => $menu_items]);

} else {
    // 🟢 SECURITY: Log error internally, show safe message to user
    error_log("Database Error: " . $conn->error);
    echo json_encode(["success" => true, "data" => []]); // Return empty list on error to keep app stable
}

$conn->close();
?>
<?php
// FILE: API/api_get_news.php

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

// 🟢 LOGIC FIX: Filter by 'is_active = 1'
// Prevents the app from showing news you archived in the dashboard.
$query = "SELECT * FROM hotel_news WHERE is_active = 1 ORDER BY news_date DESC"; 

$result = $conn->query($query);

$news_list = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        
        // --- Image Logic ---
        $rawPath = $row['image_path'];
        
        // Handle comma-separated images (take first one)
        if (!empty($rawPath) && strpos($rawPath, ',') !== false) {
            $parts = explode(',', $rawPath);
            $rawPath = trim($parts[0]);
        }

        // Use Path from Config
        if (!empty($rawPath)) {
            $row['full_image_url'] = $NEWS_IMAGE_PATH . $rawPath;
        } else {
            $row['full_image_url'] = $DEFAULT_IMAGE;
        }

        // --- Clean Description ---
        // Strip HTML tags for clean mobile display
        $cleanDesc = strip_tags($row['description']);
        
        // Truncate length for preview (optional)
        if (strlen($cleanDesc) > 150) {
            $cleanDesc = substr($cleanDesc, 0, 150) . '...';
        }
        $row['short_desc'] = $cleanDesc;

        // --- Format Date ---
        $dateObj = new DateTime($row['news_date']);
        $row['formatted_date'] = $dateObj->format('F d, Y');

        $news_list[] = $row;
    }
    
    echo json_encode(["success" => true, "data" => $news_list]);

} else {
    // 🟢 SECURITY: Log actual error internally
    error_log("Database Error (News): " . $conn->error);
    // Return empty list to prevent app crash
    echo json_encode(["success" => true, "data" => []]);
}

$conn->close();
?>
<?php
// FILE: API/api_get_payment_info.php

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

// 1. Headers & Config
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
date_default_timezone_set('Asia/Manila');

require 'connection.php';
require 'config.php'; 

// 2. Fetch the active GCash settings
// Note: Since 'GCash' is hardcoded here, typical SQL injection isn't possible, 
// so a standard query is acceptable.
$sql = "SELECT account_name, account_number, qr_image_path 
        FROM payment_settings 
        WHERE method_name = 'GCash' AND is_active = 1 
        LIMIT 1";

$result = $conn->query($sql);

if ($result) {
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // 🟢 BUILD DYNAMIC URL
        $full_qr_url = null;
        if (!empty($row['qr_image_path'])) {
            // Security: basename() ensures we only get the filename, preventing directory traversal
            $full_qr_url = $QR_IMAGE_PATH . basename($row['qr_image_path']);
        }

        echo json_encode([
            'success' => true,
            'account_name' => $row['account_name'],
            'account_number' => $row['account_number'],
            'qr_image' => $full_qr_url
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Payment info not available'
        ]);
    }
} else {
    // 🟢 SECURITY: Log error internally
    error_log("Database Error (Payment Info): " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'System error']);
}

$conn->close();
?>
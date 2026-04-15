<?php
// FILE: API/api_user_sync.php

// 🟢 SECURITY: Disable error display to prevent path and credential leakage
error_reporting(E_ALL);
ini_set('display_errors', 0);

require 'connection.php'; 

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// 🟢 SECURITY: Restrict to POST requests only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

// 1. Capture Input Safely
$input = $_POST;
if (empty($input)) {
    $raw_input = file_get_contents("php://input");
    $input = json_decode($raw_input, true);
}

// 2. Extract & Sanitize Variables
// Using trim and strip_tags ensures no malicious scripts are saved as names/emails
$uid    = isset($input['uid']) ? trim($input['uid']) : '';
$email  = isset($input['email']) ? trim($input['email']) : '';
$name   = isset($input['name']) ? trim(strip_tags($input['name'])) : 'Guest';
$photo  = $input['photo_url'] ?? ($input['photo'] ?? ''); 
$source = $input['source'] ?? 'email'; 

// 3. Validate
if (empty($uid) || empty($email)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Missing UID or Email'
        // 🟢 SECURITY: Removed 'debug_received' to avoid leaking raw data in responses
    ]);
    exit();
}

// 4. Save to Database
// Logic preserved: Update name/photo/uid if (email + source) exists.
$sql = "INSERT INTO users (firebase_uid, name, email, profile_pic, account_source) 
        VALUES (?, ?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE 
        name = VALUES(name), 
        profile_pic = VALUES(profile_pic),
        firebase_uid = VALUES(firebase_uid)"; 

$stmt = $conn->prepare($sql);

if (!$stmt) {
    // 🟢 SECURITY: Log real error to server, show generic message to user
    error_log("SQL Prepare Error in User Sync: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Internal server error during sync.']);
    exit();
}

// Bind 5 strings: uid, name, email, photo, source
$stmt->bind_param("sssss", $uid, $name, $email, $photo, $source);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'User Synced Successfully']);
} else {
    // 🟢 SECURITY: Log real error to server, show generic message to user
    error_log("DB Execution Error in User Sync: " . $stmt->error);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
}

$stmt->close();
$conn->close();
?>
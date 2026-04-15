<?php
// FILE: API/api_get_messages.php

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

// 🟢 1. Handle JSON Input (Crucial for Flutter)
// Flutter often sends "application/json", so $_POST might be empty.
$input = json_decode(file_get_contents("php://input"), true);

// Support both JSON body and Form Data
$email  = $input['email'] ?? $_POST['email'] ?? '';
$source = $input['source'] ?? $_POST['source'] ?? '';

// Basic Sanitization
$email  = trim($email);
$source = trim($source);

if (empty($email) || empty($source)) {
    echo json_encode(['success' => false, 'message' => 'Email and Source required']);
    exit();
}

// 2. Fetch Messages (Prepared Statement)
// Filter by email AND account_source to show only relevant history
$sql = "SELECT message, created_at, is_read 
        FROM guest_messages 
        WHERE email = ? AND account_source = ? 
        ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("ss", $email, $source);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $messages = [];
        
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }

        echo json_encode(['success' => true, 'data' => $messages]);
    } else {
        // Log internal error, show safe message
        error_log("Database Execute Error: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'System error']);
    }
    $stmt->close();
} else {
    error_log("Database Prepare Error: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'System error']);
}

$conn->close();
?>
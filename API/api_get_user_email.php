<?php
// FILE: API/api_get_user_email.php

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

// 🟢 1. Capture Input (Handle JSON first, then POST)
$input = json_decode(file_get_contents("php://input"), true);
$uid = isset($input['uid']) ? trim($input['uid']) : (isset($_POST['uid']) ? trim($_POST['uid']) : '');

if (empty($uid)) {
    echo json_encode(['success' => false, 'message' => 'UID required']);
    exit();
}

// 🟢 2. Check Database (Prepared Statement)
$stmt = $conn->prepare("SELECT email, account_source FROM users WHERE firebase_uid = ? LIMIT 1");

if ($stmt) {
    $stmt->bind_param("s", $uid);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            echo json_encode([
                'success' => true, 
                'email' => $row['email'],
                'source' => $row['account_source']
            ]);
        } else {
            // User not found
            echo json_encode(['success' => false, 'message' => 'User not found in DB']);
        }
    } else {
        // Log execute error
        error_log("Execute Error (User Email): " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'System error']);
    }
    $stmt->close();
} else {
    // Log prepare error
    error_log("Prepare Error (User Email): " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'System error']);
}

$conn->close();
?>
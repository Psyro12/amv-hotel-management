<?php
// FILE: API/api_contact_us.php

// 🟢 SECURITY: Disable error display for production (prevents path leakage)
error_reporting(E_ALL);
ini_set('display_errors', 0); 

// 🟢 SECURITY: Restrict to POST requests only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

require 'connection.php'; 

// 🟢 SECURITY: CORS - Allow only your app/web domain if possible, or keep * for dev
header("Access-Control-Allow-Origin: *"); 
header("Content-Type: application/json; charset=UTF-8");

// Retrieve Data
$input = $_POST;
if (empty($input)) {
    $raw_input = file_get_contents("php://input");
    $input = json_decode($raw_input, true);
}

// 🟢 SECURITY: Sanitize Inputs (Prevents XSS in Admin Dashboard)
// htmlspecialchars converts <script> tags to text, making them harmless.
$name    = isset($input['name']) ? htmlspecialchars(strip_tags(trim($input['name']))) : '';
$email   = isset($input['email']) ? filter_var(trim($input['email']), FILTER_SANITIZE_EMAIL) : '';
$message = isset($input['message']) ? htmlspecialchars(strip_tags(trim($input['message']))) : '';
$source  = isset($input['source']) ? htmlspecialchars(strip_tags(trim($input['source']))) : 'email'; 

// Validation
if (empty($name) || empty($email) || empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit();
}

// --- 🟢 NEW: Daily Message Limit Check (4 per day) ---
$stmt_check = $conn->prepare("SELECT COUNT(*) FROM guest_messages WHERE email = ? AND DATE(created_at) = CURDATE()");
$stmt_check->bind_param("s", $email);
$stmt_check->execute();
$stmt_check->bind_result($msg_count);
$stmt_check->fetch();
$stmt_check->close();

if ($msg_count >= 4) {
    echo json_encode(['success' => false, 'message' => 'Daily limit reached. (4 messages max)']);
    exit();
}

// Insert into Database
$sql = "INSERT INTO guest_messages (guest_name, email, message, account_source) VALUES (?, ?, ?, ?)";
$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("ssss", $name, $email, $message, $source);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Message sent successfully!']);
    } else {
        // 🟢 SECURITY: Do not show $stmt->error to the user in production
        error_log("Database Error: " . $stmt->error); // Log it server-side instead
        echo json_encode(['success' => false, 'message' => 'System error. Please try again later.']);
    }
    $stmt->close();
} else {
    error_log("Prepare Error: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'System error. Please try again later.']);
}

$conn->close();
?>
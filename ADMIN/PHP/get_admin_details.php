<?php
// ADMIN/PHP/get_admin_details.php

// 1. Start Session & Setup
ob_start(); // Buffer output to prevent whitespace errors
session_start();
require 'db_connect.php'; // Ensure database connection is available
ob_clean(); // Clean buffer before JSON output
header('Content-Type: application/json');

// 2. Check Login Status & Handle Timeout
if (!isset($_SESSION['user'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please login again.']);
    exit();
}

// 3. Robust ID Retrieval (Case-Insensitive)
$userId = null;
if (isset($_SESSION['user']['id'])) {
    $userId = $_SESSION['user']['id'];
} elseif (isset($_SESSION['user']['ID'])) {
    $userId = $_SESSION['user']['ID'];
}

if (!$userId) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'User ID missing in session']);
    exit();
}

// 🟢 KEEP-ALIVE: Reset activity timer
if ($userId) {
    $_SESSION['timeout'] = time();
}

// 4. Fetch User Details from Database
$sql = "SELECT name, email, contact_number FROM admin_user WHERE id = ? LIMIT 1";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    // Fallback: Try uppercase ID column
    $sql = "SELECT name, email, contact_number FROM admin_user WHERE ID = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
}

if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
    exit();
}

$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$adminData = $result->fetch_assoc();
$stmt->close();

if (!$adminData) {
    echo json_encode(['status' => 'error', 'message' => "User not found (ID: $userId)"]);
    exit();
}

// ---------------------------------------------------------
// 🟢 NEW: Fetch WiFi Settings (Row ID 1)
// ---------------------------------------------------------
$wifiSql = "SELECT ssid, password as wifi_password FROM wifi_settings WHERE id = 1 LIMIT 1";
$wifiResult = $conn->query($wifiSql);

if ($wifiResult && $wifiResult->num_rows > 0) {
    $wifiData = $wifiResult->fetch_assoc();
} else {
    // Default fallback if table is empty
    $wifiData = ['ssid' => 'Not Set', 'wifi_password' => ''];
}

// 5. Merge Data and Output
$finalData = array_merge($adminData, $wifiData);

echo json_encode(['status' => 'success', 'data' => $finalData]);

$conn->close();
exit;
?>
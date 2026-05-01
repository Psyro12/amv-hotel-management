<?php
// FILE: API/api_get_wifi.php

// 🟢 SECURITY: Disable error display to prevent leakage
error_reporting(E_ALL);
ini_set('display_errors', 0);

require 'connection.php';

$response = array();

/**
 * 🟢 FETCH LOGIC
 * Table: wifi_settings
 * Columns: ssid, password
 */
$query = "SELECT ssid, password FROM wifi_settings LIMIT 1";
$result = mysqli_query($conn, $query);

if ($result && mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);
    $response['success'] = true;
    $response['ssid'] = $row['ssid'];
    $response['password'] = $row['password'];
} else {
    $response['success'] = false;
    $response['message'] = "WiFi information not found.";
}

header('Content-Type: application/json');
echo json_encode($response);

$conn->close();
?>
<?php
header('Content-Type: application/json');
include 'db_connect.php'; 

$response = array();

// Updated to match your specific table: wifi_settings
// and columns: ssid, password
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

echo json_encode($response);
?>
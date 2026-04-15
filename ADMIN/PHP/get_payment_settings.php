<?php
// ADMIN/PHP/get_payment_settings.php
require 'db_connect.php';
header('Content-Type: application/json');

// Get the first row
$sql = "SELECT * FROM payment_settings LIMIT 1";
$result = $conn->query($sql);

if ($result && $row = $result->fetch_assoc()) {
    // MAP DATABASE COLUMNS TO JAVASCRIPT VARIABLES
    $data = [
        'payment_method' => $row['method_name'],   // DB: method_name
        'account_name'   => $row['account_name'],
        'account_number' => $row['account_number'],
        'qr_image'       => $row['qr_image_path']  // DB: qr_image_path
    ];

    echo json_encode(['status' => 'success', 'data' => $data]);
} else {
    // If empty, return default empty data
    echo json_encode(['status' => 'error', 'message' => 'No settings found']);
}
?>
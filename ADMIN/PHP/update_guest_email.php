<?php
// ADMIN/PHP/update_guest_email.php
require 'db_connect.php';
session_start();

header('Content-Type: application/json');

// 1. Security Check
if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// 2. Get Data
$old_email = $_POST['old_email'] ?? '';
$new_email = $_POST['new_email'] ?? '';

if (!$old_email || !$new_email) {
    echo json_encode(['status' => 'error', 'message' => 'Missing data']);
    exit;
}

if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email format']);
    exit;
}

// 3. Update Database
// We update ALL records matching the old email so their history stays linked
$stmt = $conn->prepare("UPDATE booking_guests SET email = ? WHERE email = ?");
$stmt->bind_param("ss", $new_email, $old_email);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
?>
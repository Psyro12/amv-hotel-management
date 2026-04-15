<?php
// ADMIN/PHP/delete_receipt.php
session_start();
header('Content-Type: application/json');
require 'db_connect.php';

// 1. Check Session
if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// 2. Get Inputs
$id = $_POST['id'] ?? 0;
$table = $_POST['table'] ?? ''; // 'bookings' or 'orders'
$filename = $_POST['filename'] ?? '';

if (!$id || !$table || !$filename) {
    echo json_encode(['status' => 'error', 'message' => 'Missing data']);
    exit;
}

// 3. Define Query based on Table
$sql = "";
if ($table === 'bookings') {
    $sql = "UPDATE bookings SET payment_proof = NULL WHERE id = ?";
} elseif ($table === 'orders') {
    $sql = "UPDATE orders SET payment_proof = NULL WHERE id = ?";
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid table']);
    exit;
}

// 4. Update Database
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    // 5. Delete Actual File
    $filePath = "../../room_includes/uploads/receipts/" . $filename;
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    
    echo json_encode(['status' => 'success', 'message' => 'Receipt deleted']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
?>
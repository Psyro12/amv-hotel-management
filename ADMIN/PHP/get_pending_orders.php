<?php
// ADMIN/PHP/get_pending_orders.php
session_start();
header('Content-Type: application/json');
require 'db_connect.php';

if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Fetch all Pending orders
$sql = "SELECT o.*, u.name as guest_name 
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        WHERE o.status = 'Pending' 
        ORDER BY o.order_date ASC";

$result = $conn->query($sql);
$data = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Decode the JSON items string (e.g., '{"Burger": 2}')
        $row['items_decoded'] = json_decode($row['items'], true);
        $data[] = $row;
    }
    echo json_encode(['status' => 'success', 'data' => $data]);
} else {
    echo json_encode(['status' => 'error', 'message' => $conn->error]);
}
?>

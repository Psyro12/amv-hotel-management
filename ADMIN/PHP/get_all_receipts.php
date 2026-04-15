<?php
// ADMIN/PHP/get_all_receipts.php
session_start();
header('Content-Type: application/json');
require 'db_connect.php';

if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Get Date (Can be empty)
$dateFilter = $_GET['date'] ?? '';

// Build Query dynamically
if (!empty($dateFilter)) {
    // 🟢 OPTION A: Filter by Date
    $sql = "
        SELECT id, payment_proof as image, created_at as date_time, booking_reference as ref, 'Booking' as type, 'bookings' as source_table
        FROM bookings 
        WHERE payment_proof IS NOT NULL AND payment_proof != '' 
        AND payment_method = 'GCash' 
        AND booking_source NOT IN ('walk-in', 'reservation') /* 🚫 EXCLUDE ADMIN BOOKINGS */
        AND DATE(created_at) = ?

        UNION ALL

        SELECT id, payment_proof as image, order_date as date_time, CONCAT('Order #', id) as ref, 'Food Order' as type, 'orders' as source_table
        FROM orders 
        WHERE payment_proof IS NOT NULL AND payment_proof != ''
        AND payment_method = 'GCash' 
        AND DATE(order_date) = ?

        ORDER BY date_time DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $dateFilter, $dateFilter);

} else {
    // 🟢 OPTION B: Show ALL (Limit 100)
    $sql = "
        SELECT id, payment_proof as image, created_at as date_time, booking_reference as ref, 'Booking' as type, 'bookings' as source_table
        FROM bookings 
        WHERE payment_proof IS NOT NULL AND payment_proof != '' 
        AND payment_method = 'GCash' 
        AND booking_source NOT IN ('walk-in', 'reservation') /* 🚫 EXCLUDE ADMIN BOOKINGS */

        UNION ALL

        SELECT id, payment_proof as image, order_date as date_time, CONCAT('Order #', id) as ref, 'Food Order' as type, 'orders' as source_table
        FROM orders 
        WHERE payment_proof IS NOT NULL AND payment_proof != ''
        AND payment_method = 'GCash' 

        ORDER BY date_time DESC LIMIT 100
    ";
    $stmt = $conn->prepare($sql);
}

$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode(['status' => 'success', 'data' => $data]);
?>
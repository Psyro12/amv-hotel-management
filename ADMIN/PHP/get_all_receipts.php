<?php
// ADMIN/PHP/get_all_receipts.php
session_start();
header('Content-Type: application/json');
require 'db_connect.php';

if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// 1. Get Parameters
$dateFilter = $_GET['date'] ?? '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

// 2. Build Count Query (Total valid receipts)
$countSql = "
    SELECT (
        (SELECT COUNT(*) FROM bookings 
         WHERE payment_proof IS NOT NULL AND payment_proof != '' 
         AND payment_method = 'GCash' 
         AND booking_source NOT IN ('walk-in', 'reservation')
         " . (!empty($dateFilter) ? "AND DATE(created_at) = ?" : "") . ")
        +
        (SELECT COUNT(*) FROM orders 
         WHERE payment_proof IS NOT NULL AND payment_proof != ''
         AND payment_method = 'GCash'
         " . (!empty($dateFilter) ? "AND DATE(order_date) = ?" : "") . ")
    ) as total
";

$stmtCount = $conn->prepare($countSql);
if (!empty($dateFilter)) {
    $stmtCount->bind_param("ss", $dateFilter, $dateFilter);
}
$stmtCount->execute();
$totalCount = $stmtCount->get_result()->fetch_assoc()['total'];

// 3. Fetch Data with Pagination
if (!empty($dateFilter)) {
    $sql = "
        SELECT id, payment_proof as image, created_at as date_time, booking_reference as ref, 'Booking' as type, 'bookings' as source_table
        FROM bookings 
        WHERE payment_proof IS NOT NULL AND payment_proof != '' 
        AND payment_method = 'GCash' 
        AND booking_source NOT IN ('walk-in', 'reservation')
        AND DATE(created_at) = ?

        UNION ALL

        SELECT id, payment_proof as image, order_date as date_time, CONCAT('Order #', id) as ref, 'Food Order' as type, 'orders' as source_table
        FROM orders 
        WHERE payment_proof IS NOT NULL AND payment_proof != ''
        AND payment_method = 'GCash' 
        AND DATE(order_date) = ?

        ORDER BY date_time DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $dateFilter, $dateFilter, $limit, $offset);
} else {
    $sql = "
        SELECT id, payment_proof as image, created_at as date_time, booking_reference as ref, 'Booking' as type, 'bookings' as source_table
        FROM bookings 
        WHERE payment_proof IS NOT NULL AND payment_proof != '' 
        AND payment_method = 'GCash' 
        AND booking_source NOT IN ('walk-in', 'reservation')

        UNION ALL

        SELECT id, payment_proof as image, order_date as date_time, CONCAT('Order #', id) as ref, 'Food Order' as type, 'orders' as source_table
        FROM orders 
        WHERE payment_proof IS NOT NULL AND payment_proof != ''
        AND payment_method = 'GCash' 

        ORDER BY date_time DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $limit, $offset);
}

$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode([
    'status' => 'success', 
    'data' => $data,
    'total' => (int)$totalCount,
    'limit' => $limit,
    'offset' => $offset
]);
?>
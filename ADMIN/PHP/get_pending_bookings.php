<?php
// ADMIN/PHP/get_pending_bookings.php
require 'db_connect.php';
header('Content-Type: application/json');

// 🟢 UPDATED QUERY: Added JOIN to booking_rooms to get 'room_name'
$sql = "SELECT b.*, 
               bg.first_name, bg.last_name, 
               GROUP_CONCAT(br.room_name SEPARATOR ', ') as room_names
        FROM bookings b
        JOIN booking_guests bg ON b.id = bg.booking_id
        LEFT JOIN booking_rooms br ON b.id = br.booking_id
        WHERE b.status = 'pending'
        GROUP BY b.id
        ORDER BY b.created_at ASC";

$result = $conn->query($sql);

$data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    echo json_encode(['status' => 'success', 'data' => $data]);
} else {
    echo json_encode(['status' => 'error', 'message' => $conn->error]);
}
?>
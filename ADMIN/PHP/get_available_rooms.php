<?php
// ADMIN/PHP/get_available_rooms.php

require 'db_connect.php';
header('Content-Type: application/json');

// 1. Get Parameters
$checkin = $_GET['checkin'] ?? '';
$checkout = $_GET['checkout'] ?? '';
$type = $_GET['type'] ?? 'reservation'; // Default to reservation if missing

if (!$checkin || !$checkout) {
    echo json_encode([]);
    exit;
}

// 2. Build the SQL Logic
// Standard Overlap Logic (Always Applies)
$overlapCondition = "(b.check_in < ? AND b.check_out > ?)";

// Strict 'In House' Block (Only applies to Walk-ins)
// If it's a walk-in, we CANNOT book a room if the guest hasn't left yet.
$strictBlock = "";
if ($type === 'walk-in') {
    $strictBlock = " OR (b.arrival_status = 'in_house' AND b.check_out = ?)";
}

$sql = "SELECT r.id, r.name, r.price, r.capacity, r.bed_type as bed, r.size, r.image_path as image 
        FROM rooms r 
        WHERE r.is_active = 1 
        AND r.id NOT IN (
            SELECT br.room_id 
            FROM booking_rooms br 
            JOIN bookings b ON br.booking_id = b.id 
            WHERE b.status IN ('confirmed', 'pending') 
            AND b.arrival_status NOT IN ('cancelled', 'no_show', 'checked_out')
            AND (
                $overlapCondition
                $strictBlock
            )
        )
        ORDER BY r.name ASC";

$stmt = $conn->prepare($sql);

if ($type === 'walk-in') {
    // Bind 3 params: checkout, checkin, checkin (for strict block)
    $stmt->bind_param("sss", $checkout, $checkin, $checkin);
} else {
    // Bind 2 params: checkout, checkin (standard only)
    $stmt->bind_param("ss", $checkout, $checkin);
}

$stmt->execute();
$result = $stmt->get_result();

$rooms = [];

// Base64 Placeholder
$placeholder = "data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3QgZmlsbD0iI2RkZCIgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJhcmlhbCIgZm9udC1zaXplPSIxMiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzU1NSI+Tm8gSW1hZ2U8L3RleHQ+PC9zdmc+";

while ($row = $result->fetch_assoc()) {
    $imgUrl = !empty($row['image']) 
        ? "../../room_includes/uploads/images/" . explode(',', $row['image'])[0] 
        : $placeholder;

    $rooms[] = [
        'id' => $row['id'],
        'name' => $row['name'],
        'price' => (float)$row['price'],
        'capacity' => $row['capacity'],
        'bed' => $row['bed'],
        'size' => $row['size'],
        'image' => $imgUrl
    ];
}

echo json_encode($rooms);
?>
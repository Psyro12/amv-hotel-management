<?php
// ADMIN/PHP/get_calendar_data.php
require 'db_connect.php';
session_start();
header('Content-Type: application/json');

// 1. Get View Parameters
$month = isset($_GET['month']) ? $_GET['month'] : date('m');
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');

$startDate = date('Y-m-d', strtotime("$year-$month-01 - 7 days"));
$endDate = date('Y-m-d', strtotime("$year-$month-01 + 1 month + 7 days"));

// 2. Fetch Rooms
$rooms = [];
$sql_rooms = "SELECT id, name, is_active FROM rooms ORDER BY is_active DESC, name ASC";
$res_rooms = $conn->query($sql_rooms);
while ($r = $res_rooms->fetch_assoc()) {
    $rooms[] = $r;
}

// 3. Fetch Bookings
$bookings = [];
$today = date('Y-m-d');

$sql_cal = "SELECT 
                b.id, b.check_in, b.check_out, b.status, b.arrival_status, 
                br.room_id, br.room_name, 
                CONCAT(bg.first_name, ' ', bg.last_name) as guest_name,
                bg.email, bg.phone
            FROM bookings b 
            JOIN booking_rooms br ON b.id = br.booking_id 
            JOIN booking_guests bg ON b.id = bg.booking_id
            WHERE b.status IN ('confirmed', 'pending')
            AND (
                b.arrival_status IS NULL 
                OR b.arrival_status = ''
                OR b.arrival_status = 'awaiting_arrival'
                OR b.arrival_status = 'upcoming'
                OR b.arrival_status = 'in_house'
                OR b.arrival_status = 'checked_out'
            )
            AND b.check_out > ? 
            AND b.check_in < ?";

$stmt = $conn->prepare($sql_cal);
$stmt->bind_param("ss", $startDate, $endDate);
$stmt->execute();
$res_cal = $stmt->get_result();

while ($row = $res_cal->fetch_assoc()) {
    $start = new DateTime($row['check_in']);
    $end = new DateTime($row['check_out']);

    // 🔴 LOGIC CHANGE: If guest is still In House, extend the block to include the checkout day
    if ($row['arrival_status'] == 'in_house') {
        $end->modify('+1 day');
    }

    for ($date = clone $start; $date < $end; $date->modify('+1 day')) {
        $dateStr = $date->format('Y-m-d');

        // 🔴 CRITICAL FIX: Skip 'checked_out' bookings for Today and Future
        // This ensures they show as "Available" (or purely History) and not "Reserved"
        if ($row['arrival_status'] === 'checked_out' && $dateStr >= $today) {
            continue;
        }

        if (!isset($bookings[$dateStr])) {
            $bookings[$dateStr] = [];
        }

        // Color Logic
        $colorType = 'future'; // Default Yellow

        if ($row['arrival_status'] == 'in_house') {
            $colorType = 'in_house'; // Gold
        }
        // 🔴 CRITICAL FIX: Match the keyword expected by dashboard.php JavaScript
        elseif ($row['arrival_status'] == 'checked_out') {
            $colorType = 'checked_out'; // Changed from 'history' to 'checked_out'
        }

        $bookings[$dateStr][] = [
            'room_id' => $row['room_id'],
            'room_name' => $row['room_name'],
            'guest' => $row['guest_name'],
            'status' => $row['status'],
            'type' => $colorType, // This now sends 'checked_out' correctly
            'check_in' => $row['check_in'],
            'check_out' => $row['check_out']
        ];
    }
}

echo json_encode([
    'status' => 'success',
    'rooms' => $rooms,
    'bookings' => $bookings
]);
?>
<?php
require 'db_connect.php';
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

// Check if we are specifically filtering messages by date
if (isset($_GET['filter_date'])) {
    $filterDate = $conn->real_escape_string($_GET['filter_date']);
    
    // Fetch ONLY messages for this specific date
    $sql = "SELECT * FROM guest_messages WHERE DATE(created_at) = '$filterDate' ORDER BY created_at DESC";
    $result = $conn->query($sql);
    
    $messages = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }
    }
    
    echo json_encode(['status' => 'success', 'messages' => $messages]);
    exit(); // Stop here, don't load the rest of the dashboard data
}

// ============================================
// STANDARD DASHBOARD DATA (Runs if no date filter is set)
// ============================================

$current_time = date('H:i:s');
$lateGuestCount = 0;

$sql_late_check = "SELECT COUNT(*) as total 
                   FROM bookings b
                   JOIN booking_guests bg ON b.id = bg.booking_id
                   WHERE b.status = 'confirmed' 
                   AND b.check_in = CURDATE()
                   AND (b.arrival_status IS NULL OR b.arrival_status = '' OR b.arrival_status = 'awaiting_arrival')
                   AND bg.arrival_time < '$current_time'";

$res_late = $conn->query($sql_late_check);
if ($res_late) {
    $lateGuestCount = $res_late->fetch_assoc()['total'] ?? 0;
}

// 1. Fetch Latest 20 Notifications
$notifs = [];
$res_notif = $conn->query("SELECT * FROM system_notifications ORDER BY created_at DESC LIMIT 20");
if ($res_notif) {
    while ($row = $res_notif->fetch_assoc()) {
        $notifs[] = $row;
    }
}

// 2. Fetch Latest 20 Messages (Keeps it fast)
$messages = [];
$res_msg = $conn->query("SELECT * FROM guest_messages ORDER BY created_at DESC LIMIT 20");
if ($res_msg) {
    while ($row = $res_msg->fetch_assoc()) {
        $messages[] = $row;
    }
}

// 3. Count Unread
$count_notif = $conn->query("SELECT COUNT(*) as c FROM system_notifications WHERE is_read = 0")->fetch_assoc()['c'];
$count_msg = $conn->query("SELECT COUNT(*) as c FROM guest_messages WHERE is_read = 0")->fetch_assoc()['c'];

echo json_encode([
    'status' => 'success',
    'notifications' => $notifs,
    'messages' => $messages,
    'counts' => [
        'notifications' => $count_notif, 
        'messages' => $count_msg,
        'late_arrivals' => $lateGuestCount
    ]
]);
?>
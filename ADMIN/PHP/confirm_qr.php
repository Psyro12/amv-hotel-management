<?php
// ADMIN/PHP/confirm_qr.php

session_start();
header('Content-Type: application/json');
require 'db_connect.php';
date_default_timezone_set('Asia/Manila');

// 1. Security Check
if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit();
}

// 2. Get Input
$input = json_decode(file_get_contents('php://input'), true);
$reference = $input['reference'] ?? '';

if (empty($reference)) {
    echo json_encode(['status' => 'error', 'message' => 'No QR code data received']);
    exit();
}

// 3. Check Booking Status
// We verify the booking exists and get all necessary details for validation
$sql = "SELECT b.id, b.status, b.arrival_status, b.check_in, 
               b.total_price, b.amount_paid,
               CONCAT(bg.first_name, ' ', bg.last_name) as guest_name 
        FROM bookings b 
        JOIN booking_guests bg ON b.id = bg.booking_id 
        WHERE b.booking_reference = ?";
        
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $reference);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Booking Reference not found.']);
    exit();
}

$row = $result->fetch_assoc();
$guestName = $row['guest_name'];
$checkInDate = date('Y-m-d', strtotime($row['check_in']));
$today = date('Y-m-d');

// --- PAYMENT CALCULATION ---
$total = floatval($row['total_price']);
$paid = floatval($row['amount_paid'] ?? 0);
$balance = $total - $paid;

// 4. VALIDATION LOGIC (The Gatekeeper)
// 🟢 FIXED ORDER: Check Status FIRST, then Date, then Payment.

// --- PRIORITY 1: STATUS CHECKS ---

// Check Cancelled
if ($row['status'] === 'cancelled') {
    echo json_encode(['status' => 'error', 'message' => "🚨 Access Denied: Reservation for $guestName has been Cancelled."]);
    exit();
}

// Check No-Show (Fixes the issue where payment was asked for no-show guests)
if ($row['arrival_status'] === 'no_show') {
    echo json_encode(['status' => 'error', 'message' => "🚨 Invalid: $guestName was marked as a No-Show. The room has already been released."]);
    exit();
}

// Check Checked Out
if ($row['arrival_status'] === 'checked_out') {
    echo json_encode(['status' => 'error', 'message' => "ℹ️ Note: This booking ($reference) has already Checked Out."]);
    exit();
}

// Check In-House (Warning only)
if ($row['arrival_status'] === 'in_house') {
    echo json_encode(['status' => 'warning', 'message' => "⚠️ Alert: $guestName is already marked In-House."]);
    exit();
}

// --- PRIORITY 2: DATE CHECK ---

// Early Arrival Protection
if ($checkInDate > $today) {
    $formattedDate = date('F j, Y', strtotime($checkInDate));
    echo json_encode([
        'status' => 'error', 
        'message' => "🚨 Access Denied: This reservation is for $formattedDate. Check-in is not allowed yet."
    ]);
    exit();
}

// --- PRIORITY 3: ROOM OCCUPANCY CHECK ---
// Ensure the room is not currently occupied by another guest who is 'in_house'
$check_sql = "SELECT r.name 
              FROM booking_rooms current_br
              JOIN booking_rooms other_br ON current_br.room_id = other_br.room_id
              JOIN bookings other_b ON other_br.booking_id = other_b.id
              JOIN rooms r ON current_br.room_id = r.id
              WHERE current_br.booking_id = ? 
              AND other_b.arrival_status = 'in_house'
              AND other_b.id != ? 
              LIMIT 1";

$stmt_check = $conn->prepare($check_sql);
$stmt_check->bind_param("ii", $row['id'], $row['id']);
$stmt_check->execute();
$res_check = $stmt_check->get_result();

if ($occupied_room = $res_check->fetch_assoc()) {
    echo json_encode([
        'status' => 'error',
        'message' => "🚨 Room Occupied: " . $occupied_room['name'] . " is currently occupied by another guest. Please ensure the previous guest has checked out before scanning."
    ]);
    exit;
}

// --- PRIORITY 4: PAYMENT CHECK ---

// Unpaid Balance Protection
if ($balance > 1) {
    $formattedBal = number_format($balance, 2);
    
    // If status is pending, show a warning instead of a hard error
    if ($row['status'] === 'pending' || $row['status'] === 'Pending') {
        echo json_encode([
            'status' => 'warning', 
            'message' => "📝 Verification Required: Guest has a pending balance of ₱$formattedBal. This booking is still waiting for admin approval. Please check the proof of payment first."
        ]);
    } else {
        echo json_encode([
            'status' => 'error', 
            'message' => "💰 Payment Required: Guest has a pending balance of ₱$formattedBal. Please settle payment at the desk before scanning."
        ]);
    }
    exit();
}

// 5. Update Database (Confirm Arrival)
// If all checks pass, mark the guest as "In House"
$update_sql = "UPDATE bookings 
               SET arrival_status = 'in_house', 
                   status = 'confirmed' 
               WHERE id = ?";
$update_stmt = $conn->prepare($update_sql);
$update_stmt->bind_param("i", $row['id']);

if ($update_stmt->execute()) {
    // 6. Log notification
    $notif_title = "QR Check-in Success";
    $notif_desc = "Guest $guestName ($reference) checked in via QR scanner.";
    $stmt_notif = $conn->prepare("INSERT INTO system_notifications (title, description, type, is_read, created_at) VALUES (?, ?, 'booking', 0, NOW())");
    $stmt_notif->bind_param("ss", $notif_title, $notif_desc);
    $stmt_notif->execute();
    
    // Success Response
    echo json_encode([
        'status' => 'success', 
        'message' => "Check-in Successful! Welcome, $guestName.",
        'guest_name' => $guestName
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database update failed.']);
}

$conn->close();
?>
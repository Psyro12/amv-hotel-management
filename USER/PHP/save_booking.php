<?php
// USER/PHP/save_booking.php

session_start();
require 'db_connect.php';

// 1. Check if we have data
if (!isset($_SESSION['temp_guest_data']) || !isset($_SESSION['selected_rooms'])) {
    die("Session Error. Please restart booking.");
}

// 2. Retrieve Data
$guest = $_SESSION['temp_guest_data'];
$amount_paid = $_POST['amount_paid'];
$payment_method = $guest['payment_method'];

// 3. Handle File Upload (If GCash)
$receipt_path = null;
$status = 'confirmed'; // Default for Cash
$payment_status = 'unpaid'; 

if ($payment_method === 'GCash') {
    $status = 'pending'; // Needs verification
    $payment_status = 'unpaid'; // Set to unpaid until Admin verifies receipt

    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] == 0) {
        $upload_dir = "../../uploads/receipts/";
        
        // Create directory if not exists
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $file_ext = pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION);
        $new_name = time() . "_" . uniqid() . "." . $file_ext;
        
        if (move_uploaded_file($_FILES['receipt']['tmp_name'], $upload_dir . $new_name)) {
            $receipt_path = "uploads/receipts/" . $new_name; // Path to store in DB
        }
    }
}

// 4. Generate Reference
$ref = 'AMV-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
$dates = $_SESSION['dates']; // Assuming dates are stored here from check_availability.php

// 5. Insert Booking
$sql1 = "INSERT INTO bookings (check_in, check_out, total_price, payment_method, payment_proof, booking_reference, status, arrival_status, booking_source, amount_paid, payment_status, payment_term) 
         VALUES (?, ?, ?, ?, ?, ?, ?, 'upcoming', 'online', ?, ?, ?)";

$stmt = $conn->prepare($sql1);
$stmt->bind_param("ssdssssssdss", 
    $dates['checkin'], 
    $dates['checkout'], 
    $guest['total_price'], 
    $payment_method, 
    $receipt_path, 
    $ref, 
    $status, 
    $amount_paid,
    $payment_status,
    $guest['payment_term']
);
$stmt->execute();
$booking_id = $stmt->insert_id;

// 6. Insert Guest Details
$sql2 = "INSERT INTO booking_guests (booking_id, first_name, last_name, email, phone, address, special_requests, adults_count, children_count) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt2 = $conn->prepare($sql2);
$stmt2->bind_param("issssssii", 
    $booking_id, 
    $guest['first_name'], 
    $guest['last_name'], 
    $guest['email'], 
    $guest['contact_number'], 
    $guest['address'], 
    $guest['requests'], 
    $guest['adults'], 
    $guest['children']
);
$stmt2->execute();

// 7. Insert Rooms
$sql3 = "INSERT INTO booking_rooms (booking_id, room_id, room_name, price_per_night) VALUES (?, ?, ?, ?)";
$stmt3 = $conn->prepare($sql3);

foreach ($_SESSION['selected_rooms'] as $room) {
    $stmt3->bind_param("iisd", $booking_id, $room['id'], $room['name'], $room['price']);
    $stmt3->execute();
}

// 8. Cleanup & Redirect
unset($_SESSION['temp_guest_data']);
unset($_SESSION['selected_rooms']);

$_SESSION['booking_success_ref'] = $ref;
// Redirect to your existing confirmation page
header("Location: confirmation.php");
exit();
?>
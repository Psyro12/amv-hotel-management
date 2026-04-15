<?php
// FILE: ADMIN/PHP/verify_payment.php
require 'db_connect.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];

    // 1. Get current booking details to verify payment logic
    $sql_check = "SELECT total_price, amount_paid, payment_term FROM bookings WHERE id = ?";
    $stmt = $conn->prepare($sql_check);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $booking = $res->fetch_assoc();

    if ($booking) {
        $new_payment_status = 'unpaid';

        // Logic: If they paid according to their term (Full or Partial)
        if ($booking['payment_term'] == 'partial') {
            // If it's a downpayment, mark as Partial
            $new_payment_status = 'partial';
        } else {
            // If it's full payment, mark as Paid
            $new_payment_status = 'paid';
        }

        // 2. Update Status to CONFIRMED
        // We set arrival_status to 'upcoming' so it appears in the table correctly
        $update = "UPDATE bookings SET 
                   status = 'confirmed', 
                   payment_status = ?, 
                   arrival_status = 'upcoming' 
                   WHERE id = ?";
        
        $stmt_up = $conn->prepare($update);
        $stmt_up->bind_param("si", $new_payment_status, $id);
        
        if ($stmt_up->execute()) {
            // 🟢 UPDATE TRANSACTION STATUS
            $new_trans_status = ($new_payment_status === 'paid') ? 'Paid' : 'Partial';
            $ref = $booking['booking_reference']; // Need to fetch reference_id first or change the query above
            
            // Re-fetching details to get reference if not selected above
            $stmt_ref = $conn->prepare("SELECT booking_reference FROM bookings WHERE id = ?");
            $stmt_ref->bind_param("i", $id);
            $stmt_ref->execute();
            $ref_row = $stmt_ref->get_result()->fetch_assoc();
            $booking_ref = $ref_row['booking_reference'] ?? '';
            $stmt_ref->close();

            if (!empty($booking_ref)) {
                $stmtT = $conn->prepare("UPDATE transactions SET status = ? WHERE reference_id = ?");
                $stmtT->bind_param("ss", $new_trans_status, $booking_ref);
                $stmtT->execute();
                $stmtT->close();
            }

            echo json_encode(['status' => 'success', 'message' => 'Payment Verified & Booking Confirmed!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database update failed.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Booking not found.']);
    }
}
$conn->close();
?>
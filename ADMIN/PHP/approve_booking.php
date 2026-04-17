<?php
// ADMIN/PHP/approve_booking.php
session_start();
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

// 1. AUTH & DB CONNECTION
if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

require 'db_connect.php'; 

// 2. LOAD PHPMAILER
require '../../USER/PHPMailer-master/src/Exception.php';
require '../../USER/PHPMailer-master/src/PHPMailer.php';
require '../../USER/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 3. VALIDATE INPUT
if (!isset($_POST['id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing booking ID.']);
    exit;
}

$booking_id = intval($_POST['id']);

try {
    $conn->begin_transaction();

    // 4. FETCH BOOKING DETAILS
    $sql = "SELECT 
                b.id, b.booking_reference, b.payment_term, b.total_price, b.amount_paid, b.check_in, b.check_out,
                bg.first_name, bg.last_name, bg.email, bg.salutation,
                u.account_source
            FROM bookings b
            JOIN booking_guests bg ON b.id = bg.booking_id
            LEFT JOIN users u ON b.user_id = u.id
            WHERE b.id = ?
            GROUP BY b.id";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        throw new Exception("Booking not found.");
    }

    // 5. UPDATE BOOKING STATUS (Confirm & Paid)
    // If partial, set 'partial'; otherwise 'paid'
    $new_pay_status = (trim($booking['payment_term']) === 'partial') ? 'partial' : 'paid';
    
    $update_sql = "UPDATE bookings SET status = 'confirmed', arrival_status = 'upcoming', payment_status = ? WHERE id = ?";
    $up_stmt = $conn->prepare($update_sql);
    $up_stmt->bind_param("si", $new_pay_status, $booking_id);
    
    if (!$up_stmt->execute()) {
        throw new Exception("Failed to update database.");
    }
    $up_stmt->close();

    // 🟢 5.5. UPDATE TRANSACTION STATUS
    // We update the corresponding entry in the transactions table
    $new_trans_status = ($new_pay_status === 'paid') ? 'Paid' : 'Partial';
    $ref = $booking['booking_reference'];
    
    $stmtT = $conn->prepare("UPDATE transactions SET status = ? WHERE reference_id = ?");
    $stmtT->bind_param("ss", $new_trans_status, $ref);
    $stmtT->execute();
    $stmtT->close();

    // 6. INSERT APP NOTIFICATION
    if (!empty($booking['email'])) {
        $notif_title = "Payment Verified";
        $notif_msg = "Your payment for booking " . $booking['booking_reference'] . " has been verified. Your reservation is now confirmed.";
        $source = $booking['account_source'] ?? 'email';

        $n_stmt = $conn->prepare("INSERT INTO guest_notifications (email, account_source, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, 'booking', 0, NOW())");
        $n_stmt->bind_param("ssss", $booking['email'], $source, $notif_title, $notif_msg);
        $n_stmt->execute();
        $n_stmt->close();
    }

    // 🟢 6.5. TRIGGER REAL-TIME UPDATE FOR DASHBOARD
    $conn->query("UPDATE system_updates SET last_updated = CURRENT_TIMESTAMP WHERE category IN ('bookings', 'transactions', 'notifications')");

    // 7. COMMIT CHANGES
    $conn->commit();

    // 🟢 FETCH HOTEL CONTACT INFO
    $hotel_email = "support@amvhotel.online";
    $hotel_phone = "+63 901 234 5678";
    $hotel_name = "AMV Hotel";
    $hotel_address = "Mamburao, Occidental Mindoro, Philippines";

    $sql_hotel = "SELECT name, email, contact_number FROM admin_user WHERE ID = 1 LIMIT 1";
    $res_hotel = $conn->query($sql_hotel);
    if ($res_hotel && $hrow = $res_hotel->fetch_assoc()) {
        $hotel_name = $hrow['name'] ?? $hotel_name;
        $hotel_email = $hrow['email'] ?? $hotel_email;
        $hotel_phone = $hrow['contact_number'] ?? $hotel_phone;
    }

    // 8. SEND EMAIL
    if (!empty($booking['email'])) {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'periolarren@gmail.com'; 
        $mail->Password = 'ftvp ilfl utmq pdgg'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // 🟢 RELAX SSL VERIFICATION
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $mail->setFrom('periolarren@gmail.com', "$hotel_name Reservations");
        $mail->addAddress($booking['email'], $booking['first_name'] . ' ' . $booking['last_name']);

        $qrCodeUrl = "https://quickchart.io/qr?text=" . urlencode($booking['booking_reference']) . "&size=300&ecLevel=H";
        $ref = $booking['booking_reference'];
        $name = $booking['first_name'];
        
        $mail->isHTML(true);
        $mail->Subject = "Payment Verified - Booking " . $ref;
        
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #eee; border-radius: 8px; overflow: hidden;'>
            <div style='background-color: #10B981; padding: 20px; text-align: center; color: white;'>
                <h2 style='margin:0;'>Payment Verified</h2>
            </div>
            <div style='padding: 20px;'>
                <p>Dear $name,</p>
                <p>Great news! We have verified your payment receipt. Your reservation <strong>$ref</strong> is now fully confirmed.</p>
                <div style='text-align: center; margin: 25px 0; background-color: #f8f9fa; padding: 20px; border-radius: 8px;'>
                     <img src='$qrCodeUrl' alt='QR Code' width='180'>
                     <h3 style='margin:10px 0 0; letter-spacing: 2px;'>$ref</h3>
                </div>
                <p>Please present this QR code upon arrival for check-in.</p>

                <div style='background-color: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid #E5E7EB; margin-top: 20px;'>
                    <h4 style='margin: 0 0 10px 0; color: #111827;'>$hotel_name Contact Information</h4>
                    <p style='margin: 5px 0; font-size: 0.9em;'>📍 <strong>Address:</strong> $hotel_address</p>
                    <p style='margin: 5px 0; font-size: 0.9em;'>📞 <strong>Phone:</strong> $hotel_phone</p>
                    <p style='margin: 5px 0; font-size: 0.9em;'>📧 <strong>Email:</strong> $hotel_email</p>
                </div>

                <br>
                <p style='color: #666; font-size: 0.9em; margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;'>
                    Regards,<br>
                    <strong>$hotel_name Management</strong>
                </p>
            </div>
        </div>";

        $mail->send();
    }

    echo json_encode(['status' => 'success', 'message' => 'Booking verified and confirmed!']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
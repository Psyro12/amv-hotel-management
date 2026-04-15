<?php
// ADMIN/PHP/save_booking.php

// 1. Settings
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

// 2. Load PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../../USER/PHPMailer-master/src/Exception.php';
require '../../USER/PHPMailer-master/src/PHPMailer.php';
require '../../USER/PHPMailer-master/src/SMTP.php';

require 'db_connect.php';

session_start();

// 3. Security & Input Check
if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Get JSON Input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid data']);
    exit;
}

try {
    // --- EXTRACT DATA FROM JSON ---
    $dates = $input['dates'];
    $guest = $input['guest'];
    $rooms = $input['rooms'];
    $financial = $input;

    // Defaults
    $booking_source = isset($input['bookingSource']) ? $input['bookingSource'] : 'reservation';
    $arrival_status = isset($input['arrivalStatus']) ? $input['arrivalStatus'] : 'upcoming';

    // Guest Info
    $salutation = $guest['salutation'];
    $first_name = $guest['firstname'];
    $last_name = $guest['lastname'];
    $email = $guest['email'];
    $phone = $guest['contact'];
    $nationality = $guest['nationality'];
    $gender = $guest['gender'];
    $birthdate = $guest['birthdate'];
    $address = $guest['address'];
    $arrival_time = $guest['arrival_time'];
    $adults = (int) $guest['adults'];
    $children = (int) $guest['children'];
    $requests = "Booked by Admin (" . $_SESSION['user']['name'] . ")";

    // Dates
    $checkin_db = $dates['checkin'];
    $checkout_db = $dates['checkout'];

    // Financials
    $total_price = (float) $financial['totalPrice'];
    $amount_paid = (float) $financial['amountPaid'];
    $payment_status = $financial['paymentStatus'];
    $payment_term = $financial['paymentTerm'];
    $payment_method = $guest['payment_method'];

    // 🟢 Define Proof String for Admin Bookings
    $payment_proof = "Made by Admin";

    // Generate Reference
    $booking_reference = 'AMV-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));

    // --- START TRANSACTION ---
    $conn->begin_transaction();

    // =================================================================
    // 1. AVAILABILITY CHECK
    // =================================================================
    $sql_check = "SELECT b.id FROM bookings b 
                  JOIN booking_rooms br ON b.id = br.booking_id 
                  WHERE br.room_id = ? 
                  AND b.status IN ('confirmed', 'pending') 
                  AND b.arrival_status NOT IN ('checked_out', 'no_show', 'cancelled') 
                  AND b.check_in < ? 
                  AND b.check_out > ? 
                  LIMIT 1 FOR UPDATE";

    $stmtCheck = $conn->prepare($sql_check);
    foreach ($rooms as $room) {
        $stmtCheck->bind_param("iss", $room['id'], $checkout_db, $checkin_db);
        $stmtCheck->execute();
        if ($stmtCheck->get_result()->num_rows > 0) {
            throw new Exception("Room " . $room['name'] . " is already booked (or unavailable) for these dates.");
        }
    }
    $stmtCheck->close();

    // =================================================================
    // 2. LINK TO MOBILE APP USER
    // =================================================================
    $uid = null;
    $detected_source = 'google';

    if (!empty($email)) {
        $user_check = $conn->prepare("SELECT id, account_source FROM users WHERE email = ? LIMIT 1");
        $user_check->bind_param("s", $email);
        $user_check->execute();
        $result_user = $user_check->get_result();

        if ($result_user->num_rows > 0) {
            $row_user = $result_user->fetch_assoc();
            $uid = $row_user['id'];
            $detected_source = $row_user['account_source'] ?? 'google';
        }
        $user_check->close();
    }

    // =================================================================
    // 3. INSERT BOOKING (🟢 UPDATED)
    // =================================================================
    // Added: payment_proof column
    $stmt1 = $conn->prepare("INSERT INTO bookings (user_id, check_in, check_out, total_price, payment_method, booking_reference, status, amount_paid, payment_status, payment_term, arrival_status, booking_source, payment_proof) VALUES (?, ?, ?, ?, ?, ?, 'confirmed', ?, ?, ?, ?, ?, ?)");

    // Added: 's' to types string and $payment_proof to parameters
    $stmt1->bind_param("issdssdsssss", $uid, $checkin_db, $checkout_db, $total_price, $payment_method, $booking_reference, $amount_paid, $payment_status, $payment_term, $arrival_status, $booking_source, $payment_proof);

    if (!$stmt1->execute())
        throw new Exception("Error saving booking: " . $stmt1->error);
    $booking_id = $conn->insert_id;
    $stmt1->close();

    // =================================================================
    // 4. INSERT GUEST
    // =================================================================
    $stmt2 = $conn->prepare("INSERT INTO booking_guests (booking_id, salutation, first_name, last_name, email, phone, nationality, gender, birthdate, address, arrival_time, special_requests, adults_count, children_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt2->bind_param("isssssssssssii", $booking_id, $salutation, $first_name, $last_name, $email, $phone, $nationality, $gender, $birthdate, $address, $arrival_time, $requests, $adults, $children);

    if (!$stmt2->execute())
        throw new Exception("Error saving guest: " . $stmt2->error);
    $stmt2->close();

    // =================================================================
    // 5. INSERT ROOMS
    // =================================================================
    $stmt3 = $conn->prepare("INSERT INTO booking_rooms (booking_id, room_id, room_name, price_per_night) VALUES (?, ?, ?, ?)");

    $roomNamesString = "";
    foreach ($rooms as $room) {
        $stmt3->bind_param("iisd", $booking_id, $room['id'], $room['name'], $room['price']);
        $stmt3->execute();
        $roomNamesString .= $room['name'] . ", ";
    }
    $stmt3->close();
    $roomNamesString = rtrim($roomNamesString, ", ");

    // =================================================================
    // 5.5. RECORD TRANSACTION
    // =================================================================
    $t_status = ($payment_status === 'paid' || $amount_paid > 0) ? 'Paid' : 'Pending';
    $t_type = 'Booking';
    $t_amount = ($amount_paid > 0) ? $amount_paid : $total_price;

    $stmtT = $conn->prepare("INSERT INTO transactions (user_id, transaction_type, reference_id, amount, payment_method, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmtT->bind_param("issdss", $uid, $t_type, $booking_reference, $t_amount, $payment_method, $t_status);

    if (!$stmtT->execute()) {
        throw new Exception("Error logging transaction: " . $stmtT->error);
    }
    $stmtT->close();

    // =================================================================
    // 6. NOTIFICATIONS (System & App)
    // =================================================================
    try {
        // A. System Notification (For Admins)
        $notif_title = "New Booking";
        $notif_desc = "New " . $booking_source . " (" . $booking_reference . "): " . $first_name . " " . $last_name . " (" . $roomNamesString . ")";
        $notif_type = "booking";

        $stmt_notif = $conn->prepare("INSERT INTO system_notifications (title, description, type, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
        $stmt_notif->bind_param("sss", $notif_title, $notif_desc, $notif_type);
        $stmt_notif->execute();
        $stmt_notif->close();

        // B. GUEST NOTIFICATION (For App Users)
        if (!empty($email)) {
            $guest_msg = "Your booking $booking_reference has been confirmed by the front desk.";
            $stmt_g_notif = $conn->prepare("INSERT INTO guest_notifications (email, account_source, title, message, type, is_read, created_at) VALUES (?, ?, 'Reservation Confirmed', ?, 'booking', 0, NOW())");
            $stmt_g_notif->bind_param("sss", $email, $detected_source, $guest_msg);
            $stmt_g_notif->execute();
            $stmt_g_notif->close();
        }

    } catch (Exception $ne) {
        error_log("Notification Error: " . $ne->getMessage());
    }

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

    // =================================================================
    // 7. SEND EMAIL (Existing Logic)
    // =================================================================
    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $mail = new PHPMailer(true);
        try {
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
            $mail->addAddress($email, "$first_name $last_name");

            $mail->isHTML(true);
            $mail->Subject = "Booking Confirmation - $booking_reference";

            // 🟢 CONDITIONALLY BUILD EMAIL BODY
            if ($booking_source === 'walk-in') {
                // WALK-IN TEMPLATE (No QR Code)
                $mail->Body = "
                <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #ddd;'>
                    <div style='background-color: #2d3748; padding: 20px; text-align: center; color: white;'>
                        <h2 style='margin:0;'>WALK-IN BOOKING CONFIRMED</h2>
                    </div>
                    <div style='padding: 20px;'>
                        <p>Dear $salutation $first_name $last_name,</p>
                        <p>Thank you for choosing $hotel_name. Your walk-in booking has been successfully processed and you are now checked in. Please find your stay details below:</p>
                        
                        <div style='background: #f9f9f9; padding: 20px; border-left: 4px solid #2d3748; line-height: 1.8; margin-top: 20px;'>
                            <p style='margin: 0;'><strong>Booking Reference:</strong> $booking_reference</p>
                            <p style='margin: 0;'><strong>Check-in Date:</strong> " . date('M d, Y', strtotime($checkin_db)) . "</p>
                            <p style='margin: 0;'><strong>Check-out Date:</strong> " . date('M d, Y', strtotime($checkout_db)) . "</p>
                            <p style='margin: 0;'><strong>Rooms:</strong> $roomNamesString</p>
                            <hr style='border: 0; border-top: 1px solid #ddd; margin: 10px 0;'>
                            <p style='margin: 0;'><strong>Total Price:</strong> ₱" . number_format($total_price, 2) . "</p>
                            <p style='margin: 0;'><strong>Amount Paid:</strong> ₱" . number_format($amount_paid, 2) . "</p>
                        </div>

                        <div style='background-color: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid #E5E7EB; margin-top: 25px;'>
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
            } else {
                // RESERVATION TEMPLATE (Includes QR Code)
                $qrCodeUrl = "https://quickchart.io/qr?text=" . urlencode($booking_reference) . "&size=300&ecLevel=H";
                $mail->Body = "
                <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #ddd;'>
                    <div style='background-color: #9e8236; padding: 20px; text-align: center; color: white;'>
                        <h2 style='margin:0;'>RESERVATION CONFIRMED</h2>
                    </div>
                    <div style='padding: 20px;'>
                        <p>Dear $salutation $first_name $last_name,</p>
                        <p>Your reservation has been successfully created by our administrator. Please find your details below:</p>
                        <div style='text-align: center; margin: 25px 0; background-color: #f8f9fa; padding: 20px; border-radius: 8px;'>
                            <p style='margin-bottom: 10px; font-weight: bold; color: #555;'>SCAN FOR EXPRESS CHECK-IN</p>
                            <img src='$qrCodeUrl' alt='Booking QR Code' width='200' height='200' style='border: 4px solid #fff;'>
                            <h3 style='margin-top: 10px; color: #333; letter-spacing: 2px;'>$booking_reference</h3>
                        </div>
                        <div style='background: #f9f9f9; padding: 20px; border-left: 4px solid #9e8236; line-height: 1.8;'>
                            <p style='margin: 0;'><strong>Booking Reference:</strong> $booking_reference</p>
                            <p style='margin: 0;'><strong>Check-in:</strong> " . date('M d, Y', strtotime($checkin_db)) . "</p>
                            <p style='margin: 0;'><strong>Check-out:</strong> " . date('M d, Y', strtotime($checkout_db)) . "</p>
                            <p style='margin: 0;'><strong>Rooms:</strong> $roomNamesString</p>
                            <hr style='border: 0; border-top: 1px solid #ddd; margin: 10px 0;'>
                            <p style='margin: 0;'><strong>Total Price:</strong> ₱" . number_format($total_price, 2) . "</p>
                            <p style='margin: 0;'><strong>Amount Paid:</strong> ₱" . number_format($amount_paid, 2) . "</p>
                        </div>

                        <div style='background-color: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid #E5E7EB; margin-top: 25px;'>
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
            }

            $mail->send();
        } catch (Exception $e) {
            error_log("Admin Booking Email Error: " . $mail->ErrorInfo);
        }
    }

    echo json_encode(['status' => 'success', 'ref' => $booking_reference, 'id' => $booking_id]);

} catch (Exception $e) {
    if ($conn->errno)
        $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>
<?php
// ADMIN/PHP/update_arrival.php

// 1. PREVENT JSON CRASHES
ob_start();

require 'db_connect.php';

// Include PHPMailer
require_once '../../USER/PHPMailer-master/src/Exception.php';
require_once '../../USER/PHPMailer-master/src/PHPMailer.php';
require_once '../../USER/PHPMailer-master/src/SMTP.php';

session_start();
date_default_timezone_set('Asia/Manila');

// 2. CLEAN BUFFER
ob_clean();

header('Content-Type: application/json');

// ⚠️ PRODUCTION URL
$baseURL = "https://amvhotel.online"; 

// 3. Security Check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

// 4. Session Check
if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit;
}

// 5. Get Input
$id = $_POST['id'] ?? 0;
$action = $_POST['action'] ?? '';
$ref = "";
$guestName = "";
$guestEmail = "";
$accountSource = "google"; 

// 6. Fetch Booking Info + Account Source
$stmt_info = $conn->prepare("
    SELECT 
        b.booking_reference, 
        bg.email, 
        CONCAT(bg.first_name, ' ', bg.last_name) as name,
        u.account_source
    FROM bookings b 
    JOIN booking_guests bg ON b.id = bg.booking_id 
    LEFT JOIN users u ON bg.email = u.email 
    WHERE b.id = ?
");
$stmt_info->bind_param("i", $id);
$stmt_info->execute();
$res_info = $stmt_info->get_result();

if ($row = $res_info->fetch_assoc()) {
    $ref = $row['booking_reference'];
    $guestName = $row['name'];
    $guestEmail = $row['email'];
    if (!empty($row['account_source'])) {
        $accountSource = $row['account_source'];
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Booking ID not found.']);
    exit;
}

// FETCH HOTEL CONTACT INFO FROM DATABASE
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

// HELPER: Send Notification to App
function sendAppNotification($conn, $email, $source, $title, $message, $type) {
    if (empty($email)) return;
    $stmt = $conn->prepare("INSERT INTO guest_notifications (email, account_source, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
    $stmt->bind_param("sssss", $email, $source, $title, $message, $type);
    $stmt->execute();
    $stmt->close();
}

// 7. Handle Actions
$title = "";
$desc = "";
$type = "";
$query = "";
$app_notif_title = ""; 
$app_notif_msg = "";   

// ARRIVE ACTION
if ($action === 'arrive') {
    // Check Room Occupancy
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
    $stmt_check->bind_param("ii", $id, $id);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();

    if ($occupied_room = $res_check->fetch_assoc()) {
        echo json_encode([
            'status' => 'error',
            'message' => "🚨 Room Occupied: " . $occupied_room['name'] . " is currently occupied by another guest."
        ]);
        exit;
    }

    $query = "UPDATE bookings SET arrival_status = 'in_house' WHERE id = ?";
    $title = "Guest Checked In";
    $desc = "$guestName ($ref) has arrived.";
    $type = "booking";
    $app_notif_title = "Welcome!";
    $app_notif_msg = "You have successfully checked in. Enjoy your stay!";

    // Email Logic with Design
    if (!empty($guestEmail) && filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com'; $mail->SMTPAuth = true; $mail->Username = 'periolarren@gmail.com'; $mail->Password = 'ftvp ilfl utmq pdgg'; $mail->SMTPSecure = 'tls'; $mail->Port = 587;
            $mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
            $mail->setFrom('periolarren@gmail.com', 'AMV Hotel'); $mail->addAddress($guestEmail, $guestName); $mail->isHTML(true);
            $mail->Subject = "Welcome to $hotel_name! - $ref";
            $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #eee; border-radius: 8px; overflow: hidden;'>
                <div style='background-color: #3B82F6; padding: 20px; text-align: center; color: white;'>
                    <h2 style='margin:0;'>Welcome to $hotel_name!</h2>
                </div>
                <div style='padding: 30px; background-color: #ffffff; color: #333;'>
                    <p>Dear <strong>$guestName</strong>,</p>
                    <p>We are delighted to confirm that you have successfully checked in.</p>
                    <div style='background-color: #EFF6FF; padding: 15px; margin: 20px 0; border-left: 4px solid #3B82F6; border-radius: 4px;'>
                        <p style='margin: 0;'><strong>We hope you enjoy your stay!</strong></p>
                        <p style='margin: 5px 0; font-size: 0.9em;'>If you need anything, dial <strong>0</strong> from your room phone or visit the front desk.</p>
                    </div>
                    <div style='background-color: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid #E5E7EB; margin-top: 20px;'>
                        <h4 style='margin: 0 0 10px 0; color: #111827;'>$hotel_name Contact Information</h4>
                        <p style='margin: 5px 0; font-size: 0.9em;'>📍 <strong>Address:</strong> $hotel_address</p>
                        <p style='margin: 5px 0; font-size: 0.9em;'>📞 <strong>Phone:</strong> $hotel_phone</p>
                        <p style='margin: 5px 0; font-size: 0.9em;'>📧 <strong>Email:</strong> $hotel_email</p>
                    </div>
                    <br><p style='color: #666; font-size: 0.9em; margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;'>Regards,<br><strong>$hotel_name Management</strong></p>
                </div>
            </div>";
            $mail->send();
        } catch (Exception $e) {}
    }
} 

// CHECKOUT ACTION
elseif ($action === 'checkout') {
    $query = "UPDATE bookings SET arrival_status = 'checked_out' WHERE id = ?";
    $title = "Guest Checked Out";
    $desc = "$guestName ($ref) has checked out.";
    $type = "reminder";
    $app_notif_title = "Checked Out";
    $app_notif_msg = "Thank you for staying with us! Safe travels.";

    if (!empty($guestEmail) && filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com'; $mail->SMTPAuth = true; $mail->Username = 'periolarren@gmail.com'; $mail->Password = 'ftvp ilfl utmq pdgg'; $mail->SMTPSecure = 'tls'; $mail->Port = 587;
            $mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
            $mail->setFrom('periolarren@gmail.com', 'AMV Hotel'); $mail->addAddress($guestEmail, $guestName); $mail->isHTML(true);
            $mail->Subject = "Thank You for Staying with Us - $ref";
            $evalLink = $baseURL . "/evaluation.php?ref=" . $ref;
            $mail->Body = "
            <div style='font-family: Montserrat, Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #eee; border-radius: 8px; overflow: hidden;'>
                <div style='background-color: #10B981; padding: 25px; text-align: center; color: white;'>
                    <h2 style='margin:0; letter-spacing: 1px;'>Thank You!</h2>
                </div>
                <div style='padding: 30px; background-color: #ffffff; color: #333;'>
                    <p style='font-size: 1.1em;'>Dear <strong>$guestName</strong>,</p>
                    <p>Thank you for choosing $hotel_name. It was a pleasure hosting you.</p>
                    <p>We hope you had a comfortable stay and look forward to welcoming you back soon.</p>
                    <hr style='border: 0; border-top: 1px solid #eee; margin: 25px 0;'>
                    <div style='text-align: center;'>
                        <p style='font-weight: 600; margin-bottom: 15px;'>How was your experience?</p>
                        <p style='font-size: 0.9em; color: #666; margin-bottom: 20px;'>We'd love to hear your thoughts to help us improve.</p>
                        <a href='$evalLink' style='background-color: #10B981; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;'>Rate Your Stay</a>
                    </div>
                    <div style='background-color: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid #E5E7EB; margin-top: 30px;'>
                        <h4 style='margin: 0 0 10px 0; color: #111827;'>$hotel_name Contact Information</h4>
                        <p style='margin: 5px 0; font-size: 0.9em;'>📍 <strong>Address:</strong> $hotel_address</p>
                        <p style='margin: 5px 0; font-size: 0.9em;'>📞 <strong>Phone:</strong> $hotel_phone</p>
                        <p style='margin: 5px 0; font-size: 0.9em;'>📧 <strong>Email:</strong> $hotel_email</p>
                    </div>
                    <p style='margin-top: 30px; font-size: 0.85em; color: #888; text-align: center; border-top: 1px solid #eee; padding-top: 20px;'>Safe travels,<br><strong>$hotel_name Management</strong></p>
                </div>
            </div>";
            $mail->send();
        } catch (Exception $e) {}
    }
} 

// CANCEL ACTION
elseif ($action === 'cancel') {
    $query = "UPDATE bookings SET status = 'cancelled', arrival_status = 'cancelled' WHERE id = ?";
    $tStmt = $conn->prepare("UPDATE transactions SET status = 'Cancelled' WHERE reference_id = ?");
    $tStmt->bind_param("s", $ref);
    $tStmt->execute();
    $tStmt->close();
    $title = "Booking Cancelled";
    $desc = "Admin cancelled reservation for $guestName ($ref).";
    $type = "cancel";
    $app_notif_title = "Booking Cancelled";
    $app_notif_msg = "Your reservation $ref has been cancelled by the admin.";

    if (!empty($guestEmail) && filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com'; $mail->SMTPAuth = true; $mail->Username = 'periolarren@gmail.com'; $mail->Password = 'ftvp ilfl utmq pdgg'; $mail->SMTPSecure = 'tls'; $mail->Port = 587;
            $mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
            $mail->setFrom('periolarren@gmail.com', 'AMV Hotel'); $mail->addAddress($guestEmail, $guestName); $mail->isHTML(true);
            $mail->Subject = "Booking Cancelled - Reference: $ref";
            $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #eee; border-radius: 8px; overflow: hidden;'>
                <div style='background-color: #EF4444; padding: 20px; text-align: center; color: white;'>
                    <h2 style='margin:0;'>Reservation Cancelled</h2>
                </div>
                <div style='padding: 30px; background-color: #ffffff; color: #333;'>
                    <p>Dear <strong>$guestName</strong>,</p>
                    <p>We are writing to confirm that your reservation with AMV Hotel has been cancelled.</p>
                    <div style='background-color: #FEF2F2; padding: 15px; margin: 20px 0; border-left: 4px solid #EF4444; border-radius: 4px;'>
                        <p style='margin: 5px 0;'><strong>Booking Reference:</strong> $ref</p>
                        <p style='margin: 5px 0; color: #DC2626;'><strong>Status:</strong> Cancelled by Admin</p>
                    </div>
                    <p>If you did not request this cancellation or believe this is an error, please contact our front desk immediately.</p>
                    <div style='background-color: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid #E5E7EB; margin-top: 20px;'>
                        <h4 style='margin: 0 0 10px 0; color: #111827;'>$hotel_name Contact Information</h4>
                        <p style='margin: 5px 0; font-size: 0.9em;'>📍 <strong>Address:</strong> $hotel_address</p>
                        <p style='margin: 5px 0; font-size: 0.9em;'>📞 <strong>Phone:</strong> $hotel_phone</p>
                        <p style='margin: 5px 0; font-size: 0.9em;'>📧 <strong>Email:</strong> $hotel_email</p>
                    </div>
                    <br><p style='color: #666; font-size: 0.9em; margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;'>Regards,<br><strong>$hotel_name Management</strong></p>
                </div>
            </div>";
            $mail->send();
        } catch (Exception $e) {}
    }
}

// NO-SHOW ACTION
elseif ($action === 'no_show') {
    $query = "UPDATE bookings SET arrival_status = 'no_show' WHERE id = ?";
    $title = "Booking Marked No-Show";
    $desc = "Admin marked $guestName ($ref) as No-Show.";
    $type = "cancel";
    $app_notif_title = "No-Show";
    $app_notif_msg = "Your booking was marked as No-Show because you did not arrive.";

    if (!empty($guestEmail) && filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com'; $mail->SMTPAuth = true; $mail->Username = 'periolarren@gmail.com'; $mail->Password = 'ftvp ilfl utmq pdgg'; $mail->SMTPSecure = 'tls'; $mail->Port = 587;
            $mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
            $mail->setFrom('periolarren@gmail.com', 'AMV Hotel'); $mail->addAddress($guestEmail, $guestName); $mail->isHTML(true);
            $mail->Subject = "Notification: No-Show Status - $ref";
            $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #eee; border-radius: 8px; overflow: hidden;'>
                <div style='background-color: #d9534f; padding: 20px; color: white; text-align: center;'>
                    <h2 style='margin:0;'>Reservation Update</h2>
                </div>
                <div style='padding: 30px; background-color: #ffffff; color: #333; line-height: 1.6;'>
                    <p>Dear <strong>$guestName</strong>,</p>
                    <p>This is a formal notification regarding your reservation (Reference: <strong>$ref</strong>) at $hotel_name. As you did not arrive, your booking has been marked as No-Show.</p>
                    <div style='background: #fdf2f2; border-left: 5px solid #d9534f; padding: 15px; margin: 20px 0;'>
                        <p style='margin:0; color: #b94a48;'><strong>Please Note:</strong> The reserved room has been released.</p>
                    </div>
                    <div style='background-color: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid #E5E7EB; margin-top: 20px;'>
                        <h4 style='margin: 0 0 10px 0; color: #111827;'>$hotel_name Contact Information</h4>
                        <p style='margin: 5px 0; font-size: 0.9em;'>📍 <strong>Address:</strong> $hotel_address</p>
                        <p style='margin: 5px 0; font-size: 0.9em;'>📞 <strong>Phone:</strong> $hotel_phone</p>
                        <p style='margin: 5px 0; font-size: 0.9em;'>📧 <strong>Email:</strong> $hotel_email</p>
                    </div>
                    <br><p style='color: #666; font-size: 0.9em; margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;'>Regards,<br><strong>$hotel_name Management</strong></p>
                </div>
            </div>";
            $mail->send();
        } catch (Exception $e) {}
    }
}

// SMART EXTEND ACTION
elseif ($action === 'extend') {
    if (!isset($_POST['new_checkout']) || empty($_POST['new_checkout'])) {
        echo json_encode(['status' => 'error', 'message' => 'New checkout date missing.']);
        exit;
    }
    $new_checkout = $_POST['new_checkout'];
    $extension_payment = $_POST['extension_payment'] ?? 'pay_later';
    $ignore_conflicts = isset($_POST['ignore_conflicts']) && $_POST['ignore_conflicts'] === '1';
    $room_swaps = isset($_POST['room_swaps']) ? json_decode($_POST['room_swaps'], true) : [];

    // 1. Fetch All Rooms
    $stmt_details = $conn->prepare("SELECT b.check_in, b.check_out, b.total_price, b.payment_method, b.user_id, br.room_id, br.room_name, br.price_per_night FROM bookings b JOIN booking_rooms br ON b.id = br.booking_id WHERE b.id = ?");
    $stmt_details->bind_param("i", $id);
    $stmt_details->execute();
    $res_details = $stmt_details->get_result();
    $booking_rooms = [];
    $old_checkout = "";
    $total_original_price = 0;
    $payment_method = "N/A";
    $guest_user_id = 0;
    while ($row = $res_details->fetch_assoc()) {
        $booking_rooms[] = $row;
        $old_checkout = $row['check_out'];
        $total_original_price = $row['total_price'];
        $payment_method = $row['payment_method'];
        $guest_user_id = $row['user_id'];
    }

    // 2. Conflict Check
    $conflicts = [];
    foreach ($booking_rooms as $room) {
        if (isset($room_swaps[$room['room_id']])) continue;
        $check_sql = "SELECT b.id FROM bookings b JOIN booking_rooms br ON b.id = br.booking_id WHERE br.room_id = ? AND b.status IN ('confirmed', 'pending') AND b.arrival_status NOT IN ('no_show', 'cancelled', 'checked_out') AND b.id != ? AND (b.check_in < ? AND b.check_out > ?) LIMIT 1";
        $stmt_check = $conn->prepare($check_sql);
        $stmt_check->bind_param("iiss", $room['room_id'], $id, $new_checkout, $old_checkout);
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows > 0) $conflicts[] = $room;
    }

    if (!empty($conflicts) && !$ignore_conflicts) {
        $alt_sql = "SELECT id, name, price, image_path FROM rooms WHERE is_active = 1 AND id NOT IN (SELECT br.room_id FROM booking_rooms br JOIN bookings b ON br.booking_id = b.id WHERE b.status IN ('confirmed', 'pending') AND b.arrival_status NOT IN ('no_show', 'cancelled', 'checked_out') AND (b.check_in < ? AND b.check_out > ?))";
        $stmt_alt = $conn->prepare($alt_sql);
        $stmt_alt->bind_param("ss", $new_checkout, $old_checkout);
        $stmt_alt->execute();
        $res_alt = $stmt_alt->get_result();
        $alternatives = [];
        while($alt = $res_alt->fetch_assoc()) $alternatives[] = $alt;

        echo json_encode(['status' => 'conflict', 'conflicted_rooms' => $conflicts, 'alternatives' => $alternatives, 'message' => 'Room Conflict Detected']);
        exit;
    }

    // 3. Process Transaction
    $conn->begin_transaction();
    try {
        $extra_nights = (new DateTime($old_checkout))->diff(new DateTime($new_checkout))->days;
        if ($extra_nights <= 0) throw new Exception("Extension must be at least 1 night.");

        $added_cost = 0;
        foreach ($booking_rooms as $room) {
            $curr_id = $room['room_id'];
            $price = floatval($room['price_per_night']);
            if (isset($room_swaps[$curr_id])) {
                $swp = $room_swaps[$curr_id];
                $stmt_swap = $conn->prepare("UPDATE booking_rooms SET room_id = ?, room_name = ?, price_per_night = ? WHERE booking_id = ? AND room_id = ?");
                $stmt_swap->bind_param("isdii", $swp['id'], $swp['name'], $swp['price'], $id, $curr_id);
                $stmt_swap->execute();
                $price = floatval($swp['price']);
            }
            $added_cost += ($extra_nights * $price);
        }

        $new_total = floatval($total_original_price) + $added_cost;
        $sql_upd = "UPDATE bookings SET check_out = ?, total_price = ?";
        if ($extension_payment === 'pay_now' || $extension_payment === 'pay_full') {
            $sql_upd .= ", amount_paid = amount_paid + ?, payment_status = 'paid'";
            $upd_stmt = $conn->prepare($sql_upd . " WHERE id = ?");
            $upd_stmt->bind_param("sddi", $new_checkout, $new_total, $added_cost, $id);
        } else {
            $sql_upd .= ", payment_status = 'partial'";
            $upd_stmt = $conn->prepare($sql_upd . " WHERE id = ?");
            $upd_stmt->bind_param("sdi", $new_checkout, $new_total, $id);
        }
        $upd_stmt->execute();

        // 🟢 NEW: RECORD IN TRANSACTIONS TABLE
        $is_full = ($extension_payment === 'pay_now' || $extension_payment === 'pay_full');
        $trans_status = $is_full ? 'Extended - Fully Paid' : 'Extended - Partially Paid';
        $user_id = $guest_user_id;

        // 🟢 DEDUPLICATION CHECK: Prevent triple recording
        $check_dup = $conn->prepare("SELECT id FROM transactions WHERE reference_id = ? AND status = ? AND amount = ? AND created_at > (NOW() - INTERVAL 10 SECOND)");
        $check_dup->bind_param("ssd", $ref, $trans_status, $added_cost);
        $check_dup->execute();
        if ($check_dup->get_result()->num_rows === 0) {
            $ins_trans = $conn->prepare("INSERT INTO transactions (user_id, transaction_type, reference_id, amount, payment_method, status, created_at) VALUES (?, 'Booking', ?, ?, ?, ?, NOW())");
            $ins_trans->bind_param("isdss", $user_id, $ref, $added_cost, $payment_method, $trans_status);
            $ins_trans->execute();
        }

        // Notifications
        $stmt_notif = $conn->prepare("INSERT INTO system_notifications (title, description, type, is_read, created_at) VALUES ('Booking Extended', ?, 'booking', 0, NOW())");
        $notif_d = "Stay for $guestName ($ref) extended until $new_checkout.";
        $stmt_notif->bind_param("s", $notif_d);
        $stmt_notif->execute();

        sendAppNotification($conn, $guestEmail, $accountSource, "Stay Extended", "Your stay has been extended until $new_checkout.", "booking");

        // EMAIL with Design
        if (!empty($guestEmail) && filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com'; $mail->SMTPAuth = true; $mail->Username = 'periolarren@gmail.com'; $mail->Password = 'ftvp ilfl utmq pdgg'; $mail->SMTPSecure = 'tls'; $mail->Port = 587;
                $mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
                $mail->setFrom('periolarren@gmail.com', 'AMV Hotel'); $mail->addAddress($guestEmail, $guestName); $mail->isHTML(true);
                $mail->Subject = "Booking Extended - $ref";
                $newT = number_format($new_total, 2);
                $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #eee; border-radius: 8px; overflow: hidden;'>
                    <div style='background-color: #8B5CF6; padding: 20px; text-align: center; color: white;'>
                        <h2 style='margin:0;'>Stay Extended</h2>
                    </div>
                    <div style='padding: 30px; background-color: #ffffff; color: #333;'>
                        <p>Dear <strong>$guestName</strong>,</p>
                        <p>Your booking (Reference: <strong>$ref</strong>) has been successfully extended.</p>
                        <div style='background-color: #F3F4F6; padding: 15px; border-radius: 6px; margin: 20px 0;'>
                            <p style='margin: 5px 0;'><strong>New Checkout Date:</strong> $new_checkout</p>
                            <p style='margin: 5px 0; color: #111827;'><strong>Updated Total Price:</strong> ₱$newT</p>
                        </div>
                        <p>We are happy to have you with us for a little longer!</p>
                        <div style='background-color: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid #E5E7EB; margin-top: 20px;'>
                            <h4 style='margin: 0 0 10px 0; color: #111827;'>$hotel_name Contact Information</h4>
                            <p style='margin: 5px 0; font-size: 0.9em;'>📍 <strong>Address:</strong> $hotel_address</p>
                            <p style='margin: 5px 0; font-size: 0.9em;'>📞 <strong>Phone:</strong> $hotel_phone</p>
                            <p style='margin: 5px 0; font-size: 0.9em;'>📧 <strong>Email:</strong> $hotel_email</p>
                        </div>
                        <br><p style='color: #666; font-size: 0.9em; margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;'>Regards,<br><strong>$hotel_name Management</strong></p>
                    </div>
                </div>";
                $mail->send();
            } catch (Exception $e) {}
        }

        $conn->commit();
        echo json_encode(['status' => 'success', 'new_total' => number_format($new_total, 2)]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// APPLY LATE FEE
elseif ($action === 'apply_late_fee') {
    $amount = floatval($_POST['amount'] ?? 0);
    $sql = "UPDATE bookings SET total_price = total_price + ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("di", $amount, $id);
    if ($stmt->execute()) echo json_encode(['status' => 'success']);
    else echo json_encode(['status' => 'error', 'message' => 'DB error']);
    $stmt->close();
    exit;
}

// SETTLE PAYMENT
elseif ($action === 'settle_payment') {
    $stmt_get = $conn->prepare("SELECT total_price FROM bookings WHERE id = ?");
    $stmt_get->bind_param("i", $id);
    $stmt_get->execute();
    if ($row_p = $stmt_get->get_result()->fetch_assoc()) {
        $tp = $row_p['total_price'];
        $stmt_pay = $conn->prepare("UPDATE bookings SET amount_paid = ?, payment_status = 'paid' WHERE id = ?");
        $stmt_pay->bind_param("di", $tp, $id);
        if ($stmt_pay->execute()) echo json_encode(['status' => 'success']);
        else echo json_encode(['status' => 'error', 'message' => 'Update failed']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Not found']);
    }
    exit;
}

// 8. Execute Simple Actions
if (!empty($query)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        if (!empty($title)) {
            $stmt_notif = $conn->prepare("INSERT INTO system_notifications (title, description, type, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
            $stmt_notif->bind_param("sss", $title, $desc, $type);
            $stmt_notif->execute();
        }
        if (!empty($app_notif_title)) {
            sendAppNotification($conn, $guestEmail, $accountSource, $app_notif_title, $app_notif_msg, "booking");
        }
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $conn->error]);
    }
} else {
     echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}
?>
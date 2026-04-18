<?php
// guest_reschedule.php
session_start();
header('Content-Type: application/json');
require 'db_connect.php';

// 🟢 LOAD PHPMAILER
require '../../USER/PHPMailer-master/src/Exception.php';
require '../../USER/PHPMailer-master/src/PHPMailer.php';
require '../../USER/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 1. INPUT VALIDATION
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request Method']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

$ref         = $input['booking_reference'] ?? '';
$newCheckIn  = $input['new_check_in'] ?? '';
$newCheckOut = $input['new_check_out'] ?? '';
$newRoomId   = $input['new_room_id'] ?? null; // Optional: Only present if user selected a new room

if (empty($ref) || empty($newCheckIn) || empty($newCheckOut)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields.']);
    exit();
}

// 2. FETCH CURRENT BOOKING DETAILS
$sql = "SELECT b.id, b.user_id, b.check_in, b.check_out, b.created_at, b.status, b.arrival_status, b.total_price, b.payment_method, b.booking_source,
               br.room_id, r.price as price_per_night, r.name as room_name,
               bg.first_name, bg.last_name, bg.email,
               u.account_source
        FROM bookings b
        JOIN booking_rooms br ON b.id = br.booking_id
        JOIN rooms r ON br.room_id = r.id
        JOIN booking_guests bg ON b.id = bg.booking_id
        LEFT JOIN users u ON b.user_id = u.id
        WHERE b.booking_reference = ? LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $ref);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    echo json_encode(['status' => 'error', 'message' => 'Booking not found.']);
    exit();
}

// 🟢 NEW: PREVENT RESCHEDULING IF GUEST IS ALREADY IN-HOUSE
if ($booking['arrival_status'] === 'in_house') {
    echo json_encode(['status' => 'error', 'message' => 'Rescheduling Denied: Guest is already checked in.']);
    exit();
}

// 3. ENFORCE 3-DAY GRACE PERIOD (Skip this check if we are just finalizing the room swap)
if (!$newRoomId) {
    $createdDateStr = !empty($booking['created_at']) ? $booking['created_at'] : $booking['check_in'];
    $createdDate = new DateTime($createdDateStr);
    $now = new DateTime();
    $hoursPassed = ($createdDate->diff($now)->days * 24) + $createdDate->diff($now)->h;

    if ($hoursPassed > 72) {
        echo json_encode(['status' => 'error', 'message' => 'Rescheduling Denied: The 3-day grace period has expired.']);
        exit();
    }
}

// 4. DETERMINE TARGET ROOM (Current vs New)
$targetRoomId = $newRoomId ? $newRoomId : $booking['room_id'];

// If swapping rooms, get the NEW price
$pricePerNight = $booking['price_per_night'];
$newRoomName = $booking['room_name'];

if ($newRoomId) {
    $stmtRoom = $conn->prepare("SELECT name, price FROM rooms WHERE id = ?");
    $stmtRoom->bind_param("i", $newRoomId);
    $stmtRoom->execute();
    $roomData = $stmtRoom->get_result()->fetch_assoc();
    $pricePerNight = $roomData['price'];
    $newRoomName = $roomData['name'];
}

// 5. CHECK AVAILABILITY
$checkSql = "SELECT b.id, b.check_out FROM bookings b
             JOIN booking_rooms br ON b.id = br.booking_id
             WHERE br.room_id = ? 
             AND b.status IN ('confirmed', 'pending') 
             AND b.arrival_status NOT IN ('checked_out', 'no_show', 'cancelled')
             AND b.id != ? 
             AND (b.check_in < ? AND b.check_out > ?) -- Overlap
             LIMIT 1";

$stmtCheck = $conn->prepare($checkSql);
$stmtCheck->bind_param("iiss", $targetRoomId, $booking['id'], $newCheckOut, $newCheckIn);
$stmtCheck->execute();
$conflictRes = $stmtCheck->get_result();
$isCurrentRoomConflict = ($conflictRes->num_rows > 0);

// --- NEW FLOW: ALWAYS SHOW ROOM SELECTION FIRST ---
// If the user hasn't explicitly selected a room yet, we don't update.
// We just return the availability status and the list of rooms.
if (!$newRoomId) {
    // Find ALL available rooms (including current if it's not in conflict)
    // 🟢 REMOVED LIMIT to show all rooms (like Room 211)
    $altSql = "SELECT r.id, r.name, r.price, r.image_path, r.type, r.capacity, r.bed_type, r.size 
               FROM rooms r
               WHERE r.is_active = 1
               AND r.id NOT IN (
                   SELECT br.room_id FROM booking_rooms br
                   JOIN bookings b ON br.booking_id = b.id
                   WHERE b.status IN ('confirmed', 'pending')
                   AND b.arrival_status NOT IN ('checked_out', 'no_show', 'cancelled')
                   AND b.id != ?
                   AND (b.check_in < ? AND b.check_out > ?)
               ) 
               ORDER BY r.name ASC";
               
    $stmtAlt = $conn->prepare($altSql);
    $stmtAlt->bind_param("iss", $booking['id'], $newCheckOut, $newCheckIn);
    $stmtAlt->execute();
    $altRes = $stmtAlt->get_result();
    
    $alternatives = [];
    while($row = $altRes->fetch_assoc()) {
        $alternatives[] = $row;
    }

    echo json_encode([
        'status' => 'selection_required',
        'is_conflict' => $isCurrentRoomConflict,
        'message' => $isCurrentRoomConflict 
            ? "Room '{$booking['room_name']}' is occupied. Please select another." 
            : "Room '{$booking['room_name']}' is available, but you can also choose others.",
        'next_date' => $isCurrentRoomConflict ? $conflictRes->fetch_assoc()['check_out'] : null,
        'alternatives' => $alternatives,
        'current_room_id' => $booking['room_id']
    ]);
    exit();
}

// 6. UPDATE DATABASE (Only runs if $newRoomId is present)
$d1 = new DateTime($newCheckIn);
$d2 = new DateTime($newCheckOut);
$nights = $d1->diff($d2)->days;
if ($nights < 1) $nights = 1;

$newTotal = $nights * $pricePerNight;

// Update Bookings
$updateBooking = $conn->prepare("UPDATE bookings SET check_in=?, check_out=?, total_price=? WHERE id=?");
$updateBooking->bind_param("ssdi", $newCheckIn, $newCheckOut, $newTotal, $booking['id']);
$updateBooking->execute();

// 🟢 NEW: RECORD IN TRANSACTIONS TABLE
$trans_type = 'Booking';
$trans_status = 'Rescheduled';
$payment_method = $booking['payment_method'] ?? 'N/A'; // 🟢 Use original payment method
$user_id = $booking['user_id']; // 🟢 FIX: Use guest ID, not admin ID

// 🟢 DEDUPLICATION CHECK: Prevent multiple recording
$check_dup = $conn->prepare("SELECT id FROM transactions WHERE reference_id = ? AND status = ? AND amount = ? AND created_at > (NOW() - INTERVAL 10 SECOND)");
$check_dup->bind_param("ssd", $ref, $trans_status, $newTotal);
$check_dup->execute();
if ($check_dup->get_result()->num_rows === 0) {
    $ins_trans = $conn->prepare("INSERT INTO transactions (user_id, transaction_type, reference_id, amount, payment_method, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $ins_trans->bind_param("issdss", $user_id, $trans_type, $ref, $newTotal, $payment_method, $trans_status);
    $ins_trans->execute();
}

// Update Room ID if changed
if ($newRoomId) {
    $updateRoom = $conn->prepare("UPDATE booking_rooms SET room_id=?, room_name=? WHERE booking_id=? AND room_id=?");
    $updateRoom->bind_param("isii", $newRoomId, $newRoomName, $booking['id'], $booking['room_id']);
    $updateRoom->execute();
}

// 🟢 7. NOTIFICATIONS & EMAIL
if (!empty($booking['email'])) {
    $notif_title = "Booking Rescheduled";
    $notif_msg = "Your booking $ref has been rescheduled to $newCheckIn - $newCheckOut.";
    $source = $booking['account_source'] ?? 'email';

    // 🟢 Use centralized notification helper
    require_once 'notification_helper.php';
    sendAppNotification($conn, $booking['email'], $source, $notif_title, $notif_msg, 'booking');
}

// Trigger real-time updates for dashboard
$conn->query("UPDATE system_updates SET last_updated = CURRENT_TIMESTAMP WHERE category IN ('bookings', 'notifications')");

// 🟢 FETCH HOTEL CONTACT INFO FROM DATABASE
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

// Send Email (ONLY FOR WEB/ADMIN GUESTS)
if (!empty($booking['email']) && ($booking['booking_source'] ?? 'online') !== 'mobile_app') {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'periolarren@gmail.com'; 
        $mail->Password = 'ftvp ilfl utmq pdgg'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $mail->setFrom('periolarren@gmail.com', "$hotel_name Reservations");
        $mail->addAddress($booking['email'], $booking['first_name'] . ' ' . $booking['last_name']);

        $mail->isHTML(true);
        $mail->Subject = "Reschedule Confirmation - Booking " . $ref;
        
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #eee; border-radius: 8px; overflow: hidden;'>
            <div style='background-color: #3B82F6; padding: 25px; text-align: center; color: white;'>
                <h2 style='margin:0; letter-spacing: 1px;'>Reschedule Confirmation</h2>
            </div>
            <div style='padding: 30px; background-color: #ffffff;'>
                <p>Dear <strong>{$booking['first_name']}</strong>,</p>
                <p>Your booking <strong>$ref</strong> has been successfully rescheduled.</p>
                <div style='background-color: #F3F4F6; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #3B82F6;'>
                    <p style='margin: 5px 0;'><strong>New Dates:</strong> $newCheckIn to $newCheckOut</p>
                    <p style='margin: 5px 0;'><strong>Room:</strong> $newRoomName</p>
                    <p style='margin: 5px 0; color: #111827;'><strong>New Total Price:</strong> ₱" . number_format($newTotal, 2) . "</p>
                </div>
                <p>We look forward to hosting you soon!</p>
                
                <div style='background-color: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid #E5E7EB; margin-top: 30px;'>
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
    } catch (Exception $e) {
        // Silently log or ignore email errors for now to not break the response
    }
}

echo json_encode([
    'status' => 'success', 
    'message' => 'Reschedule Successful!',
    'new_total' => $newTotal
]);
?>
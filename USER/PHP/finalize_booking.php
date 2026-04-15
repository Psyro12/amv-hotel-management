<?php
// USER/PHP/finalize_booking.php

// 1. Output Buffering
ob_start();

// 2. SECURE SESSION SETTINGS
if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => true, // Ensure TRUE for production
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require 'db_connect.php';

// 3. 🔴 CSRF PROTECTION (Critical)
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("Security Violation: Invalid Request.");
}

// 4. ACCESS CONTROL
if (!isset($_SESSION['temp_booking'])) {
    header("Location: index.php");
    exit;
}

$data = $_SESSION['temp_booking'];

// 🟢 START TRANSACTION
$conn->begin_transaction();

try {
    // 5. 🟢 PENDING BOOKING LIMIT CHECK
    $email_to_check = $data['email'];
    if (!empty($email_to_check)) {
        $stmt_check = $conn->prepare("SELECT COUNT(*) FROM bookings b JOIN booking_guests bg ON b.id = bg.booking_id WHERE bg.email = ? AND b.status = 'pending'");
        $stmt_check->bind_param("s", $email_to_check);
        $stmt_check->execute();
        $stmt_check->bind_result($pending_count);
        $stmt_check->fetch();
        $stmt_check->close();

        if ($pending_count >= 4) {
            throw new Exception("LIMIT_EXCEEDED");
        }
    }

    // 6. 🟢 FINAL AVAILABILITY RE-CHECK (Prevent double-booking)
    $checkIn = $data['checkin'];
    $checkOut = $data['checkout'];
    if (isset($data['selected_rooms']) && is_array($data['selected_rooms'])) {
        foreach ($data['selected_rooms'] as $room) {
            $roomId = intval($room['id']);
            $sql_avail = "SELECT b.id FROM bookings b 
                          JOIN booking_rooms br ON b.id = br.booking_id 
                          WHERE br.room_id = ? 
                          AND b.status IN ('confirmed', 'pending') 
                          AND b.arrival_status != 'checked_out' 
                          AND (? < b.check_out AND ? > b.check_in) 
                          LIMIT 1 FOR UPDATE";
            $stmtAvail = $conn->prepare($sql_avail);
            $stmtAvail->bind_param("iss", $roomId, $checkIn, $checkOut);
            $stmtAvail->execute();
            if ($stmtAvail->get_result()->num_rows > 0) {
                throw new Exception("ROOM_TAKEN");
            }
            $stmtAvail->close();
        }
    }

    // 7. HANDLE FILE UPLOAD (SECURED)
    $proofFilename = null;
    $paymentRef = $_POST['payment_reference'] ?? null;

    if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../../room_includes/uploads/receipts/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $fileTmpPath = $_FILES['receipt_image']['tmp_name'];
        $fileName = $_FILES['receipt_image']['name'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'jfif'];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $fileTmpPath);
        finfo_close($finfo);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

        if ($_FILES['receipt_image']['size'] > 5 * 1024 * 1024) throw new Exception("FILE_TOO_LARGE");

        if (in_array($fileExt, $allowedExts) && in_array($mimeType, $allowedMimes)) {
            $proofFilename = 'proof_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
            if (!move_uploaded_file($fileTmpPath, $uploadDir . $proofFilename)) throw new Exception("UPLOAD_FAILED");
        } else {
            throw new Exception("INVALID_FILE");
        }
    }

    // 8. Prepare Financials
    $totalPrice = floatval($data['total_price']);
    $method = $data['payment_method'];
    $term = $data['payment_term'];
    $amountPaid = 0;
    $paymentStatus = 'unpaid';

    if ($method !== 'Cash') {
        $amountPaid = isset($_POST['actual_amount']) ? floatval($_POST['actual_amount']) : (($term === 'partial') ? ($totalPrice / 2) : $totalPrice);
        $paymentStatus = ($term === 'partial') ? 'partial' : (($amountPaid >= $totalPrice) ? 'paid' : 'partial');
    }

    // 9. Generate Reference
    $ref = 'AMV-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));

    // 10. Get User ID
    $email = $data['email'];
    $userId = null; 
    $uCheck = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $uCheck->bind_param("s", $email);
    $uCheck->execute();
    $uRes = $uCheck->get_result();
    if ($uRes->num_rows > 0) $userId = $uRes->fetch_assoc()['id'];
    $uCheck->close();

    // 11. INSERT BOOKING
    $status = 'pending'; 
    $bookSql = "INSERT INTO bookings (booking_reference, user_id, check_in, check_out, total_price, amount_paid, payment_method, payment_status, payment_term, status, payment_proof, payment_reference, created_at, booking_source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'online')";
    $stmt = $conn->prepare($bookSql);
    $stmt->bind_param("sissddssssss", $ref, $userId, $checkIn, $checkOut, $totalPrice, $amountPaid, $method, $paymentStatus, $term, $status, $proofFilename, $paymentRef);
    if (!$stmt->execute()) throw new Exception("BOOKING_INSERT_FAILED");
    $bookingId = $stmt->insert_id;
    $stmt->close();

    // 12. LOG TRANSACTION
    $transAmount = ($method === 'Cash') ? $totalPrice : $amountPaid; 
    $sqlTrans = "INSERT INTO transactions (user_id, transaction_type, reference_id, amount, payment_method, status, created_at) VALUES (?, 'Booking', ?, ?, ?, 'Pending', NOW())";
    $stmtT = $conn->prepare($sqlTrans);
    $stmtT->bind_param("isds", $userId, $ref, $transAmount, $method);
    if (!$stmtT->execute()) error_log("Transaction Log Failed: " . $stmtT->error);
    $stmtT->close();

    // 13. INSERT GUEST DETAILS
    $guestSql = "INSERT INTO booking_guests (booking_id, first_name, last_name, email, phone, nationality, gender, birthdate, address, adults_count, children_count, salutation, special_requests, arrival_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $gStmt = $conn->prepare($guestSql);
    $firstName = htmlspecialchars($data['first_name']);
    $lastName = htmlspecialchars($data['last_name']);
    $gStmt->bind_param("issssssssiisss", 
        $bookingId, 
        $firstName, 
        $lastName, 
        $email, 
        $data['contact_number'], 
        $data['nationality'], 
        $data['gender'], 
        $data['birthdate'], 
        $data['address'], 
        $data['adults'], 
        $data['children'], 
        $data['salutation'],
        $data['requests'],
        $data['arrival_time']
    );
    $gStmt->execute();
    $gStmt->close();

    // 14. INSERT ROOMS
    if (isset($data['selected_rooms']) && is_array($data['selected_rooms'])) {
        $roomSql = "INSERT INTO booking_rooms (booking_id, room_id, room_name, price_per_night) VALUES (?, ?, ?, ?)";
        $rStmt = $conn->prepare($roomSql);
        foreach ($data['selected_rooms'] as $room) {
            $rid = intval($room['id']); $rname = htmlspecialchars($room['name']); $rprice = floatval($room['price']);
            $rStmt->bind_param("iisd", $bookingId, $rid, $rname, $rprice);
            $rStmt->execute();
        }
        $rStmt->close();
    }

    $conn->commit();
    unset($_SESSION['temp_booking']);
    $_SESSION['booking_success_ref'] = $ref;
    header("Location: confirmation.php");
    exit;

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    $msg = $e->getMessage();

    if ($msg === 'LIMIT_EXCEEDED') {
        header("Location: check_availability.php?error=too_many_pending");
        exit;
    }

    if ($msg === 'ROOM_TAKEN') {
        // 🟢 DISPLAY ERROR PAGE INSTEAD OF REDIRECT
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Room Unavailable - AMV Hotel</title>
            <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
            <style>
                body { font-family: 'Montserrat', sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
                .error-box { background: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); text-align: center; max-width: 450px; width: 90%; }
                .error-icon { font-size: 4rem; color: #e74c3c; margin-bottom: 20px; }
                h2 { color: #333; margin-bottom: 15px; }
                p { color: #666; line-height: 1.6; margin-bottom: 30px; }
                .btn-back { background: #9e8236; color: #fff; text-decoration: none; padding: 15px 30px; border-radius: 8px; font-weight: 700; text-transform: uppercase; display: inline-block; transition: 0.3s; }
                .btn-back:hover { background: #8c7330; transform: translateY(-2px); }
            </style>
        </head>
        <body>
            <div class="error-box">
                <div class="error-icon"><i class="fas fa-calendar-times"></i></div>
                <h2>Room Already Booked</h2>
                <p>We're sorry, but one of your selected rooms was just reserved by another guest a few moments ago. Please return to the room selection page to choose an alternative.</p>
                <a href="select_rooms.php" class="btn-back">Back to Room Selection</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    error_log("Booking Error: " . $msg);
    die("An error occurred during booking. Error Code: " . $msg);
}

$conn->close();
?>
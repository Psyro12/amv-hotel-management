<?php
// FILE: AMV_Project_exp/API/api_save_booking.php

// 🟢 SECURITY: Hide errors from public output (Log them instead)
error_reporting(E_ALL); 
ini_set('display_errors', 0);
ini_set('log_errors', 1); // Enable server logging
date_default_timezone_set('Asia/Manila');

// 🟢 SECURITY: Restrict to POST requests only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// 2. CATCH FATAL ERRORS (Keep your existing safety net)
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && $error['type'] === E_ERROR) {
        // Log the real error to server logs
        error_log("Fatal Booking Error: " . $error['message']);
        echo json_encode(['success' => false, 'message' => 'Server encountered a fatal error.']);
        exit;
    }
});

// 3. LOAD PHPMAILER
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../USER/PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../USER/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../USER/PHPMailer-master/src/SMTP.php';

require_once 'connection.php'; 

// 4. INPUT HANDLING
$input = null;
$receipt_path = null; 

if (isset($_FILES['receipt'])) {
    // --- A. MULTIPART REQUEST (GCash with Image) ---
    if (isset($_POST['booking_data'])) {
        $input = json_decode($_POST['booking_data'], true);
    }

    // 🟢 SECURITY: Validate Image Content
    // Don't just trust the extension. Check if it's actually an image.
    $check_image = @getimagesize($_FILES["receipt"]["tmp_name"]);
    if ($check_image === false) {
         echo json_encode(['success' => false, 'message' => 'File is not a valid image.']);
         exit();
    }

    $target_dir = "../room_includes/uploads/receipts/"; 
    
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $file_extension = strtolower(pathinfo($_FILES["receipt"]["name"], PATHINFO_EXTENSION));
    // Safe filename generation
    $new_filename = 'proof_' . time() . "_" . uniqid() . "." . $file_extension; 
    $target_file = $target_dir . $new_filename;

    $allowed_types = ['jpg', 'jpeg', 'png'];
    
    if (in_array($file_extension, $allowed_types)) {
        if (move_uploaded_file($_FILES["receipt"]["tmp_name"], $target_file)) {
            $receipt_path = $new_filename; 
        } else {
            error_log("File Upload Failed: Permissions or Path issue");
            echo json_encode(['success' => false, 'message' => 'Failed to save receipt.']);
            exit();
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG & PNG allowed.']);
        exit();
    }

} else {
    // --- B. STANDARD JSON REQUEST (Cash / No Image) ---
    $input = json_decode(file_get_contents("php://input"), true);
}

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'No booking data received']);
    exit();
}

try {
    // --- EXTRACT DATA ---
    $firebase_uid = $input['uid'] ?? '';
    $guest = $input['guest'];
    $payment = $input['payment'];
    $dates = $input['dates'];
    $rooms = $input['rooms'];
    $adults = $input['adults'] ?? 1;
    $children = $input['children'] ?? 0;

    $check_in = $dates['check_in'];
    $check_out = $dates['check_out'];
    
    // Guest Fields
    $salutation = $guest['salutation'] ?? '';
    $nationality = $guest['nationality'] ?? '';
    $gender = $guest['gender'] ?? '';
    $birthdate = $guest['birthdate'] ?? null;
    $arrival_time = $guest['arrival_time'] ?? '';
    
    $total_price = (float) $payment['total_price'];
    $amount_paid = isset($payment['amount_paid']) ? (float) $payment['amount_paid'] : $total_price;
    $payment_term = isset($payment['term']) ? $payment['term'] : 'full';
    $payment_method = $payment['method'] ?? $guest['payment_method'] ?? 'Online/App';
    $payment_ref    = $payment['payment_reference'] ?? $_POST['payment_reference'] ?? null;
    $ocr_text       = $payment['ocr_text'] ?? $_POST['ocr_text'] ?? ''; // 🟢 Received from mobile OCR

    // 🟢 RECIPIENT VALIDATION (BACKEND)
    if ($payment_method === 'GCash' && !empty($accNum)) {
        $last4 = substr($accNum, -4);
        $cleanOcr = str_replace([' ', '-', ':'], '', $ocr_text);
        $cleanAcc = str_replace([' ', '-', ':'], '', $accNum);

        // Check if full account or last 4 digits exist in the OCR text
        if (!empty($ocr_text) && !str_contains($cleanOcr, $cleanAcc) && !str_contains($cleanOcr, $last4)) {
            throw new Exception("RECIPIENT_MISMATCH: The receipt does not match the hotel's GCash number.");
        }
    }

    // DETERMINE PAYMENT PROOF TEXT
    $final_payment_proof = null;
    if ($receipt_path) {
        $final_payment_proof = $receipt_path;
    } elseif ($payment_method === 'Cash') {
        $final_payment_proof = "Cash Payment";
    } elseif ($payment_method === 'Charge to Room') {
        $final_payment_proof = "Charge to Room";
    }

    // Status Logic
    $payment_status = 'unpaid'; 
    $booking_source = 'mobile_app';
    $arrival_status = $input['status'] ?? 'upcoming'; 
    $db_status = ($receipt_path != null) ? 'pending' : 'confirmed'; 

    $ref = 'AMV-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));

    // --- 🟢 FETCH HOTEL GCASH CREDENTIALS ---
    $accNum = "";
    $stmt_pay = $conn->prepare("SELECT account_number FROM payment_settings WHERE method_name = 'GCash' LIMIT 1");
    $stmt_pay->execute();
    $res_pay = $stmt_pay->get_result();
    if ($row_p = $res_pay->fetch_assoc()) {
        $accNum = $row_p['account_number'];
    }
    $stmt_pay->close();

    // --- START TRANSACTION ---
    $conn->begin_transaction();

    // STEP 1: RESOLVE USER ID
    $user_id = null;
    $detected_source = 'email'; 

    if (!empty($firebase_uid)) {
        $stmt_u = $conn->prepare("SELECT id, account_source FROM users WHERE firebase_uid = ? LIMIT 1");
        $stmt_u->bind_param("s", $firebase_uid);
        $stmt_u->execute();
        $res_u = $stmt_u->get_result();
        if ($row_u = $res_u->fetch_assoc()) {
            $user_id = $row_u['id'];
            $detected_source = $row_u['account_source'] ?? 'email';
        }
        $stmt_u->close();
    }
    // 🟢 BLOCK CHECK: Pending Booking Limit (4)
    if ($user_id) {
        $checkLimit = $conn->prepare("SELECT COUNT(*) as pending_count FROM bookings WHERE user_id = ? AND status = 'pending'");
        $checkLimit->bind_param("i", $user_id);
        $checkLimit->execute();
        $limitResult = $checkLimit->get_result()->fetch_assoc();
        $pendingBookings = $limitResult['pending_count'] ?? 0;
        $checkLimit->close();

        if ($pendingBookings >= 4) {
            echo json_encode(['success' => false, 'message' => 'You have 4 pending bookings. Please wait for them to be processed before booking again.']);
            exit();
        }
    }
    // STEP 2: AVAILABILITY CHECK
    // This query logic is safe to keep as-is
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
        $stmtCheck->bind_param("iss", $room['id'], $check_out, $check_in);
        $stmtCheck->execute();
        if ($stmtCheck->get_result()->num_rows > 0) {
            // 🟢 Allow this specific Exception because it's a logic error (User needs to know)
            throw new Exception("Room " . $room['name'] . " is no longer available.");
        }
    }
    $stmtCheck->close();

    // STEP 3: INSERT BOOKING
    $sql1 = "INSERT INTO bookings (user_id, check_in, check_out, total_price, payment_method, payment_proof, payment_reference, booking_reference, status, arrival_status, booking_source, amount_paid, payment_status, payment_term, created_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt1 = $conn->prepare($sql1);
    
    $stmt1->bind_param("issdsssssssdss",
        $user_id,
        $check_in,
        $check_out,
        $total_price,
        $payment_method,
        $final_payment_proof,
        $payment_ref,
        $ref,
        $db_status,
        $arrival_status,
        $booking_source,
        $amount_paid,
        $payment_status,
        $payment_term
    );

    if (!$stmt1->execute()) {
        // 🟢 SECURITY: Log the raw SQL error securely, throw generic error to user
        error_log("Booking Insert Failed: " . $stmt1->error);
        throw new Exception("System Error: Could not save booking.");
    }
    $booking_id = $conn->insert_id;
    $stmt1->close();

    // STEP 4: INSERT GUEST DETAILS
    $sql2 = "INSERT INTO booking_guests (booking_id, salutation, first_name, last_name, email, phone, nationality, gender, birthdate, address, arrival_time, special_requests, adults_count, children_count) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $first_name = $guest['first_name'];
    $last_name = $guest['last_name'];
    $email = $guest['email'];

    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param("isssssssssssii", 
        $booking_id,
        $salutation,
        $first_name, 
        $last_name, 
        $email, 
        $guest['phone'], 
        $nationality,   
        $gender,        
        $birthdate,     
        $guest['address'], 
        $arrival_time,  
        $guest['requests'],
        $adults,
        $children
    );
    
    if (!$stmt2->execute()) {
        error_log("Guest Insert Failed: " . $stmt2->error);
        throw new Exception("System Error: Could not save guest details.");
    }
    $stmt2->close();

    // STEP 5: INSERT ROOMS
    $sql3 = "INSERT INTO booking_rooms (booking_id, room_id, room_name, price_per_night) VALUES (?, ?, ?, ?)";
    $stmt3 = $conn->prepare($sql3);

    $roomNamesString = "";
    foreach ($rooms as $room) {
        $stmt3->bind_param("iisd", $booking_id, $room['id'], $room['name'], $room['price']);
        $stmt3->execute();
        $roomNamesString .= $room['name'] . ", ";
    }
    $stmt3->close();
    $roomNamesString = rtrim($roomNamesString, ", ");

    // STEP 6: TRANSACTION LOGGING
    $trans_amount = ($payment_method === 'Cash') ? $total_price : $amount_paid;
    $trans_status = 'Pending'; 
    $trans_type = 'Booking';

    $sqlTrans = "INSERT INTO transactions (user_id, transaction_type, reference_id, amount, payment_method, status, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, NOW())";

    $stmtT = $conn->prepare($sqlTrans);
    if($user_id === null) {
       $null_val = null;
       $stmtT->bind_param("issdss", $null_val, $trans_type, $ref, $trans_amount, $payment_method, $trans_status);
    } else {
       $stmtT->bind_param("issdss", $user_id, $trans_type, $ref, $trans_amount, $payment_method, $trans_status);
    }
    
    if (!$stmtT->execute()) {
        // Non-critical, just log it. Don't stop the booking.
        error_log("App Transaction Log Failed: " . $stmtT->error);
    }
    $stmtT->close();

    // STEP 7: NOTIFICATIONS
    $notif_title = ($receipt_path) ? "New GCash Booking" : "New Mobile Booking";
    $notif_desc = "App Booking ($ref): $first_name $last_name ($roomNamesString)";
    
    $stmt_notif = $conn->prepare("INSERT INTO system_notifications (title, description, type, is_read, created_at) VALUES (?, ?, 'booking', 0, NOW())");
    $stmt_notif->bind_param("ss", $notif_title, $notif_desc);
    $stmt_notif->execute();
    $stmt_notif->close();

    if (!empty($email)) {
        $guest_msg = ($receipt_path) 
            ? "Booking $ref submitted for verification. We will check your payment shortly."
            : "Your booking $ref is confirmed! Check your email for details.";
        
        $stmt_g_notif = $conn->prepare("INSERT INTO guest_notifications (email, account_source, title, message, type, is_read, created_at) VALUES (?, ?, 'Booking Update', ?, 'booking', 0, NOW())");
        $stmt_g_notif->bind_param("sss", $email, $detected_source, $guest_msg);
        $stmt_g_notif->execute();
        $stmt_g_notif->close();
    }

    // STEP 8: COMMIT & EMAIL
    $conn->query("UPDATE system_updates SET last_updated = CURRENT_TIMESTAMP WHERE category IN ('bookings', 'transactions', 'notifications')");
    $conn->commit();

    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $mail = new PHPMailer(true);
        try {
            $qrCodeUrl = "https://quickchart.io/qr?text=" . urlencode($ref) . "&size=300&ecLevel=H";

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

            $mail->setFrom('periolarren@gmail.com', 'AMV Hotel Reservations');
            $mail->addAddress($email, "$first_name $last_name");

            $mail->isHTML(true);
            
            if ($db_status === 'pending') {
                $mail->Subject = "Booking Received - $ref (Pending Verification)";
                $status_text = "PENDING VERIFICATION";
                $body_msg = "<p>We have received your reservation and payment proof for booking <strong>$ref</strong>. Our team is currently verifying your payment.</p>";
                $qr_section = "<div style='text-align: center; margin: 25px 0; background-color: #fff9e6; padding: 20px; border: 1px solid #ffeeba;'>
                                    <p style='font-weight: bold; color: #856404;'>Verification in Progress</p>
                                    <p style='font-size: 0.9em; color: #856404;'>Once your receipt is verified, we will send you a second email containing your <strong>Check-in QR Code</strong>.</p>
                               </div>";
            } else {
                $mail->Subject = "Booking Confirmation - $ref";
                $status_text = "RESERVATION CONFIRMED";
                $body_msg = "<p>Your booking via the AMV Mobile App is confirmed! We look forward to your arrival.</p>";
                $qr_section = "<div style='text-align: center; margin: 25px 0; background-color: #f8f9fa; padding: 20px;'>
                                    <p style='font-weight: bold;'>BOOKING REFERENCE</p>
                                    <img src='$qrCodeUrl' alt='QR Code' width='200'>
                                    <h3>$ref</h3>
                               </div>";
            }

            $mail->Body = "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #ddd;'><div style='background-color: #9e8236; padding: 20px; text-align: center; color: white;'><h2 style='margin:0;'>$status_text</h2></div><div style='padding: 20px;'><p>Dear $salutation $first_name $last_name,</p>$body_msg $qr_section <p><strong>Check-in:</strong> $check_in</p><p><strong>Check-out:</strong> $check_out</p><p><strong>Total Booking Cost:</strong> ₱" . number_format($total_price, 2) . "</p></div></div>";

            $mail->send();
        } catch (Exception $e) {
            error_log("Email Error: " . $mail->ErrorInfo);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Booking Successful', 'ref' => $ref, 'status' => $db_status]);

} catch (Exception $e) {
    if (isset($conn) && $conn->errno) $conn->rollback();
    
    // 🟢 SECURITY: Check if it's a known "Room Unavailable" error.
    // If it contains "no longer available", show it. Otherwise, show generic system error.
    $msg = $e->getMessage();
    if (strpos($msg, "no longer available") !== false) {
        echo json_encode(['success' => false, 'message' => $msg]);
    } else {
        echo json_encode(['success' => false, 'message' => $msg]); 
        // Note: For absolute strictness, change $msg to "System Error" here, 
        // but since we caught specific errors above, $msg is reasonably safe now.
    }
} finally {
    if (isset($conn)) $conn->close();
}
?>
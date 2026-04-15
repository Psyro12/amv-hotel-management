<?php
// ADMIN/PHP/send_guest_email.php~

// 1. Settings
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

// 2. Load PHPMailer (EXACTLY AS IN YOUR REFERENCE)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../../USER/PHPMailer-master/src/Exception.php';
require '../../USER/PHPMailer-master/src/PHPMailer.php';
require '../../USER/PHPMailer-master/src/SMTP.php';

require 'db_connect.php';

session_start();

// 3. Security Check
if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// 4. Get Data
$email = $_POST['email'] ?? '';
$subjectType = $_POST['subject_type'] ?? 'Notification';
$customSubject = $_POST['custom_subject'] ?? '';
$messageBody = $_POST['message'] ?? '';

// Logic for Subject
$finalSubject = ($subjectType === 'Other') ? $customSubject : "AMV Hotel - " . $subjectType;

if (empty($email) || empty($messageBody)) {
    echo json_encode(['status' => 'error', 'message' => 'Email and Message are required.']);
    exit;
}

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

// 5. Send Email
$mail = new PHPMailer(true);

try {
    // --- SMTP CONFIGURATION (EXACTLY FROM YOUR save_booking.php) ---
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'periolarren@gmail.com'; // Your Email
    $mail->Password = 'ftvp ilfl utmq pdgg';   // Your App Password
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

    $mail->setFrom('periolarren@gmail.com', "$hotel_name Admin");
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = $finalSubject;

    // --- HTML STYLING (MATCHES YOUR REFERENCE COLOR SCHEME) ---
    $mail->Body = "
    <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #ddd;'>
        
        <div style='background-color: #9e8236; padding: 20px; text-align: center; color: white;'>
            <h2 style='margin:0; text-transform:uppercase;'>New Message</h2>
        </div>

        <div style='padding: 20px; line-height: 1.6;'>
            <p>Dear Guest,</p>
            
            <div style='background: #f8f9fa; padding: 20px; border-left: 4px solid #9e8236; border-radius: 4px;'>
                " . nl2br(htmlspecialchars($messageBody)) . "
            </div>

            <div style='background-color: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid #E5E7EB; margin-top: 25px;'>
                <h4 style='margin: 0 0 10px 0; color: #111827;'>$hotel_name Contact Information</h4>
                <p style='margin: 5px 0; font-size: 0.9em;'>📍 <strong>Address:</strong> $hotel_address</p>
                <p style='margin: 5px 0; font-size: 0.9em;'>📞 <strong>Phone:</strong> $hotel_phone</p>
                <p style='margin: 5px 0; font-size: 0.9em;'>📧 <strong>Email:</strong> $hotel_email</p>
            </div>

            <br>
            <p>If you have any questions, please reply to this email or visit the front desk.</p>
            <p>Thank you,<br><strong>$hotel_name Administration</strong></p>
        </div>

        <div style='background-color: #f1f1f1; padding: 15px; text-align: center; font-size: 0.8rem; color: #666;'>
            &copy; " . date('Y') . " $hotel_name. All rights reserved.
        </div>
    </div>";

    $mail->send();

    // 6. (Optional) Log this message in your 'messages' table if you have one
    /*
    $stmt = $conn->prepare("INSERT INTO messages (guest_email, subject, message, direction, created_at) VALUES (?, ?, ?, 'outgoing', NOW())");
    $stmt->bind_param("sss", $email, $finalSubject, $messageBody);
    $stmt->execute();
    */

    echo json_encode(['status' => 'success', 'message' => 'Email sent successfully']);

} catch (Exception $e) {
    error_log("Guest Email Error: " . $mail->ErrorInfo);
    echo json_encode(['status' => 'error', 'message' => 'Mailer Error: ' . $mail->ErrorInfo]);
}

$conn->close();
?>
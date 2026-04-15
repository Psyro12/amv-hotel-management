<?php
// FILE: AMV_Project_exp/ADMIN/API/send_email_api.php

// 🟢 SECURITY: Disable error display to prevent leakage (Logs only)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 2. HEADERS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// 🟢 SECURITY: Restrict to POST requests only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

// 3. LOAD PHPMAILER
require '../../USER/PHPMailer-master/src/Exception.php';
require '../../USER/PHPMailer-master/src/PHPMailer.php';
require '../../USER/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$response = array();

// 4. RECEIVE & SANITIZE INPUT
// Using trim() and strip_tags() prevents users from injecting HTML/Scripts into your emails
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$name = isset($_POST['name']) ? trim(strip_tags($_POST['name'])) : 'User';

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'A valid email is required']);
    exit();
}

// 5. GENERATE OTP SECURELY
// random_int() is cryptographically secure, unlike rand()
try {
    $otp = random_int(100000, 999999);
} catch (Exception $e) {
    $otp = rand(100000, 999999); // Fallback if server lacks random_int
}

$mail = new PHPMailer(true);

try {
    // 6. SMTP SETTINGS
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'periolarren@gmail.com'; 
    $mail->Password   = 'ftvp ilfl utmq pdgg';   
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // 🟢 RELAX SSL VERIFICATION
    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );

    // 7. EMAIL CONTENT
    $mail->setFrom('periolarren@gmail.com', 'AMV Hotel');
    $mail->addAddress($email, $name);

    $mail->isHTML(true);
    $mail->Subject = "Your Verification Code";
    $mail->Body    = "
        <div style='font-family: Arial, sans-serif; border: 1px solid #ddd; padding: 20px; border-radius: 10px;'>
            <h2 style='color: #9e8236;'>Welcome to AMV Hotel</h2>
            <p>Hello <strong>$name</strong>,</p>
            <p>To complete your verification, please use the following OTP code:</p>
            <div style='background: #f4f4f4; padding: 15px; text-align: center; font-size: 30px; letter-spacing: 5px; font-weight: bold; color: #9e8236;'>
                $otp
            </div>
            <p>This code will expire in 10 minutes. If you did not request this, please ignore this email.</p>
        </div>
    ";

    $mail->send();

    // 8. SEND SUCCESS TO FLUTTER
    $response['success'] = true;
    $response['message'] = 'Email sent successfully';
    
    // 🟢 SECURITY NOTE: Returning OTP is necessary for your current Flutter logic.
    // Ensure your Flutter app handles this variable over HTTPS.
    $response['otp'] = $otp; 

} catch (Exception $e) {
    // 🟢 SECURITY: Log real error to server logs, show safe message to user
    error_log("Mailer Error: " . $mail->ErrorInfo);
    $response['success'] = false;
    $response['message'] = "Failed to send email. Please try again later.";
}

echo json_encode($response);
?>
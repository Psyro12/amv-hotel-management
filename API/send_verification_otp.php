<?php
// FILE: AMV_Project_exp/API/send_verification_otp.php

// 🟢 SECURITY: Disable error display to prevent leakage of server paths
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set Headers
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// 🟢 SECURITY: Restrict to POST requests only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

// 3. Load PHPMailer
require '../USER/PHPMailer-master/src/Exception.php';
require '../USER/PHPMailer-master/src/PHPMailer.php';
require '../USER/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$response = array();

// 4. RECEIVE & SANITIZE INPUT
// Using trim() and strip_tags() to prevent malicious scripts in the email body
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$name = isset($_POST['name']) ? trim(strip_tags($_POST['name'])) : 'Guest'; 

// 🟢 SECURITY: Basic Email Validation
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'A valid email is required']);
    exit();
}

// 5. GENERATE OTP SECURELY
// random_int() is more secure than rand() for authentication
try {
    $otp = random_int(100000, 999999);
} catch (Exception $e) {
    $otp = rand(100000, 999999); // Fallback
}

$mail = new PHPMailer(true);

try {
    // Authenticate (Credentials preserved)
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

    // Email Content
    $mail->setFrom('periolarren@gmail.com', 'AMV Hotel Verification');
    $mail->addAddress($email, $name); 

    $mail->isHTML(true);
    $mail->Subject = "Your Verification Code";
    $mail->Body    = "
        <div style='font-family: Arial, sans-serif; text-align: center; padding: 20px; border: 1px solid #ddd; border-radius: 10px;'>
            <h2 style='color: #2D0F35;'>Verify Your Email</h2>
            <p>Hello <strong>$name</strong>,</p>
            <p>Thank you for choosing AMV Hotel. Your OTP code is:</p>
            <div style='background: #f4f4f4; padding: 15px; font-size: 32px; font-weight: bold; color: #D4AF37; margin: 20px auto; width: 200px; border-radius: 8px; letter-spacing: 5px;'>
                $otp
            </div>
            <p style='font-size: 12px; color: #888;'>This code is for your verification. Please do not share it with others.</p>
        </div>
    ";

    $mail->send();
    
    $response['success'] = true;
    $response['message'] = 'OTP sent successfully';
    $response['otp'] = $otp; // Preserved for Flutter verification

} catch (Exception $e) {
    // 🟢 SECURITY: Log the real error to server, show generic error to user
    error_log("Mailer Error: " . $mail->ErrorInfo);
    $response['success'] = false;
    $response['message'] = "Failed to send verification email. Please try again later.";
}

echo json_encode($response);
?>
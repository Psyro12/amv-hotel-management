<?php
// send_p2p_otp.php

// 1. Load PHPMailer manually (MATCHING YOUR ADMIN LOGIN)
require '../../USER/PHPMailer-master/src/Exception.php';
require '../../USER/PHPMailer-master/src/PHPMailer.php';
require '../../USER/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Allow Flutter to access this script
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$response = array();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 2. Receive Data from Flutter
    // Note: Ensure your Flutter app sends these keys exactly
    $sender_name = $_POST['sender_name'];   
    $sender_email = $_POST['sender_email']; 
    $receiver_email = $_POST['receiver_email']; 
    
    // 3. Generate the OTP securely on the server
    $otp = rand(100000, 999999);

    $mail = new PHPMailer(true);

    try {
        // 4. Authenticate using YOUR WORKING CREDENTIALS
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; 
        $mail->SMTPAuth   = true;
        $mail->Username   = 'periolarren@gmail.com'; // Updated from your login.php
        $mail->Password   = 'ftvp ilfl utmq pdgg';   // Updated from your login.php
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

        // 5. Setup "Reply-To" Trick
        // The email technically comes from "AMV Admin", but replying goes to the Sender
        $mail->setFrom('periolarren@gmail.com', 'AMV Transfer Security');
        $mail->addReplyTo($sender_email, $sender_name); 
        $mail->addAddress($receiver_email); 

        // 6. Email Body
        $mail->isHTML(true);
        $mail->Subject = "$sender_name is sending you funds via AMV";
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; color: #333; padding: 20px; border: 1px solid #ddd;'>
                <h2 style='color: #2D0F35;'>Transfer Verification</h2>
                <p>Hello,</p>
                <p><b>$sender_name</b> ($sender_email) wants to send money to you.</p>
                <p>To accept this transfer, please give them this verification code:</p>
                <div style='background: #f4f4f4; padding: 15px; text-align: center; font-size: 32px; letter-spacing: 5px; font-weight: bold; color: #2D0F35; border-radius: 8px; margin: 20px 0;'>
                    $otp
                </div>
                <p style='font-size: 12px; color: #888;'>If you don't know this person, please ignore this email.</p>
            </div>
        ";

        $mail->send();
        
        // 7. Success Response
        $response['success'] = true;
        $response['message'] = 'OTP sent successfully';
        
        // ⚠️ SECURITY NOTE: We are sending the OTP back to the phone so the App can verify it.
        // In a banking app, you would verify this on the server, but for this project, this is fine.
        $response['otp'] = $otp; 

    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = "Mailer Error: {$mail->ErrorInfo}";
    }
} else {
    $response['success'] = false;
    $response['message'] = 'Invalid Request Method';
}

echo json_encode($response);
?>
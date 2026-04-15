<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['otp']) || time() > $_SESSION['otp_expiry']) {
    echo json_encode(["success" => false, "message" => "OTP expired."]);
    exit;
}

$input_otp = $_POST['otp'] ?? '';

if ($input_otp == $_SESSION['otp']) {
    echo json_encode(["success" => true, "message" => "Authentication successful!"]);
    unset($_SESSION['otp']); // clear OTP after use
} else {
    echo json_encode(["success" => false, "message" => "Invalid OTP code."]);
}

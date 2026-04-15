<?php
session_start();

// Generate random 6-digit OTP
$otp = rand(100000, 999999);

// Store OTP in session (valid for 5 minutes)
$_SESSION['otp'] = $otp;
$_SESSION['otp_expiry'] = time() + 300; // 5 minutes

// In real app: send OTP via email or SMS
// For demo: just return it in JSON
echo json_encode([
    "success" => true,
    "otp" => $otp, // remove this in production
    "message" => "OTP generated successfully"
]);

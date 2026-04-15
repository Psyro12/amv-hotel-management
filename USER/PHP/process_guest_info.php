<?php
// USER/PHP/process_guest_info.php

// 1. Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline';");

// 2. Secure Session Settings (Retaining 'Lax' to fix redirect issues)
if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => true, // Ensure TRUE for production
        'httponly' => true,
        'samesite' => 'Lax' // Kept Lax to prevent session dropping on redirects
    ]);
    session_start();
}

// 3. Strict Method Check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

// 4. 🔴 CSRF PROTECTION (Critical)
// We verify the token sent from guest_info.php
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("Security Violation: Invalid Request (CSRF Token Mismatch)");
}

// 5. 🔴 SANITIZE & VALIDATE DATA (Prevent Mass Assignment)
// Instead of dumping all $_POST, we pick only what we need and clean it.

$clean_data = [];

// Clean Strings
$fields_to_sanitize = ['first_name', 'last_name', 'salutation', 'gender', 'birthdate', 'email', 'contact_number', 'nationality', 'address', 'requests', 'payment_method', 'payment_term', 'checkin', 'checkout', 'arrival_time'];

foreach ($fields_to_sanitize as $field) {
    if (isset($_POST[$field])) {
        // strip_tags removes HTML/Script tags
        $clean_data[$field] = trim(strip_tags($_POST[$field]));
    } else {
        $clean_data[$field] = ''; // Default to empty string if missing
    }
}

// Clean Numbers
$clean_data['adults'] = isset($_POST['adults']) ? intval($_POST['adults']) : 1;
$clean_data['children'] = isset($_POST['children']) ? intval($_POST['children']) : 0;
$clean_data['total_price'] = isset($_POST['total_price']) ? floatval($_POST['total_price']) : 0.00;

// Handle JSON (Selected Rooms) safely
if (isset($_POST['selected_rooms'])) {
    // Decode and re-encode to ensure it is valid JSON and strip invalid chars
    $decoded = json_decode($_POST['selected_rooms'], true);
    if (is_array($decoded)) {
        $clean_data['selected_rooms'] = $decoded; 
    } else {
        $clean_data['selected_rooms'] = [];
    }
}

require 'db_connect.php';

// 🟢 PENDING BOOKING LIMIT CHECK (Max 4)
$email_to_check = $clean_data['email'];
if (!empty($email_to_check)) {
    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM bookings b JOIN booking_guests bg ON b.id = bg.booking_id WHERE bg.email = ? AND b.status = 'pending'");
    $stmt_check->bind_param("s", $email_to_check);
    $stmt_check->execute();
    $stmt_check->bind_result($pending_count);
    $stmt_check->fetch();
    $stmt_check->close();

    if ($pending_count >= 4) {
        // We redirect back with a specific error message
        header("Location: check_availability.php?error=too_many_pending");
        exit;
    }
}

// 6. Store CLEAN Data into Session
$_SESSION['temp_booking'] = $clean_data;

// 7. Force Save Session (Prevents race conditions on redirect)
session_write_close();

// 8. Payment Logic Redirect
// We validate against a strict allowed list
$allowed_methods = ['Cash', 'GCash']; 
$method = $clean_data['payment_method'];

if (in_array($method, $allowed_methods)) {
    if ($method === 'Cash') {
        // 🟢 FOR CASH: We use an auto-submitting POST form to carry the CSRF token
        $token = $_SESSION['csrf_token'];
        echo "
        <form id='cashForm' action='finalize_booking.php' method='POST'>
            <input type='hidden' name='csrf_token' value='$token'>
        </form>
        <script>document.getElementById('cashForm').submit();</script>
        ";
        exit;
    } else {
        header("Location: payment.php");
        exit;
    }
} else {
    // Fallback if payment method is manipulated
    header("Location: guest_info.php?error=invalid_payment");
    exit;
}
?>
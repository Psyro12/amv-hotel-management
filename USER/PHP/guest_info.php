<?php
// USER/PHP/guest_info.php

// 1. Output Buffering
ob_start();

// 2. SET TIMEZONE FIRST
date_default_timezone_set('Asia/Manila');

// 3. Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
// Allow Nominatim for address search
header("Content-Security-Policy: default-src 'self'; connect-src 'self' https://nominatim.openstreetmap.org; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https://*.google.com https://*.googleapis.com; frame-src 'self' https://*.google.com; upgrade-insecure-requests;");

// 4. Secure Session Settings
if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => true, // Ensure TRUE for production
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// 5. Disable Error Reporting to Screen
ini_set('display_errors', 0);
error_reporting(E_ALL);

require 'db_connect.php';

// --- 6. FETCH ADMIN CONTACT INFO ---
$admin_sql = "SELECT email, contact_number FROM admin_user LIMIT 1";
$admin_result = mysqli_query($conn, $admin_sql);

if ($admin_result && mysqli_num_rows($admin_result) > 0) {
    $admin_data = mysqli_fetch_assoc($admin_result);
    $hotel_email = !empty($admin_data['email']) ? htmlspecialchars($admin_data['email']) : 'info@amvhotel.com';
    $hotel_phone = !empty($admin_data['contact_number']) ? htmlspecialchars($admin_data['contact_number']) : '+63 945 343 455';
} else {
    $hotel_email = 'info@amvhotel.com';
    $hotel_phone = '+63 945 343 455';
}

$hotel_phone_link = preg_replace('/[^0-9+]/', '', $hotel_phone);

// 7. Strict Method Check (Must be POST)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: select_rooms.php");
    exit;
}

// 8. Capture Data Safely
$checkin = isset($_POST['checkin']) ? htmlspecialchars($_POST['checkin']) : '';
$checkout = isset($_POST['checkout']) ? htmlspecialchars($_POST['checkout']) : '';
$adults = isset($_POST['adults']) ? intval($_POST['adults']) : 1;
$children = isset($_POST['children']) ? intval($_POST['children']) : 0;
$selected_rooms_json = isset($_POST['selected_rooms']) ? $_POST['selected_rooms'] : '[]';
$total_price = isset($_POST['total_price']) ? floatval($_POST['total_price']) : 0.0;

// 9. Decode Selected Rooms
$selected_rooms = json_decode($selected_rooms_json, true);

if (!is_array($selected_rooms) || empty($selected_rooms)) {
    die("Error: No rooms selected.");
}

// 10. Format Dates
$nights = 0;
$formatted_checkin = "";
$formatted_checkout = "";

try {
    $date_in = new DateTime($checkin);
    $date_out = new DateTime($checkout);
    $interval = $date_in->diff($date_out);
    $nights = $interval->days;
    $formatted_checkin = $date_in->format('D, M d, Y');
    $formatted_checkout = $date_out->format('D, M d, Y');
} catch (Exception $e) {
    die("Invalid dates provided.");
}

// 11. Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- AJAX Chat Handler (Embedded) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ajax_chat'])) {
    header('Content-Type: application/json');
    $guest_name = trim(strip_tags($_POST['guest_name'] ?? ''));
    $email = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));
    $message = trim(strip_tags($_POST['message'] ?? ''));

    if (empty($guest_name) || empty($email) || empty($message)) {
        echo json_encode(['status' => 'error', 'message' => 'Please fill in all fields.']);
        exit;
    }

    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM guest_messages WHERE email = ? AND DATE(created_at) = CURDATE()");
    $stmt_check->bind_param("s", $email);
    $stmt_check->execute();
    $stmt_check->bind_result($msg_count);
    $stmt_check->fetch();
    $stmt_check->close();

    if ($msg_count >= 4) {
        echo json_encode(['status' => 'limit', 'message' => 'You have reached the daily limit of 4 messages. Please try again tomorrow.']);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO guest_messages (guest_name, email, message) VALUES (?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sss", $guest_name, $email, $message);
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Message successfully sent to Admin!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to send message.']);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error.']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Information - AMV Hotel</title>
    <link rel="icon" type="image/png" href="../../IMG/5.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

    <link rel="stylesheet" href="../STYLE/home_page.css">
    <link rel="stylesheet" href="../STYLE/utilities.css">

    <style>
        /* --- GLOBAL STYLES --- */
        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            color: #333;
        }

        * {
            box-sizing: border-box;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            background-color: transparent;
        }
        ::-webkit-scrollbar-track {
            background-color: transparent !important;
        }
        ::-webkit-scrollbar-thumb {
            background: #b8860b;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #9e8236;
        }
        /* Firefox */
        * {
            scrollbar-width: thin;
            scrollbar-color: #b8860b transparent;
        }

        /* --- CONTENT WRAPPER --- */
        .page-content-wrapper {
            background-color: #f5f5f5;
            position: relative;
            z-index: 10;
            margin-bottom: 110px;
            /* Space for fixed footer on desktop */
            padding-bottom: 60px;
            min-height: 100vh;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        /* --- HEADER --- */
        header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 5%;
            z-index: 1000;
            background-color: #ffffff;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 1100;
            transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            cursor: pointer;
        }

        .logo-container:active {
            transform: scale(0.92);
        }

        .logo-container img {
            height: 40px;
            transition: 0.3s;
        }

        .logo-text {
            display: flex;
            flex-direction: row;
            font-weight: 700;
            line-height: 1.1;
            gap: 5px;
            position: relative;
            overflow: hidden;
        }

        /* Shimmer Effect for Gold Text */
        .logo-text::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 50%;
            height: 100%;
            background: linear-gradient(
                to right,
                transparent,
                rgba(255, 255, 255, 0.4),
                transparent
            );
            transform: skewX(-25deg);
            transition: 0.5s;
        }

        .logo-container:hover .logo-text::after {
            animation: shimmer 1.5s infinite;
        }

        @keyframes shimmer {
            100% { left: 200%; }
        }

        .logo-text span {
            color: #333 !important;
        }

        .logo-text span:first-child {
            font-size: 18px;
            color: #333 !important;
        }

        .logo-text span:last-child {
            font-size: 12px;
        }

        .desktop-nav {
            display: flex;
            align-items: center;
        }

        .desktop-nav a {
            text-decoration: none;
            color: #333;
            font-weight: 500;
            font-size: .8rem;
            margin-right: 25px;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: color 0.4s ease;
        }

        .desktop-nav a:hover,
        .desktop-nav a.active {
            color: #b8860b;
        }

        /* --- BURGER MENU ANIMATION --- */
        .burger-menu {
            width: 30px;
            height: 20px;
            position: relative;
            cursor: pointer;
            display: none;
            z-index: 11000; /* Higher than sticky bar */
        }

        .burger-menu span {
            display: block;
            position: absolute;
            height: 2px;
            width: 100%;
            background: #333;
            border-radius: 2px;
            opacity: 1;
            left: 0;
            transform: rotate(0deg);
            transition: .25s ease-in-out;
        }

        .burger-menu span:nth-child(1) { top: 0px; }
        .burger-menu span:nth-child(2) { top: 9px; }
        .burger-menu span:nth-child(3) { top: 18px; }

        .burger-menu.active span:nth-child(1) {
            top: 9px;
            transform: rotate(135deg);
        }

        .burger-menu.active span:nth-child(2) {
            opacity: 0;
            left: -60px;
        }

        .burger-menu.active span:nth-child(3) {
            top: 9px;
            transform: rotate(-135deg);
        }

        .mobile-nav-overlay {
            position: fixed;
            top: 0;
            right: -100%;
            width: 85%;
            max-width: 320px;
            height: 100vh;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: -10px 0 30px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            padding: 40px 30px;
            transition: all 0.5s cubic-bezier(0.77, 0.2, 0.05, 1.0);
            z-index: 11000; /* Higher than sticky bar */
            overflow-y: auto;
        }

        .mobile-nav-overlay.active {
            right: 0;
        }

        .mobile-nav-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 50px;
        }

        .mobile-nav-header .logo-text span {
            color: #333 !important;
        }

        .mobile-nav-overlay a {
            text-decoration: none;
            color: #333;
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 25px;
            text-transform: uppercase;
            padding-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            transform: translateX(30px);
            opacity: 0;
            transition: all 0.4s ease;
            position: relative;
        }

        .mobile-nav-overlay.active a {
            transform: translateX(0);
            opacity: 1;
        }

        .mobile-nav-overlay a i {
            width: 24px;
            color: #b8860b;
            font-size: 1.1rem;
        }

        .mobile-nav-overlay a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: #b8860b;
            transition: width 0.3s ease;
        }

        .mobile-nav-overlay a:hover::after {
            width: 100%;
        }

        /* Staggered animation for links */
        .mobile-nav-overlay.active a:nth-child(2) { transition-delay: 0.1s; }
        .mobile-nav-overlay.active a:nth-child(3) { transition-delay: 0.2s; }
        .mobile-nav-overlay.active a:nth-child(4) { transition-delay: 0.3s; }
        .mobile-nav-overlay.active a:nth-child(5) { transition-delay: 0.4s; }
        .mobile-nav-overlay.active a:nth-child(6) { transition-delay: 0.5s; }

        .mobile-nav-footer {
            margin-top: auto;
            padding-top: 30px;
            border-top: 1px solid #eee;
        }

        .mobile-socials {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }

        .mobile-socials a {
            transform: none !important;
            opacity: 1 !important;
            margin-bottom: 0;
            padding-bottom: 0;
            font-size: 1.4rem;
            color: #b8860b;
        }

        .mobile-socials a::after { display: none; }

        .mobile-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(3px);
            z-index: 10500; /* Higher than sticky bar */
            display: none;
            opacity: 0;
            transition: all 0.4s ease;
        }

        .mobile-backdrop.active {
            display: block;
            opacity: 1;
        }

        body.menu-open {
            overflow: hidden;
        }

        @media (max-width: 992px) {
            .desktop-nav {
                display: none;
            }

            .burger-menu {
                display: block;
            }

            header {
                padding: 15px 20px;
            }
        }

        @media (max-width: 768px) {
            .logo-text {
                flex-direction: column;
                align-items: flex-start;
                gap: 0;
                line-height: 1;
            }

            .logo-text span:first-child {
                font-size: 1.1rem !important;
            }

            .logo-text span:last-child {
                font-size: 0.6rem !important;
                letter-spacing: 2px;
                text-transform: uppercase;
                margin-top: -2px;
            }

            .logo-container img {
                height: 38px !important;
            }
        }

        /* --- STEPPER --- */
        .booking-stepper {
            margin-top: 80px;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
            position: sticky;
            top: 70px;
            z-index: 900;
            background-color: #fff;
            transition: all 0.3s ease;
            display: flex;
            justify-content: center;
            gap: 0 !important;
            width: 100%;
            padding-left: max(20px, calc(50% - 400px));
            padding-right: max(20px, calc(50% - 400px));
            margin-left: auto;
            margin-right: auto;
        }

        .booking-stepper.scrolled {
            background-color: rgba(255, 255, 255, 0.6) !important;
            backdrop-filter: blur(1px);
            -webkit-backdrop-filter: blur(1px);
            border-bottom-color: transparent !important;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1) !important;
        }

        .booking-stepper.scrolled .step-item {
            color: #555;
            text-shadow: 0 0 3px #fff;
            font-weight: 700;
        }

        .booking-stepper.scrolled .step-icon {
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            border-color: #888;
        }

        .booking-stepper.scrolled .step-item.active {
            color: #9e8236;
        }

        .booking-stepper.scrolled .step-item.completed {
            color: #000;
        }

        .booking-stepper.scrolled .step-item.active .step-icon,
        .booking-stepper.scrolled .step-item.completed .step-icon {
            border-color: #9e8236;
            box-shadow: 0 2px 8px rgba(158, 130, 54, 0.4);
        }

        .booking-stepper.scrolled:hover {
            opacity: 1;
            background-color: rgba(255, 255, 255, 0.9) !important;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1) !important;
        }

        .step-item {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            margin: 0;
            color: #ccc;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 1;
        }

        .step-item:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 15px;
            left: 50%;
            width: 100%;
            height: 2px;
            background-color: #e0e0e0;
            z-index: -1;
        }

        .step-item.completed::after {
            background-color: #9e8236;
        }

        .step-item.active {
            color: #9e8236;
            font-weight: 700;
        }

        .step-item.completed {
            color: #333;
        }

        .step-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            border: 2px solid #ccc;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
            font-size: 0.9rem;
            background-color: #fff;
            z-index: 2;
        }

        .step-item.active .step-icon {
            border-color: #9e8236;
            background-color: #9e8236;
            color: #fff;
        }

        .step-item.completed .step-icon {
            border-color: #9e8236;
            background-color: #9e8236;
            color: #fff;
        }

        @media (max-width: 768px) {
            .booking-stepper {
                gap: 0;
                justify-content: space-between;
                padding: 15px 10px;
                top: 70px; /* Adjust for mobile header */
            }

            .step-item {
                font-size: 0.55rem;
                flex: 1;
            }

            .step-icon {
                width: 28px;
                height: 28px;
                font-size: 0.7rem;
                margin-bottom: 6px;
                transition: all 0.3s ease;
            }

            .step-item.active .step-icon {
                transform: scale(1.2);
                box-shadow: 0 0 15px rgba(158, 130, 54, 0.4);
                animation: pulse-gold 2s infinite;
            }

            @keyframes pulse-gold {
                0% { box-shadow: 0 0 0 0 rgba(158, 130, 54, 0.7); }
                70% { box-shadow: 0 0 0 10px rgba(158, 130, 54, 0); }
                100% { box-shadow: 0 0 0 0 rgba(158, 130, 54, 0); }
            }

            .step-item:not(:last-child)::after {
                top: 14px;
                width: 100%;
                left: 50%;
                height: 2px;
            }

            .step-item span {
                display: block;
                text-align: center;
                line-height: 1.2;
                font-weight: 700;
                max-width: 65px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
        }

        @media (max-width: 480px) {
            .step-item span {
                font-size: 0.5rem;
            }
            
            .booking-stepper {
                padding: 12px 5px;
            }
        }

        /* 🟢 NEW: MOBILE PAYMENT SECTION CSS */
        .mobile-payment-section {
            display: none;
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid #f0f0f0;
            margin-top: 30px;
        }

        @media (max-width: 900px) {
            .mobile-payment-section {
                display: block;
            }
            .mobile-plan-select {
                display: block !important;
                width: 100%;
                background: #fcfcfc;
                border: 2px solid #eee;
                border-radius: 8px;
                padding: 14px 16px;
                font-family: 'Montserrat', sans-serif;
                font-weight: 600;
                color: #333;
                cursor: pointer;
            }
        }

        /* LAYOUT */
        .main-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: 2.5fr 1fr;
            gap: 30px;
        }

        @media (max-width: 900px) {
            .main-container {
                grid-template-columns: 1fr;
            }
        }

        /* FORM */
        .guest-form-container {
            background: #fff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid #f0f0f0;
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 35px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f8f8f8;
        }

        .form-title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #222;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-title i {
            color: #9e8236;
        }

        .form-grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        .form-grid-1 {
            margin-bottom: 25px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 700;
            color: #555;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-label span {
            color: #d32f2f;
            margin-left: 3px;
        }

        .form-input {
            padding: 14px 16px;
            border: 2px solid #eee;
            border-radius: 8px;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.95rem;
            color: #333;
            background-color: #fcfcfc;
            width: 100%;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #9e8236;
            background-color: #fff;
            box-shadow: 0 4px 15px rgba(158, 130, 54, 0.1);
        }

        /* --- NEW CUSTOM SELECT STYLES --- */
        .form-select {
            display: none;
        }

        @media (max-width: 900px) {
            /* 🔴 Use custom styled selects even on mobile for premium look */
            .form-select {
                display: none !important;
            }

            .custom-select-wrapper {
                display: block !important;
                margin-bottom: 5px;
            }

            /* Matches .form-input at 900px */
            .custom-select-trigger {
                padding: 12px 14px !important;
                font-size: 0.9rem !important;
            }

            .custom-option {
                padding: 12px 14px !important;
                font-size: 0.9rem !important;
            }
            
            /* Ensure the dropdown list stays on top of the sticky bar */
            .custom-options {
                z-index: 10002 !important;
                position: absolute;
                bottom: auto;
                top: calc(100% + 5px);
            }
        }

        /* Small Phones (iPhone SE, etc) */
        @media (max-width: 600px) {
            .custom-select-trigger, .custom-option {
                padding: 10px 12px !important;
                font-size: 0.85rem !important;
            }
        }

        /* Very Small Devices */
        @media (max-width: 400px) {
            .custom-select-trigger, .custom-option {
                padding: 8px 10px !important;
                font-size: 0.8rem !important;
            }
        }

        .custom-select-wrapper {
            position: relative;
            user-select: none;
            width: 100%;
        }

        .custom-select-trigger {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px;
            font-size: 0.95rem;
            font-weight: 600;
            color: #333;
            background: #fcfcfc;
            border: 2px solid #eee;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .custom-select-wrapper.open .custom-select-trigger {
            border-color: #9e8236;
            background: #fff;
            box-shadow: 0 4px 15px rgba(158, 130, 54, 0.1);
        }

        .custom-arrow {
            font-size: 0.8rem;
            color: #888;
            transition: transform 0.3s ease;
        }

        .custom-select-wrapper.open .custom-arrow {
            transform: rotate(180deg);
            color: #9e8236;
        }

        /* --- 🟢 BULLETPROOF CUSTOM SELECT ANIMATION --- */

        /* 1. The Container List */
        .custom-options {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 10px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
            z-index: 9999 !important;
            max-height: 250px;
            overflow-y: auto;
            display: block !important;
            opacity: 0;
            transform: translateY(-10px) scale(0.98);
            pointer-events: none;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1) !important;
            transform-origin: top center;
        }

        /* 2. The Open State */
        .custom-select-wrapper.open .custom-options {
            opacity: 1;
            transform: translateY(0) scale(1);
            pointer-events: auto;
        }

        /* 5. Option Hover Effects */
        .custom-option {
            padding: 14px 16px;
            font-size: 0.95rem;
            color: #444;
            cursor: pointer;
            transition: all 0.2s ease;
            border-bottom: 1px solid #f8f8f8;
        }

        .custom-option:hover {
            background-color: #fffdf5;
            color: #9e8236;
            padding-left: 22px;
        }

        .custom-option.selected {
            background-color: #9e8236;
            color: #fff;
        }

        /* UPDATED CHECKBOX STYLES */
        .checkbox-wrapper {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-top: 30px;
            font-size: 0.95rem;
            color: #444;
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #eee;
        }

        .checkbox-wrapper input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: #9e8236;
            margin-top: 2px;
            cursor: pointer;
        }

        .checkbox-wrapper label {
            cursor: pointer;
            line-height: 1.6;
            font-weight: 500;
        }

        .checkbox-wrapper a {
            color: #9e8236;
            font-weight: 700;
            text-decoration: none;
        }

        .checkbox-wrapper a:hover {
            text-decoration: underline;
        }

        @media (max-width: 900px) {
            .main-container {
                grid-template-columns: 1fr;
                padding: 0 10px;
                gap: 20px;
                margin-top: 20px;
            }

            .guest-form-container {
                padding: 25px;
            }

            .form-header {
                margin-bottom: 25px;
                padding-bottom: 15px;
            }

            .form-title {
                font-size: 1.4rem;
            }

            .form-grid-3,
            .form-grid-2 {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .form-label {
                font-size: 0.8rem;
                margin-bottom: 8px;
            }

            .form-input {
                padding: 12px 14px;
                font-size: 0.9rem;
            }

            /* 🔴 HIDE DESKTOP SUMMARY ON MOBILE */
            .sidebar {
                display: none !important;
            }

            body {
                padding-bottom: 90px !important; /* Space for sticky bar */
            }
        }

        /* Small Phones (iPhone SE, etc) */
        @media (max-width: 600px) {
            .form-title {
                font-size: 1.2rem;
            }
            .form-label {
                font-size: 0.75rem;
                margin-bottom: 6px;
            }
            .form-input {
                padding: 10px 12px;
                font-size: 0.85rem;
                border-radius: 6px;
            }
            .guest-form-container {
                padding: 18px;
            }
            .checkbox-wrapper {
                font-size: 0.8rem;
                padding: 12px;
                gap: 10px;
            }
            .checkbox-wrapper input[type="checkbox"] {
                width: 16px;
                height: 16px;
            }
        }

        /* Very Small Devices */
        @media (max-width: 400px) {
            .form-title {
                font-size: 1.1rem;
            }
            .form-label {
                font-size: 0.7rem;
            }
            .form-input {
                padding: 8px 10px;
                font-size: 0.8rem;
            }
            .guest-form-container {
                padding: 15px;
                border-radius: 8px;
            }
            .form-header {
                margin-bottom: 15px;
            }
        }

        /* 🟢 MOBILE STICKY SUMMARY BAR (Consistent with select_rooms) */
        .mobile-sticky-bar {
            display: none;
            position: fixed;
            bottom: 0 !important;
            left: 0;
            right: 0;
            width: 100%;
            background: #fff;
            padding: 10px 15px;
            padding-bottom: calc(10px + env(safe-area-inset-bottom));
            box-shadow: 0 -10px 30px rgba(0, 0, 0, 0.15);
            z-index: 10001; 
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #f0f0f0;
            min-height: 75px;
            transform: translateZ(0);
            -webkit-transform: translateZ(0);
        }

        @media (max-width: 900px) {
            .mobile-sticky-bar {
                display: flex;
            }
        }

        .sticky-bar-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
            max-width: 55%;
        }

        .sticky-bar-label {
            font-size: 0.55rem;
            color: #999;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.5px;
            line-height: 1;
        }

        #sticky-payment-label {
            font-size: 0.6rem;
            color: #666;
            font-weight: 600;
            margin-bottom: 2px;
        }

        #sticky-total {
            font-size: 1.2rem;
            color: #9e8236;
            font-weight: 800;
            line-height: 1;
        }

        .btn-sticky-confirm {
            background: #9e8236;
            color: #fff;
            border: none;
            padding: 14px 20px;
            border-radius: 8px;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            box-shadow: 0 4px 15px rgba(158, 130, 54, 0.2);
            transition: 0.3s;
            flex-shrink: 0;
        }

        .btn-sticky-confirm:active {
            transform: scale(0.95);
            background: #8c7330;
        }

        /* SIDEBAR */
        .sidebar {
            position: sticky;
            top: 100px;
            height: fit-content;
        }

        .summary-box {
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            border-top: 5px solid #9e8236;
            position: relative;
            overflow: hidden;
        }

        .summary-box::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: radial-gradient(circle at top right, rgba(158, 130, 54, 0.05), transparent);
            pointer-events: none;
        }

        .summary-title {
            font-size: 1.1rem;
            font-weight: 800;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f0f0f0;
            color: #222;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .summary-title i {
            color: #9e8236;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 18px;
            font-size: 0.9rem;
            color: #555;
            align-items: center;
        }

        .summary-row span {
            font-weight: 500;
            color: #888;
        }

        .summary-row b {
            color: #333;
            font-weight: 700;
        }

        .selected-room-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            font-size: 0.85rem;
            border-bottom: 1px dashed #eee;
        }

        .total-row {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid #f5f5f5;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .total-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .total-amount {
            font-size: 1.8rem;
            font-weight: 800;
            color: #9e8236;
            line-height: 1;
        }

        .btn-book {
            width: 100%;
            background: #222;
            color: #fff;
            border: none;
            padding: 18px;
            font-weight: 800;
            cursor: pointer;
            border-radius: 8px;
            margin-top: 25px;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-size: 0.9rem;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        .btn-book:hover {
            background-color: #9e8236;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(158, 130, 54, 0.3);
        }

        .s-room-name {
            font-size: 0.95rem;
            font-weight: 700;
            color: #9e8236;
            margin-bottom: 4px;
            display: block;
        }

        .s-room-price {
            font-size: 0.85rem;
            color: #555;
            float: right;
        }

        .total-wrapper {
            background-color: #f9f9f9;
            padding: 20px 25px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .total-label {
            font-weight: 700;
            color: #333;
            font-size: 1.1rem;
        }

        .total-amount {
            font-weight: 700;
            color: #9e8236;
            font-size: 1.4rem;
        }

        /* PAYMENT BOX */
        .payment-box {
            background: #fff;
            border-radius: 4px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            padding: 20px;
        }

        .payment-title {
            font-weight: 700;
            color: #333;
            margin-bottom: 15px;
        }

        .payment-option {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: 0.2s;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .payment-option:hover {
            border-color: #9e8236;
            background-color: #fdfdfd;
        }

        .payment-option input[type="radio"] {
            accent-color: #9e8236;
            transform: scale(1.2);
        }

        .btn-submit {
            width: 100%;
            padding: 15px;
            background-color: #9e8236;
            color: #fff;
            border: none;
            font-size: 1rem;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
            border-radius: 4px;
            transition: 0.3s;
            margin-top: 20px;
        }

        .btn-submit:hover {
            background-color: #8c7330;
        }

        /* --- ADDRESS AUTOCOMPLETE --- */
        .spinner {
            position: absolute;
            right: 15px;
            top: 50%;
            margin-top: -10px;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #9e8236;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: none;
            pointer-events: none;
            z-index: 10;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .address-results-list {
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 6px 6px;
            max-height: 250px;
            overflow-y: auto;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            display: none;
        }

        .address-result-item {
            padding: 10px 15px;
            font-size: 0.9rem;
            color: #333;
            cursor: pointer;
            border-bottom: 1px solid #f9f9f9;
        }

        .address-result-item:hover {
            background-color: #f5f5f5;
        }

        /* --- SIMPLE FIXED FOOTER (Desktop) --- */
        .simple-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            height: auto;
            /* Changed to auto so it grows */
            min-height: 100px;
            background: #fff;
            border-top: 1px solid #eee;
            z-index: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .simple-footer-content {
            padding: 15px 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .footer-link {
            color: #555;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: color 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .footer-link i {
            color: #D4AF37;
            font-size: 0.9rem;
        }

        .footer-link:hover {
            color: #D4AF37;
        }

        .footer-separator {
            color: #ddd;
            font-size: 0.8rem;
        }

        .simple-copyright {
            background-color: #333;
            color: #b0a1b5;
            padding: 10px;
            font-size: 0.75rem;
            width: 100%;
            text-align: center;
        }

        /* --- 🔴 MOBILE FOOTER FIXES 🔴 --- */
        @media (max-width: 600px) {

            /* Un-fix the footer so it sits at the very bottom of the document */
            .simple-footer {
                position: relative;
                height: auto;
                box-shadow: none;
                border-top: 1px solid #ddd;
                padding-top: 10px;
            }

            /* Remove the spacer since footer is no longer floating */
            .page-content-wrapper {
                margin-bottom: 0;
                box-shadow: none;
            }

            .simple-footer-content {
                flex-direction: column;
                gap: 10px;
                padding: 20px;
            }

                    .footer-separator {
                        display: none;
                    }
                }
                </style></head>

<body>
    <?php include 'booking_loader.php'; ?>

    <div class="page-content-wrapper">
        <header id="mainHeader">
            <div class="logo-container" onclick="window.location.href='index.php'">
                <img src="../../IMG/5.png" alt="AMV Logo">
                <div class="logo-text">
                    <span>AMV</span>
                    <span>Hotel</span>
                </div>
            </div>
            <nav class="desktop-nav">
                <a href="index.php">Home</a>
                <a href="menu.php">Dining</a>
                <a href="check_availability.php" class="active" style="color: #b8860b;">Reservations</a>
                <a href="about_us.php">About Us</a>
            </nav>
            <div class="burger-menu" id="burgerIcon" onclick="toggleMobileMenu()">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </header>

        <div class="mobile-backdrop" id="mobileBackdrop" onclick="toggleMobileMenu()"></div>
        <div class="mobile-nav-overlay" id="mobileMenu">
            <div class="mobile-nav-header">
                <div class="logo-container">
                    <img src="../../IMG/5.png" alt="AMV Logo" style="height: 40px;">
                    <div class="logo-text" style="font-size: 14px;">
                        <span>AMV</span>
                        <span style="font-size: 10px;">Hotel</span>
                    </div>
                </div>
                <i class="fa-solid fa-times" style="font-size: 24px; cursor: pointer; color: #333;" onclick="toggleMobileMenu()"></i>
            </div>
            
            <a href="index.php"><i class="fa-solid fa-house"></i> Home</a>
            <a href="menu.php"><i class="fa-solid fa-utensils"></i> Dining</a>
            <a href="check_availability.php" style="color: #b8860b;"><i class="fa-solid fa-calendar-check"></i> Reservations</a>
            <a href="about_us.php"><i class="fa-solid fa-circle-info"></i> About Us</a>
            
            <div class="mobile-nav-footer">
                <a href="check_availability.php" style="color: #b8860b; border: 2px solid #b8860b; text-align: center; padding: 12px; margin-top: 10px; border-radius: 4px; justify-content: center; transform: none !important; opacity: 1 !important;">
                    BOOK YOUR STAY
                </a>
                
                <div class="mobile-socials">
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                </div>
                <p style="font-size: 0.7rem; color: #999; margin-top: 20px; text-transform: uppercase; letter-spacing: 1px;">
                    © 2025 AMV Hotel Mamburao
                </p>
            </div>
        </div>

        <div class="booking-stepper">
            <div class="step-item completed">
                <div class="step-icon"><i class="fa-regular fa-calendar-check"></i></div>Check-in & Check-out
            </div>
            <div class="step-item completed">
                <div class="step-icon"><i class="fa-solid fa-bed"></i></div>Select Rooms
            </div>
            <div class="step-item active">
                <div class="step-icon"><i class="fa-regular fa-id-card"></i></div>Guest Info
            </div>
            <div class="step-item">
                <div class="step-icon"><i class="fa-solid fa-check"></i></div>Confirmation
            </div>
        </div>

        <form action="process_guest_info.php" method="POST" id="guestInfoForm">
            <div class="main-container">

                <div class="left-col">
                    <div class="guest-form-container">
                        <div class="form-header">
                            <h2 class="form-title"><i class="fa-solid fa-user"></i> Guest Information</h2>
                        </div>

                        <div class="form-grid-3">
                            <div class="form-group">
                                <label class="form-label">Salutation<span>*</span></label>
                                <select class="form-select" name="salutation" required>
                                    <option value="" disabled selected>- Select -</option>
                                    <option>Mr.</option>
                                    <option>Ms.</option>
                                    <option>Mrs.</option>
                                    <option>Dr.</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">First Name<span>*</span></label>
                                <input type="text" class="form-input" name="first_name" 
                                    oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, '');" 
                                    pattern="[A-Za-z\s]+" 
                                    title="Please enter letters and spaces only"
                                    required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Last Name<span>*</span></label>
                                <input type="text" class="form-input" name="last_name" 
                                    oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, '');" 
                                    pattern="[A-Za-z\s]+" 
                                    title="Please enter letters and spaces only"
                                    required>
                            </div>
                        </div>

                        <div class="form-grid-3">
                            <div class="form-group">
                                <label class="form-label">Gender<span>*</span></label>
                                <select class="form-select" name="gender" required>
                                    <option value="" disabled selected>- Select -</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Prefer not to say</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Birthdate<span>*</span></label>
                                <input type="text" class="form-input custom-date-input" id="birthdate_picker"
                                    name="birthdate" placeholder="YYYY-MM-DD" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Nationality<span>*</span></label>
                                <input type="text" class="form-input" id="nationalityInput" name="nationality"
                                    list="nationality_list" placeholder="- Select -" autocomplete="off" 
                                    oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, '');" 
                                    pattern="[A-Za-z\s]+" 
                                    title="Please enter letters and spaces only"
                                    required>
                                <datalist id="nationality_list"></datalist>
                            </div>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Email Address<span>*</span></label>
                                <input type="email" class="form-input" name="email" required placeholder="example@email.com">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Re-type Email Address<span>*</span></label>
                                <input type="email" class="form-input" name="retype_email" required placeholder="example@email.com">
                            </div>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Contact Number<span>*</span></label>
                                <input type="tel" class="form-input" name="contact_number" 
                                    placeholder="09123456789#" 
                                    maxlength="12"
                                    oninput="this.value = this.value.replace(/[^0-9#]/g, ''); if(this.value.length > 12) this.value = this.value.slice(0, 12);"
                                    title="Please enter 11 digits and a # character."
                                    required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Estimated Arrival Time<span>*</span></label>
                                <select class="form-select" name="arrival_time" id="guest_arrival_time" required>
                                    <option value="" disabled selected>- Select -</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-grid-1">
                            <div class="form-group">
                                <label class="form-label">Address<span>*</span></label>

                                <div style="position: relative;">
                                    <input type="text" class="form-input" id="addressInputDisplay"
                                        placeholder="Start typing address..." autocomplete="off" required>
                                    <input type="hidden" name="address" id="finalAddressInput">
                                    <div class="spinner" id="addressLoader"></div>
                                    <div class="address-results-list" id="addressResultsList"></div>
                                </div>
                            </div>
                        </div>

                        <div class="form-grid-1">
                            <div class="form-group">
                                <label class="form-label">Special Requests</label>
                                <textarea class="form-input" name="requests"
                                    style="height:100px; resize:vertical;"></textarea>
                            </div>
                        </div>

                        <div class="checkbox-wrapper" style="margin-top: 25px;">
                            <input type="checkbox" name="agree_terms" id="agreeTerms" required>
                            <label for="agreeTerms">
                                I have read and agree to the <a href="term_conditions.php" target="_blank"
                                    style="color:#9e8236; text-decoration:none; font-weight:600;">Terms & Conditions</a>
                            </label>
                        </div>
                        <div class="checkbox-wrapper" style="margin-top: 10px;">
                            <input type="checkbox" name="agree_privacy" id="agreePrivacy" required>
                            <label for="agreePrivacy">
                                I have read and agree to the <a href="privacy_policy.php" target="_blank"
                                    style="color:#9e8236; text-decoration:none; font-weight:600;">Privacy Policy</a>
                            </label>
                        </div>
                    </div>

                    <!-- 🟢 NEW: MOBILE PAYMENT SELECTION (Visible only on mobile) -->
                    <div class="mobile-payment-section">
                        <div class="form-header" style="margin-bottom: 20px; padding-bottom: 10px;">
                            <h2 class="form-title" style="font-size: 1.2rem;"><i class="fa-solid fa-credit-card"></i> Payment Details</h2>
                        </div>
                        
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label class="form-label">Payment Method</label>
                            <div style="background: #fcfcfc; padding: 15px; border: 2px solid #eee; border-radius: 8px; display: flex; align-items: center; gap: 12px;">
                                <i class="fa-solid fa-circle-check" style="color: #007bff; font-size: 1.2rem;"></i>
                                <div style="display: flex; flex-direction: column;">
                                    <span style="font-weight: 700; color: #333;">GCash</span>
                                    <span style="font-size: 0.7rem; color: #888;">Instant Confirmation</span>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Choose Payment Plan</label>
                            <select class="form-select" id="mobilePaymentTermSelect" onchange="syncPaymentPlan(this.value)">
                                <option value="full">Full Payment (100%)</option>
                                <option value="partial">50% Down Payment</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="sidebar">
                    <!-- Booking Summary Box -->
                    <div class="summary-box">
                        <div class="summary-title">
                            <i class="fa-solid fa-receipt"></i> Your Booking
                        </div>
                        
                        <div class="summary-row">
                            <span>Check-in</span>
                            <b><?php echo $formatted_checkin; ?></b>
                        </div>
                        <div class="summary-row">
                            <span>Check-out</span>
                            <b><?php echo $formatted_checkout; ?></b>
                        </div>
                        <div class="summary-row" style="border-bottom: 1px solid #f0f0f0; padding-bottom: 15px;">
                            <span>Stay Duration</span>
                            <b><?php echo $nights; ?> Night(s)</b>
                        </div>

                        <div class="summary-row" style="margin-top: 15px;">
                            <span>Guests</span>
                            <b><?php echo $adults; ?> Adults, <?php echo $children; ?> Children</b>
                        </div>

                        <div style="margin: 20px 0; border-top: 1px dashed #ddd; padding-top: 15px;">
                            <span style="font-size: 0.75rem; color: #888; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 10px;">Selected Rooms</span>
                            <?php if (!empty($selected_rooms)): ?>
                                <?php foreach ($selected_rooms as $room): ?>
                                    <div class="selected-room-item">
                                        <span><?php echo htmlspecialchars($room['name']); ?></span>
                                        <b>₱<?php echo number_format($room['price'], 2); ?></b>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p style="color:#999; font-size:0.85rem; text-align:center;">No room selected</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Payment Details Box -->
                    <div class="summary-box" style="margin-top: 25px; border-top-color: #333;">
                        <div class="summary-title" style="margin-bottom: 20px;">
                            <i class="fa-solid fa-credit-card"></i> Payment Details
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="font-size:0.75rem; font-weight:700; color:#888; text-transform:uppercase; display:block; margin-bottom:10px;">Select Method</label>
                            <label class="payment-option" style="display:flex; align-items:center; gap:12px; padding:15px; border:2px solid #eee; border-radius:8px; cursor:pointer; transition:0.3s; background:#fcfcfc;">
                                <input type="radio" name="payment_method" value="GCash" required checked style="width:18px; height:18px; accent-color:#007bff;">
                                <div style="display:flex; flex-direction:column;">
                                    <span style="font-weight:700; color:#007bff; font-size:1rem;">GCash</span>
                                    <span style="font-size:0.75rem; color:#888;">Instant Confirmation</span>
                                </div>
                            </label>
                        </div>

                        <div>
                            <label style="font-size:0.75rem; font-weight:700; color:#888; text-transform:uppercase; display:block; margin-bottom:10px;">Payment Plan</label>
                            <select class="form-select" name="payment_term" id="paymentTermSelect" onchange="updateTotalDisplay()">
                                <option value="full">Full Payment (100%)</option>
                                <option value="partial">50% Down Payment</option>
                            </select>
                        </div>

                        <div class="total-row" style="margin-top: 25px; border-top: 2px solid #f5f5f5; padding-top: 20px;">
                            <span class="total-label" id="payment-status-label">TOTAL AMOUNT</span>
                            <span class="total-amount" id="payment-total-amount">₱<?php echo number_format($total_price, 2); ?></span>
                        </div>

                        <input type="hidden" name="checkin" value="<?php echo htmlspecialchars($checkin); ?>">
                        <input type="hidden" name="checkout" value="<?php echo htmlspecialchars($checkout); ?>">
                        <input type="hidden" name="adults" value="<?php echo $adults; ?>">
                        <input type="hidden" name="children" value="<?php echo $children; ?>">
                        <input type="hidden" name="total_price" value="<?php echo $total_price; ?>">
                        <input type="hidden" name="selected_rooms"
                            value="<?php echo htmlspecialchars($selected_rooms_json, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                        <button type="submit" class="btn-book" style="margin-top: 25px; background: #9e8236;">
                            CONFIRM & BOOK <i class="fa-solid fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div> <!-- end main-container -->

            <div id="mobile-sticky-bar" class="mobile-sticky-bar">
                <div class="sticky-bar-info">
                    <div id="sticky-payment-label" style="font-size: 0.65rem; color: #444; font-weight: 700; line-height: 1.2;">
                        <b><?php echo $formatted_checkin; ?></b> | <?php echo count($selected_rooms); ?> Room(s)
                    </div>
                    <div id="sticky-payment-summary" style="font-size: 0.6rem; color: #666; font-weight: 600; margin-top: 1px;">
                        GCash - Full Payment
                    </div>
                    <div style="margin-top: 3px;">
                        <span class="sticky-bar-label" id="sticky-label">Total Amount</span>
                        <b id="sticky-total">₱<?php echo number_format($total_price, 2); ?></b>
                    </div>
                </div>
                <button type="submit" class="btn-sticky-confirm">
                    CONFIRM <i class="fa-solid fa-chevron-right"></i>
                </button>
            </div>
        </form>
    </div>

    <footer class="simple-footer">
        <div class="simple-footer-content">
            <a href="term_conditions.php" class="footer-link">Terms & Conditions</a>
            <span class="footer-separator">|</span>
            <a href="privacy_policy.php" class="footer-link">Privacy Policy</a>
            <span class="footer-separator">|</span>
            <div class="footer-link"><i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($hotel_phone); ?>
            </div>
            <span class="footer-separator">|</span>
            <div class="footer-link"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($hotel_email); ?></div>
        </div>
        <div class="simple-copyright">© 2025 AMV Hotel. All rights reserved.</div>
    </footer>

    <!-- Custom Alert Modal -->
    <div id="jsAlertModal" class="custom-modal-overlay">
        <div class="custom-modal-box">
            <i class="fas fa-exclamation-circle custom-modal-icon" id="jsAlertIcon"></i>
            <div class="custom-modal-title" id="jsAlertTitle">Alert</div>
            <p class="custom-modal-msg" id="jsAlertMsg">Something went wrong.</p>
            <button class="custom-modal-btn" onclick="closeJsAlert()">Okay</button>
        </div>
    </div>

    <script>
    function showCustomAlert(title, message, iconClass = 'fa-exclamation-circle') {
        const modal = document.getElementById('jsAlertModal');
        const titleEl = document.getElementById('jsAlertTitle');
        const msgEl = document.getElementById('jsAlertMsg');
        const iconEl = document.getElementById('jsAlertIcon');

        if (modal && titleEl && msgEl) {
            titleEl.innerText = title;
            msgEl.innerText = message;
            if (iconEl) {
                iconEl.className = 'fas ' + iconClass + ' custom-modal-icon';
            }
            modal.classList.add('show');
        } else {
            alert(message);
        }
    }

    function closeJsAlert() {
        const modal = document.getElementById('jsAlertModal');
        if (modal) {
            modal.classList.remove('show');
        }
    }

    window.addEventListener('click', function(event) {
        const modal = document.getElementById('jsAlertModal');
        if (event.target === modal) {
            closeJsAlert();
        }
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <script>
        function toggleMobileMenu() {
            const menu = document.getElementById('mobileMenu');
            const backdrop = document.getElementById('mobileBackdrop');
            const burger = document.getElementById('burgerIcon');
            const body = document.body;
            
            menu.classList.toggle('active');
            backdrop.classList.toggle('active');
            burger.classList.toggle('active');
            body.classList.toggle('menu-open');
        }

        // --- NEW: MOBILE PAYMENT SECTION CSS ---

        function syncPaymentPlan(val) {
            // Sync the desktop selector
            const desktopSelect = document.getElementById('paymentTermSelect');
            const mobileSelect = document.getElementById('mobilePaymentTermSelect');
            
            if (desktopSelect) desktopSelect.value = val;
            if (mobileSelect) mobileSelect.value = val;

            // Trigger the UI update
            updateTotalDisplay();
            
            // If using custom selects, refresh them
            if (typeof refreshGuestCustomSelect === 'function') {
                refreshGuestCustomSelect('paymentTermSelect');
            }
        }

        function updateTotalDisplay() {
            const fullTotal = <?php echo $total_price; ?>;
            const term = document.getElementById('paymentTermSelect').value;
            const totalEl = document.getElementById('payment-total-amount');
            const labelEl = document.getElementById('payment-status-label');

            // Sticky Bar Elements
            const stickyTotalEl = document.getElementById('sticky-total');
            const stickyLabelEl = document.getElementById('sticky-label');
            const stickySummaryEl = document.getElementById('sticky-payment-summary');

            if (!totalEl || !labelEl) return;

            // Animate
            totalEl.style.transform = "scale(1.1)";
            setTimeout(() => totalEl.style.transform = "scale(1)", 200);

            let displayPrice = "";
            let displayLabel = "";
            let displayColor = "#9e8236";

            if (term === 'partial') {
                const downPayment = fullTotal / 2;
                displayLabel = "DUE NOW (50%)";
                displayPrice = "₱" + downPayment.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                displayColor = "#E67E22";
            } else {
                displayLabel = "TOTAL AMOUNT";
                displayPrice = "₱" + fullTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                displayColor = "#9e8236";
            }

            // Update Desktop Sidebar
            labelEl.innerText = displayLabel;
            totalEl.innerText = displayPrice;
            totalEl.style.color = displayColor;

            // Update Mobile Sticky Bar
            if (stickyTotalEl) {
                stickyTotalEl.innerText = displayPrice;
                stickyTotalEl.style.color = displayColor;
            }
            if (stickyLabelEl) {
                stickyLabelEl.innerText = displayLabel;
            }

            // Update Sticky Payment Summary (Method + Plan)
            if (stickySummaryEl) {
                const method = document.querySelector('input[name="payment_method"]:checked')?.value || "GCash";
                const termText = term === 'partial' ? '50% Downpayment' : 'Full Payment';
                stickySummaryEl.innerText = `${method} - ${termText}`;
            }
        }

        // Add Listeners for Payment Method Changes
        document.addEventListener("DOMContentLoaded", function() {
            const paymentRadios = document.querySelectorAll('input[name="payment_method"]');
            paymentRadios.forEach(radio => {
                radio.addEventListener('change', updateTotalDisplay);
            });
            // Initial Call
            updateTotalDisplay();
        });

        // --- FLATPICKR ---
        document.addEventListener("DOMContentLoaded", function () {
            const today = new Date();
            const legalAgeDate = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate());

            flatpickr("#birthdate_picker", {
                disableMobile: true,
                dateFormat: "Y-m-d",
                allowInput: true,
                maxDate: legalAgeDate,
                onClose: function (selectedDates, dateStr, instance) {
                    if (selectedDates.length === 0 && dateStr !== "") {
                        showCustomAlert("Invalid Date", "Invalid date or format. Please use YYYY-MM-DD.");
                        instance.clear();
                    }
                    if (selectedDates.length > 0) {
                        if (selectedDates[0] > legalAgeDate) {
                            showCustomAlert("Age Restriction", "Guest must be at least 18 years old.");
                            instance.clear();
                        }
                    }
                }
            });
        });

        // --- NATIONALITY DROPDOWN LOGIC ---
        document.addEventListener("DOMContentLoaded", function () {
            const nationalities = [
                "Afghan", "Albanian", "Algerian", "American", "Andorran", "Angolan", "Antiguans", "Argentinean", "Armenian", "Australian", "Austrian", "Azerbaijani",
                "Bahamian", "Bahraini", "Bangladeshi", "Barbadian", "Barbudans", "Batswana", "Belarusian", "Belgian", "Belizean", "Beninese", "Bhutanese", "Bolivian",
                "Bosnian", "Brazilian", "British", "Bruneian", "Bulgarian", "Burkinabe", "Burmese", "Burundian", "Cambodian", "Cameroonian", "Canadian", "Cape Verdean",
                "Central African", "Chadian", "Chilean", "Chinese", "Colombian", "Comoran", "Congolese", "Costa Rican", "Croatian", "Cuban", "Cypriot", "Czech",
                "Danish", "Djibouti", "Dominican", "Dutch", "East Timorese", "Ecuadorean", "Egyptian", "Emirian", "Equatorial Guinean", "Eritrean", "Estonian",
                "Ethiopian", "Fijian", "Filipino", "Finnish", "French", "Gabonese", "Gambian", "Georgian", "German", "Ghanaian", "Greek", "Grenadian", "Guatemalan",
                "Guinea-Bissauan", "Guinean", "Guyanese", "Haitian", "Herzegovinian", "Honduran", "Hungarian", "Icelander", "Indian", "Indonesian", "Iranian", "Iraqi",
                "Irish", "Israeli", "Italian", "Ivorian", "Jamaican", "Japanese", "Jordanian", "Kazakhstani", "Kenyan", "Kittian and Nevisian", "Kuwaiti", "Kyrgyz",
                "Laotian", "Latvian", "Lebanese", "Liberian", "Libyan", "Liechtensteiner", "Lithuanian", "Luxembourger", "Macedonian", "Malagasy", "Malawian",
                "Malaysian", "Maldivan", "Malian", "Maltese", "Marshallese", "Mauritanian", "Mauritian", "Mexican", "Micronesian", "Moldovan", "Monacan", "Mongolian",
                "Moroccan", "Mosotho", "Motswana", "Mozambican", "Namibian", "Nauruan", "Nepalese", "New Zealander", "Ni-Vanuatu", "Nicaraguan", "Nigerien",
                "North Korean", "Northern Irish", "Norwegian", "Omani", "Pakistani", "Palauan", "Panamanian", "Papua New Guinean", "Paraguayan", "Peruvian", "Polish",
                "Portuguese", "Qatari", "Romanian", "Russian", "Rwandan", "Saint Lucian", "Salvadoran", "Samoan", "San Marinese", "Sao Tomean", "Saudi", "Scottish",
                "Senegalese", "Serbian", "Seychellois", "Sierra Leonean", "Singaporean", "Slovakian", "Slovenian", "Solomon Islander", "Somali", "South African",
                "South Korean", "Spanish", "Sri Lankan", "Sudanese", "Surinamer", "Swazi", "Swedish", "Swiss", "Syrian", "Taiwanese", "Tajik", "Tanzanian", "Thai",
                "Togolese", "Tongan", "Trinidadian or Tobagonian", "Tunisian", "Turkish", "Tuvaluan", "Ugandan", "Ukrainian", "Uruguayan", "Uzbekistani", "Venezuelan",
                "Vietnamese", "Welsh", "Yemenite", "Zambian", "Zimbabwean"
            ];

            const dataList = document.getElementById('nationality_list');
            if (dataList) {
                let optionsHTML = '';
                nationalities.forEach(nation => {
                    optionsHTML += `<option value="${nation}">`;
                });
                dataList.innerHTML = optionsHTML;
            }

            const input = document.getElementById('nationalityInput');
            if (input) {
                input.addEventListener('change', function () {
                    if (!nationalities.includes(this.value)) {
                        this.setCustomValidity("Please select a valid nationality from the list.");
                    } else {
                        this.setCustomValidity("");
                    }
                });
                input.addEventListener('input', function () {
                    this.setCustomValidity("");
                });
            }
        });

        // --- ADDRESS AUTOCOMPLETE LOGIC ---
        const addrInput = document.getElementById('addressInputDisplay');
        const addrList = document.getElementById('addressResultsList');
        const addrLoader = document.getElementById('addressLoader');
        const addrHidden = document.getElementById('finalAddressInput');
        let debounceTimer;

        if (addrInput) {
            addrInput.addEventListener('input', function () {
                const query = this.value.trim();
                clearTimeout(debounceTimer);

                if (query.length < 3) {
                    addrList.style.display = 'none';
                    return;
                }
                debounceTimer = setTimeout(() => {
                    fetchAddress(query);
                }, 600);
            });
        }

        function fetchAddress(query) {
            addrLoader.style.display = 'block';
            const url = `search_address.php?q=${encodeURIComponent(query)}`;
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    addrLoader.style.display = 'none';
                    renderAddressResults(data);
                })
                .catch(err => {
                    console.error(err);
                    addrLoader.style.display = 'none';
                });
        }

        function renderAddressResults(data) {
            addrList.innerHTML = '';
            if (data.length === 0) {
                addrList.style.display = 'none';
                return;
            }
            data.forEach(place => {
                const item = document.createElement('div');
                item.className = 'address-result-item';
                item.innerText = place.display_name;
                item.onclick = () => {
                    addrInput.value = place.display_name;
                    addrHidden.value = place.display_name;
                    addrList.style.display = 'none';
                };
                addrList.appendChild(item);
            });
            addrList.style.display = 'block';
        }

        document.addEventListener('click', function (e) {
            if (addrInput && e.target !== addrInput && e.target !== addrList) {
                addrList.style.display = 'none';
            }
        });

        // --- ROBUST STICKY STEPPER ---
        document.addEventListener("DOMContentLoaded", function () {
            const stepper = document.querySelector('.booking-stepper');
            if (stepper) {
                window.addEventListener('scroll', function () {
                    if (window.scrollY > 50) {
                        stepper.classList.add('scrolled');
                    } else {
                        stepper.classList.remove('scrolled');
                    }
                });
            }
        });

        // --- HANDLE SUBMIT LOADER ---
       // --- 🟢 UPDATED SUBMIT HANDLER ---
        // We target the form by ID now
        const bookingForm = document.getElementById('guestInfoForm');
        
        if (bookingForm) {
            bookingForm.addEventListener('submit', function (e) {
                
                // 1. Validate Emails Match
                const email = this.querySelector('input[name="email"]').value;
                const retypeEmail = this.querySelector('input[name="retype_email"]').value;
                if (email !== retypeEmail) {
                    e.preventDefault();
                    showCustomAlert("Email Mismatch", "The email addresses you entered do not match. Please check and try again.", "fa-envelope");
                    return;
                }

                // 2. Validate Custom Dropdowns
                const arrivalSelect = document.getElementById('guest_arrival_time');
                if (arrivalSelect && arrivalSelect.value === "") {
                    e.preventDefault();
                    showCustomAlert("Required Field", "Please select an Estimated Arrival Time.", "fa-clock");
                    // Scroll to it
                    arrivalSelect.closest('.form-group').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }

                // 2. Show Loader
                const btn = this.querySelector('.btn-submit');
                if(btn) {
                    const originalText = btn.innerHTML;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> PROCESSING...';
                    btn.style.opacity = '0.7';
                    btn.style.pointerEvents = 'none';
                }

                // 3. Let the form submit naturally to process_guest_info.php
            });
        }

        // --- 🟢 NEW: CUSTOM SELECT INITIALIZATION 🟢 ---
        document.addEventListener("DOMContentLoaded", function () {
            initCustomSelects();
        });

        function initCustomSelects() {
            // Find all <select> elements with class .form-select
            const selects = document.querySelectorAll('.form-select');

            selects.forEach(originalSelect => {
                // Skip if already custom-built to avoid duplicates
                if (originalSelect.nextElementSibling && originalSelect.nextElementSibling.classList.contains('custom-select-wrapper')) {
                    return;
                }

                // Create the Wrapper
                const wrapper = document.createElement('div');
                wrapper.classList.add('custom-select-wrapper');

                // Create the Trigger (The box you click)
                const trigger = document.createElement('div');
                trigger.classList.add('custom-select-trigger');

                // Set initial text (placeholder or selected value)
                const selectedOption = originalSelect.options[originalSelect.selectedIndex];
                trigger.innerHTML = `<span>${selectedOption.text}</span> <i class="fa-solid fa-chevron-down custom-arrow"></i>`;

                // Create the Options Container
                const optionsDiv = document.createElement('div');
                optionsDiv.classList.add('custom-options');

                // Loop through original options and recreate them as DIVs
                Array.from(originalSelect.options).forEach(option => {
                    // Skip disabled placeholder options if you want, or style them differently
                    if (option.disabled) return;

                    const divOption = document.createElement('div');
                    divOption.classList.add('custom-option');
                    divOption.innerText = option.text;
                    divOption.dataset.value = option.value;

                    // Mark selected
                    if (option.selected) divOption.classList.add('selected');

                    // Click Event for Option
                    divOption.addEventListener('click', function () {
                        // 1. Update Trigger Text
                        trigger.querySelector('span').innerText = this.innerText;

                        // 2. Update Visual Selection
                        optionsDiv.querySelectorAll('.custom-option').forEach(opt => opt.classList.remove('selected'));
                        this.classList.add('selected');

                        // 3. Update Original Hidden Select Value
                        originalSelect.value = this.dataset.value;

                        // 4. Close Dropdown
                        wrapper.classList.remove('open');

                        // 5. 🟢 IMPORTANT: Trigger 'change' event manually so other scripts react (e.g. Total Price update)
                        const event = new Event('change');
                        originalSelect.dispatchEvent(event);
                    });

                    optionsDiv.appendChild(divOption);
                });

                // Assemble DOM
                wrapper.appendChild(trigger);
                wrapper.appendChild(optionsDiv);

                // Insert after the hidden original select
                originalSelect.parentNode.insertBefore(wrapper, originalSelect.nextSibling);

                // Trigger Click Event (Toggle Open/Close)
                trigger.addEventListener('click', function (e) {
                    e.stopPropagation();
                    // Close all other open selects first
                    document.querySelectorAll('.custom-select-wrapper').forEach(ws => {
                        if (ws !== wrapper) ws.classList.remove('open');
                    });
                    wrapper.classList.toggle('open');
                });
            });

            // Global Click to Close All Selects
            document.addEventListener('click', function (e) {
                if (!e.target.closest('.custom-select-wrapper')) {
                    document.querySelectorAll('.custom-select-wrapper').forEach(ws => ws.classList.remove('open'));
                }
            });
        }

        /* --- 🟢 SMART ARRIVAL TIME LOGIC (Guest Side) --- */

        document.addEventListener("DOMContentLoaded", function () {
            generateGuestArrivalTimes();
        });

        // 🟢 ROBUST STICKY STEPPER SCRIPT
        document.addEventListener("DOMContentLoaded", function () {
            const stepper = document.querySelector('.booking-stepper');

            if (stepper) {
                window.addEventListener('scroll', function () {
                    if (window.scrollY > 50) {
                        stepper.classList.add('scrolled');
                    } else {
                        stepper.classList.remove('scrolled');
                    }
                });
            }
        });

        function generateGuestArrivalTimes() {
            // 1. Get the Check-In Date (Hidden Input from PHP)
            const checkinInput = document.querySelector('input[name="checkin"]');
            if (!checkinInput) return;

            const checkinDate = checkinInput.value; // Format: YYYY-MM-DD
            const select = document.getElementById('guest_arrival_time');

            // Clear options
            select.innerHTML = '<option value="" disabled selected>- Select -</option>';

            // 2. Date Setup
            const now = new Date();
            // Create a date object for "Today" (Midnight) to compare strings accurately
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const todayStr = `${year}-${month}-${day}`;

            // 3. Logic: Standard 2 PM (14:00) to 8 PM (20:00)
            let startHour = 14;
            let endHour = 20;

            // If check-in is TODAY, check current time
            if (checkinDate === todayStr) {
                const currentHour = now.getHours();
                // If it's already past 2 PM, start from the next hour
                if (currentHour >= 14) {
                    startHour = currentHour + 1;
                }
            }

            // 4. Generate Options
            for (let h = startHour; h <= endHour; h++) {
                let realHour24 = h;
                let displayHour12 = realHour24;
                let suffix = 'AM';

                if (displayHour12 >= 12) {
                    suffix = 'PM';
                    if (displayHour12 > 12) displayHour12 -= 12;
                }
                if (displayHour12 === 0) displayHour12 = 12;

                // Value: "14:00"
                let valueStr = (realHour24 < 10 ? '0' : '') + realHour24 + ':00';
                // Text: "02:00 PM"
                let textStr = (displayHour12 < 10 ? '0' : '') + displayHour12 + ':00 ' + suffix;

                let opt = document.createElement('option');
                opt.value = valueStr;
                opt.innerText = textStr;
                select.appendChild(opt);

                // Add :30 interval (stop before the final hour)
                if (h < endHour) {
                    let halfValue = (realHour24 < 10 ? '0' : '') + realHour24 + ':30';
                    let halfText = (displayHour12 < 10 ? '0' : '') + displayHour12 + ':30 ' + suffix;
                    let halfOpt = document.createElement('option');
                    halfOpt.value = halfValue;
                    halfOpt.innerText = halfText;
                    select.appendChild(halfOpt);
                }
            }

            // 5. 🟢 REFRESH CUSTOM DROPDOWN (Important!)
            // This makes sure your custom-styled dropdown sees the new options
            refreshGuestCustomSelect('guest_arrival_time');
        }

        // --- Helper to refresh just one specific custom select ---
        function refreshGuestCustomSelect(selectId) {
            const originalSelect = document.getElementById(selectId);
            if (!originalSelect) return;

            // Find the custom wrapper next to it
            const wrapper = originalSelect.nextElementSibling;
            if (!wrapper || !wrapper.classList.contains('custom-select-wrapper')) return;

            const optionsContainer = wrapper.querySelector('.custom-options');
            const triggerSpan = wrapper.querySelector('.custom-select-trigger span');

            // Clear visual options
            optionsContainer.innerHTML = '';

            // Rebuild from new options
            Array.from(originalSelect.options).forEach(option => {
                if (option.disabled) return; // Skip placeholder

                const divOption = document.createElement('div');
                divOption.classList.add('custom-option');
                divOption.innerText = option.text;
                divOption.dataset.value = option.value;

                if (option.selected) {
                    divOption.classList.add('selected');
                    triggerSpan.innerText = option.text;
                }

                divOption.addEventListener('click', function (e) {
                    e.stopPropagation();
                    triggerSpan.innerText = this.innerText;

                    optionsContainer.querySelectorAll('.custom-option').forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');

                    originalSelect.value = this.dataset.value;
                    originalSelect.dispatchEvent(new Event('change'));

                    wrapper.classList.remove('open');
                });

                optionsContainer.appendChild(divOption);
            });

            // Reset trigger text if empty
            if (originalSelect.value === "") {
                triggerSpan.innerText = "- Select -";
            }
        }
    </script>
    <?php if (isset($_GET['msg_success']) && $_GET['msg_success'] == 1): ?>
        <script>window.addEventListener('load', function () { alert('Message successfully sent to Admin!'); });</script>
    <?php endif; ?>

    <?php if (isset($_GET['error']) && $_GET['error'] !== 'too_many_pending'): ?>
        <script>
            window.addEventListener('load', function() {
                const error = "<?php echo $_GET['error']; ?>";
                if (error === 'invalid_payment') {
                    alert('Invalid Payment: The selected payment method is not supported.');
                }
            });
        </script>
    <?php endif; ?>

    <?php include 'reusable_modals.php'; ?>
</body>

</html>
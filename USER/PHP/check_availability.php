<?php
// USER/PHP/check_availability.php

// 0. SET TIMEZONE FIRST
date_default_timezone_set('Asia/Manila');

// 1. Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
// Added upgrade-insecure-requests
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https://*.google.com https://*.googleapis.com; frame-src 'self' https://*.google.com; upgrade-insecure-requests;");

// 2. Secure Session Settings
if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => true, // Ensure this is TRUE for production
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// 3. Disable Error Reporting to Screen
ini_set('display_errors', 0);
error_reporting(E_ALL);

require 'db_connect.php';

// --- 4. FETCH ADMIN CONTACT INFO ---
$admin_sql = "SELECT email, contact_number FROM admin_user LIMIT 1";
$admin_result = mysqli_query($conn, $admin_sql);

if ($admin_result && mysqli_num_rows($admin_result) > 0) {
    $admin_data = mysqli_fetch_assoc($admin_result);
    // Sanitize output
    $hotel_email = !empty($admin_data['email']) ? htmlspecialchars($admin_data['email']) : 'info@amvhotel.com';
    $hotel_phone = !empty($admin_data['contact_number']) ? htmlspecialchars($admin_data['contact_number']) : '+63 945 343 455';
} else {
    $hotel_email = 'info@amvhotel.com';
    $hotel_phone = '+63 945 343 455';
}

$hotel_phone_link = preg_replace('/[^0-9+]/', '', $hotel_phone);

// 5. CSRF Token
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

// 🟢 6. CUSTOM MODAL LOGIC (PHP Side)
$showCutoffModal = false;

if (isset($_GET['checkin'])) {
    // Sanitize GET input
    $checkinDate = htmlspecialchars($_GET['checkin']); 
    $serverToday = date('Y-m-d');
    $serverHour = (int) date('H');

    if ($checkinDate === $serverToday && $serverHour >= 20) {
        $showCutoffModal = true;
        // Clear variables to prevent form filling
        $_GET['checkin'] = '';
        $_GET['checkout'] = '';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Availability - AMV Hotel</title>
    <link rel="icon" type="image/png" href="../../IMG/5.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

    <link rel="stylesheet" href="../STYLE/home_page.css">
    <link rel="stylesheet" href="../STYLE/utilities.css">

    <style>
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

        /* --- GLOBAL RESET & FONTS --- */
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #f9f9f9;
            margin: 0;
            padding: 0;
            color: #333;
            overflow-x: hidden;
        }

        /* --- WRAPPER FOR REVEAL EFFECT --- */
        .page-content-wrapper {
            background-color: #f9f9f9;
            position: relative;
            z-index: 10;
            margin-bottom: 120px;
            padding-bottom: 60px;
            min-height: 100vh;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        /* --- HEADER STYLES --- */
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
            z-index: 1100;
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
            z-index: 20001;
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
            z-index: 20000;
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

        /* --- MAIN BOOKING CONTAINER --- */
        .booking-container {
            max-width: 1200px;
            margin: 40px auto;
            background: #fff;
            padding: 40px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            border-radius: 4px;
        }

        @media (max-width: 768px) {
            .booking-container {
                margin: 20px 15px;
                padding: 20px;
            }
        }

        /* --- FORM CONTROLS --- */
        .booking-controls {
            display: flex;
            gap: 20px;
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 1px solid #eee;
        }

        .control-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .control-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            color: #888;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .control-input-box {
            border: 1px solid #ddd;
            padding: 15px;
            font-size: 1rem;
            color: #333;
            background: #fff;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            position: relative;
            user-select: none;
            transition: all 0.3s ease;
        }

        .control-input-box:hover,
        .control-input-box.active {
            border-color: #9e8236;
            box-shadow: 0 0 0 2px rgba(158, 130, 54, 0.1);
        }

        .control-input-box i {
            color: #9e8236;
        }

        /* CUSTOM FLATPICKR INPUT */
        .custom-flatpickr {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
            z-index: 2;
        }

        /* FLATPICKR GOLD THEME */
        .flatpickr-day.selected,
        .flatpickr-day.startRange,
        .flatpickr-day.endRange,
        .flatpickr-day.selected:hover,
        .flatpickr-day.startRange:hover,
        .flatpickr-day.endRange:hover {
            background: #9e8236;
            border-color: #9e8236;
        }

        .flatpickr-day.inRange {
            box-shadow: -5px 0 0 #f7ecb5, 5px 0 0 #f7ecb5;
        }

        @media (max-width: 768px) {
            .booking-controls {
                flex-direction: column;
                gap: 15px;
            }
        }

        /* --- GUEST DROPDOWN (ANIMATED) --- */
        .guest-dropdown {
            display: block;
            position: absolute;
            top: 110%;
            left: 0;
            width: 100%;
            background: #fff;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            border-radius: 8px;
            padding: 20px;
            z-index: 100;
            border: 1px solid #eee;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .guest-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        @media (max-width: 768px) {
            .guest-dropdown {
                width: 100%;
                left: 0;
            }
        }

        .guest-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .guest-label {
            display: flex;
            flex-direction: column;
        }

        .guest-type {
            font-weight: 600;
            font-size: 1rem;
            color: #333;
        }

        .guest-age {
            font-size: 0.75rem;
            color: #888;
        }

        .guest-counter {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .counter-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 1px solid #ccc;
            background: #fff;
            color: #555;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: 0.2s;
            padding-bottom: 3px;
        }

        .counter-btn:hover {
            border-color: #9e8236;
            color: #9e8236;
        }

        .counter-btn.disabled {
            opacity: 0.3;
            cursor: not-allowed;
            border-color: #eee;
            color: #ccc;
            pointer-events: none;
        }

        .counter-val {
            font-weight: 600;
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }

        .btn-done {
            width: 100%;
            background-color: #9e8236;
            color: #fff;
            border: none;
            padding: 12px;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            text-transform: uppercase;
        }

        .btn-done:hover {
            background-color: #8c7330;
        }

        /* --- CALENDAR STYLES --- */
        .calendars-row {
            display: flex;
            gap: 40px;
            margin-bottom: 30px;
        }

        .calendar-wrapper {
            flex: 1;
        }

        /* --- CALENDAR HINT TOAST --- */
        .calendar-hint-toast {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            background: rgba(158, 130, 54, 0.95);
            color: #fff;
            padding: 12px 25px;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
            z-index: 2000;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            opacity: 0;
            visibility: hidden;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            display: flex;
            align-items: center;
            gap: 10px;
            pointer-events: none;
        }

        .calendar-hint-toast.show {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(0);
        }

        .calendar-hint-toast i {
            font-size: 1.1rem;
        }

        @media (max-width: 768px) {
            .calendar-hint-toast {
                width: 85%;
                max-width: 300px;
                padding: 10px 15px;
                font-size: 0.8rem;
                bottom: 30px;
                margin-bottom: 60px;
            }
            .calendar-hint-toast i {
                font-size: 1rem;
            }
        }

        /* --- MOBILE STICKY BAR (Synced with select_rooms) --- */
        .mobile-sticky-bar {
            display: none;
            position: fixed;
            bottom: 0;
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
            height: auto;
            min-height: 75px;
            transform: translateZ(0);
            -webkit-transform: translateZ(0);
        }

        @media (max-width: 900px) {
            .mobile-sticky-bar {
                display: flex;
            }
            .btn-continue {
                display: none !important;
            }
            body {
                padding-bottom: 90px !important;
            }
        }

        .sticky-bar-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
            max-width: 60%;
        }

        .sticky-summary-details {
            font-size: 0.65rem;
            color: #444;
            font-weight: 700;
            display: flex;
            flex-direction: column;
            line-height: 1.3;
        }

        .sticky-summary-details span {
            color: #999;
            font-weight: 500;
            font-size: 0.6rem;
            padding-bottom: 5px;
            text-transform: uppercase;
        }

        .btn-sticky-next {
            background: #222;
            color: #fff;
            border: none;
            padding: 14px 22px;
            border-radius: 8px;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: 0.3s;
        }

        .btn-sticky-next:disabled {
            background: #eee;
            color: #aaa;
            box-shadow: none;
        }

        .btn-sticky-next:active:not(:disabled) {
            transform: scale(0.95);
            background: #9e8236;
        }

        /* Shake animation for calendar to draw attention */
        @keyframes cal-shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        .cal-attention {
            animation: cal-shake 0.4s ease-in-out 2;
        }

        .calendar-header {
            background-color: #333;
            color: #fff;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 4px 4px 0 0;
        }

        .cal-nav-btn {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0 10px;
            transition: color 0.3s;
        }

        .cal-nav-btn:hover {
            color: #9e8236;
        }

        .cal-nav-btn:disabled {
            color: #555;
            cursor: not-allowed;
            opacity: 0.5;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            border: 1px solid #ddd;
            border-top: none;
        }

        .cal-cell {
            padding: 15px 5px;
            text-align: center;
            font-size: 0.9rem;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            position: relative;
            z-index: 1;
        }

        .cal-head {
            font-weight: 700;
            color: #9e8236;
            font-size: 0.75rem;
            text-transform: uppercase;
            padding-top: 15px;
            padding-bottom: 10px;
            text-align: center;
        }

        @media (max-width: 900px) {
            .calendars-row {
                flex-direction: column;
                gap: 20px;
            }

            .cal-cell {
                padding: 10px 2px;
                height: 45px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
        }

        .cal-date {
            color: #333;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .cal-date:not(.disabled):not(.selected):not(.hover-range):hover {
            background-color: #f0f0f0;
            transform: scale(1.1);
            z-index: 5;
            border-radius: 4px;
        }

        .cal-date.disabled {
            color: #ccc;
            cursor: not-allowed;
            background-color: #f9f9f9;
            pointer-events: none;
        }

        .cal-date.selected {
            background-color: #9e8236;
            color: #fff;
            border-radius: 4px;
            transform: scale(1.05);
            z-index: 2;
            box-shadow: 0 4px 10px rgba(158, 130, 54, 0.3);
        }

        .cal-date.range {
            background-color: rgba(158, 130, 54, 0.15);
        }

        .cal-date.hover-range {
            background-color: rgba(158, 130, 54, 0.3);
        }

        /* Dynamic Nights Tooltip */
        #nights-tooltip {
            position: fixed;
            background: #333;
            color: #fff;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 0.75rem;
            pointer-events: none;
            display: none;
            z-index: 10000;
            white-space: nowrap;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            transform: translate(-50%, -120%);
            font-weight: 600;
        }

        #nights-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: #333;
        }

        .control-input-box {
            transition: all 0.3s ease;
        }

        .control-input-box.active {
            border-color: #9e8236;
            background-color: rgba(158, 130, 54, 0.05);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(158, 130, 54, 0.1);
        }
        

        /* --- ACTION BAR --- */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .legend {
            display: flex;
            gap: 20px;
            font-size: 0.8rem;
            color: #666;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .legend-box {
            width: 15px;
            height: 15px;
            border-radius: 2px;
        }

        .box-available {
            border: 1px solid #ddd;
            background: #fff;
        }

        .box-selected {
            background: #9e8236;
        }

        .box-disabled {
            background: #eee;
        }

        .btn-continue {
            background-color: #9e8236;
            color: #fff;
            border: none;
            padding: 15px 40px;
            font-size: 1rem;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
            transition: 0.3s;
            border-radius: 4px;
        }

        .btn-continue:hover {
            background-color: #8c7330;
        }

        @media (max-width: 768px) {
            .action-bar {
                flex-direction: column;
                gap: 25px;
                align-items: center;
                padding: 20px 0;
                text-align: center;
            }

            .legend {
                justify-content: center;
                gap: 15px;
                width: 100%;
            }

            .btn-continue {
                width: 100%;
                max-width: 280px;
                padding: 12px 20px;
                font-size: 0.9rem;
            }
        }

        /* --- SIMPLE FIXED FOOTER (Desktop) --- */
        .simple-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            height: auto;
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
            .simple-footer {
                position: relative;
                height: auto;
                box-shadow: none;
                border-top: 1px solid #ddd;
                padding-top: 10px;
            }

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

        /* --- FLOATING CHAT BUTTON --- */
        .floating-chat-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: #2D0F35;
            color: #D4AF37;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            z-index: 1001;
        }

        .floating-chat-btn:hover {
            transform: scale(1.1) translateY(-5px);
            background-color: #D4AF37;
            color: #2D0F35;
        }

        /* --- CHAT BUBBLE --- */
        .chat-bubble-container {
            position: fixed;
            bottom: 100px;
            right: 30px;
            z-index: 9999;
            width: 320px;
            background-color: #fff;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 20px;
            border: 1px solid #f0f0f0;
            border-radius: 8px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(20px) scale(0.95);
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            font-family: 'Montserrat', sans-serif;
        }

        .chat-bubble-container.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }

        .bubble-header {
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
            font-size: 1.1rem;
        }

        .bubble-sub {
            font-size: 0.8rem;
            color: #888;
            display: block;
            margin-bottom: 15px;
        }

        .chat-input {
            width: 100%;
            padding: 12px;
            margin-bottom: 15px;
            border: 1px solid #eee;
            font-size: 0.85rem;
            background: #fcfcfc;
            border-radius: 4px;
            box-sizing: border-box;
            font-family: 'Montserrat', sans-serif;
        }

        .chat-textarea {
            resize: vertical;
            min-height: 80px;
        }

        .btn-chat-send {
            width: 100%;
            background-color: #D4AF37;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 4px;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.85rem;
            text-transform: uppercase;
            transition: background 0.3s;
            font-family: 'Montserrat', sans-serif;
        }

        .btn-chat-send:hover {
            background-color: #b8860b;
        }

        .close-bubble {
            position: absolute;
            top: 15px;
            right: 15px;
            cursor: pointer;
            color: #ccc;
        }

        @media (max-width: 480px) {
            .chat-bubble-container {
                right: 5%;
                bottom: 90px;
                width: 90%;
            }

            .floating-chat-btn {
                bottom: 20px;
                right: 20px;
                width: 50px;
                height: 50px;
                font-size: 1.2rem;
            }
        }

        /* 🔴 CUSTOM ALERT MODAL CSS (UPDATED FOR SMOOTH SCALE) */
        .custom-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;

            /* 1. Layout: Always Flex (to keep centering) */
            display: flex;
            justify-content: center;
            align-items: center;

            /* 2. Hidden State (Allows transition) */
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        /* 3. Visible State */
        .custom-modal-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .custom-modal-box {
            background: white;
            width: 90%;
            max-width: 400px;
            padding: 30px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);

            /* 4. Animation Start: Scaled Down & Transparent */
            transform: scale(0.8);
            opacity: 0;

            /* 5. The "Pop" Effect (Cubic Bezier makes it smooth/bouncy) */
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s ease;
        }

        /* 6. Animation End: Full Size */
        .custom-modal-overlay.show .custom-modal-box {
            transform: scale(1);
            opacity: 1;
        }

        /* Icons & Text (No changes needed here) */
        .custom-modal-icon {
            font-size: 3rem;
            color: #D4AF37;
            margin-bottom: 20px;
        }

        .custom-modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 10px;
        }

        .custom-modal-msg {
            font-size: 0.95rem;
            color: #666;
            margin-bottom: 25px;
            line-height: 1.5;
        }

        .custom-modal-btn {
            background: #D4AF37;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: background 0.2s;
            text-transform: uppercase;
        }

        .custom-modal-btn:hover {
            background: #b89324;
        }

        /* 🟢 CELLPHONE-ONLY UX OPTIMIZATIONS (Isolated) 🟢 */
        @media (max-width: 768px) {
            /* 1. Check-in and out in one row, smaller */
            .booking-controls {
                flex-direction: row !important;
                flex-wrap: wrap !important;
                gap: 8px !important;
            }
            .control-group:nth-child(1), 
            .control-group:nth-child(2) {
                flex: 1 !important;
                min-width: 120px !important;
            }
            /* 2. Guest input smaller */
            .control-group:nth-child(3) {
                width: 100% !important;
                margin-top: 5px !important;
            }
            .control-input-box {
                padding: 10px 12px !important;
                font-size: 0.8rem !important;
                height: 42px !important;
            }
            .control-label {
                font-size: 0.65rem !important;
                margin-bottom: 4px !important;
            }
            /* 3. Single calendar in mobile */
            #cal2-container {
                display: none !important;
            }
            .next-mobile-only {
                display: block !important;
            }
            .desktop-only-spacer {
                display: none !important;
            }
        }
    </style>
</head>

<body>
    <?php include 'booking_loader.php'; ?>

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

        <div class="booking-stepper">
            <div class="step-item active">
                <div class="step-icon"><i class="fa-regular fa-calendar-check"></i></div>
                <span>Check-in & Out</span>
            </div>
            <div class="step-item">
                <div class="step-icon"><i class="fa-solid fa-bed"></i></div>
                <span>Select Rooms</span>
            </div>
            <div class="step-item">
                <div class="step-icon"><i class="fa-regular fa-id-card"></i></div>
                <span>Guest Info</span>
            </div>
            <div class="step-item">
                <div class="step-icon"><i class="fa-solid fa-check"></i></div>
                <span>Confirmation</span>
            </div>
        </div>

        <div class="booking-container">
            <form id="bookingForm" action="select_rooms.php" method="GET">
                <div class="booking-controls">
                    <div class="control-group">
                        <label class="control-label">Check-in</label>
                        <div class="control-input-box" id="checkin-box">
                            <span id="checkin-text">Select Date</span>
                            <i class="fa-regular fa-calendar"></i>

                            <input type="text" id="checkin-input" name="checkin" class="custom-flatpickr"
                                placeholder="Select Date" readonly>
                        </div>
                    </div>
                    <div class="control-group">
                        <label class="control-label">Check-out</label>
                        <div class="control-input-box" id="checkout-box">
                            <span id="checkout-text">Select Date</span>
                            <i class="fa-regular fa-calendar"></i>

                            <input type="text" id="checkout-input" name="checkout" class="custom-flatpickr"
                                placeholder="Select Date" readonly>
                        </div>
                    </div>
                    <div class="control-group">
                        <label class="control-label">Guests</label>
                        <div class="control-input-box" id="guest-input-box" onclick="toggleGuestDropdown()">
                            <span id="guest-summary-text">1 Adult, 0 Children</span>
                            <i class="fa-solid fa-chevron-down custom-arrow" id="guest-arrow"></i>
                        </div>

                        <div class="guest-dropdown" id="guest-dropdown">
                            <div class="guest-row">
                                <div class="guest-label">
                                    <span class="guest-type">Adults</span>
                                    <span class="guest-age">Ages 13 or above</span>
                                </div>
                                <div class="guest-counter">
                                    <button type="button" class="counter-btn" id="btn-adult-minus"
                                        onclick="updateGuest('adults', -1)">-</button>
                                    <span class="counter-val" id="count-adults">1</span>
                                    <button type="button" class="counter-btn" id="btn-adult-plus"
                                        onclick="updateGuest('adults', 1)">+</button>
                                </div>
                            </div>
                            <div class="guest-row">
                                <div class="guest-label">
                                    <span class="guest-type">Children</span>
                                    <span class="guest-age">Ages 0-12</span>
                                </div>
                                <div class="guest-counter">
                                    <button type="button" class="counter-btn" id="btn-child-minus"
                                        onclick="updateGuest('children', -1)">-</button>
                                    <span class="counter-val" id="count-children">0</span>
                                    <button type="button" class="counter-btn" id="btn-child-plus"
                                        onclick="updateGuest('children', 1)">+</button>
                                </div>
                            </div>
                            <button type="button" class="btn-done" onclick="toggleGuestDropdown()">DONE</button>
                        </div>
                        <input type="hidden" name="adults" id="input-adults" value="1">
                        <input type="hidden" name="children" id="input-children" value="0">
                    </div>
                </div>
            </form>

            <div id="nights-tooltip">0 Nights</div>
            <div class="calendars-row">
                <div class="calendar-wrapper" id="cal1-container">
                    <div class="calendar-header">
                        <button class="cal-nav-btn" id="prevMonthBtn" onclick="changeMonth(-1)"><i
                                class="fa-solid fa-chevron-left"></i></button>
                        <span id="cal1-title">Month 1</span>
                        <button class="cal-nav-btn next-mobile-only" onclick="changeMonth(1)" style="display:none;"><i
                                class="fa-solid fa-chevron-right"></i></button>
                        <span class="desktop-only-spacer" style="width:24px;"></span>
                    </div>
                    <div class="calendar-grid" id="cal1-grid"></div>
                </div>
                <div class="calendar-wrapper" id="cal2-container">
                    <div class="calendar-header">
                        <span style="width:24px;"></span>
                        <span id="cal2-title">Month 2</span>
                        <button class="cal-nav-btn" onclick="changeMonth(1)"><i
                                class="fa-solid fa-chevron-right"></i></button>
                    </div>
                    <div class="calendar-grid" id="cal2-grid"></div>
                </div>
            </div>

            <div class="action-bar">
                <div class="legend">
                    <div class="legend-item">
                        <div class="legend-box box-available"></div> Available
                    </div>
                    <div class="legend-item">
                        <div class="legend-box box-selected"></div> Check-in/Out
                    </div>
                    <div class="legend-item">
                        <div class="legend-box box-disabled"></div> Booked/Past
                    </div>
                </div>
                <button class="btn-continue" onclick="submitBooking()">CONTINUE <i
                        class="fa-solid fa-chevron-right"></i></button>
            </div>
        </div>
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

    <button class="floating-chat-btn" onclick="toggleChat()" title="Message Admin">
        <i class="fas fa-comment-dots"></i>
    </button>

    <div id="chatBubble" class="chat-bubble-container">
        <span class="close-bubble" onclick="toggleChat()"><i class="fas fa-times"></i></span>
        <div class="bubble-header">Message Admin</div>
        <span class="bubble-sub">We usually reply within 1 hour.</span>
        <form onsubmit="submitChatForm(event)">
            <input type="text" name="guest_name" class="chat-input" placeholder="Your Name" required>
            <input type="email" name="email" class="chat-input" placeholder="Your Email" required>
            <textarea id="msgAreaFooter" name="message" class="chat-input chat-textarea" placeholder="How can we help?"
                required></textarea>
            <button type="submit" class="btn-chat-send">Send Message</button>
        </form>
    </div>

    <div id="cutoffModal" class="custom-modal-overlay <?php echo ($showCutoffModal ? 'show' : ''); ?>">
        <div class="custom-modal-box">
            <i class="fas fa-moon custom-modal-icon"></i>
            <div class="custom-modal-title">Booking Cut-off</div>
            <p class="custom-modal-msg">
                Online booking for <strong>Today</strong> closes at <strong>8:00 PM</strong>.<br>
                Please select a future date or contact us directly.
            </p>
            <button class="custom-modal-btn" onclick="closeCutoffModal()">Okay, Got it</button>
        </div>
    </div>

    <div id="mobile-sticky-bar" class="mobile-sticky-bar">
        <div class="sticky-bar-info">
            <div class="sticky-summary-details" id="sticky-summary-details">
                <span>Stay Details</span>
                <b id="sticky-stay-text">Please select dates</b>
            </div>
        </div>
        <button type="button" onclick="submitBooking()" class="btn-sticky-next" id="sticky-next-btn" disabled>
            CONTINUE <i class="fa-solid fa-arrow-right"></i>
        </button>
    </div>

    <!-- Calendar Hint Toast -->
    <div id="calendarHint" class="calendar-hint-toast">
        <i class="fa-solid fa-calendar-days"></i>
        <span>Select dates in the calendar below</span>
    </div>

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

    function submitChatForm(event) {
        event.preventDefault();

        // Check internet connection before attempting to send
        if (!navigator.onLine) {
            alert("Your internet connection appears to be offline. Please check your connection and try again.");
            return;
        }

        const form = event.target;
        const btn = form.querySelector('button[type="submit"]');
        if (!btn) return;

        const originalBtnText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

        const formData = new FormData(form);
        formData.append('ajax_chat', '1');

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                alert(data.message);
                form.reset();
                if (typeof toggleChat === 'function') {
                    const bubble = document.getElementById("chatBubble");
                    if (bubble && bubble.classList.contains('active')) {
                        toggleChat();
                    }
                }
            } else {
                alert(data.message);
            }
        })
        .catch(err => {
            console.error('Fetch Error:', err);
            alert("Internet Error: The connection was interrupted or is too slow. Please check your internet and try again.");
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = originalBtnText;
        });
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
        // 🟢 Close Modal Functions
        function closeCutoffModal() {
            document.getElementById('cutoffModal').classList.remove('show');
            window.location.href = 'check_availability.php';
        }

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

        // Toggle Chat
        function toggleChat() {
            var bubble = document.getElementById("chatBubble");
            bubble.classList.toggle("active");
        }

        // Close Chat Outside
        document.addEventListener('click', function (event) {
            var bubble = document.getElementById("chatBubble");
            var btn = document.querySelector('.floating-chat-btn');
            if (bubble && btn && !bubble.contains(event.target) && !btn.contains(event.target)) {
                bubble.classList.remove("active");
            }
        });

        // Auto-resize Textarea
        const ta = document.getElementById('msgAreaFooter');
        if (ta) {
            ta.addEventListener('input', function () {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }

        // 🟢 1. Initialize Dates
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        // 🟢 2. EIGHT PM CUTOFF LOGIC (CLIENT SIDE)
        const now = new Date();
        const currentHour = now.getHours();

        if (currentHour >= 20) {
            today.setDate(today.getDate() + 1);
        }

        const realCurrentMonth = new Date().getMonth();
        const realCurrentYear = new Date().getFullYear();

        let displayMonth = realCurrentMonth;
        let displayYear = realCurrentYear;

        let checkInDate = null;
        let checkOutDate = null;

        let adultCount = 1;
        let childCount = 0;
        const MAX_TOTAL_GUESTS = 4;
        const MAX_ADULTS = 4;
        const MAX_CHILDREN = 4;

        // 🟢 CUSTOM GUEST DROPDOWN TOGGLE
        function toggleGuestDropdown() {
            const dropdown = document.getElementById('guest-dropdown');
            const inputBox = document.getElementById('guest-input-box');

            const isOpen = dropdown.classList.contains('show');
            if (isOpen) {
                dropdown.classList.remove('show');
                inputBox.classList.remove('active');
            } else {
                dropdown.classList.add('show');
                inputBox.classList.add('active');
            }
            updateButtonStates();
        }

        // Global Close for Guest Dropdown
        document.addEventListener('click', function (event) {
            const box = document.getElementById('guest-input-box');
            const dropdown = document.getElementById('guest-dropdown');
            if (!box.contains(event.target) && !dropdown.contains(event.target)) {
                dropdown.classList.remove('show');
                box.classList.remove('active');
            }
        });

        function updateGuest(type, change) {
            if (event) event.stopPropagation();

            const currentTotal = adultCount + childCount;

            if (change > 0 && currentTotal >= MAX_TOTAL_GUESTS) {
                return;
            }

            if (type === 'adults') {
                const newCount = adultCount + change;
                if (newCount < 1) return;
                if (newCount > MAX_ADULTS) return;
                adultCount = newCount;
                document.getElementById('count-adults').innerText = adultCount;
                document.getElementById('input-adults').value = adultCount;
            } else if (type === 'children') {
                const newCount = childCount + change;
                if (newCount < 0) return;
                if (newCount > MAX_CHILDREN) return;
                childCount = newCount;
                document.getElementById('count-children').innerText = childCount;
                document.getElementById('input-children').value = childCount;
            }

            updateGuestSummary();
            updateButtonStates();
        }

        function updateButtonStates() {
            const total = adultCount + childCount;
            const btnAdultMinus = document.getElementById('btn-adult-minus');
            const btnAdultPlus = document.getElementById('btn-adult-plus');

            if (adultCount <= 1) btnAdultMinus.classList.add('disabled');
            else btnAdultMinus.classList.remove('disabled');

            if (adultCount >= MAX_ADULTS || total >= MAX_TOTAL_GUESTS) {
                btnAdultPlus.classList.add('disabled');
            } else {
                btnAdultPlus.classList.remove('disabled');
            }

            const btnChildMinus = document.getElementById('btn-child-minus');
            const btnChildPlus = document.getElementById('btn-child-plus');

            if (childCount <= 0) btnChildMinus.classList.add('disabled');
            else btnChildMinus.classList.remove('disabled');

            if (childCount >= MAX_CHILDREN || total >= MAX_TOTAL_GUESTS) {
                btnChildPlus.classList.add('disabled');
            } else {
                btnChildPlus.classList.remove('disabled');
            }
        }

        function updateGuestSummary() {
            const adultText = adultCount === 1 ? '1 Adult' : `${adultCount} Adults`;
            const childText = childCount === 1 ? '1 Child' : `${childCount} Children`;
            document.getElementById('guest-summary-text').innerText = `${adultText}, ${childText}`;
        }

        document.addEventListener('DOMContentLoaded', () => {
            updateButtonStates();
        });

        // --- CALENDAR LOGIC ---
        const checkInText = document.getElementById('checkin-text');
        const checkOutText = document.getElementById('checkout-text');
        const checkInInput = document.getElementById('checkin-input');
        const checkOutInput = document.getElementById('checkout-input');
        const prevMonthBtn = document.getElementById('prevMonthBtn');

        document.addEventListener('DOMContentLoaded', () => {
            renderCalendars();

            // 🟢 CALENDAR HINT LOGIC
            const showCalendarHint = () => {
                const toast = document.getElementById('calendarHint');
                const calGrid = document.querySelector('.calendars-row');
                
                toast.classList.add('show');
                if (calGrid) {
                    calGrid.classList.add('cal-attention');
                    setTimeout(() => calGrid.classList.remove('cal-attention'), 800);
                }
                
                setTimeout(() => {
                    toast.classList.remove('show');
                }, 3500);
            };

            const checkinBox = document.getElementById('checkin-box');
            const checkoutBox = document.getElementById('checkout-box');
            
            if (checkinBox) {
                checkinBox.addEventListener('click', showCalendarHint);
            }
            if (checkoutBox) {
                checkoutBox.addEventListener('click', showCalendarHint);
            }
        });

        function changeMonth(offset) {
            let newMonth = displayMonth + offset;
            let newYear = displayYear;

            if (newMonth > 11) { newMonth = 0; newYear++; }
            else if (newMonth < 0) { newMonth = 11; newYear--; }

            if (newYear < realCurrentYear || (newYear === realCurrentYear && newMonth < realCurrentMonth)) return;

            displayMonth = newMonth;
            displayYear = newYear;
            renderCalendars();
        }

        function renderCalendars() {
            if (displayYear === realCurrentYear && displayMonth === realCurrentMonth) {
                prevMonthBtn.disabled = true;
            } else {
                prevMonthBtn.disabled = false;
            }
            renderMonth(displayYear, displayMonth, 'cal1-title', 'cal1-grid');

            let nextMonth = displayMonth + 1;
            let nextYear = displayYear;
            if (nextMonth > 11) { nextMonth = 0; nextYear++; }
            renderMonth(nextYear, nextMonth, 'cal2-title', 'cal2-grid');
        }

        function renderMonth(year, month, titleId, gridId) {
            const container = document.getElementById(gridId);
            const title = document.getElementById(titleId);
            const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
            title.innerText = `${monthNames[month]} ${year}`;
            container.innerHTML = '';

            const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            days.forEach(d => {
                const div = document.createElement('div');
                div.className = 'cal-head';
                div.innerText = d;
                container.appendChild(div);
            });

            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();

            for (let i = 0; i < firstDay; i++) {
                const empty = document.createElement('div');
                empty.className = 'cal-cell disabled';
                container.appendChild(empty);
            }

            for (let i = 1; i <= daysInMonth; i++) {
                const dateObj = new Date(year, month, i);
                const cell = document.createElement('div');
                cell.className = 'cal-cell cal-date';
                cell.innerText = i;
                cell.dataset.fullDate = dateObj.getFullYear() + '-' + (dateObj.getMonth() + 1) + '-' + dateObj.getDate();

                if (dateObj < today) {
                    cell.classList.add('disabled');
                } else {
                    cell.onclick = () => handleDateClick(dateObj);
                    if (checkInDate && !checkOutDate) {
                        cell.onmouseover = () => handleDateHover(dateObj, true);
                        cell.onmouseout = () => handleDateHover(dateObj, false);
                    }
                }

                if (checkInDate && isSameDate(dateObj, checkInDate)) cell.classList.add('selected');
                if (checkOutDate && isSameDate(dateObj, checkOutDate)) cell.classList.add('selected');
                if (checkInDate && checkOutDate && dateObj > checkInDate && dateObj < checkOutDate) cell.classList.add('range');

                container.appendChild(cell);
            }
        }

        const tooltip = document.getElementById('nights-tooltip');
        
        document.addEventListener('mousemove', (e) => {
            if (tooltip && tooltip.style.display === 'block') {
                tooltip.style.left = e.clientX + 'px';
                tooltip.style.top = e.clientY + 'px';
            }
        });

        function handleDateHover(hoverDate, isHovering) {
            if (!checkInDate || checkOutDate) {
                if (tooltip) tooltip.style.display = 'none';
                return;
            }
            
            const allDateCells = document.querySelectorAll('.cal-date:not(.disabled)');
            let nights = 0;

            if (isHovering && hoverDate > checkInDate) {
                const diffTime = Math.abs(hoverDate - checkInDate);
                nights = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                if (tooltip) {
                    tooltip.innerText = nights + (nights === 1 ? ' Night' : ' Nights');
                    tooltip.style.display = 'block';
                }
            } else {
                if (tooltip) tooltip.style.display = 'none';
            }

            allDateCells.forEach(cell => {
                const dateParts = cell.dataset.fullDate.split('-').map(Number);
                const cellDate = new Date(dateParts[0], dateParts[1] - 1, dateParts[2]);
                cell.classList.remove('hover-range');
                if (isHovering && cellDate > checkInDate && (cellDate < hoverDate || isSameDate(cellDate, hoverDate))) {
                    cell.classList.add('hover-range');
                }
            });
        }

        function handleDateClick(date) {
            if (!checkInDate || checkOutDate) {
                checkInDate = date;
                checkOutDate = null;
            } else if (date <= checkInDate) {
                checkInDate = date;
                checkOutDate = null;
            } else {
                checkOutDate = date;
            }
            updateUI();
        }

        function handleInputUpdate(val, type) {
            if (!val) return;
            const date = new Date(val);
            const fixedDate = new Date(date.valueOf() + date.getTimezoneOffset() * 60000);
            if (fixedDate < today) {
                // 🔴 REPLACED NATIVE ALERT
                showCustomAlert("Invalid Date", "You cannot select a date in the past.");

                if (type === 'checkin') checkInInput.value = ''; else checkOutInput.value = '';
                updateUI();
                return;
            }
            if (type === 'checkin') {
                checkInDate = fixedDate;
                if (checkOutDate && checkOutDate <= checkInDate) checkOutDate = null;
            } else {
                if (checkInDate && fixedDate > checkInDate) checkOutDate = fixedDate;
                else {
                    // 🔴 REPLACED NATIVE ALERT
                    showCustomAlert("Invalid Selection", "Check-out must be after Check-in");

                    checkOutInput.value = '';
                    updateUI();
                    return;
                }
            }
            displayMonth = fixedDate.getMonth();
            displayYear = fixedDate.getFullYear();
            updateUI();
        }

        function updateUI() {
            const toLocalISO = (date) => {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            };

            const checkinBox = document.getElementById('checkin-box');
            const checkoutBox = document.getElementById('checkout-box');

            if (checkInDate) {
                checkInText.innerText = formatDate(checkInDate);
                checkInText.style.color = "#333";
                checkInInput.value = toLocalISO(checkInDate);
                checkinBox.classList.add('active');
                checkinBox.style.transform = "scale(1.02)";
                setTimeout(() => checkinBox.style.transform = "scale(1)", 200);
            } else {
                checkInText.innerText = "Select Date";
                checkInInput.value = '';
                checkinBox.classList.remove('active');
            }

            if (checkOutDate) {
                checkOutText.innerText = formatDate(checkOutDate);
                checkOutText.style.color = "#333";
                checkOutInput.value = toLocalISO(checkOutDate);
                checkoutBox.classList.add('active');
                checkoutBox.style.transform = "scale(1.02)";
                setTimeout(() => checkoutBox.style.transform = "scale(1)", 200);
            } else {
                checkOutText.innerText = "Select Date";
                checkOutInput.value = '';
                checkoutBox.classList.remove('active');
            }

            // 🟢 UPDATE MOBILE STICKY BAR
            const stickyStayText = document.getElementById('sticky-stay-text');
            const stickyBtn = document.getElementById('sticky-next-btn');
            
            if (checkInDate && checkOutDate) {
                if (stickyStayText) stickyStayText.innerText = formatDate(checkInDate) + ' - ' + formatDate(checkOutDate);
                if (stickyBtn) stickyBtn.disabled = false;
            } else {
                if (stickyStayText) stickyStayText.innerText = "Please select dates";
                if (stickyBtn) stickyBtn.disabled = true;
            }

            renderCalendars();
        }

        function formatDate(date) {
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function isSameDate(d1, d2) {
            return d1.getFullYear() === d2.getFullYear() && d1.getMonth() === d2.getMonth() && d1.getDate() === d2.getDate();
        }

        // 🟢 SUBMIT FUNCTION WITH LOADER & CUSTOM ALERTS
        function submitBooking() {
            if (checkInDate && checkOutDate) {
                // 1. Manually trigger the loader
                const bookingLoader = document.getElementById("booking-processing");
                bookingLoader.classList.add('active');

                // 2. Set the Bridge Flag so the next page knows to be static
                sessionStorage.setItem('amv_bridge_active', 'true');

                // 3. Optional text update
                setTimeout(() => {
                    const textP = bookingLoader.querySelector('p');
                    if (textP) textP.innerText = "Checking room availability...";
                }, 1000);

                // 4. Wait 2 seconds, THEN submit the form
                setTimeout(() => {
                    document.getElementById('bookingForm').submit();
                }, 2000);

            } else {
                // 🔴 REPLACED NATIVE ALERT
                showCustomAlert("Incomplete Dates", "Please select both a Check-in and Check-out date to continue.");
            }
        }

        // 🟢 ROBUST STICKY STEPPER SCRIPT
        document.addEventListener("DOMContentLoaded", function () {
            const stepper = document.querySelector('.booking-stepper');

            if (stepper) {
                window.addEventListener('scroll', function () {
                    // Triggers when scrolled down 50px
                    if (window.scrollY > 50) {
                        stepper.classList.add('scrolled');
                    } else {
                        stepper.classList.remove('scrolled');
                    }
                });
            }
        });
    </script>

    <?php if (isset($_GET['error']) && $_GET['error'] == 'too_many_pending'): ?>
        <script>
            window.addEventListener('load', function() {
                alert('Booking Blocked: You have reached the limit of 4 pending bookings. Please wait for the admin to verify your previous bookings before making a new one.');
            });
        </script>
    <?php endif; ?>

</body>

</html>
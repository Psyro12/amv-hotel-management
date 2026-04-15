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
header("Content-Security-Policy: default-src 'self'; connect-src 'self' https://nominatim.openstreetmap.org; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https://*.google.com https://*.googleapis.com; frame-src 'self' https://*.google.com;");

// 4. Secure Session Settings
$cookieParams = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => $cookieParams['lifetime'],
    'path' => $cookieParams['path'],
    'domain' => $cookieParams['domain'],
    'secure' => false, // Set to TRUE for live HTTPS
    'httponly' => true,
    'samesite' => 'Strict'
]);

session_start();

// 5. Disable Error Reporting to Screen
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Ensure db_connect.php connects to 'amv_db'
require 'db_connect.php';

// --- 6. FETCH ADMIN CONTACT INFO ---
$admin_sql = "SELECT email, contact_number FROM admin_user LIMIT 1";
$admin_result = mysqli_query($conn, $admin_sql);
$admin_data = mysqli_fetch_assoc($admin_result);

// Set variables (use DB data if available, otherwise fallback to default)
$hotel_email = !empty($admin_data['email']) ? $admin_data['email'] : 'info@amvhotel.com';
$hotel_phone = !empty($admin_data['contact_number']) ? $admin_data['contact_number'] : '+63 945 343 455';

// Clean phone number for "tel:" link (removes spaces/dashes)
$hotel_phone_link = preg_replace('/[^0-9+]/', '', $hotel_phone);

// 7. Strict Method Check (Must be POST)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: select_rooms.php");
    exit;
}

// 8. Capture Data Safely
$checkin = $_POST['checkin'] ?? '';
$checkout = $_POST['checkout'] ?? '';
$adults = (int) ($_POST['adults'] ?? 1);
$children = (int) ($_POST['children'] ?? 0);
$selected_rooms_json = $_POST['selected_rooms'] ?? '[]';
$total_price = (float) ($_POST['total_price'] ?? 0);

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

// 🟢 CHAT MESSAGE HANDLING
$msg_success = false;
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

        .burger-menu {
            color: #333;
            font-size: 24px;
            cursor: pointer;
            display: none;
            z-index: 1100;
        }

        .mobile-nav-overlay {
            position: fixed;
            top: 0;
            right: -100%;
            width: 80%;
            max-width: 300px;
            height: 100vh;
            background: #fff;
            box-shadow: -5px 0 15px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            padding: 80px 30px;
            transition: right 0.4s ease-in-out;
            z-index: 1050;
        }

        .mobile-nav-overlay.active {
            right: 0;
        }

        .mobile-nav-overlay a {
            text-decoration: none;
            color: #333;
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 20px;
            text-transform: uppercase;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .mobile-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1040;
            display: none;
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        .mobile-backdrop.active {
            display: block;
            opacity: 1;
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

        @media (max-width: 600px) {
            .step-item span {
                display: none;
            }

            .step-item {
                font-size: 0.6rem;
            }
        }

        @media (max-width: 768px) {
            .booking-stepper {
                gap: 0;
                justify-content: space-between;
                padding: 15px 20px;
            }

            .step-item {
                font-size: 0.6rem;
                flex: 1;
            }

            .step-icon {
                width: 30px;
                height: 30px;
                font-size: 0.8rem;
                margin-bottom: 5px;
            }

            .step-item:not(:last-child)::after {
                top: 15px;
                width: 100%;
                left: 50%;
            }

            .step-item span {
                display: block;
                text-align: center;
                line-height: 1.1;
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
            padding: 30px;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #f0f0f0;
        }

        .form-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
            margin: 0;
        }

        .form-grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-grid-1 {
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .form-label {
            font-size: 0.9rem;
            font-weight: 700;
            color: #444;
            margin-bottom: 8px;
        }

        .form-label span {
            color: #d32f2f;
        }

        .form-input {
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.95rem;
            color: #555;
            background-color: #fff;
            width: 100%;
        }

        .form-input:focus {
            outline: none;
            border-color: #9e8236;
        }

        /* --- NEW CUSTOM SELECT STYLES --- */
        .form-select {
            display: none;
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
            padding: 12px;
            font-size: 0.95rem;
            font-weight: 500;
            color: #555;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .custom-select-wrapper.open .custom-select-trigger {
            border-color: #9e8236;
            box-shadow: 0 0 0 2px rgba(158, 130, 54, 0.1);
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
            top: 110%;
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #eee;
            border-radius: 4px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            /* Deeper shadow for depth */

            /* Z-Index: Must be higher than header (1000) and footer */
            z-index: 9999 !important;

            max-height: 250px;
            overflow-y: auto;

            /* 🔴 ANIMATION START STATE */
            /* Ensure it is ALWAYS rendered (display: block) but invisible */
            display: block !important;
            opacity: 0;

            /* Scale down slightly and move up */
            transform: translateY(-15px) scale(0.95) translateZ(0);

            /* Make it unclickable when hidden */
            pointer-events: none;

            /* Force Smooth Transition */
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1) !important;
            transform-origin: top center;
        }

        /* 2. The Open State */
        .custom-select-wrapper.open .custom-options {
            /* 🔴 ANIMATION END STATE */
            opacity: 1;

            /* Return to normal size and position */
            transform: translateY(0) scale(1) translateZ(0);

            /* Make clickable */
            pointer-events: auto;
        }

        /* 3. The Trigger Box */
        .custom-select-trigger {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            font-size: 0.95rem;
            font-weight: 500;
            color: #555;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 2;
            /* Ensure trigger sits above other inputs */
        }

        .custom-select-wrapper.open .custom-select-trigger {
            border-color: #9e8236;
            box-shadow: 0 0 0 2px rgba(158, 130, 54, 0.1);
        }

        /* 4. Arrow Rotation */
        .custom-arrow {
            transition: transform 0.3s ease !important;
        }

        .custom-select-wrapper.open .custom-arrow {
            transform: rotate(180deg);
        }

        /* 5. Option Hover Effects */
        .custom-option {
            padding: 12px;
            font-size: 0.95rem;
            color: #555;
            cursor: pointer;
            transition: background 0.2s ease, padding-left 0.2s ease;
            border-bottom: 1px solid #f9f9f9;
        }

        .custom-option:hover {
            background-color: #f9f5e8;
            color: #9e8236;
            padding-left: 18px;
            /* Smooth slide effect */
        }

        .custom-option.selected {
            background-color: #9e8236;
            color: #fff;
        }

        /* UPDATED CHECKBOX STYLES */
        .checkbox-wrapper {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-top: 20px;
            font-size: 0.95rem;
            color: #555;
        }

        .checkbox-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #9e8236;
            margin-top: 3px;
            cursor: pointer;
        }

        .checkbox-wrapper label {
            cursor: pointer;
            line-height: 1.5;
        }

        .checkbox-wrapper a:hover {
            text-decoration: underline !important;
        }

        @media (max-width: 900px) {

            .form-grid-3,
            .form-grid-2 {
                grid-template-columns: 1fr;
            }

            .sidebar {
                order: 1;
            }
        }

        /* SIDEBAR */
        .sidebar {
            position: sticky;
            top: 100px;
            height: fit-content;
        }

        .summary-box {
            background: #fff;
            border-radius: 4px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .summary-header {
            background-color: #333;
            color: #fff;
            padding: 20px;
            font-weight: 700;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .summary-content {
            padding: 25px;
        }

        .summary-item {
            margin-bottom: 20px;
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 15px;
        }

        .summary-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .s-label {
            font-size: 0.75rem;
            color: #888;
            text-transform: uppercase;
            font-weight: 600;
            display: block;
            margin-bottom: 5px;
        }

        .s-value {
            font-size: 1rem;
            font-weight: 600;
            color: #333;
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
    </style>
</head>

<body>
    <?php include 'booking_loader.php'; ?>

    <div class="page-content-wrapper">
        <header id="mainHeader">
            <div class="logo-container">
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
            <div class="burger-menu" onclick="toggleMobileMenu()">
                <i class="fa-solid fa-bars"></i>
            </div>
        </header>

        <div class="mobile-backdrop" id="mobileBackdrop" onclick="toggleMobileMenu()"></div>
        <div class="mobile-nav-overlay" id="mobileMenu">
            <div style="text-align: right; margin-bottom: 20px;">
                <i class="fa-solid fa-times" style="font-size: 24px; cursor: pointer;" onclick="toggleMobileMenu()"></i>
            </div>
            <a href="index.php">Home</a>
            <a href="menu.php">Dining</a>
            <a href="check_availability.php" style="color: #b8860b;">Reservations</a>
            <a href="about_us.php">About Us</a>
            <a href="check_availability.php"
                style="color: #b8860b; border: 2px solid #b8860b; text-align: center; padding: 10px; margin-top: 20px; border-radius: 4px;">Book
                Your Stay</a>
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

        <form action="confirmation.php" method="POST">
            <div class="main-container">

                <div class="left-col">
                    <div class="guest-form-container">
                        <div class="form-header">
                            <h2 class="form-title">Personal Information</h2>
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
                                <input type="text" class="form-input" name="first_name" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Last Name<span>*</span></label>
                                <input type="text" class="form-input" name="last_name" required>
                            </div>
                        </div>

                        <div class="form-grid-3">
                            <div class="form-group">
                                <label class="form-label">Gender<span>*</span></label>
                                <select class="form-select" name="gender" required>
                                    <option value="" disabled selected>- Select -</option>
                                    <option>Male</option>
                                    <option>Female</option>
                                    <option>Prefer not to say</option>
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
                                    list="nationality_list" placeholder="- Select -" autocomplete="off" required>
                                <datalist id="nationality_list"></datalist>
                            </div>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Email Address<span>*</span></label>
                                <input type="email" class="form-input" name="email" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Re-type Email Address<span>*</span></label>
                                <input type="email" class="form-input" name="retype_email" required>
                            </div>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Contact Number<span>*</span></label>
                                <input type="tel" class="form-input" name="contact_number" placeholder="+63-901-2345678"
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
                </div>

                <div class="sidebar">
                    <div class="summary-box">
                        <div class="summary-header">Your Booking</div>
                        <div class="summary-content">
                            <div class="summary-item">
                                <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                                    <div>
                                        <span class="s-label">Check-in</span>
                                        <span class="s-value"><?php echo $formatted_checkin; ?></span>
                                    </div>
                                    <div style="text-align:right;">
                                        <span class="s-label">Check-out</span>
                                        <span class="s-value"><?php echo $formatted_checkout; ?></span>
                                    </div>
                                </div>
                                <div style="font-size:0.85rem; color:#666; margin-top:5px;">
                                    <?php echo $nights; ?> Night(s)
                                </div>
                            </div>

                            <div class="summary-item">
                                <span class="s-label">Guests</span>
                                <span class="s-value"><?php echo $adults; ?> Adults, <?php echo $children; ?>
                                    Children</span>
                            </div>

                            <div class="summary-item">
                                <span class="s-label">Selected Rooms</span>
                                <?php if (!empty($selected_rooms)): ?>
                                    <?php foreach ($selected_rooms as $room): ?>
                                        <div style="margin-top: 8px; border-bottom: 1px dashed #eee; padding-bottom: 5px;">
                                            <span class="s-room-name"><?php echo htmlspecialchars($room['name']); ?></span>
                                            <span class="s-room-price">₱<?php echo htmlspecialchars($room['price']); ?> /
                                                night</span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="s-value">No room selected</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="total-wrapper">
                            <span class="total-label">TOTAL</span>
                            <span class="total-amount">₱<?php echo number_format($total_price, 2); ?></span>
                        </div>
                    </div>

                    <div class="payment-box">
                        <div class="payment-title">Payment Details</div>

                        <div style="margin-bottom: 20px;">
                            <label
                                style="font-size:0.85rem; font-weight:600; color:#555; display:block; margin-bottom:8px;">Select
                                Method</label>
                            <label class="payment-option">
                                <input type="radio" name="payment_method" value="GCash" required checked>
                                <span style="font-weight:600; color:#007bff;">GCash</span>
                            </label>
                            <label class="payment-option">
                                <input type="radio" name="payment_method" value="Maya" required>
                                <span style="font-weight:600; color:#000;">Maya</span>
                            </label>
                        </div>

                        <div>
                            <label
                                style="font-size:0.85rem; font-weight:600; color:#555; display:block; margin-bottom:8px;">Payment
                                Option</label>

                            <select class="form-select" name="payment_term" id="paymentTermSelect"
                                onchange="updateTotalDisplay()">
                                <option value="full">Full Payment (100%)</option>
                                <option value="partial">50% Down Payment</option>
                            </select>
                        </div>

                        <input type="hidden" name="checkin" value="<?php echo htmlspecialchars($checkin); ?>">
                        <input type="hidden" name="checkout" value="<?php echo htmlspecialchars($checkout); ?>">
                        <input type="hidden" name="adults" value="<?php echo $adults; ?>">
                        <input type="hidden" name="children" value="<?php echo $children; ?>">
                        <input type="hidden" name="total_price" value="<?php echo $total_price; ?>">
                        <input type="hidden" name="selected_rooms"
                            value="<?php echo htmlspecialchars($selected_rooms_json, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                        <button type="submit" class="btn-submit">CONFIRM & BOOK <i
                                class="fa-solid fa-chevron-right"></i></button>
                    </div>

                </div>
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
        const form = event.target;
        const btn = form.querySelector('button[type="submit"]');
        if (!btn) return;

        const originalBtnText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

        const formData = new FormData(form);

        fetch('process_chat_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                showCustomAlert('Success', data.message, 'fa-check-circle');
                form.reset();
                if (typeof toggleChat === 'function') {
                    setTimeout(() => {
                        const bubble = document.getElementById("chatBubble");
                        if (bubble && bubble.classList.contains('active')) {
                            toggleChat();
                        }
                    }, 3000);
                }
            } else if (data.status === 'limit') {
                showCustomAlert('Message Limit', data.message, 'fa-shield-alt');
            } else {
                showCustomAlert('Notice', data.message, 'fa-info-circle');
            }
        })
        .catch(err => {
            console.error('Fetch Error:', err);
            showCustomAlert('Error', 'An unexpected error occurred.');
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
        function toggleMobileMenu() {
            document.getElementById('mobileMenu').classList.toggle('active');
            document.getElementById('mobileBackdrop').classList.toggle('active');
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

        function updateTotalDisplay() {
            const fullTotal = <?php echo $total_price; ?>;
            const term = document.getElementById('paymentTermSelect').value;
            const totalEl = document.querySelector('.total-amount');
            const labelEl = document.querySelector('.total-label');

            if (term === 'partial') {
                const downPayment = fullTotal / 2;
                labelEl.innerText = "DUE NOW (50%)";
                totalEl.innerText = "$" + downPayment.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                totalEl.style.color = "#E67E22";
            } else {
                labelEl.innerText = "TOTAL";
                totalEl.innerText = "$" + fullTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                totalEl.style.color = "#9e8236";
            }
        }

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
                        alert("Invalid date or format. Please use YYYY-MM-DD.");
                        instance.clear();
                    }
                    if (selectedDates.length > 0) {
                        if (selectedDates[0] > legalAgeDate) {
                            alert("Guest must be at least 18 years old.");
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
        const bookingForm = document.querySelector('form[action="confirmation.php"]');
        if (bookingForm) {
            bookingForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const bookingLoader = document.getElementById("booking-processing");
                if (bookingLoader) {
                    bookingLoader.classList.add('active');
                    const title = bookingLoader.querySelector('h2');
                    const desc = bookingLoader.querySelector('p');
                    if (title) title.innerText = "Processing Booking";
                    if (desc) desc.innerText = "Please wait while we secure your reservation...";
                }
                setTimeout(() => {
                    this.submit();
                }, 2000);
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
</body>

</html>
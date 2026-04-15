<?php
// USER/PHP/menu.php

// --- 1. Security Headers ---
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
// Added upgrade-insecure-requests
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https://*.google.com https://*.googleapis.com; frame-src 'self' https://*.google.com; upgrade-insecure-requests;");

// --- 2. Secure Session Settings ---
if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => true, // Ensure HTTPS
        'httponly' => true, // Prevents JavaScript access to session cookie
        'samesite' => 'Strict' // Prevents CSRF
    ]);
    session_start();
}

// --- 3. Database Connection & Error Handling ---
ini_set('display_errors', 0);
error_reporting(E_ALL);
require 'db_connect.php';

// --- 4. FETCH ADMIN CONTACT INFO ---
// Static query, no user input, but we use safe fetching
$admin_sql = "SELECT email, contact_number FROM admin_user LIMIT 1";
$admin_result = mysqli_query($conn, $admin_sql);

if ($admin_result && mysqli_num_rows($admin_result) > 0) {
    $admin_data = mysqli_fetch_assoc($admin_result);
    // Sanitize output immediately
    $hotel_email = !empty($admin_data['email']) ? htmlspecialchars($admin_data['email']) : 'info@amvhotel.com';
    $hotel_phone = !empty($admin_data['contact_number']) ? htmlspecialchars($admin_data['contact_number']) : '+63 945 343 455';
} else {
    $hotel_email = 'info@amvhotel.com';
    $hotel_phone = '+63 945 343 455';
}

$hotel_phone_link = preg_replace('/[^0-9+]/', '', $hotel_phone);

// --- 5. AJAX Chat Handler (Embedded) ---
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

// --- 6. Fetch unique categories ---
// Static query, safe to use standard mysqli_query
$cat_sql = "SELECT DISTINCT category FROM food_menu WHERE category IS NOT NULL AND category != '' ORDER BY category ASC";
$cat_result = mysqli_query($conn, $cat_sql);
$categories = [];
if ($cat_result) {
    while ($cat_row = mysqli_fetch_assoc($cat_result)) {
        $categories[] = $cat_row['category'];
    }
}

// --- 7. Fetch all food items ---
// Static query, safe to use standard mysqli_query
$sql = "SELECT * FROM food_menu ORDER BY category DESC, item_name ASC";
$result = mysqli_query($conn, $sql);
$menuItems = [];
if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $menuItems[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dining Menu | AMV Hotel</title>
    <link rel="icon" type="image/png" href="../../IMG/5.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../STYLE/home_page.css">
    <link rel="stylesheet" href="../STYLE/utilities.css">

    <style>
        /* Global & Layout */
        html {
            scroll-behavior: smooth;
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

        body {
            font-family: 'Montserrat', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f9f9f9;
        }

        /* --- WRAPPER FOR FIXED FOOTER EFFECT --- */
        .page-content-wrapper {
            background-color: #f9f9f9;
            position: relative;
            z-index: 10;
            margin-bottom: 250px;
            /* Matches Footer Height */
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            padding-bottom: 60px;
            /* Space for content above footer */
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
            padding: 25px 5%;
            z-index: 1000;
            background-color: transparent;
            transition: all 0.4s ease-in-out;
        }

        header.scrolled {
            background-color: #ffffff;
            padding: 15px 5%;
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
            height: 50px;
            transition: 0.3s;
        }

        .logo-text {
            display: flex;
            flex-direction: row;
            font-weight: 700;
            line-height: 1.1;
            color: #fff;
            transition: color 0.3s;
            position: relative;
            overflow: hidden;
        }

        /* Shimmer Effect for Gold Text (shown when scrolled) */
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

        header.scrolled .logo-container:hover .logo-text::after {
            animation: shimmer 1.5s infinite;
        }

        @keyframes shimmer {
            100% { left: 200%; }
        }

        header.scrolled .logo-text {
            color: #b8860b;
        }

        header.scrolled .logo-container img {
            height: 40px;
        }

        header.scrolled .logo-text span {
            color: #333;
        }

        header.scrolled .logo-text span:first-child {
            font-size: 18px;
        }

        header.scrolled .logo-text span:last-child {
            font-size: 12px;
        }

        .desktop-nav {
            display: flex;
            align-items: center;
        }

        .desktop-nav a {
            text-decoration: none;
            color: #fff;
            font-weight: 500;
            font-size: .8rem;
            margin-right: 25px;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: color 0.4s ease;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        header.scrolled .desktop-nav a {
            color: #333;
            text-shadow: none;
        }

        .desktop-nav a:hover,
        .desktop-nav a.active {
            color: #b8860b;
        }

        .btn-header-book {
            padding: 10px 30px;
            background-color: transparent;
            color: #fff !important;
            border: 1px solid #fff;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-header-book:hover {
            background-color: #fff;
            color: #b8860b !important;
        }

        header.scrolled .btn-header-book {
            color: #b8860b !important;
            border-color: #b8860b;
        }

        header.scrolled .btn-header-book:hover {
            background-color: #b8860b;
            color: #fff !important;
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
            background: #fff;
            border-radius: 2px;
            opacity: 1;
            left: 0;
            transform: rotate(0deg);
            transition: .25s ease-in-out;
        }

        header.scrolled .burger-menu span {
            background: #333;
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

        /* --- HERO --- */
        .menu-hero {
            position: relative;
            margin-top: 0;
            height: 50vh;
            min-height: 350px;
            background-image: linear-gradient(rgba(0, 0, 0, 0.5), rgba(0, 0, 0, 0.5)), url('../../IMG/food_7.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: white;
            padding-top: 60px;
        }

        .menu-hero h1 {
            font-size: 2.8rem;
            font-weight: 700;
            text-transform: uppercase;
            margin: 0 0 10px 0;
            letter-spacing: 2px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .menu-hero p {
            font-size: 1.1rem;
            opacity: 0.9;
            letter-spacing: 1px;
        }

        @media (max-width: 768px) {
            .menu-hero h1 {
                font-size: 1.8rem !important;
                line-height: 1.1 !important;
                margin-bottom: 10px !important;
            }

            .menu-hero p {
                font-size: 1rem !important;
                padding: 0 15px;
            }

            .menu-hero {
                padding-top: 80px !important;
            }

            /* --- MOBILE MENU GRID (2 COLUMNS) --- */
            .menu-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 15px !important;
                padding: 0 10px !important;
            }

            .menu-item {
                height: 220px !important; /* Reduced height for 2-column layout */
            }

            .menu-info {
                padding: 12px !important;
            }

            .menu-title {
                font-size: 0.95rem !important;
            }

            .menu-price {
                font-size: 1.1rem !important;
            }

            /* --- MOBILE CATEGORY NAV (HORIZONTAL SCROLL) --- */
            .menu-nav {
                justify-content: flex-start !important;
                flex-wrap: nowrap !important;
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch;
                padding: 20px 15px !important;
                gap: 10px !important;
                scrollbar-width: none; /* Hide scrollbar Firefox */
            }

            .menu-nav::-webkit-scrollbar {
                display: none; /* Hide scrollbar Chrome/Safari */
            }

            .filter-btn {
                flex: 0 0 auto !important;
                padding: 8px 20px !important;
                font-size: 0.75rem !important;
            }
        }

        /* --- FILTER TABS --- */
        .menu-nav {
            display: flex;
            justify-content: center;
            gap: 10px;
            padding: 40px 20px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 10px 25px;
            border: 1px solid #ddd;
            border-radius: 30px;
            background: white;
            color: #555;
            font-family: 'Montserrat', sans-serif;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-btn:hover,
        .filter-btn.active {
            background-color: #b8860b;
            color: white;
            border-color: #b8860b;
        }

        /* --- MENU GRID --- */
        .menu-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px 20px 20px;
        }

        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 30px;
        }

        .menu-item {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s;
            animation: fadeIn 0.5s ease forwards;
            display: flex;
            flex-direction: column;
        }

        .menu-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .menu-icon-header {
            height: 140px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .menu-icon-header i {
            font-size: 4rem;
            opacity: 0.3;
            color: rgba(255, 255, 255, 0.8);
        }

        /* Image override */
        .menu-food-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .menu-item:hover .menu-food-img {
            transform: scale(1.1);
        }

        .category-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: rgba(255, 255, 255, 0.9);
            color: #333;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 10;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .menu-info {
            padding: 20px;
            text-align: center;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .menu-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #333;
            margin: 0 0 10px 0;
            line-height: 1.3;
        }

        .menu-price {
            font-size: 1.2rem;
            font-weight: 700;
            color: #b8860b;
        }

        /* Category Colors */
        .bg-main {
            background: linear-gradient(135deg, #FF9966, #FF5E62);
        }

        .bg-appetizer {
            background: linear-gradient(135deg, #F2994A, #F2C94C);
        }

        .bg-dessert {
            background: linear-gradient(135deg, #DA4453, #89216B);
        }

        .bg-drink {
            background: linear-gradient(135deg, #56CCF2, #2F80ED);
        }

        .bg-soup {
            background: linear-gradient(135deg, #fd746c, #ff9068);
        }

        .bg-snack {
            background: linear-gradient(135deg, #F7971E, #FFD200);
        }

        .bg-default {
            background: linear-gradient(135deg, #bdc3c7, #2c3e50);
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* --- MOBILE MENU OVERLAY --- */
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
            z-index: 1050;
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
            z-index: 1040;
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

        /* --- FIXED FOOTER STYLES --- */
        .footer-white-section {
            background-color: #ffffff;
            color: #2D0F35;
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 250px;
            z-index: 1;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
        }

        /* 🔴 IMPORTANT OVERRIDE 🔴 */
        footer.footer-white-section {
            padding: 0 !important;
            border: none !important;
            border-top: 1px solid #eee !important;
        }

        .footer-content-area {
            padding: 20px 5%;
            flex-grow: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .footer-grid-container {
            width: 100%;
            display: grid;
            grid-template-columns: 1.5fr 1fr 0.8fr 0.5fr;
            gap: 30px;
            align-items: center;
        }

        .footer-tagline-text {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.1rem;
            line-height: 1.6;
            color: #2D0F35;
            font-weight: 500;
            font-style: italic;
            margin: 0;
        }

        .footer-contact-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .footer-contact-item {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.95rem;
            font-weight: 500;
            color: #555;
            text-decoration: none;
            transition: 0.3s;
            font-family: 'Montserrat', sans-serif;
        }

        .footer-contact-item i {
            color: #D4AF37;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        .footer-contact-item:hover {
            color: #D4AF37;
            transform: translateX(5px);
        }

        .footer-violet-bar {
            background-color: #333;
            color: #b0a1b5;
            padding: 15px 5% !important;
            text-align: center;
            font-size: 0.85rem;
            width: 100%;
            box-sizing: border-box;
            margin: 0 !important;
        }

        .action-buttons-col {
            display: flex;
            justify-content: flex-end;
        }

        .btn-icon-msg {
            width: 65px;
            height: 65px;
            border-radius: 50%;
            background-color: #2D0F35;
            color: #D4AF37;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 20px rgba(45, 15, 53, 0.3);
            transition: all 0.3s ease;
        }

        .btn-icon-msg:hover {
            transform: translateY(-5px) scale(1.05);
            background-color: #D4AF37;
            color: #2D0F35;
        }

        /* --- CHAT BUBBLE --- */
        .chat-bubble-container {
            position: fixed;
            bottom: 100px;
            right: 5%;
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

        @media (max-width: 900px) {
            .page-content-wrapper {
                margin-bottom: 700px;
            }

            .footer-white-section {
                height: 700px;
                padding: 60px 20px !important;
            }

            .footer-grid-container {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 40px;
            }

            .footer-contact-item,
            .action-buttons-col {
                justify-content: center;
            }

            .chat-bubble-container {
                right: 50%;
                transform: translateX(50%) translateY(20px) scale(0.95);
                width: 90%;
            }

            .chat-bubble-container.active {
                transform: translateX(50%) translateY(0) scale(1);
            }
        }

        /* --- MOBILE APP PROMO (ROOM SERVICE) --- */
        .app-promo-banner {
            background-color: #f8f5f0;
            padding: 25px 5%;
            margin: 20px auto;
            max-width: 1100px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid #e0d8c8;
        }

        .app-promo-text {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 25px;
            flex-wrap: wrap;
        }

        .app-promo-text div {
            flex: 1;
            min-width: 250px;
        }

        .app-promo-text h3 {
            font-size: 1.2rem;
            color: #333;
            margin-bottom: 5px;
            font-weight: 700;
        }

        .app-promo-text p {
            color: #666;
            line-height: 1.4;
            margin: 0;
            font-size: 0.85rem;
        }

        .btn-app-order {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background-color: #b8860b;
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1px;
            transition: 0.3s;
            border-radius: 40px;
            white-space: nowrap;
        }

        .btn-app-order:hover {
            background-color: #333;
            transform: translateY(-2px);
        }

        .app-promo-icon {
            font-size: 2.5rem;
            color: #b8860b;
            opacity: 0.8;
        }

        @media (max-width: 768px) {
            .app-promo-banner {
                flex-direction: column;
                text-align: center;
                padding: 25px 20px;
                gap: 15px;
            }
            .app-promo-text {
                flex-direction: column;
                gap: 10px;
            }
            .app-promo-icon {
                display: block;
                margin-bottom: 10px;
            }
        }

        /* 1. Make the card a container for absolute positioning */
        .menu-item {
            position: relative;
            height: 300px;
            /* Fixed height for uniformity */
            border: none;
            overflow: hidden;
        }

        /* 2. Force the image/header to fill the entire card background */
        .menu-icon-header {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
        }

        /* 3. Create a dark gradient overlay so white text stands out */
        .menu-info {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            padding: 20px;
            z-index: 2;
            background: linear-gradient(transparent, rgba(0, 0, 0, 0.8));
            /* Contrast anchor */
            text-align: left;
            /* Optional: left-aligned looks more modern for this style */
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
        }

        /* 4. Style the text for high contrast */
        .menu-title {
            color: #ffffff !important;
            font-size: 1.2rem;
            margin-bottom: 5px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5);
        }

        .menu-price {
            color: #FFD700 !important;
            /* Gold price for premium feel */
            font-size: 1.3rem;
            font-weight: 800;
        }

        /* 5. Ensure the fallback icons are centered if there is no image */
        .menu-icon-header i {
            opacity: 1;
            font-size: 5rem;
            color: rgba(255, 255, 255, 0.5);
            position: absolute;
            top: 40%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
    </style>
</head>

<body>
    <?php include 'loader.php'; ?>

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
                <a href="menu.php" style="color: #b8860b;">Dining</a>
                <a href="check_availability.php">Reservations</a>
                <a href="about_us.php">About Us</a>
                <a href="check_availability.php" class="btn-header-book">Book Now</a>
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
            <a href="menu.php" style="color: #b8860b;"><i class="fa-solid fa-utensils"></i> Dining</a>
            <a href="check_availability.php"><i class="fa-solid fa-calendar-check"></i> Reservations</a>
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

        <section class="menu-hero">
            <div>
                <h1>Dining Menu</h1>
                <p>Savor our exquisite selection</p>
            </div>
        </section>

        <!-- 🟢 MOBILE APP ADVERTISEMENT FOR ROOM SERVICE -->
        <div class="app-promo-banner reveal">
            <div class="app-promo-icon">
                <i class="fa-solid fa-bell-concierge"></i>
            </div>
            <div class="app-promo-text">
                <div>
                    <h3>Order Room Service with Ease</h3>
                    <p>Download our app for instant ordering directly from your room.</p>
                </div>
                <a href="javascript:void(0)" onclick="secureDownload()" class="btn-app-order">
                    <i class="fa-solid fa-mobile-screen"></i> Get the App
                </a>
            </div>
        </div>

        <script>
        function secureDownload() {
            fetch('prep_download.php')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'authorized') {
                        window.open('app_get.php', '_blank');
                    }
                })
                .catch(err => {
                    console.error('Download prep failed:', err);
                    window.open('app_get.php', '_blank');
                });
        }
        </script>

        <nav class="menu-nav">
            <button class="filter-btn active" onclick="filterMenu('all', this)">All</button>
            <?php foreach ($categories as $cat): ?>
                <button class="filter-btn" onclick="filterMenu('<?php echo htmlspecialchars($cat); ?>', this)">
                    <?php echo htmlspecialchars($cat); ?>
                </button>
            <?php endforeach; ?>
        </nav>

        <div class="menu-container">
            <div class="menu-grid" id="menuGrid">
                <?php if (!empty($menuItems)): ?>
                    <?php
                    // Directory where food images are stored
                    $imgBase = '../../room_includes/uploads/food/';

                    foreach ($menuItems as $item):
                        // Logic to assign Style based on Category for fallback icons
                        $catRaw = $item['category'];
                        $catLower = strtolower($catRaw);

                        $iconClass = 'fa-utensils'; // Default Icon
                        $bgClass = 'bg-default';    // Default Color
                
                        if (strpos($catLower, 'beverage') !== false || strpos($catLower, 'drink') !== false) {
                            $iconClass = 'fa-glass-martini-alt';
                            $bgClass = 'bg-drink';
                        } elseif (strpos($catLower, 'dessert') !== false) {
                            $iconClass = 'fa-ice-cream';
                            $bgClass = 'bg-dessert';
                        } elseif (strpos($catLower, 'snack') !== false) {
                            $iconClass = 'fa-cookie-bite';
                            $bgClass = 'bg-snack';
                        } elseif (strpos($catLower, 'soup') !== false) {
                            $iconClass = 'fa-mug-hot';
                            $bgClass = 'bg-soup';
                        } elseif (strpos($catLower, 'appetizer') !== false) {
                            $iconClass = 'fa-carrot';
                            $bgClass = 'bg-appetizer';
                        } elseif (strpos($catLower, 'main') !== false) {
                            $iconClass = 'fa-drumstick-bite';
                            $bgClass = 'bg-main';
                        }

                        // 🟢 CHECK FOR IMAGE
                        $hasImage = !empty($item['image_path']);
                        $imgUrl = $hasImage ? $imgBase . $item['image_path'] : '';
                        ?>

                        <div class="menu-item" data-category="<?php echo htmlspecialchars($catRaw); ?>">
                            <div class="menu-icon-header <?php echo $hasImage ? '' : $bgClass; ?>">
                                <?php if ($hasImage): ?>
                                    <img src="<?php echo htmlspecialchars($imgUrl); ?>" class="menu-food-img" onerror="...">
                                <?php else: ?>
                                    <i class="fas <?php echo $iconClass; ?>"></i>
                                <?php endif; ?>
                                <span class="category-badge"><?php echo htmlspecialchars($catRaw); ?></span>
                            </div>

                            <div class="menu-info">
                                <h3 class="menu-title"><?php echo htmlspecialchars($item['item_name']); ?></h3>
                                <div class="menu-price">₱<?php echo number_format($item['price'], 2); ?></div>
                            </div>
                        </div>

                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="grid-column: 1/-1; text-align: center; padding: 50px; color: #888;">
                        <h3>No menu items available at the moment.</h3>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
    <footer class="footer-white-section">
        <div class="footer-content-area">
            <div class="footer-grid-container">
                <div>
                    <p class="footer-tagline-text">"Come experience the difference at AMV Hotel - your sophisticated
                        home in the heart of Mamburao."</p>
                </div>
                <div class="footer-contact-list">
                    <div class="footer-contact-item" style="cursor: default;">
                        <i class="fas fa-phone-alt"></i>
                        <span><?php echo htmlspecialchars($hotel_phone); ?></span>
                    </div>

                    <div class="footer-contact-item" style="cursor: default;">
                        <i class="fas fa-envelope"></i>
                        <span><?php echo htmlspecialchars($hotel_email); ?></span>
                    </div>

                    <a href="https://facebook.com" target="_blank" class="footer-contact-item">
                        <i class="fab fa-facebook-f"></i>
                        <span>AMV Hotel Official</span>
                    </a>
                </div>
                <div class="footer-contact-list">
                    <a href="terms_conditions.php" class="footer-contact-item"><i class="fas fa-file-contract"></i>
                        <span>Hotel Policies</span></a>
                    <a href="privacy_policy.php" class="footer-contact-item"><i class="fas fa-user-shield"></i>
                        <span>Privacy Policy</span></a>
                </div>
                <div class="action-buttons-col">
                    <button class="btn-icon-msg" onclick="toggleChat()" title="Message Admin"><i
                            class="fas fa-comment-dots"></i></button>
                </div>
            </div>
        </div>
        <div class="footer-violet-bar">© 2025 AMV Hotel. All rights reserved.</div>
    </footer>

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

    <?php if (isset($msg_success) && $msg_success): ?>
        <script>window.onload = function () { alert("Message successfully sent to Admin!"); }</script>
    <?php endif; ?>

    <script>
        // Header Logic
        const header = document.getElementById('mainHeader');
        function checkScroll() {
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        }
        window.addEventListener('scroll', checkScroll);
        document.addEventListener('DOMContentLoaded', checkScroll);

        // Mobile Menu
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

        function filterMenu(category, btnElement) {
            const items = document.querySelectorAll('.menu-item');
            const buttons = document.querySelectorAll('.filter-btn');

            // 1. Highlight Logic: Use the button element passed directly
            buttons.forEach(btn => btn.classList.remove('active'));
            if (btnElement) {
                btnElement.classList.add('active');
            }

            // 2. Filter Logic
            items.forEach(item => {
                const itemCat = item.getAttribute('data-category');

                if (category === 'all' || itemCat === category) {
                    item.style.display = 'flex';
                    item.style.animation = 'none';
                    item.offsetHeight; // trigger reflow
                    item.style.animation = 'fadeIn 0.5s ease forwards';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        // Chat Toggle
        function toggleChat() {
            var bubble = document.getElementById("chatBubble");
            bubble.classList.toggle("active");
        }

        // Close Chat when clicking outside
        document.addEventListener('click', function (event) {
            var bubble = document.getElementById("chatBubble");
            var btn = document.querySelector('.btn-icon-msg');
            if (bubble && btn && !bubble.contains(event.target) && !btn.contains(event.target)) {
                bubble.classList.remove("active");
            }
        });

        // Auto-resize textarea
        const ta = document.getElementById('msgAreaFooter');
        if (ta) {
            ta.addEventListener('input', function () {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }
    </script>
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
</body>

</html>
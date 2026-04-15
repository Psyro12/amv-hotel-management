<?php
// --- 1. Security Headers ---
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
// Added upgrade-insecure-requests to force HTTPS if available
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https://ui-avatars.com; upgrade-insecure-requests;");

// --- 2. Secure Session Settings ---
// Only set params if session hasn't started yet
if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => true, // Ensure you are running on HTTPS or localhost
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// --- 3. Disable Error Reporting to Screen (Security Best Practice) ---
ini_set('display_errors', 0);
error_reporting(E_ALL);

require 'db_connect.php';

// --- 4. FETCH ADMIN CONTACT INFO ---
// No user input here, so standard query is fine, but checking for errors is good practice
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

// Clean phone number for "tel:" link (removes spaces/dashes)
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

    // Daily Message Limit Check (4 per day)
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


// Base64 Placeholder
$placeholder = "data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3QgZmlsbD0iI2RkZCIgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJhcmlhbCIgZm9udC1zaXplPSIxMiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzU1NSI+Tm8gSW1hZ2U8L3RleHQ+PC9zdmc+";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMV Hotel</title>
    <link rel="icon" type="image/png" href="../../IMG/5.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../STYLE/home_page.css">
    <link rel="stylesheet" href="../STYLE/utilities.css">
    <style>
        /* --- GLOBAL RESET --- */
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

        html {
            scroll-behavior: smooth;
            overflow-x: hidden;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f9f9f9;
            overflow-x: hidden;
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

        .logo-text {
            display: flex;
            flex-direction: row;
            font-weight: 700;
            line-height: 1.1;
            color: #b8860b;
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

        header.scrolled .logo-text span {
            color: #333;
        }

        header.scrolled .logo-text span:first-child {
            font-size: 18px;
        }

        header.scrolled .logo-text span:last-child {
            font-size: 12px;
        }

        header.scrolled .logo-container img {
            height: 40px;
        }

        /* --- DESKTOP NAV --- */
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
        }

        header.scrolled .desktop-nav a {
            color: #333;
        }

        .desktop-nav a:hover {
            color: #b8860b;
        }

        .btn-header-book {
            padding: 10px 30px;
            background-color: transparent;
            color: #fff;
            border: 1px solid #fff;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
        }

        .btn-header-book:hover {
            background-color: #fff;
            color: #333;
        }

        header.scrolled .btn-header-book {
            color: #b8860b;
            border-color: #b8860b;
        }

        header.scrolled .btn-header-book:hover {
            background-color: #b8860b;
            color: #fff;
        }

        /* --- MOBILE MENU --- */
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

            header.scrolled {
                background-color: #fff;
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

        /* --- HERO SECTION --- */
        .hero-section {
            position: relative;
            width: 100%;
            height: 100vh;
            min-height: 600px;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            text-align: left;
            overflow: hidden;
            background-color: #000;
        }

        .hero-slider-wrapper {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
        }

        .hero-slide {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center;
            opacity: 0;
            transform: scale(1);
            transition: opacity 1.5s ease-in-out, transform 6s linear;
            z-index: 0;
        }

        .hero-slide.active {
            opacity: 1;
            transform: scale(1.1);
            z-index: 1;
        }

        .hero-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2;
        }

        .hero-content {
            position: relative;
            z-index: 3;
            color: #fff;
            padding-left: 8%;
            padding-right: 20px;
            max-width: 900px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .hero-logo {
            height: 120px;
            width: auto;
            margin-bottom: 20px;
            display: block;
            opacity: 0;
            animation: fadeUp 1s ease forwards 0.3s;
        }

        .hero-sub-text {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 3px;
            margin-bottom: 15px;
            display: block;
            opacity: 0;
            animation: fadeUp 1s ease forwards 0.5s;
        }

        .hero-title {
            font-size: 3.5rem;
            font-weight: 700;
            line-height: 1.1;
            margin: 0 0 30px 0;
            text-transform: uppercase;
            opacity: 0;
            animation: fadeUp 1s ease forwards 0.7s;
        }

        .btn-hero-book {
            display: none;
            padding: 15px 40px;
            background-color: #b8860b;
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-radius: 4px;
            transition: all 0.3s ease;
            opacity: 0;
            animation: fadeUp 1s ease forwards 0.9s;
            margin-top: 10px;
        }

        .btn-hero-book:hover {
            background-color: #fff;
            color: #b8860b;
            transform: translateY(-3px);
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 768px) {
            .hero-section {
                justify-content: center;
                text-align: center;
            }

            .hero-content {
                padding-left: 20px;
                padding-right: 20px;
                align-items: center;
            }

            .hero-logo {
                height: 80px;
                margin-left: auto;
                margin-right: auto;
            }

            .hero-title {
                font-size: 2.5rem;
            }

            .hero-sub-text {
                font-size: 0.75rem;
                letter-spacing: 2px;
            }

            .btn-hero-book {
                display: inline-block;
            }
        }

        /* --- INTRO --- */
        .intro-elegant {
            padding: 80px 5%;
            text-align: center;
            background: #f8f5f0;
        }

        .intro-elegant-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .intro-elegant-title {
            font-size: 2rem;
            color: #333;
            margin-bottom: 20px;
        }

        .intro-elegant-text {
            color: #666;
            line-height: 1.8;
            margin-bottom: 30px;
            font-size: 1rem;
        }

        .btn-outline-gold {
            padding: 12px 35px;
            border: 1px solid #b8860b;
            color: #b8860b;
            text-decoration: none;
            text-transform: uppercase;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 1px;
            transition: all 0.3s;
        }

        .btn-outline-gold:hover {
            background-color: #b8860b;
            color: #fff;
        }

        /* --- FEATURES --- */
        .features-container {
            width: 100%;
            background-color: #fff;
        }

        .feature-row {
            display: flex;
            flex-wrap: wrap;
            width: 100%;
            min-height: 500px;
        }

        .feature-row:nth-child(even) {
            flex-direction: row-reverse;
        }

        .feature-half-img,
        .feature-half-text {
            flex: 1 1 50%;
            min-width: 300px;
        }

        .feature-half-img {
            position: relative;
            overflow: hidden;
            width: 50%;
            min-height: 500px;
            height: 100%;
            z-index: 1;
        }

        .feature-half-img img {
            position: absolute;
            top: -20%;
            left: 0;
            width: 100%;
            height: 140%;
            object-fit: cover;
            will-change: transform;
            transition: none;
        }

        .feature-half-text {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px 80px;
            background-color: #fff;
        }

        .feature-sub {
            color: #999;
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .feature-title {
            font-size: 1.4rem;
            font-weight: 300;
            margin: 10px 0 25px 0;
            color: #333;
            line-height: 1.2;
        }

        .feature-desc {
            line-height: 1.8;
            font-size: 15px;
            color: #666;
            margin-bottom: 35px;
        }

        .feature-btn {
            padding: 12px 35px;
            background-color: transparent;
            color: #b8860b;
            text-decoration: none;
            display: inline-block;
            font-size: 13px;
            letter-spacing: 1px;
            border: 1px solid #b8860b;
            transition: all 0.3s ease;
            text-transform: uppercase;
            font-weight: 600;
            align-self: flex-start;
        }

        .feature-btn:hover {
            background-color: #b8860b;
            color: #fff;
        }

        @media (max-width: 992px) {

            .feature-row,
            .feature-row:nth-child(even) {
                flex-direction: column;
            }

            .feature-half-text {
                padding: 40px 30px;
                text-align: center;
                align-items: center;
            }

            .feature-half-img {
                height: 350px;
                width: 100%;
                min-height: 350px;
            }

            .feature-title {
                font-size: 1.5rem;
            }

            .feature-btn {
                align-self: center !important;
                margin-left: auto;
                margin-right: auto;
            }
        }

        /* --- EVENTS SECTION (Bento/Mosaic Grid) --- */
        .events-section {
            padding: 80px 5%;
            background-color: #fff;
        }

        .events-header {
            text-align: center;
            margin-bottom: 50px;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }

        .bento-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            grid-auto-rows: 250px;
            gap: 20px;
            grid-auto-flow: dense;
        }

        .bento-item {
            position: relative;
            overflow: hidden;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .bento-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.6s ease;
        }

        .bento-item:hover img {
            transform: scale(1.1);
        }

        .bento-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            padding: 20px;
            background: linear-gradient(to top, rgba(0, 0, 0, 0.8), transparent);
            color: #fff;
            transform: translateY(20px);
            opacity: 0;
            transition: all 0.4s ease;
        }

        .bento-item:hover .bento-overlay {
            transform: translateY(0);
            opacity: 1;
        }

        .bento-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #b8860b;
        }

        .bento-desc {
            font-size: 0.85rem;
            margin-top: 5px;
            color: #eee;
        }

        /* Grid Spans */
        .span-2-2 {
            grid-column: span 2;
            grid-row: span 2;
        }

        .span-2-1 {
            grid-column: span 2;
            grid-row: span 1;
        }

        .span-1-2 {
            grid-column: span 1;
            grid-row: span 2;
        }

        .span-1-1 {
            grid-column: span 1;
            grid-row: span 1;
        }

        @media (max-width: 992px) {
            .bento-grid {
                grid-template-columns: repeat(2, 1fr);
                grid-auto-rows: 200px;
                grid-auto-flow: dense;
            }

            /* Re-declare spans for 2-column layout */
            .span-2-2 { grid-column: span 2; grid-row: span 2; }
            .span-2-1 { grid-column: span 2; grid-row: span 1; }
            .span-1-2 { grid-column: span 1; grid-row: span 2; }
            .span-1-1 { grid-column: span 1; grid-row: span 1; }
        }

        @media (max-width: 768px) {
            .events-section {
                padding: 60px 15px;
            }

            .bento-grid {
                grid-template-columns: repeat(2, 1fr);
                grid-auto-rows: 160px;
                gap: 12px;
            }

            .bento-overlay {
                opacity: 1;
                transform: translateY(0);
                background: linear-gradient(to top, rgba(0, 0, 0, 0.9) 30%, transparent);
                padding: 15px;
            }

            .bento-title {
                font-size: 0.9rem;
            }

            .bento-desc {
                font-size: 0.75rem;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }
        }

        @media (max-width: 576px) {
            .bento-grid {
                grid-auto-rows: 140px;
                gap: 10px;
            }

            .bento-title {
                font-size: 0.8rem;
            }

            /* Hide description on smallest items for 576px to save space */
            .span-1-1 .bento-desc {
                display: none;
            }
        }

        /* --- ROOMS SECTION --- */
        .rooms {
            background-color: #f8f5f0;
            overflow: hidden;
            position: relative;
            padding-bottom: 50px;
        }

        .room-header-container {
            padding: 50px 7%;
            text-align: center;
        }

        .gallery-viewport {
            width: 100%;
            overflow: visible;
            position: relative;
            padding-bottom: 20px;
            user-select: none;
            -webkit-user-select: none;
            cursor: grab;
        }

        .gallery-viewport:active {
            cursor: grabbing;
        }

        .room-gallery-track {
            display: flex;
            gap: 30px;
            padding-left: calc(50% - 300px);
            transition: transform 0.5s ease-in-out;
        }

        .room-card-premium {
            flex: 0 0 600px;
            height: 420px;
            position: relative;
            background: #fff;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            transform: scale(0.9);
            opacity: 0.7;
            z-index: 1;
            transition: transform 0.5s ease-in-out, opacity 0.5s ease-in-out, box-shadow 0.3s ease;
        }

        .room-card-premium.active {
            transform: scale(1.1);
            opacity: 1;
            z-index: 10;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
        }

        .premium-img-wrapper {
            width: 100%;
            height: 75%;
            overflow: hidden;
            position: relative;
        }

        .room-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.8s;
            pointer-events: none;
            -webkit-user-drag: none;
        }

        .room-card-premium:hover .room-image {
            transform: scale(1.15);
        }

        .featured-badge {
            position: absolute;
            top: 25px;
            left: 0;
            background-color: #b8860b;
            color: white;
            padding: 8px 20px;
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 1px;
            z-index: 10;
            box-shadow: 2px 2px 5px rgba(0, 0, 0, 0.2);
        }

        .premium-details {
            height: 25%;
            padding: 15px 25px;
            background-color: #fff;
            display: flex;
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
            border-bottom: 4px solid transparent;
            transition: border-color 0.3s;
        }

        .room-card-premium:hover .premium-details {
            border-bottom: 4px solid #b8860b;
        }

        .premium-title {
            font-size: 20px;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }

        .amenities-row {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-top: 10px;
        }

        .amenity-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            width: 50px;
        }

        .amenity-item i {
            color: #b8860b;
            font-size: 1.1rem;
            margin-bottom: 4px;
            height: 30px;
            width: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .amenity-item span {
            font-size: 0.6rem;
            color: #888;
            font-weight: 600;
            text-transform: uppercase;
            line-height: 1.1;
            display: block;
            width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .amenity-more {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            width: 50px;
        }

        .mobile-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #b8860b;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 4px;
            box-shadow: 0 4px 10px rgba(184, 134, 11, 0.3);
            transition: transform 0.2s;
        }

        .amenity-more:hover .mobile-icon {
            transform: scale(1.1);
        }

        .more-badge {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #fcfcfc;
            border: 1px solid #ddd;
            color: #b8860b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            transition: all 0.3s;
            margin-bottom: 4px;
        }

        .amenity-more:hover .more-badge {
            background-color: #b8860b;
            color: #fff;
            border-color: #b8860b;
        }

        .more-label {
            font-size: 0.6rem;
            color: #888;
            font-weight: 600;
        }

        .amenities-popover {
            position: absolute;
            bottom: 120%;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            width: 220px;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 100;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            border: 1px solid #f0f0f0;
        }

        .amenities-popover::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -6px;
            border-width: 6px;
            border-style: solid;
            border-color: white transparent transparent transparent;
        }

        .amenity-more:hover .amenities-popover,
        .amenity-more.active .amenities-popover {
            opacity: 1;
            visibility: visible;
            bottom: 130%;
        }

        .popover-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .popover-item i {
            color: #b8860b;
            font-size: 1rem;
            margin-bottom: 3px;
        }

        .popover-item span {
            font-size: 0.6rem;
            color: #555;
            text-transform: uppercase;
            font-weight: 600;
        }

        .amenities-desktop {
            display: flex;
            gap: 15px;
        }

        .amenities-mobile {
            display: none;
        }

        .slider-btn {
            position: absolute;
            top: 370px;
            width: 55px;
            height: 55px;
            border-radius: 50%;
            border: none;
            background: white;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            z-index: 50;
            font-size: 18px;
            color: #333;
        }

        .slider-btn:hover {
            background-color: #b8860b;
            color: white;
            transform: scale(1.1);
        }

        .prev-btn {
            left: 30px;
        }

        .next-btn {
            right: 30px;
        }

        @media (max-width: 768px) {
            .gallery-viewport {
                width: 100vw !important;
                margin-left: calc(-50vw + 50%) !important;
                margin-right: calc(-50vw + 50%) !important;
                padding: 0;
                overflow-x: auto;
                scroll-snap-type: x mandatory;
                padding-bottom: 40px;
                scrollbar-width: none;
                display: block !important;
                overscroll-behavior-x: none;
                scroll-behavior: auto !important;
            }

            .gallery-viewport::-webkit-scrollbar {
                display: none;
            }

            .room-gallery-track {
                display: flex !important;
                gap: 20px;
                width: max-content;
                padding-left: 7.5vw;
                padding-right: 7.5vw;
                transform: none !important;
            }

            .room-card-premium {
                flex: 0 0 85vw !important;
                width: 85vw !important;
                height: auto !important;
                min-height: 480px;
                margin: 0 !important;
                transform: none !important;
                opacity: 1 !important;
                scroll-snap-align: center;
                scroll-snap-stop: always;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
            }

            .premium-img-wrapper {
                height: 350px !important;
            }

            .premium-details {
                height: auto !important;
                padding: 25px 20px !important;
            }

            .premium-title {
                font-size: 1.2rem !important;
                margin-bottom: 10px !important;
            }

            .amenities-desktop {
                display: none !important;
            }

            .amenities-mobile {
                display: flex !important;
                justify-content: center;
                width: 100%;
            }

            .amenities-popover {
                width: 260px !important;
                bottom: 130% !important;
                left: auto !important;
                right: -10px !important;
                transform: none !important;
                padding: 15px !important;
                grid-template-columns: 1fr 1fr !important;
                z-index: 1000 !important;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2) !important;
            }

            .amenities-popover::after {
                left: auto !important;
                right: 25px !important;
                margin-left: 0 !important;
            }

            .popover-item span {
                font-size: 0.6rem !important;
                white-space: normal;
                line-height: 1.1;
            }

            .slider-btn {
                display: none !important;
            }

            .room-card-premium:hover .room-image {
                transform: none !important;
            }

            .room-card-premium:hover .premium-details {
                border-bottom-color: transparent !important;
            }
        }

        /* --- NEWS & FEEDBACK --- */
        .news-section {
            padding: 60px 5%;
            background-color: #fff;
            overflow: hidden;
        }

        .news-viewport {
            width: 100%;
            overflow: hidden;
            cursor: grab;
            padding: 20px 0;
            position: relative;
            touch-action: pan-y;
        }

        .news-viewport:active {
            cursor: grabbing;
        }

        .news-track {
            display: flex;
            gap: 30px;
            width: max-content;
            padding-left: 0;
            padding-right: 0;
            will-change: transform;
        }

        .news-card {
            flex: 0 0 calc((90vw - 17px - 60px) / 3);
            width: calc((90vw - 17px - 60px) / 3);
            background: #fff;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid #f0f0f0;
            user-select: none;
        }

        .news-card img {
            pointer-events: none;
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s;
        }

        .news-img-container {
            height: 220px;
            overflow: hidden;
            pointer-events: none;
        }

        .news-card:hover .news-img-container img {
            transform: scale(1.1);
        }

        .news-content {
            padding: 25px;
        }

        .news-date {
            font-size: 0.75rem;
            color: #b8860b;
            font-weight: 700;
            text-transform: uppercase;
            display: block;
            margin-bottom: 10px;
        }

        .news-title {
            font-size: 1.2rem;
            color: #333;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .news-excerpt {
            font-size: 0.9rem;
            color: #666;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .read-more-link {
            text-decoration: none;
            color: #333;
            font-weight: 600;
            border-bottom: 2px solid #b8860b;
        }

        .feedback-section {
            padding: 80px 7%;
            background-color: #f8f5f0;
            text-align: center;
        }

        .feedback-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-top: 50px;
        }

        .feedback-viewport {
            width: 100%;
            overflow: hidden;
            cursor: grab;
            padding: 40px 0;
            position: relative;
            touch-action: pan-y;
        }

        .feedback-viewport:active {
            cursor: grabbing;
        }

        .feedback-track {
            display: flex;
            gap: 30px;
            width: max-content;
            padding-left: 0;
            padding-right: 0;
            will-change: transform;
        }

        .feedback-card {
            flex: 0 0 calc((85vw - 17px - 60px) / 3);
            width: calc((85vw - 17px - 60px) / 3);
            background: #fff;
            padding: 40px;
            border-radius: 4px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.03);
            text-align: left;
            border-top: 3px solid #b8860b;
            position: relative;
            user-select: none;
            box-sizing: border-box;
        }

        .quote-icon {
            font-size: 40px;
            color: #eee;
            position: absolute;
            top: 20px;
            right: 30px;
        }

        .stars {
            color: #b8860b;
            margin-bottom: 20px;
        }

        .feedback-text {
            font-size: 1rem;
            color: #555;
            line-height: 1.8;
            font-style: italic;
            margin-bottom: 25px;
        }

        .client-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .client-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
        }

        .client-details h4 {
            font-size: 0.95rem;
            margin: 0;
            color: #333;
            font-weight: 700;
        }

        .client-details span {
            font-size: 0.8rem;
            color: #999;
        }

        @media (max-width: 768px) {
            .news-section {
                padding: 40px 0;
            }

            .news-card {
                flex: 0 0 85vw;
                width: 85vw;
            }

            .news-track {
                gap: 20px;
                padding-left: 0;
                padding-right: 0;
            }

            .news-grid,
            .feedback-grid {
                grid-template-columns: 1fr;
            }

            .news-img-container {
                height: 250px !important;
            }

            .hero-mobile-book-btn {
                display: inline-block;
                margin-top: 30px;
                padding: 14px 40px;
                background-color: #b8860b;
                color: #fff;
                text-decoration: none;
                font-weight: 700;
                font-size: 0.9rem;
                text-transform: uppercase;
                letter-spacing: 1px;
                border-radius: 4px;
                box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
                opacity: 0;
                animation: fadeUp 1s ease forwards 1s;
            }

            .section-title {
                font-size: 1.0rem;
                color: #333;
                text-transform: uppercase;
            }

            .section-subtitle {
                font-size: 0.8rem;
                color: #666;
                margin-top: 5px;
            }

            .feature-btn {
                align-self: center !important;
                margin-left: auto;
                margin-right: auto;
            }

            .feedback-card {
                flex: 0 0 85vw !important;
                width: 85vw !important;
                min-width: 85vw !important;
                height: auto !important;
                min-height: 450px;
                scroll-snap-align: center;
                scroll-snap-stop: always;
                margin: 0 !important;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
                display: flex;
                flex-direction: column;
                justify-content: center;
                min-height: 350px;
            }
        }

        /* footer {
            background: #333;
            color: #fff;
            padding: 40px;
            text-align: center;
            font-size: 0.9rem;
        } */

        /* --- MOBILE APP SECTION --- */
        .mobile-app-promo {
            padding: 80px 5%;
            background-color: #f8f5f0; /* Updated to light beige */
            color: #333; /* Darker text for contrast */
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 60px;
            overflow: hidden;
        }

        .app-promo-content {
            flex: 1;
            max-width: 500px;
        }

        .app-promo-content .feature-sub {
            color: #b8860b; /* Using the hotel's gold color */
            margin-bottom: 15px;
        }

        .app-promo-title {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 20px;
            line-height: 1.2;
            color: #333;
        }

        .app-promo-desc {
            font-size: 1rem;
            color: #666; /* Softer grey for description */
            line-height: 1.7;
            margin-bottom: 35px;
        }

        .app-download-btns {
            display: flex;
            gap: 15px;
        }

        .btn-app-download {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 25px;
            background-color: #D4AF37;
            color: #2D0F35;
            text-decoration: none;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 1px;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .btn-app-download:hover {
            background-color: #fff;
            transform: translateY(-3px);
        }

        .app-promo-visual {
            flex: 1;
            display: flex;
            justify-content: center;
            position: relative;
        }

        .phone-mockup {
            width: 280px;
            height: 560px;
            background: #000;
            border: 12px solid #1a1a1a; /* Darker, thicker border for a premium feel */
            border-radius: 40px;
            position: relative;
            box-shadow: 0 40px 80px rgba(0,0,0,0.15); /* Softer, larger shadow */
            overflow: hidden;
        }

        .phone-screen {
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center;
            background-image: url('../../IMG/hotel_background.png'); /* Using existing bg for mockup */
        }

        .phone-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(184, 134, 11, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .phone-overlay i {
            font-size: 4rem;
            color: #fff;
            text-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }

        @media (max-width: 992px) {
            .mobile-app-promo {
                flex-direction: column;
                text-align: center;
                gap: 40px;
                padding: 60px 5%;
            }
            .app-download-btns {
                justify-content: center;
            }
            .app-promo-title {
                font-size: 1.8rem;
            }
        }

        .reveal.active {
            opacity: 1;
            transform: translateY(0);
        }

        .reveal-from-left {
            opacity: 0;
            transform: translateX(-30px);
            transition: all 1s ease-out;
        }

        .reveal-from-left.active {
            opacity: 1;
            transform: translateX(0);
        }

        .reveal-from-right {
            opacity: 0;
            transform: translateX(30px);
            transition: all 1s ease-out;
        }

        .reveal-from-right.active {
            opacity: 1;
            transform: translateX(0);
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 400;
            color: #333;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0;
        }

        .section-subtitle {
            font-size: 1.1rem;
            color: #666;
            margin-top: 5px;
        }

        /* ========================================= */
        /* 🔴 ADD THIS TO YOUR <style> BLOCK 🔴      */
        /* ========================================= */

        /* 1. CONTENT WRAPPER (This sits on top of the footer) */
        .page-content-wrapper {
            background-color: #fff;
            /* Crucial: Must be solid color to hide footer */
            position: relative;
            z-index: 10;
            margin-bottom: 250px;
            /* MATCHES FOOTER HEIGHT */
            /* box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1); */
        }

        /* 2. FIXED FOOTER (This sits behind) */
        .footer-white-section {
            background-color: #ffffff;
            color: #2D0F35;
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 250px;
            /* MATCHES WRAPPER MARGIN */
            z-index: 1;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
        }

        .footer-content-area {
            padding: 20px 5%;
            flex-grow: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .footer-violet-bar {
            background-color: #333;
            color: #b0a1b5;
            padding: 15px 5%;
            text-align: center;
            font-size: 0.85rem;
            width: 100%;
            box-sizing: border-box;
        }

        .footer-grid-container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            /* 🟢 CHANGED: Added a 4th column (1.5fr 1fr 0.8fr 0.5fr) */
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

        /* Contact List Updates */
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
            box-shadow: 0 12px 25px rgba(212, 175, 55, 0.4);
        }

        /* 3. CHAT BUBBLE */
        .chat-bubble-container {
            position: fixed;
            bottom: 100px;
            right: 5%;
            z-index: 9999;
            width: 320px;
            background-color: #fff;
            /* border-radius: 12px; */
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 20px;
            border: 1px solid #f0f0f0;
            opacity: 0;
            visibility: hidden;
            transform: translateY(20px) scale(0.95);
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @media (min-width: 1300px) {
            .chat-bubble-container {
                right: calc((100% - 1200px) / 2);
            }
        }

        .chat-bubble-container.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }

        .bubble-header {
            /* font-family: 'Playfair Display', serif; */
            font-size: 1.2rem;
            color: #2D0F35;
            margin-bottom: 5px;
            font-weight: 700;
        }

        .bubble-sub {
            font-size: 0.8rem;
            color: #777;
            margin-bottom: 15px;
            display: block;
        }

        .chat-input {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #eee;
            /* border-radius: 4px; */
            font-family: 'Montserrat', sans-serif;
            font-size: 0.85rem;
            box-sizing: border-box;
            background: #fcfcfc;
        }

        .chat-input:focus {
            outline: none;
            border-color: #D4AF37;
            background: #fff;
        }

        .chat-textarea {
            min-height: 80px;
            height: auto;
            resize: none;
            overflow-y: hidden;
        }

        .btn-chat-send {
            width: 100%;
            background-color: #D4AF37;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 4px;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.85rem;
            text-transform: uppercase;
            transition: 0.2s;
        }

        .btn-chat-send:hover {
            background-color: #B59325;
        }

        .close-bubble {
            position: absolute;
            top: 15px;
            right: 15px;
            cursor: pointer;
            color: #ccc;
        }

        .close-bubble:hover {
            color: #2D0F35;
        }

        /* 4. RESPONSIVE FOOTER */
        @media (max-width: 900px) {
            .page-content-wrapper {
                margin-bottom: 700px;
            }

            .footer-white-section {
                height: 700px;
            }

            .footer-grid-container {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 30px;
            }

            .footer-contact-item {
                justify-content: center;
            }

            .action-buttons-col {
                justify-content: center;
            }

            .chat-bubble-container {
                right: 50%;
                bottom: 85px;
                transform: translateX(50%) translateY(20px) scale(0.95);
            }

            .chat-bubble-container.active {
                transform: translateX(50%) translateY(0) scale(1);
            }
        }

        /* 🔴 FORCE REMOVE BOTTOM SPACE 🔴 */
        footer.footer-white-section {
            padding: 0 !important;
            border: none !important;
        }

        /* Ensure the violet bar has no extra space below text */
        .footer-violet-bar {
            margin: 0 !important;
            padding: 15px 5% !important;
            /* Keep internal spacing, remove external */
            width: 100%;
        }

        /* --- ROOM MINI-SLIDER STYLES (Fixed) --- */
        .premium-img-wrapper {
            position: relative;
            overflow: hidden;
            /* Ensures images stay inside the rounded corners */
        }

        /* Stack all images on top of each other */
        .room-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0;
            /* Hide by default */
            z-index: 1;

            /* 🔴 FIX 1: Restore pointer-events so the main slider can be dragged */
            pointer-events: none;
            -webkit-user-drag: none;

            /* 🔴 FIX 2: Combine transitions (Zoom + Fade) */
            transition: transform 0.8s, opacity 0.4s ease-in-out;
        }

        /* Show only the active image */
        .room-image.active {
            opacity: 1;
            z-index: 2;
        }

        /* Navigation Arrows */
        .mini-nav-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255, 255, 255, 0.7);
            color: #333;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: all 0.2s;
            opacity: 0;
            /* Hidden until hover */

            /* Ensure buttons ARE clickable */
            pointer-events: auto;
        }

        /* Show arrows on hover */
        .room-card-premium:hover .mini-nav-btn {
            opacity: 1;
        }

        .mini-nav-btn:hover {
            background: #b8860b;
            color: #fff;
            transform: translateY(-50%) scale(1.1);
            /* Slight grow on hover */
        }

        .mini-prev {
            left: 10px;
        }

        .mini-next {
            right: 10px;
        }

        /* Mobile: Always show arrows */
        @media (max-width: 768px) {
            .mini-nav-btn {
                opacity: 0.8;
            }
        }

        /* --- 🟢 ELEGANT DARK NEWS TOAST STYLES --- */
        .news-toast {
            position: fixed;
            top: 100px;
            right: 30px;
            background: rgba(15, 15, 15, 0.85); /* 🔴 Dark Background to match Hero */
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.3);
            display: flex;
            align-items: flex-start;
            gap: 18px;
            z-index: 2000;
            border: 1px solid rgba(212, 175, 55, 0.2); /* Gold tint border */
            transform: translateX(120%) scale(0.9);
            opacity: 0;
            transition: all 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
            max-width: 350px;
            pointer-events: auto;
        }

        .news-toast.active {
            transform: translateX(0) scale(1);
            opacity: 1;
        }

        .nt-icon-wrapper {
            position: relative;
            flex-shrink: 0;
        }

        .nt-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #b8860b 0%, #d4af37 100%);
            color: #fff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            box-shadow: 0 8px 15px rgba(184, 134, 11, 0.3);
        }

        .nt-pulse {
            position: absolute;
            top: -4px;
            right: -4px;
            width: 12px;
            height: 12px;
            background: #ef4444;
            border-radius: 50%;
            border: 2px solid #111;
            animation: nt-pulse-red 2s infinite;
        }

        @keyframes nt-pulse-red {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
            70% { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }

        .nt-content {
            flex: 1;
        }

        .nt-content h4 {
            margin: 0;
            font-size: 0.75rem;
            font-weight: 800;
            color: #d4af37; /* Gold */
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 4px;
        }

        .nt-content p {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 600;
            color: #ffffff; /* 🔴 White text for dark bg */
            line-height: 1.4;
            display: -webkit-box;
            line-clamp: 2; /* 🟢 Modern Standard */
            -webkit-line-clamp: 2; /* 🟢 Safari/Chrome compatibility */
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .nt-link {
            font-size: 11px;
            color: #d4af37; /* Gold */
            font-weight: 700;
            text-decoration: none;
            margin-top: 10px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: gap 0.2s;
        }

        .nt-link:hover {
            gap: 8px;
            color: #fff;
        }

        .nt-close {
            position: absolute;
            top: 12px;
            right: 12px;
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.4);
            font-size: 0.9rem;
            cursor: pointer;
            transition: color 0.2s;
            padding: 5px;
        }

        .nt-close:hover {
            color: #fff;
        }

        @media (max-width: 480px) {
            .news-toast {
                right: 15px;
                left: 15px;
                max-width: none;
                top: 85px;
                padding: 15px;
            }
        }

        /* --- 🟢 NEWS HIGHLIGHT EFFECT --- */
        .news-card.highlight-pulse {
            animation: highlight-glow 2s ease-out 3; /* Pulse 3 times */
            border-color: #b8860b !important;
            z-index: 10;
        }

        @keyframes highlight-glow {
            0% { box-shadow: 0 0 0 0 rgba(184, 134, 11, 0.4); transform: scale(1); }
            50% { box-shadow: 0 0 30px 10px rgba(184, 134, 11, 0.2); transform: scale(1.02); }
            100% { box-shadow: 0 0 0 0 rgba(184, 134, 11, 0); transform: scale(1); }
        }
    </style>
</head>

<body>
    <?php include 'loader.php'; ?>

    <!-- 🟢 ELEGANT REAL-TIME NEWS TOAST -->
    <div id="newsToast" class="news-toast">
        <button class="nt-close" onclick="document.getElementById('newsToast').classList.remove('active')">
            <i class="fa-solid fa-times"></i>
        </button>
        <div class="nt-icon-wrapper">
            <div class="nt-icon">
                <i class="fa-solid fa-bullhorn"></i>
            </div>
            <div class="nt-pulse"></div>
        </div>
        <div class="nt-content">
            <h4>Announcements</h4>
            <p id="nt_headline">Loading latest updates...</p>
            <a href="javascript:void(0)" id="nt_link" class="nt-link" onclick="scrollToNewsItem()">
                READ MORE <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    </div>

    <script>
        // Move news slider logic here to ensure polling has access to setupInfiniteLoop
        document.addEventListener("DOMContentLoaded", function() {
            // News Slider
            const newsViewport = document.querySelector('.news-viewport');
            const newsTrackContainer = document.querySelector('.news-track');
            
            if (newsViewport && newsTrackContainer) {
                let isDown = false;
                let startX;
                let currentTranslate = 0;
                let prevTranslate = 0;
                let animationID;
                let slideWidth = 0;
                let totalRealWidth = 0;
                let centerOffset = 0;
                let autoScrollTimer;
                const AUTO_SCROLL_DELAY = 3000;
                const cloneCount = 5;

                function setupInfiniteLoop() {
                    const existingClones = newsTrackContainer.querySelectorAll('.clone-item');
                    existingClones.forEach(el => el.remove());
                    const cards = Array.from(newsTrackContainer.children);
                    if (cards.length === 0) return;
                    const firstClones = [];
                    const lastClones = [];
                    for (let i = 0; i < cloneCount; i++) {
                        const firstClone = cards[i % cards.length].cloneNode(true);
                        firstClone.classList.add('clone-item');
                        firstClones.push(firstClone);
                        const lastClone = cards[cards.length - 1 - (i % cards.length)].cloneNode(true);
                        lastClone.classList.add('clone-item');
                        lastClones.push(lastClone);
                    }
                    firstClones.forEach(c => newsTrackContainer.appendChild(c));
                    lastClones.reverse().forEach(c => newsTrackContainer.prepend(c));
                    updateDimensions();
                    currentTranslate = -(cloneCount * slideWidth) + centerOffset;
                    prevTranslate = currentTranslate;
                    setSliderPosition();
                    startAutoScroll();
                }

                function getSlideWidth() {
                    const card = document.querySelector('.news-card');
                    if (!card) return 0;
                    const style = window.getComputedStyle(newsTrackContainer);
                    const gap = parseFloat(style.gap) || 30;
                    return card.offsetWidth + gap;
                }

                function updateDimensions() {
                    slideWidth = getSlideWidth();
                    const realCardCount = newsTrackContainer.querySelectorAll('.news-card:not(.clone-item)').length;
                    totalRealWidth = realCardCount * slideWidth;
                    if (window.innerWidth <= 768) {
                        const card = newsTrackContainer.querySelector('.news-card');
                        if (card) centerOffset = (newsViewport.offsetWidth - card.offsetWidth) / 2;
                    } else {
                        centerOffset = 0;
                    }
                }

                function setSliderPosition() { newsTrackContainer.style.transform = `translateX(${currentTranslate}px)`; }
                function startAutoScroll() { stopAutoScroll(); autoScrollTimer = setInterval(() => { const currentAbs = Math.abs(currentTranslate - centerOffset); const currentIndex = Math.round(currentAbs / slideWidth); snapToSlide(currentIndex + 1); }, AUTO_SCROLL_DELAY); }
                function stopAutoScroll() { clearInterval(autoScrollTimer); }

                const startDrag = (pageX) => {
                    stopAutoScroll(); isDown = true; newsViewport.classList.add('active'); startX = pageX; cancelAnimationFrame(animationID);
                    const matrix = new WebKitCSSMatrix(window.getComputedStyle(newsTrackContainer).transform);
                    currentTranslate = matrix.m41; prevTranslate = currentTranslate;
                };

                const moveDrag = (pageX) => {
                    if (!isDown) return;
                    const diff = pageX - startX;
                    let newTranslate = prevTranslate + diff;
                    let checkPos = newTranslate - centerOffset;
                    if (checkPos > -slideWidth) { newTranslate -= totalRealWidth; prevTranslate -= totalRealWidth; }
                    if (checkPos < -((cloneCount * slideWidth) + totalRealWidth)) { newTranslate += totalRealWidth; prevTranslate += totalRealWidth; }
                    currentTranslate = newTranslate; setSliderPosition();
                };

                const endDrag = () => {
                    isDown = false; newsViewport.classList.remove('active');
                    const relativePos = Math.abs(currentTranslate - centerOffset);
                    const exactIndex = relativePos / slideWidth;
                    let targetIndex = Math.round(exactIndex);
                    snapToSlide(targetIndex); startAutoScroll();
                };

                function snapToSlide(targetIndex) {
                    const targetTranslate = -(targetIndex * slideWidth) + centerOffset;
                    function animate() {
                        currentTranslate += (targetTranslate - currentTranslate) * 0.1;
                        setSliderPosition();
                        if (Math.abs(targetTranslate - currentTranslate) > 0.5) { animationID = requestAnimationFrame(animate); }
                        else {
                            currentTranslate = targetTranslate;
                            const purePos = currentTranslate - centerOffset;
                            const totalClonesWidth = cloneCount * slideWidth;
                            if (Math.abs(purePos) < totalClonesWidth - 5) { currentTranslate -= totalRealWidth; }
                            else if (Math.abs(purePos) >= totalClonesWidth + totalRealWidth) { currentTranslate += totalRealWidth; }
                            setSliderPosition();
                        }
                    }
                    cancelAnimationFrame(animationID); animate();
                }

                newsViewport.addEventListener('mousedown', e => startDrag(e.pageX));
                newsViewport.addEventListener('touchstart', e => startDrag(e.touches[0].pageX));
                newsViewport.addEventListener('mousemove', e => { e.preventDefault(); moveDrag(e.pageX); });
                newsViewport.addEventListener('touchmove', e => moveDrag(e.touches[0].pageX));
                newsViewport.addEventListener('mouseenter', stopAutoScroll);
                newsViewport.addEventListener('mouseleave', () => { if (!isDown) startAutoScroll(); if (isDown) endDrag(); });
                newsViewport.addEventListener('mouseup', endDrag);
                newsViewport.addEventListener('touchend', endDrag);

                setTimeout(setupInfiniteLoop, 200);
                window.addEventListener('resize', () => {
                    updateDimensions();
                    const relativePos = Math.abs(currentTranslate - centerOffset);
                    const index = Math.round(relativePos / slideWidth);
                    currentTranslate = -(index * slideWidth) + centerOffset;
                    prevTranslate = currentTranslate;
                    setSliderPosition();
                });

                // 🟢 REAL-TIME POLLING & REFRESH LOGIC
                let lastNotifiedNewsId = sessionStorage.getItem('last_notified_news_id');
                let currentNewsId = null;

                window.scrollToNewsItem = function() {
                    if (!currentNewsId) return;
                    document.getElementById('newsToast').classList.remove('active');
                    const targetId = 'news-item-' + currentNewsId;
                    const element = document.getElementById(targetId);
                    if (element) {
                        element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        document.querySelectorAll('.news-card').forEach(card => card.classList.remove('highlight-pulse'));
                        setTimeout(() => { element.classList.add('highlight-pulse'); }, 500);
                        setTimeout(() => { element.classList.remove('highlight-pulse'); }, 6500);
                    } else {
                        document.getElementById('news').scrollIntoView({ behavior: 'smooth' });
                    }
                };

                function refreshNewsTrack() {
                    fetch('ajax_get_news_html.php?t=' + new Date().getTime())
                        .then(res => res.text())
                        .then(html => {
                            newsTrackContainer.innerHTML = html;
                            setupInfiniteLoop(); // Re-initialize slider with new content
                        })
                        .catch(err => console.error("News Refresh Error:", err));
                }

                function checkRealtimeNews() {
                    fetch('ajax_get_news_notification.php?t=' + new Date().getTime())
                        .then(res => res.json())
                        .then(data => {
                            if (data.status === 'success') {
                                currentNewsId = data.id;
                                if (data.id != lastNotifiedNewsId) {
                                    // 🟢 DYNAMIC TITLE: Show count if more than 1
                                    const titleEl = document.querySelector('#newsToast .nt-content h4');
                                    if (data.total_new > 1) {
                                        titleEl.innerText = `${data.total_new} New Updates!`;
                                    } else {
                                        titleEl.innerText = "Announcement";
                                    }

                                    document.getElementById('nt_headline').innerText = data.title;
                                    const toast = document.getElementById('newsToast');
                                    toast.classList.add('active');
                                    lastNotifiedNewsId = data.id;
                                    sessionStorage.setItem('last_notified_news_id', data.id);
                                    
                                    // REFRESH THE ACTUAL TRACK
                                    refreshNewsTrack();

                                    setTimeout(() => { toast.classList.remove('active'); }, 10000);
                                }
                            }
                        })
                        .catch(err => console.error("News Poll Error:", err));
                }

                setTimeout(checkRealtimeNews, 2500);
                setInterval(checkRealtimeNews, 10000);
            }
        });
    </script>

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
                <a href="menu.php">Dining</a>
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
                        <span style="font-size: 10px; color:#333;">Hotel</span>
                    </div>
                </div>
                <i class="fa-solid fa-times" style="font-size: 24px; cursor: pointer; color: #333;" onclick="toggleMobileMenu()"></i>
            </div>
            
            <a href="index.php"><i class="fa-solid fa-house"></i> Home</a>
            <a href="menu.php"><i class="fa-solid fa-utensils"></i> Dining</a>
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

        <section class="hero-section" id="home">
            <div class="hero-slider-wrapper">
                <div class="hero-slide active" style="background-image: url('../../IMG/hotel_background.png');"></div>
                <div class="hero-slide" style="background-image: url('../../IMG/hotel_rooms.png');"></div>
                <div class="hero-slide" style="background-image: url('../../IMG/hotel_foods.jpg');"></div>
                <div class="hero-slide" style="background-image: url('../../IMG/hotel_events.png');"></div>
            </div>
            <div class="hero-overlay"></div>
            <div class="hero-content">
                <img src="../../IMG/hero_logo_white.png" alt="AMV Hotel Logo" class="hero-logo">
                <span class="hero-sub-text">Your Vibrant Experiences Await</span>
                <h1 class="hero-title">Experience Luxury<br>& Comfort</h1>
                <a href="check_availability.php" class="btn-hero-book">Book Your Stay</a>
            </div>
        </section>

        <section class="intro-elegant" id="about">
            <div class="intro-elegant-container reveal">
                <h2 class="section-title" style="margin-bottom: 20px;">Welcome to AMV Hotel</h2>
                <p class="intro-elegant-text">
                    AMV Hotel is a property of many firsts — The "True Heart of Mamburao" is delighted to welcome you.
                    Experience luxury and comfort combined with our world-class Filipino hospitality.
                </p>
                <a href="about_us.php" class="btn-outline-gold">LEARN MORE</a>
            </div>
        </section>

        <section class="features-container">
            <div class="feature-row">
                <div class="feature-half-img reveal-from-left">
                    <img src="../../IMG/hotel_rooms.png" alt="Luxury Hotel Room">
                </div>
                <div class="feature-half-text reveal-from-right">
                    <span class="feature-sub">Rooms</span>
                    <h3 class="feature-title">Experience the Comfort</h3>
                    <p class="feature-desc">
                        Safety and comfort are key factors in leisure stays these days. We assure you of medical-grade
                        stringent sanitation procedures in preparing our rooms for guests so you can stay with us with
                        peace
                        of mind.
                    </p>
                    <a href="check_availability.php" class="feature-btn">Reservations</a>
                </div>
            </div>

            <div class="feature-row">
                <div class="feature-half-img reveal-from-right">
                    <img src="../../IMG/hotel_foods.jpg" alt="Fine Dining">
                </div>
                <div class="feature-half-text reveal-from-left">
                    <span class="feature-sub">Dining</span>
                    <h3 class="feature-title">Experience the Flavors</h3>
                    <p class="feature-desc">
                        Experience the exquisite flavors of world-class cuisine crafted by our master chefs.
                    </p>
                    <a href="menu.php" class="feature-btn">View Menu</a>
                </div>
            </div>

            <div class="feature-row">
                <div class="feature-half-img reveal-from-left">
                    <img src="../../IMG/hotel_events.png" alt="Grand Events">
                </div>
                <div class="feature-half-text reveal-from-right">
                    <span class="feature-sub">Events</span>
                    <h3 class="feature-title">Celebrate Grandeur</h3>
                    <p class="feature-desc">
                        Host your grandest occasions in our elegant ballrooms and function rooms.
                    </p>
                    <a href="events_gallery.php" class="feature-btn">Inquire Now</a>
                </div>
            </div>
        </section>

        <section class="rooms" id="rooms">
            <div class="room-header-container">
                <h2 class="section-title">Our Rooms</h2>
                <p class="section-subtitle" style="margin:0;">Stay, Eat, and Celebrate with Us.</p>
            </div>

            <div class="gallery-wrapper">
                <button class="slider-btn prev-btn" id="prevBtn"><i class="fa-solid fa-chevron-left"></i></button>
                <button class="slider-btn next-btn" id="nextBtn"><i class="fa-solid fa-chevron-right"></i></button>

                <div class="gallery-viewport">
                    <div class="room-gallery-track" id="track">
                        <?php
                        // 1. Fetch All Amenities into a Lookup Array (ID => Data)
                        $amenityMap = [];
                        $amQuery = "SELECT * FROM amenities";
                        $amResult = mysqli_query($conn, $amQuery);
                        if ($amResult) {
                            while ($row = mysqli_fetch_assoc($amResult)) {
                                $amenityMap[$row['id']] = $row;
                            }
                        }

                        // 2. Fetch Rooms
                        $query = "SELECT name AS image_name, description, image_path AS file_path, amenities, bed_type, capacity, size FROM rooms WHERE is_active = 1 ORDER BY name ASC";
                        $result = mysqli_query($conn, $query);
                        $rooms = [];
                        if ($result && mysqli_num_rows($result) > 0) {
                            while ($row = mysqli_fetch_assoc($result)) {
                                $rooms[] = $row;
                            }
                        }
                        ?>

                        <?php if (count($rooms) > 0): ?>
                            <?php foreach ($rooms as $index => $room): ?>
                                <?php
                                $rawPath = $room['file_path'];
                                $imageArray = [];

                                // 1. Parse CSV string into an array
                                if (!empty($rawPath)) {
                                    if (strpos($rawPath, ',') !== false) {
                                        $imageArray = explode(',', $rawPath);
                                    } else {
                                        $imageArray[] = $rawPath;
                                    }
                                }

                                // Clean up paths
                                $imageArray = array_map('trim', $imageArray);
                                $baseWebPath = '../../room_includes/uploads/images/';
                                ?>

                                <div class="room-card-premium"
                                    data-room-name="<?php echo htmlspecialchars($room['image_name']); ?>">

                                    <div class="premium-img-wrapper" id="room-slider-<?php echo $index; ?>">
                                        <div class="featured-badge"><?php echo htmlspecialchars($room['bed_type']); ?></div>

                                        <?php if (count($imageArray) > 0): ?>
                                            <?php foreach ($imageArray as $key => $imgFile): ?>
                                                <?php
                                                $imgUrl = !empty($imgFile) ? $baseWebPath . htmlspecialchars($imgFile) : $placeholder;
                                                $activeClass = ($key === 0) ? 'active' : ''; // Only first image is active
                                                ?>
                                                <img src="<?php echo $imgUrl; ?>"
                                                    alt="<?php echo htmlspecialchars($room['image_name']); ?>"
                                                    class="room-image <?php echo $activeClass; ?>"
                                                    onerror="this.src='<?php echo $placeholder; ?>'">
                                            <?php endforeach; ?>

                                            <?php if (count($imageArray) > 1): ?>
                                                <button class="mini-nav-btn mini-prev"
                                                    onclick="rotateRoomImage(event, '<?php echo $index; ?>', -1)">
                                                    <i class="fa-solid fa-chevron-left"></i>
                                                </button>
                                                <button class="mini-nav-btn mini-next"
                                                    onclick="rotateRoomImage(event, '<?php echo $index; ?>', 1)">
                                                    <i class="fa-solid fa-chevron-right"></i>
                                                </button>
                                            <?php endif; ?>

                                        <?php else: ?>
                                            <img src="<?php echo $placeholder; ?>" class="room-image active">
                                        <?php endif; ?>
                                    </div>

                                    <div class="premium-details">
                                        <div style="flex:1; padding-right:10px;">
                                            <div class="premium-title"><?php echo htmlspecialchars($room['image_name']); ?>
                                            </div>
                                            
                                            <!-- 🟢 NEW: Room Specs Row -->
                                            <div style="display: flex; gap: 10px; margin-bottom: 5px;">
                                                <span style="font-size: 11px; color: #b8860b; font-weight: 700; background: #fdf5e6; padding: 2px 8px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px;">
                                                    <i class="fa-solid fa-users"></i> <?php echo htmlspecialchars($room['capacity']); ?> PAX
                                                </span>
                                                <span style="font-size: 11px; color: #b8860b; font-weight: 700; background: #fdf5e6; padding: 2px 8px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px;">
                                                    <i class="fa-solid fa-expand"></i> <?php echo htmlspecialchars($room['size']); ?>
                                                </span>
                                            </div>

                                            <div
                                                style="font-size:12px; color:#888; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; max-width: 250px;">
                                                <?php echo htmlspecialchars($room['description']); ?>
                                            </div>
                                        </div>

                                        <div class="amenities-row">
                                            <?php
                                            if (!empty($room['amenities'])) {
                                                $amIds = explode(',', $room['amenities']);

                                                // 🟢 1. DESKTOP VIEW (Standard 3 items + More Button)
                                                echo '<div class="amenities-desktop">';

                                                $firstThree = array_slice($amIds, 0, 3);
                                                $remaining = array_slice($amIds, 3);

                                                foreach ($firstThree as $id) {
                                                    $id = trim($id);
                                                    if (isset($amenityMap[$id])) {
                                                        $am = $amenityMap[$id];
                                                        echo '<div class="amenity-item">';
                                                        echo '<i class="' . htmlspecialchars($am['icon_class']) . '"></i>';
                                                        echo '<span>' . htmlspecialchars(strtoupper($am['title'])) . '</span>';
                                                        echo '</div>';
                                                    }
                                                }

                                                if (count($remaining) > 0) {
                                                    echo '<div class="amenity-more">';
                                                    echo '<div class="more-badge">+' . count($remaining) . '</div>';
                                                    echo '<span class="more-label">MORE</span>';
                                                    // Tooltip for Desktop More Button
                                                    echo '<div class="amenities-popover">';
                                                    foreach ($remaining as $remId) {
                                                        $remId = trim($remId);
                                                        if (isset($amenityMap[$remId])) {
                                                            $rmAm = $amenityMap[$remId];
                                                            echo '<div class="popover-item">';
                                                            echo '<i class="' . htmlspecialchars($rmAm['icon_class']) . '"></i>';
                                                            echo '<span>' . htmlspecialchars(strtoupper($rmAm['title'])) . '</span>';
                                                            echo '</div>';
                                                        }
                                                    }
                                                    echo '</div>';
                                                    echo '</div>';
                                                }
                                                echo '</div>'; // End amenities-desktop
                                    

                                                // 🟢 2. MOBILE VIEW (Clickable Button -> All Items)
                                                echo '<div class="amenities-mobile">';
                                                echo '<div class="amenity-more mobile-trigger" onclick="toggleMobileAmenities(this)">';
                                                // New Big Icon
                                                echo '<div class="mobile-icon"><i class="fa-solid fa-list-ul"></i></div>';
                                                echo '<span class="more-label">FEATURES</span>';

                                                // Popover contains ALL items
                                                echo '<div class="amenities-popover">';
                                                foreach ($amIds as $allId) {
                                                    $allId = trim($allId);
                                                    if (isset($amenityMap[$allId])) {
                                                        $am = $amenityMap[$allId];
                                                        echo '<div class="popover-item">';
                                                        echo '<i class="' . htmlspecialchars($am['icon_class']) . '"></i>';
                                                        echo '<span>' . htmlspecialchars(strtoupper($am['title'])) . '</span>';
                                                        echo '</div>';
                                                    }
                                                }
                                                echo '</div>';
                                                echo '</div>';
                                                echo '</div>';

                                            } else {
                                                echo '<div class="amenity-item"><i class="fa-solid fa-star"></i> <span>LUXURY</span></div>';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="news-section" id="news">
            <div style="text-align: center; max-width: 800px; margin: 0 auto;">
                <h2 class="section-title">Latest News & Updates</h2>
                <p class="section-subtitle">Keep up with the latest happenings at AMV Hotel.</p>
            </div>

            <div class="news-viewport" id="newsViewport">
                <div class="news-track" id="newsTrack">
                    <?php
                    // Fetch ALL News
                    $newsQuery = "SELECT * FROM hotel_news WHERE is_active = 1 ORDER BY news_date DESC";
                    $newsResult = mysqli_query($conn, $newsQuery);
                    $newsImgBase = '../../room_includes/uploads/news/';
                    $newsPlaceholder = "data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3QgZmlsbD0iI2RkZCIgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJhcmlhbCIgZm9udC1zaXplPSIxMiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzU1NSI+Tm8gSW1hZ2U8L3RleHQ+PC9zdmc+";

                    if ($newsResult && mysqli_num_rows($newsResult) > 0) {
                        while ($newsItem = mysqli_fetch_assoc($newsResult)) {
                            $rawNewsPath = $newsItem['image_path'];
                            if (strpos($rawNewsPath, ',') !== false) {
                                $parts = explode(',', $rawNewsPath);
                                $rawNewsPath = trim($parts[0]);
                            }
                            $newsImgUrl = !empty($rawNewsPath) ? $newsImgBase . htmlspecialchars($rawNewsPath) : $newsPlaceholder;

                            $dateObj = new DateTime($newsItem['news_date']);
                            $formattedDate = $dateObj->format('F d, Y');

                            $cleanDesc = strip_tags($newsItem['description']);
                            if (strlen($cleanDesc) > 100) {
                                $cleanDesc = substr($cleanDesc, 0, 100) . '...';
                            }

                            $link = "news_details.php?id=" . $newsItem['id'];
                            ?>
                            <article class="news-card" id="news-item-<?php echo $newsItem['id']; ?>">
                                <a href="<?php echo $link; ?>" class="news-img-container" style="display:block;">
                                    <img src="<?php echo $newsImgUrl; ?>"
                                        alt="<?php echo htmlspecialchars($newsItem['title']); ?>"
                                        onerror="this.src='<?php echo $newsPlaceholder; ?>'">
                                </a>
                                <div class="news-content">
                                    <span class="news-date"><?php echo $formattedDate; ?></span>
                                    <h3 class="news-title"><?php echo htmlspecialchars($newsItem['title']); ?></h3>
                                    <p class="news-excerpt"><?php echo htmlspecialchars($cleanDesc); ?></p>
                                    <a href="<?php echo $link; ?>" class="read-more-link">Read Full Story</a>
                                </div>
                            </article>
                            <?php
                        }
                    } else {
                        echo '<p style="text-align:center; padding: 20px;">No news updates at the moment.</p>';
                    }
                    ?>
                </div>
            </div>
        </section>

        <section class="mobile-app-promo">
            <div class="app-promo-content reveal-from-left">
                <span class="feature-sub">Enhance Your Stay</span>
                <h2 class="app-promo-title">The AMV Hotel App:<br>Better, Faster Bookings</h2>
                <p class="app-promo-desc">
                    Unlock the full AMV experience. Manage your reservations, order from our kitchen, 
                    and stay updated with exclusive news—all from the palm of your hand. 
                    Download now for a more seamless and personalized service.
                </p>
                <div class="app-download-btns">
                    <a href="javascript:void(0)" onclick="secureDownload()" class="btn-app-download">
                        <i class="fa-solid fa-download"></i> Get the Android App
                    </a>
                </div>

                <script>
                function secureDownload() {
                    // 1. Authorize the download via background AJAX (PRE-FLIGHT)
                    fetch('prep_download.php')
                        .then(res => res.json())
                        .then(data => {
                            if (data.status === 'authorized') {
                                // 2. Now open the download helper in a NEW TAB
                                window.open('app_get.php', '_blank');
                            }
                        })
                        .catch(err => {
                            console.error('Download preparation failed:', err);
                            // Fallback (might be blocked by browser)
                            window.open('app_get.php', '_blank');
                        });
                }
                </script>
            </div>
            <div class="app-promo-visual reveal-from-right">
                <div class="phone-mockup">
                    <div class="phone-screen">
                        <div class="phone-overlay">
                            <i class="fa-solid fa-hotel"></i>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="events-section" id="events">
            <div class="events-header reveal">
                <h2 class="section-title">Memorable Events</h2>
                <p class="section-subtitle">From intimate gatherings to grand celebrations, we make it happen.</p>
            </div>

            <div class="bento-grid">
                <?php
                // 1. Fetch Events
                $evtQuery = "SELECT * FROM hotel_events ORDER BY event_date DESC LIMIT 6";
                $evtRes = mysqli_query($conn, $evtQuery);

                // 2. Define Layout Pattern
                $patterns = ['span-2-2', 'span-1-1', 'span-1-2', 'span-1-1', 'span-2-1', 'span-2-1'];

                // 3. Define Animation Pattern
                $animations = ['reveal-from-left', 'reveal', 'reveal-from-right', 'reveal', 'reveal-from-left', 'reveal-from-right'];

                $i = 0;

                if ($evtRes && mysqli_num_rows($evtRes) > 0) {
                    while ($evt = mysqli_fetch_assoc($evtRes)) {
                        if ($i >= 6)
                            break;

                        $gridClass = $patterns[$i];
                        $animClass = $animations[$i];

                        $imgBase = '../../room_includes/uploads/events/';
                        $img = !empty($evt['image_path']) ? $imgBase . $evt['image_path'] : '../../IMG/default_event.jpg';

                        ?>
                        <div class="bento-item <?php echo $gridClass . ' ' . $animClass; ?>">
                            <img src="<?php echo htmlspecialchars($img); ?>"
                                alt="<?php echo htmlspecialchars($evt['title']); ?>"
                                onerror="this.src='../../IMG/hotel_events.png'">
                            <div class="bento-overlay">
                                <h3 class="bento-title"><?php echo htmlspecialchars($evt['title']); ?></h3>
                                <p class="bento-desc"><?php echo date('M d, Y', strtotime($evt['event_date'])); ?></p>
                            </div>
                        </div>
                        <?php
                        $i++;
                    }
                } else {
                    echo '<div style="grid-column: 1/-1; text-align:center; padding:40px;">No upcoming events scheduled.</div>';
                }
                ?>
            </div>

            <div style="text-align: center; margin-top: 50px;" class="reveal">
                <a href="events_gallery.php" class="btn-outline-gold">SEE ALL EVENTS</a>
            </div>
        </section>

        <section class="feedback-section" id="feedback">
            <div style="text-align: center; max-width: 800px; margin: 0 auto;">
                <h2 class="section-title">Guest Stories</h2>
                <p class="section-subtitle">What our valued guests say about their stay.</p>
            </div>

            <div class="feedback-viewport" id="feedbackViewport">
                <div class="feedback-track" id="feedbackTrack">
                    <?php
                    $feedQuery = "SELECT gf.*, bg.first_name, bg.last_name 
                              FROM guest_feedback gf
                              JOIN bookings b ON gf.booking_reference = b.booking_reference
                              JOIN booking_guests bg ON b.id = bg.booking_id
                              ORDER BY (gf.rating_overall >= 4) DESC, gf.created_at DESC 
                              LIMIT 6";

                    $feedResult = mysqli_query($conn, $feedQuery);

                    if ($feedResult && mysqli_num_rows($feedResult) > 0) {
                        while ($row = mysqli_fetch_assoc($feedResult)) {
                            $fullName = $row['first_name'] . ' ' . $row['last_name'];
                            $rating = $row['rating_overall'];
                            $comment = !empty($row['comments']) ? $row['comments'] : "Rated " . $rating . " stars.";
                            $dateObj = new DateTime($row['created_at']);
                            $stayDate = $dateObj->format('M Y');
                            $avatarUrl = "https://ui-avatars.com/api/?name=" . urlencode($fullName) . "&background=b8860b&color=fff";
                            ?>
                            <div class="feedback-card">
                                <i class="fa-solid fa-quote-right quote-icon"></i>
                                <div class="stars">
                                    <?php
                                    for ($i = 1; $i <= 5; $i++) {
                                        if ($i <= $rating) {
                                            echo '<i class="fa-solid fa-star"></i>';
                                        } else {
                                            echo '<i class="fa-regular fa-star" style="color:#ccc;"></i>';
                                        }
                                    }
                                    ?>
                                </div>
                                <p class="feedback-text">"<?php echo htmlspecialchars($comment); ?>"</p>
                                <div class="client-info">
                                    <img src="<?php echo $avatarUrl; ?>" class="client-avatar" alt="Guest"
                                        style="pointer-events: none;">
                                    <div class="client-details">
                                        <h4><?php echo htmlspecialchars($fullName); ?></h4>
                                        <span>Stayed <?php echo $stayDate; ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php
                        }
                    } else {
                        echo '<p style="text-align:center; padding: 20px; width:100%;">No guest stories available yet.</p>';
                    }
                    ?>
                </div>
            </div>
        </section>
    </div>

    <footer class="footer-white-section">
        <div class="footer-content-area">
            <div class="footer-grid-container">

                <div>
                    <p class="footer-tagline-text">
                        "Come experience the difference at AMV Hotel - your sophisticated home in the heart of
                        Mamburao."
                    </p>
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
                    <a href="term_conditions.php" class="footer-contact-item">
                        <i class="fas fa-file-contract"></i> <span>Hotel Policies</span>
                    </a>
                    <a href="privacy_policy.php" class="footer-contact-item">
                        <i class="fas fa-user-shield"></i> <span>Privacy Policy</span>
                    </a>
                </div>

                <div class="action-buttons-col">
                    <button class="btn-icon-msg" onclick="toggleChat()" title="Message Admin">
                        <i class="fas fa-comment-dots"></i>
                    </button>
                </div>

            </div>
        </div>
        <div class="footer-violet-bar">© 2025 AMV Hotel. All rights reserved.</div>
    </footer>

    <!-- Message Bubble -->
    <div id="chatBubble" class="chat-bubble-container">
        <span class="close-bubble" onclick="toggleChat()"><i class="fas fa-times"></i></span>

        <div class="bubble-header">Message Admin</div>
        <span class="bubble-sub">We usually reply within 1 hour.</span>

        <form onsubmit="submitChatForm(event)">
            <input type="text" name="guest_name" class="chat-input" placeholder="Your Name" required>
            <input type="email" name="email" class="chat-input" placeholder="Your Email" required>
            <textarea id="msgArea" name="message" class="chat-input chat-textarea" placeholder="How can we help?"
                required></textarea>
            <button type="submit" class="btn-chat-send">Send</button>
        </form>
    </div>

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
        window.addEventListener('load', () => setTimeout(checkScroll, 100));

        function toggleMobileMenu() {
            const menu = document.getElementById('mobileMenu');
            const backdrop = document.getElementById('mobileBackdrop');
            const burger = document.getElementById('burgerIcon');
            
            menu.classList.toggle('active');
            backdrop.classList.toggle('active');
            if (burger) burger.classList.toggle('active');
            document.body.classList.toggle('menu-open');
        }

        function toggleMobileAmenities(element) {
            document.querySelectorAll('.amenity-more.active').forEach(el => {
                if (el !== element) el.classList.remove('active');
            });
            element.classList.toggle('active');
        }

        // Hero Slider
        document.addEventListener("DOMContentLoaded", function () {
            const slides = document.querySelectorAll('.hero-slide');
            let currentSlide = 0;
            const slideInterval = 5000;
            if (slides.length > 0) {
                setInterval(() => {
                    slides[currentSlide].classList.remove('active');
                    currentSlide = (currentSlide + 1) % slides.length;
                    slides[currentSlide].classList.add('active');
                }, slideInterval);
            }
        });

        // Scroll Reveal
        const observerOptions = { root: null, rootMargin: '0px', threshold: 0.60 };
        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                    obs.unobserve(entry.target);
                }
            });
        }, observerOptions);
        document.querySelectorAll('.reveal, .reveal-from-left, .reveal-from-right').forEach((el) => {
            observer.observe(el);
        });

        // Parallax
        const parallaxImages = document.querySelectorAll('.feature-half-img img');
        if (parallaxImages.length > 0) {
            let currentScroll = 0;
            let targetScroll = 0;
            const ease = 0.08;
            function lerp(start, end, factor) { return start + (end - start) * factor; }
            function animateParallax() {
                targetScroll = window.scrollY;
                currentScroll = lerp(currentScroll, targetScroll, ease);
                const speed = window.innerWidth <= 768 ? 0.03 : 0.06;
                parallaxImages.forEach(img => {
                    const container = img.parentElement;
                    if (!container) return;
                    const containerTop = container.offsetTop;
                    const containerHeight = container.offsetHeight;
                    const windowHeight = window.innerHeight;
                    if (targetScroll + windowHeight > containerTop && targetScroll < containerTop + containerHeight) {
                        const yPos = (currentScroll - containerTop) * -speed;
                        img.style.transform = `translate3d(0, ${yPos}px, 0)`;
                    }
                });
                requestAnimationFrame(animateParallax);
            }
            animateParallax();
        }

        // Rooms Slider (Infinite Drag)
        const track = document.getElementById('track');
        const nextBtn = document.getElementById('nextBtn');
        const prevBtn = document.getElementById('prevBtn');
        const galleryViewport = document.querySelector('.gallery-viewport');
        const CARD_WIDTH = 600;
        const GAP = 30;
        const TOTAL_MOVE = CARD_WIDTH + GAP;
        const TRANSITION_SPEED = 500;
        let isAnimating = false;
        let isDragging = false;
        let startPos = 0;
        let currentTranslate = -TOTAL_MOVE;
        let prevTranslate = -TOTAL_MOVE;
        let animationID;

        function isDesktop() { return window.innerWidth > 768; }

        if (isDesktop() && track && track.children.length > 1) {
            track.prepend(track.lastElementChild);
            track.style.transition = 'none';
            track.style.transform = `translateX(-${TOTAL_MOVE}px)`;
            void track.offsetWidth;
            track.style.transition = `transform ${TRANSITION_SPEED}ms ease-in-out`;
            track.children[1].classList.add('active');
        }

        const moveNext = (dragOffset = 0) => {
            if (!isDesktop() || isAnimating || !track.firstElementChild) return;
            isAnimating = true;
            track.children[1].classList.remove('active');
            track.children[2].classList.add('active');
            const firstCard = track.firstElementChild;
            const clone = firstCard.cloneNode(true);
            track.appendChild(clone);
            track.style.transition = 'none';
            track.style.transform = `translateX(${-TOTAL_MOVE + dragOffset}px)`;
            void track.offsetWidth;
            track.style.transition = `transform ${TRANSITION_SPEED}ms ease-in-out`;
            track.style.transform = `translateX(-${TOTAL_MOVE * 2}px)`;
            setTimeout(() => {
                track.style.transition = 'none';
                track.removeChild(clone);
                track.appendChild(firstCard);
                track.style.transform = `translateX(-${TOTAL_MOVE}px)`;
                currentTranslate = -TOTAL_MOVE;
                prevTranslate = -TOTAL_MOVE;
                setTimeout(() => { isAnimating = false; }, 50);
            }, TRANSITION_SPEED);
        };

        const movePrev = (dragOffset = 0) => {
            if (!isDesktop() || isAnimating || !track.lastElementChild) return;
            isAnimating = true;
            track.children[1].classList.remove('active');
            track.children[0].classList.add('active');
            const lastCard = track.lastElementChild;
            const clone = lastCard.cloneNode(true);
            track.prepend(clone);
            track.style.transition = 'none';
            track.style.transform = `translateX(${(-TOTAL_MOVE * 2) + dragOffset}px)`;
            void track.offsetWidth;
            track.style.transition = `transform ${TRANSITION_SPEED}ms ease-in-out`;
            track.style.transform = `translateX(-${TOTAL_MOVE}px)`;
            setTimeout(() => {
                track.style.transition = 'none';
                track.removeChild(clone);
                track.prepend(lastCard);
                currentTranslate = -TOTAL_MOVE;
                prevTranslate = -TOTAL_MOVE;
                setTimeout(() => { isAnimating = false; }, 50);
            }, TRANSITION_SPEED);
        };

        const snapBack = () => {
            track.style.transition = 'transform 0.3s ease-out';
            track.style.transform = `translateX(-${TOTAL_MOVE}px)`;
            currentTranslate = -TOTAL_MOVE;
            prevTranslate = -TOTAL_MOVE;
        };

        if (nextBtn) nextBtn.addEventListener('click', () => moveNext(0));
        if (prevBtn) prevBtn.addEventListener('click', () => movePrev(0));

        function getPositionX(event) { return event.type.includes('mouse') ? event.pageX : event.touches[0].clientX; }
        function touchStart(event) {
            if (isAnimating) return;
            isDragging = true;
            startPos = getPositionX(event);
            track.style.transition = 'none';
            galleryViewport.style.cursor = 'grabbing';
            animationID = requestAnimationFrame(animation);
        }
        function touchMove(event) {
            if (isDragging) {
                const currentPosition = getPositionX(event);
                const diff = currentPosition - startPos;
                currentTranslate = prevTranslate + diff;
            }
        }
        function touchEnd() {
            isDragging = false;
            cancelAnimationFrame(animationID);
            galleryViewport.style.cursor = 'grab';
            const movedBy = currentTranslate - prevTranslate;
            if (movedBy < -100) moveNext(movedBy);
            else if (movedBy > 100) movePrev(movedBy);
            else snapBack();
        }
        function animation() {
            if (isDragging) {
                track.style.transform = `translateX(${currentTranslate}px)`;
                requestAnimationFrame(animation);
            }
        }

        if (galleryViewport) {
            galleryViewport.addEventListener('touchstart', touchStart);
            galleryViewport.addEventListener('touchmove', touchMove);
            galleryViewport.addEventListener('touchend', touchEnd);
            galleryViewport.addEventListener('mousedown', touchStart);
            galleryViewport.addEventListener('mousemove', touchMove);
            galleryViewport.addEventListener('mouseup', touchEnd);
            galleryViewport.addEventListener('mouseleave', () => { if (isDragging) touchEnd(); });
            galleryViewport.oncontextmenu = function (event) { event.preventDefault(); return false; }
        }

        // Feedback Slider
        const feedbackViewport = document.querySelector('.feedback-viewport');
        const feedbackTrackContainer = document.querySelector('.feedback-track');
        if (feedbackViewport && feedbackTrackContainer) {
            let isDown = false;
            let startX;
            let currentTranslate = 0;
            let prevTranslate = 0;
            let animationID;
            let slideWidth = 0;
            let totalRealWidth = 0;
            let centerOffset = 0;
            let autoScrollTimer;
            const AUTO_SCROLL_DELAY = 3000;
            const cloneCount = 5;

            function setupInfiniteLoop() {
                const existingClones = feedbackTrackContainer.querySelectorAll('.clone-item');
                existingClones.forEach(el => el.remove());
                const cards = Array.from(feedbackTrackContainer.children);
                if (cards.length === 0) return;
                const firstClones = [];
                const lastClones = [];
                for (let i = 0; i < cloneCount; i++) {
                    const firstClone = cards[i % cards.length].cloneNode(true);
                    firstClone.classList.add('clone-item');
                    firstClones.push(firstClone);
                    const lastClone = cards[cards.length - 1 - (i % cards.length)].cloneNode(true);
                    lastClone.classList.add('clone-item');
                    lastClones.push(lastClone);
                }
                firstClones.forEach(c => feedbackTrackContainer.appendChild(c));
                lastClones.reverse().forEach(c => feedbackTrackContainer.prepend(c));
                updateDimensions();
                currentTranslate = -(cloneCount * slideWidth) + centerOffset;
                prevTranslate = currentTranslate;
                setSliderPosition();
                startAutoScroll();
            }

            function getSlideWidth() {
                const card = feedbackTrackContainer.querySelector('.feedback-card');
                if (!card) return 0;
                const style = window.getComputedStyle(feedbackTrackContainer);
                const gap = parseFloat(style.gap) || 30;
                return card.offsetWidth + gap;
            }

            function updateDimensions() {
                slideWidth = getSlideWidth();
                const realCardCount = feedbackTrackContainer.querySelectorAll('.feedback-card:not(.clone-item)').length;
                totalRealWidth = realCardCount * slideWidth;
                if (window.innerWidth <= 768) {
                    const card = feedbackTrackContainer.querySelector('.feedback-card');
                    if (card) centerOffset = (feedbackViewport.offsetWidth - card.offsetWidth) / 2;
                } else {
                    centerOffset = 0;
                }
            }

            function setSliderPosition() { feedbackTrackContainer.style.transform = `translateX(${currentTranslate}px)`; }
            function startAutoScroll() { stopAutoScroll(); autoScrollTimer = setInterval(() => { const currentAbs = Math.abs(currentTranslate - centerOffset); const currentIndex = Math.round(currentAbs / slideWidth); snapToSlide(currentIndex + 1); }, AUTO_SCROLL_DELAY); }
            function stopAutoScroll() { clearInterval(autoScrollTimer); }

            const startDrag = (pageX) => {
                stopAutoScroll(); isDown = true; feedbackViewport.classList.add('active'); startX = pageX; cancelAnimationFrame(animationID);
                const matrix = new WebKitCSSMatrix(window.getComputedStyle(feedbackTrackContainer).transform);
                currentTranslate = matrix.m41; prevTranslate = currentTranslate;
            };

            const moveDrag = (pageX) => {
                if (!isDown) return;
                const diff = pageX - startX;
                let newTranslate = prevTranslate + diff;
                let checkPos = newTranslate - centerOffset;
                if (checkPos > -slideWidth) { newTranslate -= totalRealWidth; prevTranslate -= totalRealWidth; }
                if (checkPos < -((cloneCount * slideWidth) + totalRealWidth)) { newTranslate += totalRealWidth; prevTranslate += totalRealWidth; }
                currentTranslate = newTranslate; setSliderPosition();
            };

            const endDrag = () => {
                isDown = false; feedbackViewport.classList.remove('active');
                const relativePos = Math.abs(currentTranslate - centerOffset);
                const exactIndex = relativePos / slideWidth;
                let targetIndex = Math.round(exactIndex);
                snapToSlide(targetIndex); startAutoScroll();
            };

            function snapToSlide(targetIndex) {
                const targetTranslate = -(targetIndex * slideWidth) + centerOffset;
                function animate() {
                    currentTranslate += (targetTranslate - currentTranslate) * 0.1;
                    setSliderPosition();
                    if (Math.abs(targetTranslate - currentTranslate) > 0.5) { animationID = requestAnimationFrame(animate); }
                    else {
                        currentTranslate = targetTranslate;
                        const purePos = currentTranslate - centerOffset;
                        const totalClonesWidth = cloneCount * slideWidth;
                        if (Math.abs(purePos) < totalClonesWidth - 5) { currentTranslate -= totalRealWidth; }
                        else if (Math.abs(purePos) >= totalClonesWidth + totalRealWidth) { currentTranslate += totalRealWidth; }
                        setSliderPosition();
                    }
                }
                cancelAnimationFrame(animationID); animate();
            }

            feedbackViewport.addEventListener('mousedown', e => startDrag(e.pageX));
            feedbackViewport.addEventListener('touchstart', e => startDrag(e.touches[0].pageX));
            feedbackViewport.addEventListener('mousemove', e => { e.preventDefault(); moveDrag(e.pageX); });
            feedbackViewport.addEventListener('touchmove', e => moveDrag(e.touches[0].pageX));
            feedbackViewport.addEventListener('mouseenter', stopAutoScroll);
            feedbackViewport.addEventListener('mouseleave', () => { if (!isDown) startAutoScroll(); if (isDown) endDrag(); });
            feedbackViewport.addEventListener('mouseup', endDrag);
            feedbackViewport.addEventListener('touchend', endDrag);

            window.addEventListener('load', () => { setTimeout(setupInfiniteLoop, 200); });
            window.addEventListener('resize', () => {
                updateDimensions();
                const relativePos = Math.abs(currentTranslate - centerOffset);
                const calculatedIndex = Math.round(relativePos / slideWidth);
                currentTranslate = -(calculatedIndex * slideWidth) + centerOffset;
                prevTranslate = currentTranslate;
                setSliderPosition();
            });
        }

        // 🔴 ADD CHAT BUBBLE SCRIPTS 🔴
        function toggleChat() {
            var bubble = document.getElementById("chatBubble");
            bubble.classList.toggle("active");
        }

        document.addEventListener('click', function (event) {
            var bubble = document.getElementById("chatBubble");
            var btn = document.querySelector('.btn-icon-msg');

            // Ensure elements exist before checking
            if (bubble && btn && !bubble.contains(event.target) && !btn.contains(event.target)) {
                bubble.classList.remove("active");
            }
        });

        const textArea = document.getElementById('msgArea');
        if (textArea) {
            textArea.addEventListener('input', function () {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }

        // --- ROOM MINI-SLIDER LOGIC ---
        function rotateRoomImage(event, roomIndex, direction) {
            // 1. Prevent the click from bubbling up (so it doesn't trigger drag/swipe)
            event.stopPropagation();
            event.preventDefault();

            // 2. Find the container for this specific room
            const wrapper = document.getElementById('room-slider-' + roomIndex);
            if (!wrapper) return;

            // 3. Get all images in this wrapper
            const images = wrapper.querySelectorAll('.room-image');
            let activeIndex = 0;

            // 4. Find currently active image index
            images.forEach((img, idx) => {
                if (img.classList.contains('active')) {
                    activeIndex = idx;
                    img.classList.remove('active'); // Hide current
                }
            });

            // 5. Calculate new index (Looping)
            let newIndex = activeIndex + direction;
            if (newIndex < 0) {
                newIndex = images.length - 1; // Loop to last
            } else if (newIndex >= images.length) {
                newIndex = 0; // Loop to first
            }

            // 6. Show new image
            images[newIndex].classList.add('active');
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
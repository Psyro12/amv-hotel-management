<?php
// USER/PHP/about_us.php

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
        'secure' => true, 
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// --- 3. Database Connection ---
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

// --- 5. FETCH ABOUT US CONTENT ---
$about_content = [
    'gem_title' => 'The Gem of Mamburao',
    'gem_p1' => "Welcome to AMV Hotel, your home away from home in Occidental Mindoro. Conveniently located in the town center, we offer travelers a comfortable and secure place to rest, whether you are here for business, family gatherings, or a relaxing getaway.\n\nWe pride ourselves on providing warm Filipino hospitality and essential modern conveniences. Our goal is to ensure that every guest enjoys a peaceful and hassle-free stay with us.",
    'gem_p2' => '',
    'sig_title' => 'Signature Experience',
    'sig_relax_h3' => 'Relax in Style',
    'sig_relax_p' => 'Our rooms and suites are designed with your utmost comfort in mind. Featuring contemporary interiors and plush bedding, every room at AMV Hotel serves as a private retreat after a day of exploring Mamburao. Wake up refreshed and ready to take on the day.',
    'sig_dining_h3' => 'Exquisite Local Dining',
    'sig_dining_p' => 'Savor the flavors of Mindoro at our signature restaurant. Our chefs prepare a delightful fusion of local favorites and international classics using the freshest ingredients. Perfect for a family feast or an intimate dinner.',
    'visit_address' => 'Mamburao, Occidental Mindoro, Philippines',
    'hotel_services' => "fa-clock|24-Hour Front Desk\nfa-wifi|High-Speed Wi-Fi Access\nfa-car|On-Site Parking\nfa-shield-halved|24/7 Security\nfa-broom|Daily Housekeeping\nfa-couch|Comfortable Lobby Lounge",
    'room_features' => "fa-wind|Air Conditioning\nfa-tv|Flat-Screen TV\nfa-shower|Private Bathroom\nfa-bed|Premium Bedding\nfa-soap|Complimentary Toiletries",
    'sig_relax_img' => '../../IMG/room_1.jpg',
    'sig_dining_img' => '../../IMG/food_7.jpg'
];

// Safer query with table existence check
$check_table = $conn->query("SHOW TABLES LIKE 'about_us_content'");
if ($check_table && $check_table->num_rows > 0) {
    $res_content = $conn->query("SELECT section_key, content_text FROM about_us_content");
    if ($res_content && $res_content->num_rows > 0) {
        while ($row_c = $res_content->fetch_assoc()) {
            if (!empty($row_c['content_text'])) {
                $about_content[$row_c['section_key']] = $row_c['content_text'];
            }
        }
    }
}

// --- 6. AJAX Chat Handler (Embedded) ---
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
    <title>About Us - AMV Hotel</title>
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

        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #f9f9f9;
            margin: 0;
            padding: 0;
            color: #333;
            overflow-x: hidden;
        }

        /* --- WRAPPER FOR FIXED FOOTER EFFECT --- */
        .page-content-wrapper {
            background-color: #fff;
            position: relative;
            z-index: 10;
            margin-bottom: 250px;
            /* MATCHES FOOTER HEIGHT */
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.1);
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

        .desktop-nav a:hover {
            color: #b8860b;
        }

        .btn-header-book {
            padding: 10px 30px;
            background-color: transparent;
            color: #fff;
            border: 1px solid #fff;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-radius: 4px;
            transition: 0.3s;
        }

        .btn-header-book:hover {
            background-color: #fff;
            color: #333;
        }

        header.scrolled .btn-header-book {
            border-color: #b8860b;
            color: #b8860b;
        }

        header.scrolled .btn-header-book:hover {
            background-color: #b8860b;
            color: #fff;
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

            .about-hero h1 {
                font-size: 1.8rem;
                letter-spacing: 2px;
            }
        }

        /* --- HERO --- */
        .about-hero {
            position: relative;
            height: 50vh;
            min-height: 350px;
            background-image: linear-gradient(rgba(0, 0, 0, 0.6), rgba(0, 0, 0, 0.6)), url('../../IMG/hotel_background.png');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: white;
            padding-top: 60px;
        }

        .about-hero h1 {
            font-size: 2.8rem;
            text-transform: uppercase;
            letter-spacing: 3px;
            font-weight: 700;
            margin: 0 0 10px 0;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .breadcrumb {
            font-size: 0.9rem;
            color: #ddd;
            letter-spacing: 1px;
        }

        /* --- SECTIONS --- */
        .about-grid-section {
            padding: 80px 7%;
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 60px;
            overflow: hidden;
            background: #fff;
        }

        .about-text-content h2 {
            font-size: 2.2rem;
            color: #333;
            margin-bottom: 30px;
            font-weight: 400;
        }

        .about-text-content p {
            line-height: 1.8;
            color: #666;
            margin-bottom: 20px;
            font-size: 1rem;
            text-align: justify;
        }

        .awards-row {
            display: flex;
            gap: 20px;
            margin-top: 40px;
            flex-wrap: wrap;
        }

        .award-badge {
            width: 100px;
            height: 100px;
            background-color: #f9f9f9;
            border: 1px solid #eee;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            font-size: 10px;
            color: #9e8236;
            font-weight: bold;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s;
        }

        .award-badge:hover {
            transform: translateY(-5px);
        }

        .award-badge i {
            font-size: 24px;
            margin-bottom: 5px;
            display: block;
            color: #9e8236;
        }

        .facilities-col h3 {
            font-size: 1.5rem;
            color: #9e8236;
            margin-bottom: 25px;
            font-weight: 600;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
            display: inline-block;
        }

        .facilities-list {
            list-style: none;
            padding: 0;
            margin: 0 0 40px 0;
        }

        .facilities-list li {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            color: #555;
            font-size: 1rem;
        }

        .facilities-list li i {
            width: 30px;
            color: #9e8236;
            font-size: 1.1rem;
            margin-right: 10px;
        }

        .signature-section {
            background-color: #f9f9f9;
            padding: 80px 7%;
            overflow: hidden;
        }

        .section-center-title {
            text-align: center;
            font-size: 2.5rem;
            color: #333;
            margin-bottom: 60px;
            font-weight: 400;
        }

        .sig-row {
            display: flex;
            align-items: center;
            gap: 50px;
            margin-bottom: 80px;
        }

        .sig-row:nth-child(even) {
            flex-direction: row-reverse;
        }

        .sig-img {
            flex: 1;
            height: 400px;
            overflow: hidden;
            border-radius: 4px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .sig-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s;
        }

        .sig-img:hover img {
            transform: scale(1.05);
        }

        .sig-text {
            flex: 1;
        }

        .sig-text h3 {
            font-size: 1.8rem;
            color: #9e8236;
            margin-bottom: 20px;
        }

        .sig-text p {
            line-height: 1.8;
            color: #666;
        }

        .location-section {
            padding: 80px 7%;
            display: flex;
            flex-wrap: wrap;
            gap: 40px;
            overflow: hidden;
            background: #fff;
        }

        .contact-details {
            flex: 1;
            min-width: 300px;
        }

        .contact-details h2 {
            font-size: 2.5rem;
            margin-bottom: 30px;
            color: #333;
        }

        .contact-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 25px;
        }

        .contact-item i {
            font-size: 1.5rem;
            color: #9e8236;
            margin-right: 20px;
            margin-top: 5px;
        }

        .map-container {
            flex: 1;
            min-width: 300px;
            height: 400px;
            background: #eee;
            border-radius: 8px;
            overflow: hidden;
        }

        @media (max-width: 992px) {
            .about-grid-section {
                grid-template-columns: 1fr;
            }

            .sig-row,
            .sig-row:nth-child(even) {
                flex-direction: column;
            }

            .sig-img {
                width: 100%;
                height: 300px;
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

        /* --- ANIMATIONS --- */
        .fade-in-up {
            opacity: 0;
            animation: fadeInUp 1s ease-out forwards;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .reveal {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.8s ease-out;
        }

        .reveal.active {
            opacity: 1;
            transform: translateY(0);
        }

        .reveal-from-left {
            opacity: 0;
            transform: translateX(-50px);
            transition: all 1s ease-out;
        }

        .reveal-from-left.active {
            opacity: 1;
            transform: translateX(0);
        }

        .reveal-from-right {
            opacity: 0;
            transform: translateX(50px);
            transition: all 1s ease-out;
        }

        .reveal-from-right.active {
            opacity: 1;
            transform: translateX(0);
        }

        /* --- FIXED FOOTER CSS (MATCHING HOME PAGE EXACTLY) --- */
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

        /* 🔴 IMPORTANT OVERRIDE TO MATCH HOME PAGE STYLING 🔴 */
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
                <a href="menu.php">Dining</a>
                <a href="check_availability.php">Reservations</a>
                <a href="about_us.php" style="color: #b8860b;">About Us</a>
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
            <a href="menu.php"><i class="fa-solid fa-utensils"></i> Dining</a>
            <a href="check_availability.php"><i class="fa-solid fa-calendar-check"></i> Reservations</a>
            <a href="about_us.php" style="color: #b8860b;"><i class="fa-solid fa-circle-info"></i> About Us</a>
            
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

        <section class="about-hero">
            <h1 class="fade-in-up">About Us</h1>
            <div class="breadcrumb fade-in-up" style="animation-delay: 0.2s;">Home > About Us</div>
        </section>

        <section class="about-grid-section">
            <div class="about-text-content reveal-from-left">
                <h2><?php echo htmlspecialchars($about_content['gem_title']); ?></h2>
                <?php 
                // Split gem_p1 into separate paragraphs by detecting blank lines
                $paragraphs = preg_split("/\n\s*\n/", $about_content['gem_p1']);
                foreach ($paragraphs as $p) {
                    if (trim($p)) {
                        echo '<p>' . nl2br(htmlspecialchars(trim($p))) . '</p>';
                    }
                }
                
                // Show gem_p2 if it's not empty (will eventually be empty after admin saves)
                if (!empty(trim($about_content['gem_p2']))) {
                    echo '<p>' . nl2br(htmlspecialchars(trim($about_content['gem_p2']))) . '</p>';
                }
                ?>

                <div class="awards-row">
                    <div class="award-badge">
                        <div><i class="fa-solid fa-location-dot"></i>Prime<br>Location</div>
                    </div>
                    <div class="award-badge">
                        <div><i class="fa-solid fa-wifi"></i>Free<br>Wi-Fi</div>
                    </div>
                    <div class="award-badge">
                        <div><i class="fa-solid fa-face-smile"></i>Friendly<br>Staff</div>
                    </div>
                    <div class="award-badge">
                        <div><i class="fa-solid fa-star"></i>Best<br>Value</div>
                    </div>
                </div>
            </div>

            <div class="facilities-col reveal-from-right">
                <h3>Hotel Services</h3>
                <ul class="facilities-list">
                    <?php
                    $services = explode("\n", $about_content['hotel_services']);
                    foreach ($services as $service) {
                        $parts = explode('|', $service);
                        if (count($parts) == 2) {
                            echo '<li><i class="fa-solid ' . htmlspecialchars(trim($parts[0])) . '"></i> ' . htmlspecialchars(trim($parts[1])) . '</li>';
                        }
                    }
                    ?>
                </ul>

                <h3>Room Features</h3>
                <ul class="facilities-list">
                    <?php
                    $features = explode("\n", $about_content['room_features']);
                    foreach ($features as $feature) {
                        $parts = explode('|', $feature);
                        if (count($parts) == 2) {
                            echo '<li><i class="fa-solid ' . htmlspecialchars(trim($parts[0])) . '"></i> ' . htmlspecialchars(trim($parts[1])) . '</li>';
                        }
                    }
                    ?>
                </ul>
            </div>
        </section>

        <section class="signature-section">
            <h2 class="section-center-title reveal"><?php echo htmlspecialchars($about_content['sig_title']); ?></h2>

            <?php
            // Helper to get correct image path
            function getAboutImg($val, $default) {
                if (empty($val)) return $default;
                if (strpos($val, '../../') === 0) return $val; // Hardcoded default paths
                return '../../room_includes/uploads/about/' . $val; // Uploaded files
            }
            ?>

            <div class="sig-row reveal-from-left">
                <div class="sig-img"><img src="<?php echo getAboutImg($about_content['sig_relax_img'], '../../IMG/room_1.jpg'); ?>" alt="Luxury Room"></div>
                <div class="sig-text">
                    <h3><?php echo htmlspecialchars($about_content['sig_relax_h3']); ?></h3>
                    <p>
                        <?php echo nl2br(htmlspecialchars($about_content['sig_relax_p'])); ?>
                    </p>
                </div>
            </div>

            <div class="sig-row reveal-from-right">
                <div class="sig-img"><img src="<?php echo getAboutImg($about_content['sig_dining_img'], '../../IMG/food_7.jpg'); ?>" alt="Dining"></div>
                <div class="sig-text">
                    <h3><?php echo htmlspecialchars($about_content['sig_dining_h3']); ?></h3>
                    <p>
                        <?php echo nl2br(htmlspecialchars($about_content['sig_dining_p'])); ?>
                    </p>
                </div>
            </div>
        </section>

        <section class="location-section">
            <div class="contact-details reveal-from-left">
                <h2>Visit Us</h2>
                <div class="contact-item"><i class="fa-solid fa-location-dot"></i>
                    <div>
                        <h4>Address</h4>
                        <p><?php echo htmlspecialchars($about_content['visit_address']); ?></p>
                    </div>
                </div>
                <div class="contact-item"><i class="fa-solid fa-phone"></i>
                    <div>
                        <h4>Phone</h4>
                        <p><?php echo htmlspecialchars($hotel_phone); ?></p>
                    </div>
                </div>
                <div class="contact-item"><i class="fa-solid fa-envelope"></i>
                    <div>
                        <h4>Email</h4>
                        <p><?php echo htmlspecialchars($hotel_email); ?></p>
                    </div>
                </div>
            </div>

            <div class="map-container">
                <iframe width="100%" height="100%" frameborder="0" scrolling="no" marginheight="0" marginwidth="0"
                    src="https://maps.google.com/maps?q=AMV+Hotel,+Events+Place+%26+Restaurant,+Mamburao,+Occidental+Mindoro&t=&z=15&ie=UTF8&iwloc=&output=embed"></iframe>
            </div>
        </section>
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
                    <a href="term_conditions.php" class="footer-contact-item"><i class="fas fa-file-contract"></i>
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

    <script>
        // Header Logic
        const header = document.getElementById('mainHeader');
        function checkScroll() {
            if (window.scrollY > 50) header.classList.add('scrolled');
            else header.classList.remove('scrolled');
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

        // Reveal Animation
        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                    obs.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });

        document.querySelectorAll('.reveal, .reveal-from-left, .reveal-from-right').forEach((el) => {
            observer.observe(el);
        });
    </script>
</body>

</html>
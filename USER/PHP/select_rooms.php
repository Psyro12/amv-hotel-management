<?php
// USER/PHP/select_rooms.php

// 1. SET TIMEZONE FIRST
date_default_timezone_set('Asia/Manila');

// 2. Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
// Added upgrade-insecure-requests
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https://via.placeholder.com; upgrade-insecure-requests;");

// 3. Secure Session Settings
if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => true, // Changed to TRUE for security standards
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// Disable Error Reporting to Screen
ini_set('display_errors', 0);
error_reporting(E_ALL);

require 'db_connect.php';

// 4. Capture & Sanitize Data
$checkin = isset($_GET['checkin']) ? htmlspecialchars($_GET['checkin']) : '';
$checkout = isset($_GET['checkout']) ? htmlspecialchars($_GET['checkout']) : '';
$adults = isset($_GET['adults']) ? intval($_GET['adults']) : 1;
$children = isset($_GET['children']) ? intval($_GET['children']) : 0;

// 5. Basic Validation
if (empty($checkin) || empty($checkout)) {
    header("Location: check_availability.php");
    exit();
}

// 6. Calculate Nights & Formats
$nights = 0;
$formatted_checkin = "Select Date";
$formatted_checkout = "Select Date";

try {
    $date1 = new DateTime($checkin);
    $date2 = new DateTime($checkout);
    
    // Security Check: Ensure checkout is after checkin
    if ($date2 <= $date1) {
        header("Location: check_availability.php?error=invalid_dates");
        exit();
    }

    $interval = $date1->diff($date2);
    $nights = $interval->days;
    $formatted_checkin = $date1->format('M d, Y');
    $formatted_checkout = $date2->format('M d, Y');
} catch (Exception $e) {
    // If dates are invalid, redirect back safely
    header("Location: check_availability.php?error=date_format");
    exit();
}

// Define Placeholder here for global use
$placeholder = "data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3QgZmlsbD0iI2RkZCIgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJhcmlhbCIgZm9udC1zaXplPSIxMiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzU1NSI+Tm8gSW1hZ2U8L3RleHQ+PC9zdmc+";

// 7. FETCH AVAILABLE ROOMS (Updated for 'rooms' table)
$available_rooms = [];

// Query selects from 'rooms' table, filtering active rooms and excluding booked ones
$query = "
    SELECT * FROM rooms 
    WHERE is_active = 1 
    AND id NOT IN (
        SELECT br.room_id 
        FROM booking_rooms br
        JOIN bookings b ON br.booking_id = b.id
        WHERE b.status IN ('confirmed', 'pending')
        AND b.arrival_status != 'checked_out' 
        AND (
            ? < b.check_out AND ? > b.check_in
        )
    )
    ORDER BY name ASC
";

$stmt = $conn->prepare($query);

if ($stmt) {
    // Bind dates securely
    $stmt->bind_param("ss", $checkin, $checkout);

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {

                // IMAGE LOGIC: Handle CSV images
                $rawPath = $row['image_path']; // Column name in new table
                if (strpos($rawPath, ',') !== false) {
                    $parts = explode(',', $rawPath);
                    $rawPath = trim($parts[0]);
                }
                
                // Sanitize Image Path just in case
                $row['final_image'] = htmlspecialchars($rawPath);
                $available_rooms[] = $row;
            }
        }
    } else {
        error_log("SQL Execute Error: " . $stmt->error);
    }
    $stmt->close();
} else {
    die("Database Error: Unable to prepare query.");
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Rooms - AMV Hotel</title>
    <link rel="icon" type="image/png" href="../../IMG/5.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

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

        /* --- CSS STYLES --- */
        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
            color: #333;
        }

        /* --- UPDATED HEADER (Matched to Check Availability) --- */
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

        /* Desktop Nav */
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

        .btn-header-book {
            padding: 10px 30px;
            background-color: transparent;
            color: #b8860b;
            border: 1px solid #b8860b;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
        }

        .btn-header-book:hover {
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

        /* Mobile Menu Overlay */
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

        /* STEPPER */
        .booking-stepper {
            margin-top: 80px;
            background-color: #fff;
            padding: 20px 0;
            display: flex;
            justify-content: center;
            gap: 50px;
            border-bottom: 1px solid #eee;
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: #ccc;
            font-size: 0.85rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .step-item.active {
            color: #9e8236;
            font-weight: 700;
        }

        .step-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 2px solid #ccc;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
            font-size: 1.2rem;
        }

        .step-item.active .step-icon {
            border-color: #9e8236;
            background-color: #9e8236;
            color: #fff;
        }

        .step-item.completed .step-icon {
            border-color: #333;
            background-color: #333;
            color: #fff;
        }

        .step-item.completed {
            color: #333;
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

        /* ROOM CARD */
        .room-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            display: flex;
            align-items: stretch;
            margin-bottom: 30px;
            border: 2px solid transparent;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            position: relative;
            cursor: pointer;
        }

        .room-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
        }

        .room-card.selected-card {
            border-color: #9e8236;
            background-color: #fffdf5;
            transform: scale(1.01);
        }

        .room-image {
            width: 32%; /* Reduced width to balance with smaller height */
            object-fit: cover;
            min-height: 220px; /* Reduced from 320px */
            display: block;
            transition: transform 0.5s ease;
        }

        .room-card:hover .room-image {
            transform: scale(1.05);
        }

        .room-details {
            padding: 15px 25px; /* Reduced vertical padding */
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 6px; /* Tighter spacing */
        }

        .room-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin: 0;
            color: #222;
            letter-spacing: -0.5px;
        }

        .room-specs {
            display: flex;
            gap: 15px;
            font-size: 0.8rem;
            color: #777;
            padding-bottom: 5px;
        }

        .room-specs span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .room-specs i {
            color: #9e8236;
            font-size: 0.85rem;
        }

        .amenity-tag {
            font-size: 0.65rem;
            background: #f8f8f8;
            padding: 4px 10px;
            border-radius: 4px;
            margin-right: 4px;
            color: #666;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 600;
            border: 1px solid #eee;
        }

        .room-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end; /* Align to bottom */
            padding-top: 10px;
            margin-top: auto;
        }

        .price-container {
            display: flex;
            flex-direction: column;
            align-items: flex-start; /* Ensure left alignment */
        }

        .price-amount {
            font-size: 1.4rem;
            font-weight: 800;
            color: #9e8236;
            line-height: 1;
        }

        .price-label {
            font-size: 0.7rem;
            color: #999;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }

        .btn-select {
            padding: 10px 25px;
            border: 2px solid #9e8236;
            background: transparent;
            color: #9e8236;
            font-weight: 700;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.8rem;
        }

        .btn-select:hover {
            background: rgba(158, 130, 54, 0.05);
        }

        .btn-select.active {
            background: #9e8236;
            color: #fff;
            box-shadow: 0 4px 15px rgba(158, 130, 54, 0.3);
        }

        /* SIDEBAR */
        .sidebar {
            position: sticky;
            top: 170px;
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
            animation: slideInLeft 0.3s ease-out forwards;
        }

        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-10px); }
            to { opacity: 1; transform: translateX(0); }
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

        #total-price {
            font-size: 1.8rem;
            font-weight: 800;
            color: #9e8236;
            line-height: 1;
            transition: all 0.3s ease;
        }

        .btn-next {
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

        .btn-next:hover:not(:disabled) {
            background-color: #9e8236;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(158, 130, 54, 0.3);
        }

        .btn-next:disabled {
            background-color: #eee;
            color: #aaa;
            cursor: not-allowed;
        }

        @media (max-width: 900px) {
            .main-container {
                grid-template-columns: 1fr;
                padding: 0 10px;
                gap: 15px;
                margin-top: 20px;
            }

            .room-card {
                flex-direction: row; /* Keep horizontal on mobile for compactness */
                height: 140px; /* Fixed small height */
                min-height: 140px;
                margin-bottom: 15px;
                align-items: center;
            }

            .room-image {
                width: 35%;
                height: 100%;
                min-height: 100%;
            }

            .room-details {
                padding: 10px 15px;
                gap: 2px;
                overflow: hidden;
            }

            .room-title {
                font-size: 1rem;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .room-specs {
                gap: 8px;
                font-size: 0.7rem;
                margin-bottom: 2px;
            }

            .room-specs span i {
                font-size: 0.75rem;
            }

            /* Hide description on mobile to save space */
            .room-details p {
                display: none;
            }

            /* Hide amenity tags on mobile to save space */
            .room-details div:nth-of-type(2) {
                display: none;
            }

            .room-footer {
                padding-top: 5px;
                align-items: center;
            }

            .price-label {
                font-size: 0.55rem;
            }

            .price-amount {
                font-size: 1.1rem;
            }

            .btn-select {
                padding: 6px 12px;
                font-size: 0.7rem;
                border-radius: 4px;
            }

            /* 🔴 HIDE DESKTOP SUMMARY ON MOBILE */
            .sidebar {
                display: none !important;
            }
        }

        /* Extreme small screens (phones < 400px) */
        @media (max-width: 400px) {
            .room-card {
                height: 120px;
                min-height: 120px;
            }
            .room-details {
                padding: 8px 10px;
            }
            .room-title {
                font-size: 0.9rem;
            }
            .room-specs {
                font-size: 0.65rem;
            }
            .price-amount {
                font-size: 1rem;
            }
            .btn-select {
                padding: 5px 10px;
                font-size: 0.65rem;
            }
        }

        /* 🟢 MOBILE STICKY SUMMARY BAR (REDESIGNED) */
        .mobile-sticky-bar {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            width: 100%;
            background: #fff;
            padding: 10px 15px;
            padding-bottom: calc(10px + env(safe-area-inset-bottom)); /* Support for modern phone notches */
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
            body {
                padding-bottom: 90px !important; /* Space for the bar */
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

        .sticky-summary-details b {
            padding-bottom: 5px;
        }

        .sticky-price-group {
            margin-top: 4px;
            display: flex;
            flex-direction: column;
        }

        .sticky-bar-label {
            font-size: 0.55rem;
            color: #999;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        #sticky-total {
            font-size: 1.2rem;
            color: #9e8236;
            font-weight: 800;
            line-height: 1;
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

        /* --- STEPPER (Sticky + Frosted Glass) --- */
        .booking-stepper {
            /* Positioning */
            margin-top: 80px;
            /* 🟢 FIX 1: Keep padding consistent (15px) to prevent "jumping/stretching" */
            padding: 15px 0;
            border-bottom: 1px solid #eee;

            /* Sticky Logic */
            position: sticky;
            top: 70px;
            z-index: 900;

            /* Visuals */
            background-color: #fff;
            transition: all 0.3s ease;

            /* Layout */
            display: flex;
            justify-content: center;
            gap: 0 !important;
            width: 100%;

            /* Centering Logic (Keeps it 800px wide visually) */
            padding-left: max(20px, calc(50% - 400px));
            padding-right: max(20px, calc(50% - 400px));
            margin-left: auto;
            margin-right: auto;
        }

        /* 🟢 THE SCROLLED STATE (High Contrast) */
        .booking-stepper.scrolled {
            /* Frosted Glass Background */
            background-color: rgba(255, 255, 255, 0.6) !important;
            /* Increased opacity slightly for better contrast */
            backdrop-filter: blur(1px);
            /* Increased blur to hide scrolling text better */
            -webkit-backdrop-filter: blur(1px);
            /* Safari support */

            border-bottom-color: transparent !important;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1) !important;
        }

        /* 🟢 CONTRAST FIX 1: Darken Inactive Text & Add Halo */
        /* When scrolled, light gray #ccc is too hard to read. We make it dark gray. */
        .booking-stepper.scrolled .step-item {
            color: #555;
            /* Dark gray instead of light gray */
            text-shadow: 0 0 3px #fff;
            /* White halo to separate text from background noise */
            font-weight: 700;
            /* Make text bolder */
        }

        /* 🟢 CONTRAST FIX 2: Pop the Icons */
        /* Add a shadow to icons so they lift off the page */
        .booking-stepper.scrolled .step-icon {
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            border-color: #888;
            /* Darker border for inactive icons */
        }

        /* 🟢 CONTRAST FIX 3: Keep Active/Completed Items Distinct */
        /* Ensure the active steps stay Gold/Black and ignore the dark gray rule above */
        .booking-stepper.scrolled .step-item.active {
            color: #9e8236;
        }

        .booking-stepper.scrolled .step-item.completed {
            color: #000;
        }

        /* Ensure Active/Completed Icons keep their Gold style */
        .booking-stepper.scrolled .step-item.active .step-icon,
        .booking-stepper.scrolled .step-item.completed .step-icon {
            border-color: #9e8236;
            box-shadow: 0 2px 8px rgba(158, 130, 54, 0.4);
            /* Gold Glow */
        }

        /* When hovering over the sticky bar, bring it back to full visibility */
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
            /* 🟢 Smaller font size */
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 1;
        }

        /* Connector Line */
        .step-item:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 15px;
            /* 🟢 Adjusted for smaller icon (30px / 2) */
            left: 50%;
            width: 100%;
            height: 2px;
            /* Thinner line */
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
            /* 🟢 Smaller Circle (was 40px) */
            height: 30px;
            /* 🟢 Smaller Circle (was 40px) */
            border-radius: 50%;
            border: 2px solid #ccc;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
            /* Reduced gap between icon and text */
            font-size: 0.9rem;
            /* 🟢 Smaller Icon Font */
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
    </style>
</head>

<body>
    <?php include 'booking_loader.php'; ?>

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
            <!-- <a href="check_availability.php" class="btn-header-book">Book Now</a> -->
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
        <div class="step-item active">
            <div class="step-icon"><i class="fa-solid fa-bed"></i></div>Select Rooms
        </div>
        <div class="step-item">
            <div class="step-icon"><i class="fa-regular fa-id-card"></i></div>Guest Info
        </div>
        <div class="step-item">
            <div class="step-icon"><i class="fa-solid fa-check"></i></div>Confirmation
        </div>
    </div>

    <div class="main-container">
        <div class="room-list-container">
            <h2 style="margin-bottom: 20px;">Available Rooms</h2>

            <?php if (empty($available_rooms)): ?>
                <div style="background:#fff; padding:40px; text-align:center; border-radius:8px;">
                    <h3>No rooms available for these dates.</h3>
                    <p style="color:#666;">Please try selecting different check-in/out dates.</p>
                    <a href="check_availability.php"
                        style="display:inline-block; margin-top:15px; color:#9e8236; font-weight:700;">Change Dates</a>
                </div>
            <?php else: ?>
                <?php foreach ($available_rooms as $room): ?>
                    <div class="room-card" id="card-<?php echo $room['id']; ?>" 
                         onclick="toggleRoom(<?php echo $room['id']; ?>, '<?php echo addslashes($room['name']); ?>', <?php echo $room['price']; ?>)">

                        <?php
                        // FIXED: Use the correct Base Path for image loading
                        $basePath = '../../room_includes/uploads/images/';
                        $imagePath = !empty($room['final_image'])
                            ? $basePath . htmlspecialchars($room['final_image'])
                            : $placeholder;
                        ?>

                        <img src="<?php echo $imagePath; ?>" alt="<?php echo htmlspecialchars($room['name']); ?>"
                            class="room-image" onerror="this.src='<?php echo $placeholder; ?>'">

                        <div class="room-details">
                            <h3 class="room-title"><?php echo $room['name']; ?></h3>

                            <div class="room-specs">
                                <span><i class="fa-solid fa-user-group"></i> <?php echo $room['capacity']; ?> Guests</span>
                                <span><i class="fa-solid fa-ruler-combined"></i> <?php echo $room['size']; ?></span>
                                <span><i class="fa-solid fa-bed"></i> <?php echo $room['bed_type']; ?></span>
                            </div>

                            <p style="font-size:0.9rem; color:#555;"><?php echo $room['description']; ?></p>

                            <div style="margin-bottom:15px;">
                                <span class="amenity-tag"><i class="fa-solid fa-wifi"></i> Free WiFi</span>
                                <span class="amenity-tag"><i class="fa-solid fa-snowflake"></i> Air Conditioned</span>
                                <span class="amenity-tag"><?php echo $room['bed_type']; ?></span>
                            </div>

                            <div class="room-footer">
                                <div class="price-container">
                                    <span class="price-label">Per Night</span>
                                    <span class="price-amount">₱<?php echo number_format($room['price'], 2); ?></span>
                                </div>

                                <button class="btn-select" id="btn-<?php echo $room['id']; ?>"
                                    onclick="event.stopPropagation(); toggleRoom(<?php echo $room['id']; ?>, '<?php echo addslashes($room['name']); ?>', <?php echo $room['price']; ?>)">SELECT</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="sidebar">
            <div class="summary-box">
                <div class="summary-title">
                    <i class="fa-solid fa-receipt"></i> Booking Summary
                </div>
                <div class="summary-row"><span>Check-in</span> <b><?php echo $formatted_checkin; ?></b></div>
                <div class="summary-row"><span>Check-out</span> <b><?php echo $formatted_checkout; ?></b></div>
                <div class="summary-row"><span>Guests</span> <b><?php echo $adults; ?> Adult, <?php echo $children; ?>
                        Child</b></div>

                <div id="selected-rooms-list" style="margin:15px 0; border-top:1px dashed #ddd; padding-top:10px;">
                </div>

                <div class="total-row">
                    <span class="total-label">Total Amount</span>
                    <b id="total-price">₱0</b>
                </div>

                <form action="guest_info.php" method="POST" id="roomsForm">
                    <input type="hidden" name="checkin" value="<?php echo $checkin; ?>">
                    <input type="hidden" name="checkout" value="<?php echo $checkout; ?>">
                    <input type="hidden" name="adults" value="<?php echo $adults; ?>">
                    <input type="hidden" name="children" value="<?php echo $children; ?>">
                    <input type="hidden" name="total_price" id="input-total-price" value="0">
                    <input type="hidden" name="selected_rooms" id="input-selected-rooms" value="">
                    <button type="button" onclick="submitRooms()" id="btn-next-step" class="btn-next" disabled>
                        NEXT STEP <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div id="mobile-sticky-bar" class="mobile-sticky-bar">
        <div class="sticky-bar-info">
            <div class="sticky-summary-details" id="sticky-summary-details">
                <span>Stay Details</span>
                <b><?php echo $formatted_checkin; ?> - <?php echo $formatted_checkout; ?></b>
                <b style="color:#666;"><?php echo $adults; ?> Adult, <?php echo $children; ?> Child</b>
            </div>
            <div class="sticky-price-group">
                <span class="sticky-bar-label">Total Amount</span>
                <b id="sticky-total">₱0</b>
            </div>
        </div>
        <button type="button" onclick="submitRooms()" class="btn-sticky-next" id="sticky-next-btn" disabled>
            NEXT <i class="fa-solid fa-arrow-right"></i>
        </button>
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
        // --- CUSTOM ALERT LOGIC ---
        function showCustomAlert(title, message, iconClass = 'fa-exclamation-circle') {
            const modal = document.getElementById('jsAlertModal');
            const titleEl = document.getElementById('jsAlertTitle');
            const msgEl = document.getElementById('jsAlertMsg');
            const iconEl = document.getElementById('jsAlertIcon');

            if (modal && titleEl && msgEl) {
                titleEl.innerText = title;
                msgEl.innerText = message;
                if (iconEl) {
                    iconEl.className = `fas ${iconClass} custom-modal-icon`;
                }
                modal.classList.add('active');
            }
        }

        function closeJsAlert() {
            const modal = document.getElementById('jsAlertModal');
            if (modal) modal.classList.remove('active');
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

        const nights = <?php echo $nights; ?>;
        let selectedRooms = [];

        function toggleRoom(id, name, price) {
            const index = selectedRooms.findIndex(room => room.id === id);
            const btn = document.getElementById(`btn-${id}`);
            const card = document.getElementById(`card-${id}`);

            if (index === -1) {
                selectedRooms.push({ id, name, price });
                btn.classList.add('active');
                card.classList.add('selected-card');
                btn.innerText = 'REMOVE';
            } else {
                selectedRooms.splice(index, 1);
                btn.classList.remove('active');
                card.classList.remove('selected-card');
                btn.innerText = 'SELECT';
            }
            updateSidebar();
        }

        function updateSidebar() {
            const listEl = document.getElementById('selected-rooms-list');
            const totalEl = document.getElementById('total-price');
            const nextBtn = document.getElementById('btn-next-step');
            
            // Sticky Bar Elements
            const stickyTotalEl = document.getElementById('sticky-total');
            const stickySummaryEl = document.getElementById('sticky-summary-details');
            const stickyNextBtn = document.getElementById('sticky-next-btn');

            listEl.innerHTML = '';
            let total = 0;

            selectedRooms.forEach(room => {
                const roomTotal = room.price * nights;
                total += roomTotal;
                
                // Show breakdown if more than 1 night
                const breakdownText = nights > 1 ? `<span style="font-size:0.7rem; color:#888; font-weight:400; display:block;">₱${room.price.toLocaleString()} x ${nights} Nights</span>` : '';
                
                listEl.innerHTML += `
                    <div class="selected-room-item" style="flex-direction:column; align-items:flex-start; gap:2px;">
                        <div style="display:flex; justify-content:space-between; width:100%;">
                            <span>${room.name}</span>
                            <b>₱${roomTotal.toLocaleString()}</b>
                        </div>
                        ${breakdownText}
                    </div>`;
            });

            if (selectedRooms.length === 0) {
                listEl.innerHTML = '<p style="color:#999; font-size:0.8rem; text-align:center;">No rooms selected</p>';
                if(stickyNextBtn) stickyNextBtn.disabled = true;
            } else {
                if(stickyNextBtn) stickyNextBtn.disabled = false;
            }

            const formattedTotal = '₱' + total.toLocaleString();

            // Update Main Sidebar Total (Desktop)
            if(totalEl) {
                totalEl.style.transform = "scale(1.1)";
                totalEl.innerText = formattedTotal;
                setTimeout(() => { totalEl.style.transform = "scale(1)"; }, 200);
            }
            
            // Update Sticky Bar Total (Mobile)
            if (stickyTotalEl) {
                stickyTotalEl.innerText = formattedTotal;
            }

            document.getElementById('input-total-price').value = total;
            document.getElementById('input-selected-rooms').value = JSON.stringify(selectedRooms);

            if(nextBtn) nextBtn.disabled = selectedRooms.length === 0;
        }

        function submitRooms() {
            // 🟢 1. Trigger the Booking Loader
            const bookingLoader = document.getElementById("booking-processing");
            bookingLoader.classList.add('active');

            // 🟢 2. Update text to make sense for this step
            // We change "Checking Availability" to something relevant
            const title = bookingLoader.querySelector('h2');
            const desc = bookingLoader.querySelector('p');

            if (title) title.innerText = "Saving Selection";
            if (desc) desc.innerText = "Proceeding to Guest Details...";

            // 🟢 3. Wait 2 seconds, THEN submit the form
            setTimeout(() => {
                document.getElementById('roomsForm').submit();
            }, 2000);
        }

        // 🟢 ROBUST STICKY STEPPER SCRIPT
        document.addEventListener("DOMContentLoaded", function () {
            // Check for Room Taken error
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('error') === 'room_taken') {
                showCustomAlert("Room Unavailable", "Sorry, one of your selected rooms was just booked by another guest. Please choose a different room.");
            }

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
</body>

</html>
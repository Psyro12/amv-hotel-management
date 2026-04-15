<?php
// 1. Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https://ui-avatars.com;");

// 2. Secure Session Settings
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

// 3. Disable Error Reporting to Screen
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once '../../room_includes/includes/ImageManager.php';
require 'db_connect.php';

$imageManager = new ImageManager();

// Base64 Placeholder
$placeholder = "data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3QgZmlsbD0iI2RkZCIgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJhcmlhbCIgZm9udC1zaXplPSIxMiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzU1NSI+Tm8gSW1hZ2U8L3RleHQ+PC9zdmc+";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMV Hotel</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../STYLE/home_page.css">
    <link rel="stylesheet" href="../STYLE/utilities.css">
    <style>
        /* --- GLOBAL RESET --- */
        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f9f9f9;
            overflow-x: hidden;
        }

        html,
        body {
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

        /* Scrolled State (White Background) */
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
            color: #b8860b;
        }

        /* Logo Colors on Scroll */
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

        /* --- MOBILE MENU (BURGER) --- */
        .burger-menu {
            color: #fff !important;
            font-size: 24px;
            cursor: pointer;
            display: none;
            /* Hidden on Desktop by default */
            z-index: 1100;

            /* 🟢 FIX 1: Cleaned up Filter (removed duplicate) */
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.9));

            /* 🟢 FIX 2: Force Hardware Acceleration to prevent "invisible" glitch */
            transform: translateZ(0);
            will-change: transform, color;
            transition: color 0.3s ease;
        }

        /* Force dark color when scrolled */
        header.scrolled .burger-menu {
            color: #333 !important;
            filter: none;
            /* Remove shadow on white background */
        }

        /* Mobile Nav Drawer */
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
                display: none !important;
            }

            .burger-menu {
                /* 🟢 FIX 3: Explicitly force display block */
                display: block !important;
                opacity: 1 !important;
                visibility: visible !important;

                /* 🟢 CRITICAL FIX: Forces the phone GPU to render the icon immediately */
                transform: translateZ(0);
                -webkit-transform: translateZ(0);
            }

            header {
                padding: 15px 20px;
                /* Darker gradient on mobile header ensures visibility at top */
                background: linear-gradient(to bottom, rgba(0, 0, 0, 0.8) 0%, transparent 100%);
            }

            header.scrolled {
                background: #fff;
            }
        }

        /* --- HERO SECTION --- */
        .hero-section {
            height: 100vh;
            width: 100%;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            text-align: left;
            color: #fff;
            overflow: hidden;
            background-color: #000;
            /* Fallback */
        }

        /* Background Slider */
        .hero-bg-slider {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
        }

        .hero-bg-slide {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center;
            opacity: 0;
            z-index: 0;
            transform: scale(1);
            transition: opacity 3s ease-in-out, transform 3s ease-in-out;
        }

        .hero-bg-slide.active {
            opacity: 1;
            z-index: 1;
            transform: scale(1.1);
            transition: opacity 1.5s ease-in-out, transform 6s linear;
        }

        .hero-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 2;
        }

        /* Hero Content */
        .hero-content {
            position: relative;
            z-index: 3;
            padding-left: 10%;
            max-width: 900px;
            width: 100%;
        }

        .hero-logo {
            height: 120px;
            width: auto;
            margin-bottom: 20px;
            display: block;
            margin-left: 0;
            margin-right: auto;
            opacity: 0;
            animation: fadeUp 1s ease-out forwards 0.2s;
        }

        .hero-title {
            font-size: 3rem;
            font-weight: 700;
            line-height: 1.1;
            margin-bottom: 10px;
            text-transform: uppercase;
            opacity: 0;
            animation: fadeUp 1s ease-out forwards 0.4s;
        }

        .hero-subtitle {
            font-size: 1.2rem;
            font-weight: 400;
            margin-bottom: 30px;
            letter-spacing: 1px;
            opacity: 0;
            animation: fadeUp 1s ease-out forwards 0.6s;
        }

        /* Hero Book Button (Hidden on Desktop, Visible on Mobile) */
        .btn-book-hero {
            display: none;
            /* Hide by default */
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
            animation: fadeUp 1s ease-out forwards 0.8s;
        }

        .btn-book-hero:hover {
            background-color: #fff;
            color: #b8860b;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(40px);
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
            }

            .hero-logo {
                margin-left: auto;
                margin-right: auto;
                height: 80px;
            }

            .hero-title {
                font-size: 2.5rem;
            }

            .btn-book-hero {
                display: inline-block;
                margin-top: 20px;
            }

            /* Show on Mobile */
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
            align-items: stretch;
        }

        .feature-row:nth-child(even) {
            flex-direction: row-reverse;
        }

        .feature-half-img {
            flex: 0 0 50%;
            width: 50%;
            position: relative;
            overflow: hidden;
            min-height: 500px;
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
            flex: 0 0 50%;
            width: 50%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px 80px;
            background-color: #fff;
            z-index: 2;
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
                flex-direction: column !important;
            }

            .feature-half-img {
                flex: 0 0 100%;
                width: 100%;
                height: 350px;
                min-height: auto;
            }

            .feature-half-text {
                flex: 0 0 100%;
                width: 100%;
                padding: 40px 30px;
                text-align: center;
                align-items: center;
            }

            .feature-half-img img {
                height: 100%;
                top: 0;
                position: absolute;
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

        /* --- 🟢 FIXED: REMOVED CORRUPTED SYNTAX ERROR HERE --- */

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

        /* Amenities */
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
            will-change: transform;
        }

        .news-card {
            flex: 0 0 calc((90vw - 17px - 60px) / 3);
            width: calc((90vw - 17px - 60px) / 3);
            background: #fff;
            border-radius: 8px;
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

        .feedback-card {
            background: #fff;
            padding: 40px;
            border-radius: 4px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.03);
            text-align: left;
            border-top: 3px solid #b8860b;
            position: relative;
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
        }

        /* Feedback Slider */
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

        @media (max-width: 768px) {
            .feedback-card {
                flex: 0 0 85vw;
                width: 85vw;
            }
        }

        /* Scroll Reveals */
        .reveal {
            opacity: 0;
            transform: translateY(40px);
            transition: all .7s ease-out;
        }

        .reveal.active {
            opacity: 1;
            transform: translateY(0);
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

        .reveal-from-left {
            opacity: 0;
            transform: translateX(-50px);
            transition: all 1s ease-out;
        }

        .reveal-from-left.active {
            opacity: 1;
            transform: translateX(0);
        }

        footer {
            background: #333;
            color: #fff;
            padding: 40px;
            text-align: center;
            font-size: 0.9rem;
        }
    </style>
</head>

<body>

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
            <a href="check_availability.php" class="btn-header-book">Book Now</a>
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
        <a href="check_availability.php">Reservations</a>
        <a href="about_us.php">About Us</a>
        <a href="check_availability.php"
            style="color: #b8860b; border: 2px solid #b8860b; text-align: center; padding: 10px; margin-top: 20px; border-radius: 4px;">Book
            Your Stay</a>
    </div>

    <div class="hero-section" id="home">

        <div class="hero-bg-slider">
            <div class="hero-bg-slide active" style="background-image: url('../../IMG/hotel_background.png');"></div>
            <div class="hero-bg-slide" style="background-image: url('../../IMG/room_1.jpg');"></div>
            <div class="hero-bg-slide" style="background-image: url('../../IMG/food_1.jpg');"></div>
            <div class="hero-bg-slide" style="background-image: url('../../IMG/intro_night.png');"></div>
        </div>

        <div class="hero-overlay"></div>

        <div class="hero-content">
            <img src="../../IMG/5.png" alt="AMV Hotel Logo" class="hero-logo">
            <h1 class="hero-title">Experience Luxury & Comfort</h1>
            <p class="hero-subtitle">Your perfect getaway awaits at AMV Hotel</p>
            <a href="check_availability.php" class="btn-book-hero">Book Your Stay</a>
        </div>
    </div>

    <section class="intro-elegant" id="about">
        <div class="intro-elegant-container reveal">
            <h2 class="intro-elegant-title" style="margin-bottom: 20px;">Welcome to AMV Hotel</h2>
            <p class="intro-elegant-text">
                AMV Hotel is a property of many firsts — The "True Heart of Mamburao" is delighted to welcome you.
                Experience luxury and comfort combined with our world-class Filipino hospitality.
            </p>
            <a href="about_us.php" class="btn-outline-gold">LEARN MORE</a>
        </div>
    </section>

    <section class="features-container">
        <div class="feature-row">
            <div class="feature-half-img">
                <img src="../../IMG/room_7.jpg" alt="Luxury Hotel Room">
            </div>
            <div class="feature-half-text reveal-from-right">
                <span class="feature-sub">Rooms</span>
                <h3 class="feature-title">Experience the Comfort</h3>
                <p class="feature-desc">
                    Safety and comfort are key factors in leisure stays these days. We assure you of medical-grade
                    stringent sanitation procedures in preparing our rooms for guests so you can stay with us with peace
                    of mind.
                </p>
                <a href="check_availability.php" class="feature-btn">Reservations</a>
            </div>
        </div>

        <div class="feature-row">
            <div class="feature-half-img">
                <img src="../../IMG/food_7.jpg" alt="Fine Dining">
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
            <div class="feature-half-img">
                <img src="../../IMG/room_1.jpg" alt="Grand Events">
            </div>
            <div class="feature-half-text reveal-from-right">
                <span class="feature-sub">Events</span>
                <h3 class="feature-title">Celebrate Grandeur</h3>
                <p class="feature-desc">
                    Host your grandest occasions in our elegant ballrooms and function rooms.
                </p>
                <a href=".event-place" class="feature-btn">Inquire Now</a>
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
                            $amenityMap[$row['id']] = $row; // Key is the ID, Value is the row data
                        }
                    }

                    // 2. Fetch Rooms
                    $query = "SELECT name AS image_name, description, image_path AS file_path, amenities, bed_type FROM rooms WHERE is_active = 1";
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
                            if (strpos($rawPath, ',') !== false) {
                                $pathParts = explode(',', $rawPath);
                                $rawPath = trim($pathParts[0]);
                            }
                            $baseWebPath = '../../room_includes/uploads/images/';
                            $imageUrl = !empty($rawPath)
                                ? $baseWebPath . htmlspecialchars($rawPath)
                                : $placeholder;
                            ?>

                            <div class="room-card-premium"
                                data-room-name="<?php echo htmlspecialchars($room['image_name']); ?>">

                                <div class="premium-img-wrapper">
                                    <img src="<?php echo $imageUrl; ?>"
                                        alt="<?php echo htmlspecialchars($room['image_name']); ?>" class="room-image">
                                    <div class="featured-badge"><?php echo htmlspecialchars($room['bed_type']); ?></div>
                                </div>

                                <div class="premium-details">
                                    <div style="flex:1; padding-right:10px;">
                                        <div class="premium-title"><?php echo htmlspecialchars($room['image_name']); ?></div>
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
                // 1. Fetch ALL News
                $newsQuery = "SELECT * FROM hotel_news ORDER BY news_date DESC";
                $newsResult = mysqli_query($conn, $newsQuery);

                // Define path
                $newsImgBase = '../../room_includes/uploads/news/';
                $newsPlaceholder = "data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3QgZmlsbD0iI2RkZCIgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJhcmlhbCIgZm9udC1zaXplPSIxMiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzU1NSI+Tm8gSW1hZ2U8L3RleHQ+PC9zdmc+";

                if ($newsResult && mysqli_num_rows($newsResult) > 0) {
                    while ($newsItem = mysqli_fetch_assoc($newsResult)) {
                        // Image Handling
                        $rawNewsPath = $newsItem['image_path'];
                        if (strpos($rawNewsPath, ',') !== false) {
                            $parts = explode(',', $rawNewsPath);
                            $rawNewsPath = trim($parts[0]);
                        }
                        $newsImgUrl = !empty($rawNewsPath) ? $newsImgBase . htmlspecialchars($rawNewsPath) : $newsPlaceholder;

                        // Date Formatting
                        $dateObj = new DateTime($newsItem['news_date']);
                        $formattedDate = $dateObj->format('F d, Y');

                        // Desc Cleanup
                        $cleanDesc = strip_tags($newsItem['description']);
                        if (strlen($cleanDesc) > 100) {
                            $cleanDesc = substr($cleanDesc, 0, 100) . '...';
                        }
                        ?>
                        <article class="news-card">
                            <div class="news-img-container">
                                <img src="<?php echo $newsImgUrl; ?>" alt="<?php echo htmlspecialchars($newsItem['title']); ?>"
                                    onerror="this.src='<?php echo $newsPlaceholder; ?>'">
                            </div>
                            <div class="news-content">
                                <span class="news-date"><?php echo $formattedDate; ?></span>
                                <h3 class="news-title"><?php echo htmlspecialchars($newsItem['title']); ?></h3>
                                <p class="news-excerpt"><?php echo htmlspecialchars($cleanDesc); ?></p>
                                <a href="#" class="read-more-link">Read Full Story</a>
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

    <section class="feedback-section" id="feedback">
        <div style="text-align: center; max-width: 800px; margin: 0 auto;">
            <h2 class="section-title">Guest Stories</h2>
            <p class="section-subtitle">What our valued guests say about their stay.</p>
        </div>

        <div class="feedback-viewport" id="feedbackViewport">
            <div class="feedback-track" id="feedbackTrack">
                <?php
                // 1. Fetch 6 Reviews using "Smart Mix" 
                // (Prioritizes 4+ stars, then sorts by newest date)
                $feedQuery = "SELECT gf.*, bg.first_name, bg.last_name 
                              FROM guest_feedback gf
                              JOIN bookings b ON gf.booking_reference = b.booking_reference
                              JOIN booking_guests bg ON b.id = bg.booking_id
                              ORDER BY (gf.rating_overall >= 4) DESC, gf.created_at DESC 
                              LIMIT 6";

                $feedResult = mysqli_query($conn, $feedQuery);

                if ($feedResult && mysqli_num_rows($feedResult) > 0) {
                    while ($row = mysqli_fetch_assoc($feedResult)) {

                        // -- A. Prepare Data --
                        $fullName = $row['first_name'] . ' ' . $row['last_name'];
                        $rating = $row['rating_overall'];

                        // Fallback comment if the user left it blank
                        $comment = !empty($row['comments']) ? $row['comments'] : "Rated " . $rating . " stars.";

                        // Format Date (e.g., "Sep 2025")
                        $dateObj = new DateTime($row['created_at']);
                        $stayDate = $dateObj->format('M Y');

                        // Generate Avatar based on name
                        $avatarUrl = "https://ui-avatars.com/api/?name=" . urlencode($fullName) . "&background=b8860b&color=fff";
                        ?>
                        <div class="feedback-card">
                            <i class="fa-solid fa-quote-right quote-icon"></i>

                            <div class="stars">
                                <?php
                                // Render Stars Logic
                                for ($i = 1; $i <= 5; $i++) {
                                    if ($i <= $rating) {
                                        echo '<i class="fa-solid fa-star"></i>'; // Filled Gold
                                    } else {
                                        echo '<i class="fa-regular fa-star" style="color:#ccc;"></i>'; // Empty Grey
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
                    // -- B. Fallback if no reviews exist in DB --
                    echo '<p style="text-align:center; padding: 20px; width:100%;">No guest stories available yet.</p>';
                }
                ?>
            </div>
        </div>
    </section>

    <footer>
        <p>&copy; 2025 AMV Hotel. All rights reserved.</p>
    </footer>

    <script>
        // 1. Header & Mobile Menu (Fixed for reload/refresh)
        const header = document.getElementById('mainHeader');

        // Define the check function separately so we can use it multiple times
        function checkScroll() {
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        }

        // Listen for scroll events (Standard behavior)
        window.addEventListener('scroll', checkScroll);

        // 🟢 FIX: Run the check immediately when page loads
        // This ensures if you reload halfway down the page, the header is ALREADY white/sticky
        document.addEventListener('DOMContentLoaded', checkScroll);
        checkScroll(); // Run once more for safety

        function toggleMobileMenu() {
            document.getElementById('mobileMenu').classList.toggle('active');
            document.getElementById('mobileBackdrop').classList.toggle('active');
        }
        function toggleMobileAmenities(element) {
            document.querySelectorAll('.amenity-more.active').forEach(el => {
                if (el !== element) el.classList.remove('active');
            });
            element.classList.toggle('active');
        }

        // 3. Desktop Infinite Carousel (Rooms - Fixed Drag & Loop)
        const track = document.getElementById('track');
        const nextBtn = document.getElementById('nextBtn');
        const prevBtn = document.getElementById('prevBtn');
        const galleryViewport = document.querySelector('.gallery-viewport');

        const CARD_WIDTH = 600;
        const GAP = 30;
        const TOTAL_MOVE = CARD_WIDTH + GAP; // 630px
        const TRANSITION_SPEED = 500;

        let isAnimating = false;
        let isDragging = false;
        let startPos = 0;
        let currentTranslate = -TOTAL_MOVE;
        let prevTranslate = -TOTAL_MOVE;
        let animationID;

        function isDesktop() {
            return window.innerWidth > 768;
        }

        // --- 1. INITIAL SETUP ---
        if (isDesktop() && track && track.children.length > 1) {
            track.prepend(track.lastElementChild);
            track.style.transition = 'none';
            track.style.transform = `translateX(-${TOTAL_MOVE}px)`;

            // Force reflow
            void track.offsetWidth;
            track.style.transition = `transform ${TRANSITION_SPEED}ms ease-in-out`;
            track.children[1].classList.add('active');
        }

        // --- 2. MOVEMENT FUNCTIONS (Updated with Offset) ---

        // Move Next (Drag Left -> Content moves Left)
        const moveNext = (dragOffset = 0) => {
            if (!isDesktop() || isAnimating || !track.firstElementChild) return;
            isAnimating = true;

            track.children[1].classList.remove('active');
            track.children[2].classList.add('active');

            const firstCard = track.firstElementChild;
            const clone = firstCard.cloneNode(true);
            track.appendChild(clone);

            // 1. Instant: Set position to where you dragged it (Seamless)
            track.style.transition = 'none';
            // We are visually at -630 + dragOffset. 
            // Appending a card at the end DOES NOT change visual position of current items.
            track.style.transform = `translateX(${-TOTAL_MOVE + dragOffset}px)`;

            // 2. Force Reflow
            void track.offsetWidth;

            // 3. Animate: Slide to the next card (-1260px)
            track.style.transition = `transform ${TRANSITION_SPEED}ms ease-in-out`;
            track.style.transform = `translateX(-${TOTAL_MOVE * 2}px)`;

            // 4. Cleanup
            setTimeout(() => {
                track.style.transition = 'none';
                track.removeChild(clone);
                track.appendChild(firstCard);
                track.style.transform = `translateX(-${TOTAL_MOVE}px)`;

                // Reset State
                currentTranslate = -TOTAL_MOVE;
                prevTranslate = -TOTAL_MOVE;

                setTimeout(() => { isAnimating = false; }, 50);
            }, TRANSITION_SPEED);
        };

        // Move Prev (Drag Right -> Content moves Right)
        const movePrev = (dragOffset = 0) => {
            if (!isDesktop() || isAnimating || !track.lastElementChild) return;
            isAnimating = true;

            track.children[1].classList.remove('active');
            track.children[0].classList.add('active');

            const lastCard = track.lastElementChild;
            const clone = lastCard.cloneNode(true);
            track.prepend(clone);

            // 1. Instant: Set position to where you dragged it (Seamless)
            track.style.transition = 'none';

            // PREPEND shifts everything right by TOTAL_MOVE (630px).
            // To stay visually in the same place (where you dragged), we must shift LEFT by 630px.
            // Target Visual = (-TOTAL_MOVE) + dragOffset
            // Shifted Reality = (-TOTAL_MOVE * 2) + dragOffset
            track.style.transform = `translateX(${(-TOTAL_MOVE * 2) + dragOffset}px)`;

            // 2. Force Reflow
            void track.offsetWidth;

            // 3. Animate: Slide to the "new" center (-630px)
            track.style.transition = `transform ${TRANSITION_SPEED}ms ease-in-out`;
            track.style.transform = `translateX(-${TOTAL_MOVE}px)`;

            // 4. Cleanup
            setTimeout(() => {
                track.style.transition = 'none';
                track.removeChild(clone);
                track.prepend(lastCard);

                // Reset State
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

        // --- 3. LISTENERS ---
        if (nextBtn) nextBtn.addEventListener('click', () => moveNext(0));
        if (prevBtn) prevBtn.addEventListener('click', () => movePrev(0));


        // --- 4. DRAG LOGIC (Fixed for Jumps) ---

        function getPositionX(event) {
            return event.type.includes('mouse') ? event.pageX : event.touches[0].clientX;
        }

        function touchStart(event) {
            if (isAnimating) return;
            isDragging = true;
            startPos = getPositionX(event);

            // Stop transition so it follows mouse instantly
            track.style.transition = 'none';
            galleryViewport.style.cursor = 'grabbing';

            animationID = requestAnimationFrame(animation);
        }

        function touchMove(event) {
            if (isDragging) {
                // Prevent scrolling page while dragging slider
                if (event.type.includes('touch')) {
                    // event.preventDefault(); // Optional: Uncomment if page scrolls too much
                }

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

            // Threshold: 100px drag triggers the slide
            if (movedBy < -100) {
                moveNext(movedBy); // Pass the drag distance!
            } else if (movedBy > 100) {
                movePrev(movedBy); // Pass the drag distance!
            } else {
                snapBack();
            }
        }

        function animation() {
            if (isDragging) {
                track.style.transform = `translateX(${currentTranslate}px)`;
                requestAnimationFrame(animation);
            }
        }

        // Attach Listeners
        if (galleryViewport) {
            galleryViewport.addEventListener('touchstart', touchStart);
            galleryViewport.addEventListener('touchmove', touchMove);
            galleryViewport.addEventListener('touchend', touchEnd);

            galleryViewport.addEventListener('mousedown', touchStart);
            galleryViewport.addEventListener('mousemove', touchMove);
            galleryViewport.addEventListener('mouseup', touchEnd);
            galleryViewport.addEventListener('mouseleave', () => {
                if (isDragging) touchEnd();
            });

            // Prevent context menu
            galleryViewport.oncontextmenu = function (event) {
                event.preventDefault();
                return false;
            }
        }

        // 3. Realtime Updates
        function fetchRoomUpdates() {
            fetch('fetch_room_updates.php?ts=' + Date.now(), { cache: 'no-store' })
                .then(response => response.ok ? response.json() : null)
                .catch(e => console.error(e));
        }
        setInterval(fetchRoomUpdates, 5000);

        // 4. Mobile Rooms Scroll
        function initInfiniteScroll(containerSelector) {
            if (window.innerWidth > 768) return;
            const container = document.querySelector(containerSelector);
            if (!container) return;
            const cards = Array.from(container.children);
            if (cards.length === 0) return;

            cards.forEach(card => container.appendChild(card.cloneNode(true)));
            cards.slice().reverse().forEach(card => container.prepend(card.cloneNode(true)));

            setTimeout(() => {
                const cardWidth = cards[0].offsetWidth;
                const gap = 20;
                const singleSetWidth = (cardWidth + gap) * cards.length;
                container.scrollLeft = singleSetWidth;
                container.addEventListener('scroll', () => {
                    if (container.scrollLeft <= 50) container.scrollLeft += singleSetWidth;
                    else if (container.scrollLeft >= (singleSetWidth * 2) - 50) container.scrollLeft -= singleSetWidth;
                });
            }, 100);
        }

        // --- 5. DRAGGABLE NEWS SLIDER (Infinite + Centered + AutoScroll) ---
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

            // Auto-Scroll Variables
            let autoScrollTimer;
            const AUTO_SCROLL_DELAY = 3000; // 5 Seconds

            const cloneCount = 5;

            // --- INIT ---
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

                // Start at the first real item
                currentTranslate = -(cloneCount * slideWidth) + centerOffset;
                prevTranslate = currentTranslate;
                setSliderPosition();

                // Start the Timer
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
                    if (card) {
                        centerOffset = (newsViewport.offsetWidth - card.offsetWidth) / 2;
                    }
                } else {
                    centerOffset = 0;
                }
            }

            function setSliderPosition() {
                newsTrackContainer.style.transform = `translateX(${currentTranslate}px)`;
            }

            // --- AUTO SCROLL LOGIC ---
            function startAutoScroll() {
                stopAutoScroll(); // Clear any existing timer first
                autoScrollTimer = setInterval(() => {
                    // Calculate the "Next" index based on current position
                    // We divide the current position by slide width to find roughly where we are
                    const currentAbs = Math.abs(currentTranslate - centerOffset);
                    const currentIndex = Math.round(currentAbs / slideWidth);

                    // Move to the next slide (Right to Left direction)
                    snapToSlide(currentIndex + 1);
                }, AUTO_SCROLL_DELAY);
            }

            function stopAutoScroll() {
                clearInterval(autoScrollTimer);
            }

            // --- DRAG EVENTS ---
            const startDrag = (pageX) => {
                stopAutoScroll(); // Pause when user touches
                isDown = true;
                newsViewport.classList.add('active');
                startX = pageX;
                cancelAnimationFrame(animationID);

                const matrix = new WebKitCSSMatrix(window.getComputedStyle(newsTrackContainer).transform);
                currentTranslate = matrix.m41;
                prevTranslate = currentTranslate;
            };

            const moveDrag = (pageX) => {
                if (!isDown) return;
                const diff = pageX - startX;
                let newTranslate = prevTranslate + diff;
                let checkPos = newTranslate - centerOffset;

                // Infinite Wrap Checks (Immediate)
                if (checkPos > -slideWidth) {
                    newTranslate -= totalRealWidth;
                    prevTranslate -= totalRealWidth;
                }
                if (checkPos < -((cloneCount * slideWidth) + totalRealWidth)) {
                    newTranslate += totalRealWidth;
                    prevTranslate += totalRealWidth;
                }

                currentTranslate = newTranslate;
                setSliderPosition();
            };

            const endDrag = () => {
                isDown = false;
                newsViewport.classList.remove('active');

                const relativePos = Math.abs(currentTranslate - centerOffset);
                const exactIndex = relativePos / slideWidth;
                let targetIndex = Math.round(exactIndex);

                snapToSlide(targetIndex);
                startAutoScroll(); // Resume timer after drag
            };

            // --- ANIMATION ---
            function snapToSlide(targetIndex) {
                const targetTranslate = -(targetIndex * slideWidth) + centerOffset;

                function animate() {
                    // Smooth easing (0.1 = speed factor)
                    currentTranslate += (targetTranslate - currentTranslate) * 0.1;
                    setSliderPosition();

                    if (Math.abs(targetTranslate - currentTranslate) > 0.5) {
                        animationID = requestAnimationFrame(animate);
                    } else {
                        currentTranslate = targetTranslate;

                        // Wrap Logic on Snap End (Seamless Loop)
                        const purePos = currentTranslate - centerOffset;
                        const totalClonesWidth = cloneCount * slideWidth;

                        if (Math.abs(purePos) < totalClonesWidth - 5) {
                            currentTranslate -= totalRealWidth;
                        }
                        else if (Math.abs(purePos) >= totalClonesWidth + totalRealWidth) {
                            currentTranslate += totalRealWidth;
                        }

                        setSliderPosition();
                    }
                }
                cancelAnimationFrame(animationID); // Stop any previous animation
                animate();
            }

            // Listeners
            newsViewport.addEventListener('mousedown', e => startDrag(e.pageX));
            newsViewport.addEventListener('touchstart', e => startDrag(e.touches[0].pageX));

            newsViewport.addEventListener('mousemove', e => { e.preventDefault(); moveDrag(e.pageX); });
            newsViewport.addEventListener('touchmove', e => moveDrag(e.touches[0].pageX));

            // Pause on Hover / Resume on Leave
            newsViewport.addEventListener('mouseenter', stopAutoScroll);
            newsViewport.addEventListener('mouseleave', () => {
                if (!isDown) startAutoScroll();
                if (isDown) endDrag();
            });

            newsViewport.addEventListener('mouseup', endDrag);
            newsViewport.addEventListener('touchend', endDrag);

            window.addEventListener('load', () => {
                // Wait slightly for layout to settle
                setTimeout(setupInfiniteLoop, 200);
            });

            window.addEventListener('resize', () => {
                updateDimensions();
                // Recenter
                const relativePos = Math.abs(currentTranslate - centerOffset);
                const index = Math.round(relativePos / slideWidth);
                currentTranslate = -(index * slideWidth) + centerOffset;
                prevTranslate = currentTranslate;
                setSliderPosition();
            });
        }

        // --- 6. FEEDBACK SLIDER (Infinite + AutoScroll) ---
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

            // Auto-Scroll Variables
            let autoScrollTimer;
            const AUTO_SCROLL_DELAY = 3000; // 5 Seconds

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

                // Start Auto Scroll
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
                    if (card) {
                        centerOffset = (feedbackViewport.offsetWidth - card.offsetWidth) / 2;
                    }
                } else {
                    centerOffset = 0;
                }
            }

            function setSliderPosition() {
                feedbackTrackContainer.style.transform = `translateX(${currentTranslate}px)`;
            }

            // --- AUTO SCROLL LOGIC ---
            function startAutoScroll() {
                stopAutoScroll();
                autoScrollTimer = setInterval(() => {
                    const currentAbs = Math.abs(currentTranslate - centerOffset);
                    const currentIndex = Math.round(currentAbs / slideWidth);
                    snapToSlide(currentIndex + 1); // Move Right-to-Left
                }, AUTO_SCROLL_DELAY);
            }

            function stopAutoScroll() {
                clearInterval(autoScrollTimer);
            }

            // --- DRAG EVENTS ---
            const startDrag = (pageX) => {
                stopAutoScroll(); // Pause on interaction
                isDown = true;
                feedbackViewport.classList.add('active');
                startX = pageX;
                cancelAnimationFrame(animationID);
                const matrix = new WebKitCSSMatrix(window.getComputedStyle(feedbackTrackContainer).transform);
                currentTranslate = matrix.m41;
                prevTranslate = currentTranslate;
            };

            const moveDrag = (pageX) => {
                if (!isDown) return;
                const diff = pageX - startX;
                let newTranslate = prevTranslate + diff;
                let checkPos = newTranslate - centerOffset;

                if (checkPos > -slideWidth) {
                    newTranslate -= totalRealWidth;
                    prevTranslate -= totalRealWidth;
                }
                if (checkPos < -((cloneCount * slideWidth) + totalRealWidth)) {
                    newTranslate += totalRealWidth;
                    prevTranslate += totalRealWidth;
                }

                currentTranslate = newTranslate;
                setSliderPosition();
            };

            const endDrag = () => {
                isDown = false;
                feedbackViewport.classList.remove('active');
                const relativePos = Math.abs(currentTranslate - centerOffset);
                const exactIndex = relativePos / slideWidth;
                let targetIndex = Math.round(exactIndex);
                snapToSlide(targetIndex);
                startAutoScroll(); // Resume after drag
            };

            function snapToSlide(targetIndex) {
                const targetTranslate = -(targetIndex * slideWidth) + centerOffset;
                function animate() {
                    currentTranslate += (targetTranslate - currentTranslate) * 0.1;
                    setSliderPosition();
                    if (Math.abs(targetTranslate - currentTranslate) > 0.5) {
                        animationID = requestAnimationFrame(animate);
                    } else {
                        currentTranslate = targetTranslate;
                        const purePos = currentTranslate - centerOffset;
                        const totalClonesWidth = cloneCount * slideWidth;

                        if (Math.abs(purePos) < totalClonesWidth - 5) {
                            currentTranslate -= totalRealWidth;
                        } else if (Math.abs(purePos) >= totalClonesWidth + totalRealWidth) {
                            currentTranslate += totalRealWidth;
                        }
                        setSliderPosition();
                    }
                }
                cancelAnimationFrame(animationID);
                animate();
            }

            // Listeners
            feedbackViewport.addEventListener('mousedown', e => startDrag(e.pageX));
            feedbackViewport.addEventListener('touchstart', e => startDrag(e.touches[0].pageX));
            feedbackViewport.addEventListener('mousemove', e => { e.preventDefault(); moveDrag(e.pageX); });
            feedbackViewport.addEventListener('touchmove', e => moveDrag(e.touches[0].pageX));

            // Pause on Hover
            feedbackViewport.addEventListener('mouseenter', stopAutoScroll);
            feedbackViewport.addEventListener('mouseleave', () => {
                if (!isDown) startAutoScroll();
                if (isDown) endDrag();
            });

            feedbackViewport.addEventListener('mouseup', endDrag);
            feedbackViewport.addEventListener('touchend', endDrag);

            window.addEventListener('load', () => {
                setTimeout(setupInfiniteLoop, 200);
            });

            window.addEventListener('resize', () => {
                updateDimensions();
                currentTranslate = -(currentIndex + cloneCount) * slideWidth + centerOffset;
                prevTranslate = currentTranslate;
                setSliderPosition();
            });
        }

        // --- LIQUID PARALLAX SCRIPT ---
        const parallaxImages = document.querySelectorAll('.feature-half-img img');

        // Only run on Desktop
        if (window.innerWidth > 992) {
            let currentScroll = 0;
            let targetScroll = 0;
            const ease = 0.08; // The "Liquid" factor (Lower = heavier/slower)

            // Linear Interpolation function
            function lerp(start, end, factor) {
                return start + (end - start) * factor;
            }

            function animateParallax() {
                targetScroll = window.scrollY;
                currentScroll = lerp(currentScroll, targetScroll, ease);

                parallaxImages.forEach(img => {
                    const container = img.parentElement;
                    const containerTop = container.offsetTop;
                    const containerHeight = container.offsetHeight;

                    // Only animate if the section is roughly in view
                    if (targetScroll + window.innerHeight > containerTop && targetScroll < containerTop + containerHeight) {
                        // Calculate relative scroll position
                        const yPos = (currentScroll - containerTop) * -0.06; // 0.15 is the movement speed
                        img.style.transform = `translate3d(0, ${yPos}px, 0)`;
                    }
                });

                requestAnimationFrame(animateParallax);
            }

            // Start Loop
            animateParallax();
        }

        // --- UPDATED SCROLL REVEAL SCRIPT ---
        const observerOptions = {
            root: null,
            rootMargin: '0px',
            threshold: 0.15
        };

        const observer = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        // Target ALL reveal types: Standard, Left, and Right
        document.querySelectorAll('.reveal, .reveal-from-left, .reveal-from-right').forEach((el) => {
            observer.observe(el);
        });

        // --- HERO BACKGROUND AUTO-SLIDER ---
        document.addEventListener("DOMContentLoaded", function () {
            const slides = document.querySelectorAll('.hero-bg-slide');
            let currentSlide = 0;
            const slideInterval = 5000; // Change image every 5 seconds

            function nextSlide() {
                // Remove 'active' from current slide
                slides[currentSlide].classList.remove('active');

                // Move to next index (loop back to 0 if at end)
                currentSlide = (currentSlide + 1) % slides.length;

                // Add 'active' to new slide
                slides[currentSlide].classList.add('active');
            }

            // Start the timer
            if (slides.length > 0) {
                setInterval(nextSlide, slideInterval);
            }
        });
    </script>
</body>

</html>
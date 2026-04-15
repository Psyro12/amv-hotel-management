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
    'secure' => true, // Ensure your site uses HTTPS!
    'httponly' => true,
    'samesite' => 'Strict'
]);

session_start();

// 3. Disable Error Reporting to Screen
ini_set('display_errors', 0);
error_reporting(E_ALL);

// require_once '../../room_includes/includes/ImageManager.php';
require 'db_connect.php';

// $imageManager = new ImageManager();

// Base64 Placeholder to prevent broken images if path is empty
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
        /* --- SMOOTH SCROLLING --- */
        html {
            scroll-behavior: smooth;
        }

        /* General Override */
        body {
            font-family: 'Montserrat', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f9f9f9;
            overflow-x: hidden;
        }

        /* --- SECTION TITLES --- */
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

        /* --- HEADER STYLES --- */
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
            transition: color 0.4s ease;
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

        nav {
            display: flex;
            align-items: center;
        }

        nav a {
            text-decoration: none;
            color: #fff;
            font-weight: 500;
            font-size: .8rem;
            margin-right: 25px;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: color 0.4s ease, font-size 0.4s ease;
        }

        header.scrolled nav a {
            color: #333;
            font-size: .8rem;
        }

        nav a:hover {
            color: #b8860b;
        }

        /* --- BOOK NOW HEADER BUTTON --- */
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
            margin-right: 0;
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

        .burger-menu {
            color: #fff;
            font-size: 24px;
            cursor: pointer;
            display: none;
            transition: color 0.4s ease;
            margin-left: 20px;
        }

        header.scrolled .burger-menu {
            color: #333;
        }

        @media (max-width: 992px) {
            .desktop-icons {
                display: none;
            }

            .burger-menu {
                display: block;
            }

            header {
                padding: 20px 20px;
            }

            header.scrolled {
                padding: 15px 20px;
            }
        }

        /* --- HERO --- */
        .hero-section {
            position: relative;
            width: 100%;
            height: 100vh;
            min-height: 600px;
            background-image: linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url('../../IMG/hotel_background.png');
            background-size: cover;
            background-position: center;
            display: flex;
            align-items: center;
            justify-content: flex-start;
        }

        .hero-text-overlay {
            padding-left: 8%;
            color: white;
            z-index: 2;
            max-width: 900px;
            margin-top: 50px;
        }

        .hero-sub {
            font-size: .75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 4px;
            display: block;
            margin-bottom: 20px;
            opacity: 0;
            animation: fadeUp 1s ease forwards 0.5s;
        }

        .hero-main-title {
            font-size: 3rem;
            font-weight: 700;
            text-transform: uppercase;
            line-height: 1.1;
            margin: 0;
            opacity: 0;
            animation: fadeUp 1s ease forwards 0.8s;
        }

        .no-wrap {
            white-space: nowrap;
        }

        @keyframes fadeUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* --- ROOM SLIDER STYLES --- */
        .rooms {
            background-color: #f8f5f0;
            overflow: hidden;
            position: relative;
        }

        .room-header-container {
            padding: 50px 7%;
            text-align: center;
        }

        .gallery-viewport {
            width: 100%;
            overflow: visible;
            position: relative;
            padding-bottom: 50px;
        }

        .room-gallery-track {
            display: flex;
            gap: 30px;
            padding-left: calc(50% - 300px);
        }

        .room-card-premium {
            flex: 0 0 600px;
            height: 420px;
            position: relative;
            background: #fff;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            color: inherit;
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
            transition: transform 0.8s cubic-bezier(0.25, 0.46, 0.45, 0.94);
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
            font-family: 'Montserrat', sans-serif;
        }

        .amenities-row {
            display: flex;
            gap: 15px;
        }

        .amenity-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            font-size: 10px;
            color: #999;
            font-weight: 600;
            text-transform: uppercase;
        }

        .amenity-item i {
            color: #b8860b;
            margin-bottom: 5px;
            font-size: 14px;
        }

        /* --- ARROW BUTTONS --- */
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

        @media (max-width: 992px) {
            .room-card-premium {
                flex: 0 0 450px;
                height: 380px;
            }
        }

        @media (max-width: 768px) {
            .room-card-premium {
                flex: 0 0 90vw;
                height: 350px;
            }

            .hero-main-title {
                font-size: 30px;
            }

            .slider-btn {
                top: 350px;
                width: 40px;
                height: 40px;
            }

            .prev-btn {
                left: 10px;
            }

            .next-btn {
                right: 10px;
            }

            .room-gallery-track {
                padding-left: 20px;
                gap: 15px;
            }
        }

        /* --- FEATURES --- */
        .features-container {
            width: 100%;
            margin: 0;
            padding: 0;
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
            position: relative;
        }

        .feature-half-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .feature-half-text {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px 80px;
            box-sizing: border-box;
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
            align-self: flex-start;
            text-transform: uppercase;
            font-weight: 600;
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
            }

            .feature-half-img {
                height: 350px;
            }

            .feature-title {
                font-size: 1.5rem;
            }
        }

        /* --- NEWS SECTION --- */
        .news-section {
            padding-block: 60px 50px;
            padding-inline: 80px;
            background-color: #fff;
        }

        .news-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 40px;
            margin-top: 50px;
        }

        .news-card {
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid #f0f0f0;
        }

        .news-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
        }

        .news-img-container {
            height: 220px;
            width: 100%;
            overflow: hidden;
        }

        .news-img-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
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
            letter-spacing: 1px;
            margin-bottom: 10px;
            display: block;
        }

        .news-title {
            font-size: 1.2rem;
            color: #333;
            margin-bottom: 15px;
            line-height: 1.4;
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
            font-size: 0.85rem;
            border-bottom: 2px solid #b8860b;
            padding-bottom: 2px;
            transition: color 0.3s;
        }

        .read-more-link:hover {
            color: #b8860b;
        }

        /* --- FEEDBACK SECTION --- */
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
            position: relative;
            border-top: 3px solid #b8860b;
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
            font-size: 14px;
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
            object-fit: cover;
            background-color: #ddd;
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
        <nav>
            <a href="menu.php" class="active">Dining</a>
            <a href="#">Reservations</a>

            <a href="check_availability.php" class="btn-header-book desktop-icons">Book Now</a>

            <div class="burger-menu">
                <i class="fa-solid fa-bars"></i>
            </div>
        </nav>
    </header>

    <section class="hero-section">
        <div class="hero-text-overlay">
            <span class="hero-sub">Your Vibrant Experiences Await</span>
            <h1 class="hero-main-title">Welcome to<br><span class="no-wrap">AMV Hotel Mamburao</span></h1>
        </div>
    </section>

    <section class="intro-elegant" id="about">
        <div class="intro-elegant-container">
            <h2 class="intro-elegant-title">Welcome to AMV Hotel</h2>

            <p class="intro-elegant-text">
                AMV Hotel is a property of many firsts — The "True Heart of Mamburao" is delighted to welcome you.
                Experience luxury and comfort combined with our world-class Filipino hospitality.
                Our hotel is an invitation for guests to project their personalities and preferences
                onto the space so that they can truly call the experience their own.
            </p>

            <a href="about_us.php" class="btn-outline-gold">LEARN MORE</a>
        </div>
    </section>

    <section class="features-container">
        <div class="feature-row">
            <div class="feature-half-img">
                <img src="../../IMG/room_7.jpg" alt="Luxury Hotel Room">
            </div>
            <div class="feature-half-text">
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
            <div class="feature-half-text">
                <span class="feature-sub">Dining</span>
                <h3 class="feature-title">Experience the Flavors</h3>
                <p class="feature-desc">
                    Experience the exquisite flavors of world-class cuisine crafted by our master chefs. From
                    traditional favorites to innovative dishes, our restaurants offer an unparalleled gastronomic
                    journey.
                </p>
                <a href="menu.php" class="feature-btn">View Menu</a>
            </div>
        </div>

        <div class="feature-row">
            <div class="feature-half-img">
                <img src="../../IMG/room_1.jpg" alt="Grand Events">
            </div>
            <div class="feature-half-text">
                <span class="feature-sub">Events</span>
                <h3 class="feature-title">Celebrate Grandeur</h3>
                <p class="feature-desc">
                    Host your grandest occasions in our elegant ballrooms and function rooms. Whether it's a wedding,
                    corporate meeting, or a private celebration, our dedicated team will ensure a flawless event.
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

            <button class="slider-btn prev-btn" id="prevBtn">
                <i class="fa-solid fa-chevron-left"></i>
            </button>
            <button class="slider-btn next-btn" id="nextBtn">
                <i class="fa-solid fa-chevron-right"></i>
            </button>

            <div class="gallery-viewport">
                <div class="mt-3">
                    <?php
                    // 🟢 UPDATED QUERY: Fetch from the main 'rooms' table (Matches Admin Logic)
                    // We map the columns using aliases (AS) to match what the frontend/JS expects
                    $query = "SELECT name AS image_name, description, image_path AS file_path, capacity, size FROM rooms WHERE is_active = 1";

                    $result = mysqli_query($conn, $query);
                    $rooms = [];

                    if ($result && mysqli_num_rows($result) > 0) {
                        while ($row = mysqli_fetch_assoc($result)) {
                            $rooms[] = $row;
                        }
                    }
                    ?>

                    <?php if (count($rooms) > 0): ?>
                        <div class="room-gallery-track" id="track">
                            <?php foreach ($rooms as $index => $room): ?>
                                <?php
                                // 🟢 UPDATED IMAGE LOGIC: Handle multiple images stored as CSV
                        
                                // 1. Get the raw path string
                                $rawPath = $room['file_path'];

                                // 2. If it contains commas (multiple images), take only the first one
                                if (strpos($rawPath, ',') !== false) {
                                    $pathParts = explode(',', $rawPath);
                                    $rawPath = trim($pathParts[0]);
                                }

                                // 3. Build the final URL (Matches your JS BASE_PATH)
                                // Use the correct relative path to your room_includes folder
                                $basePath = '../../room_includes/uploads/images/';

                                $imageUrl = !empty($rawPath)
                                    ? $basePath . htmlspecialchars($rawPath)
                                    : $placeholder;
                                ?>
                                <div class="room-card-premium"
                                    data-room-name="<?php echo htmlspecialchars($room['image_name']); ?>">

                                    <div class="premium-img-wrapper">
                                        <img src="<?php echo $imageUrl; ?>"
                                            alt="<?php echo htmlspecialchars($room['image_name']); ?>" class="room-image">
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
                                                style="font-size:12px; color:#888; overflow: hidden; white-space: nowrap; text-overflow: ellipsis;">
                                                <?php echo htmlspecialchars($room['description']); ?>
                                            </div>
                                        </div>
                                        <div class="amenities-row">
                                            <div class="amenity-item"><i class="fa-solid fa-wifi"></i> <span>WIFI</span></div>
                                            <div class="amenity-item"><i class="fa-solid fa-bed"></i> <span>KING</span></div>
                                            <div class="amenity-item"><i class="fa-solid fa-maximize"></i> <span>35SQM</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            <?php endforeach; ?>
                        </div>
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

        <div class="news-grid">
            <article class="news-card">
                <div class="news-img-container">
                    <img src="../../IMG/food_7.jpg" alt="Culinary Award">
                </div>
                <div class="news-content">
                    <span class="news-date">October 15, 2025</span>
                    <h3 class="news-title">AMV Hotel Wins "Best Culinary Experience" Award</h3>
                    <p class="news-excerpt">We are honored to receive this prestigious recognition for our dedication to
                        authentic Filipino and international cuisine.</p>
                    <a href="#" class="read-more-link">Read Full Story</a>
                </div>
            </article>

            <article class="news-card">
                <div class="news-img-container">
                    <img src="../../IMG/room_7.jpg" alt="Renovation">
                </div>
                <div class="news-content">
                    <span class="news-date">September 22, 2025</span>
                    <h3 class="news-title">New Executive Suites Now Open</h3>
                    <p class="news-excerpt">Experience the pinnacle of luxury in our newly renovated Executive Suites,
                        featuring panoramic views of Mamburao.</p>
                    <a href="#" class="read-more-link">Read Full Story</a>
                </div>
            </article>

            <article class="news-card">
                <div class="news-img-container">
                    <img src="../../IMG/room_1.jpg" alt="Holiday Promo">
                </div>
                <div class="news-content">
                    <span class="news-date">August 05, 2025</span>
                    <h3 class="news-title">Holiday Season Early Bird Promo</h3>
                    <p class="news-excerpt">Plan your holidays early! Get up to 30% off on room bookings when you
                        reserve before November 1st.</p>
                    <a href="#" class="read-more-link">Read Full Story</a>
                </div>
            </article>
        </div>
    </section>

    <section class="feedback-section" id="feedback">
        <div style="text-align: center; max-width: 800px; margin: 0 auto;">
            <h2 class="section-title">Guest Stories</h2>
            <p class="section-subtitle">What our valued guests say about their stay.</p>
        </div>

        <div class="feedback-grid">
            <div class="feedback-card">
                <i class="fa-solid fa-quote-right quote-icon"></i>
                <div class="stars">
                    <i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i>
                </div>
                <p class="feedback-text">"The service at AMV Hotel is unmatched. From the moment we arrived, we felt
                    like royalty. The room was pristine and the view was breathtaking."</p>
                <div class="client-info">
                    <img src="https://ui-avatars.com/api/?name=Maria+Santos&background=b8860b&color=fff" alt="User"
                        class="client-avatar">
                    <div class="client-details">
                        <h4>Maria Santos</h4>
                        <span>Stayed Sep 2025</span>
                    </div>
                </div>
            </div>

            <div class="feedback-card">
                <i class="fa-solid fa-quote-right quote-icon"></i>
                <div class="stars">
                    <i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i>
                </div>
                <p class="feedback-text">"An absolute gem in Mamburao. The event hall was perfect for our wedding
                    reception. Highly recommended for big events!"</p>
                <div class="client-info">
                    <img src="https://ui-avatars.com/api/?name=John+Doe&background=333&color=fff" alt="User"
                        class="client-avatar">
                    <div class="client-details">
                        <h4>John Doe</h4>
                        <span>Event Client</span>
                    </div>
                </div>
            </div>

            <div class="feedback-card">
                <i class="fa-solid fa-quote-right quote-icon"></i>
                <div class="stars">
                    <i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i>
                    <i class="fa-regular fa-star"></i>
                </div>
                <p class="feedback-text">"Great food and very accommodating staff. The location is accessible and the
                    ambiance is very relaxing. Will definitely come back."</p>
                <div class="client-info">
                    <img src="https://ui-avatars.com/api/?name=Sarah+L&background=b8860b&color=fff" alt="User"
                        class="client-avatar">
                    <div class="client-details">
                        <h4>Sarah L.</h4>
                        <span>Stayed Aug 2025</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <footer>
        <p>&copy; 2025 AMV Hotel. All rights reserved.</p>
    </footer>

    <script src="../SCRIPT/home_page.js"></script>

    <script>
        // 1. Header Scroll Logic
        const header = document.getElementById('mainHeader');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 50) header.classList.add('scrolled');
            else header.classList.remove('scrolled');
        });

        // 2. INFINITE CAROUSEL LOGIC
        const track = document.getElementById('track');
        const nextBtn = document.getElementById('nextBtn');
        const prevBtn = document.getElementById('prevBtn');

        const CARD_WIDTH = 600;
        const GAP = 30;
        const TOTAL_MOVE = CARD_WIDTH + GAP;
        const TRANSITION_SPEED = 500;

        // INIT: Move the last item to the front immediately so left side isn't empty
        if (track && track.children.length > 1) {
            track.prepend(track.lastElementChild);
            track.style.transform = `translateX(-${TOTAL_MOVE}px)`;
            // The one in the visual center is now index 1
            track.children[1].classList.add('active');
        }

        let isAnimating = false;

        // NEXT BUTTON
        nextBtn.addEventListener('click', () => {
            if (isAnimating || !track.firstElementChild) return;
            isAnimating = true;

            // 1. Handle SCALING concurrently
            track.children[1].classList.remove('active');
            track.children[2].classList.add('active');

            // 2. Clone first card and append to end (fills the right-side gap)
            const firstCard = track.firstElementChild;
            const clone = firstCard.cloneNode(true);
            track.appendChild(clone);

            // 3. Animate slide left
            track.style.transition = `transform ${TRANSITION_SPEED}ms ease-in-out`;
            track.style.transform = `translateX(-${TOTAL_MOVE * 2}px)`;

            // 4. Cleanup after animation
            setTimeout(() => {
                track.style.transition = 'none';
                track.removeChild(clone); // Remove clone
                track.appendChild(firstCard); // Move real element to end
                track.style.transform = `translateX(-${TOTAL_MOVE}px)`; // Snap back
                setTimeout(() => { isAnimating = false; }, 50);
            }, TRANSITION_SPEED);
        });

        // PREV BUTTON (With Clone Logic for Left Side)
        prevBtn.addEventListener('click', () => {
            if (isAnimating || !track.lastElementChild) return;
            isAnimating = true;

            // 1. Handle SCALING concurrently
            // Note: We are prepending a clone soon, so indices shift.
            // Current 'active' is index 1.
            // The one entering from left (index 0) becomes index 1.
            track.children[1].classList.remove('active');
            track.children[0].classList.add('active');

            // 2. Clone last card and prepend (fills the left-side gap)
            const lastCard = track.lastElementChild;
            const clone = lastCard.cloneNode(true);
            track.prepend(clone);

            // 3. Snap to offset (shift view to accommodate new clone)
            // Standard offset is -630. We added 630 at start. So new offset is -1260.
            track.style.transition = 'none';
            track.style.transform = `translateX(-${TOTAL_MOVE * 2}px)`;

            // 4. Force Reflow
            void track.offsetWidth;

            // 5. Animate to standard offset
            track.style.transition = `transform ${TRANSITION_SPEED}ms ease-in-out`;
            track.style.transform = `translateX(-${TOTAL_MOVE}px)`;

            // 6. Cleanup
            setTimeout(() => {
                track.style.transition = 'none';
                track.removeChild(clone); // Remove clone
                track.prepend(lastCard); // Move real element to front
                // track.style.transform is already correct (-TOTAL_MOVE)
                setTimeout(() => { isAnimating = false; }, 50);
            }, TRANSITION_SPEED);
        });

        // 3. REALTIME UPDATES
        const IMG_BASE_PATH = '/image-storage/uploads/images/';
        function fetchRoomUpdates() {
            fetch('fetch_room_updates.php?ts=' + Date.now(), { cache: 'no-store' })
                .then(response => response.ok ? response.json() : null)
                .then(data => {
                    if (!data || data.error) return;
                    const rooms = Array.isArray(data) ? data : [];
                    const fetchedNames = new Set(rooms.map(r => String(r.image_name)));

                    document.querySelectorAll('.room-card-premium').forEach(card => {
                        if (!fetchedNames.has(card.dataset.roomName)) card.remove();
                    });

                    rooms.forEach(room => {
                        const id = String(room.image_name);
                        if (!document.querySelector(`.room-card-premium[data-room-name="${id}"]`)) {
                            // --- 🟢 UPDATED: JS IMAGE SPLIT LOGIC ---
                            let rawPath = room.file_path ? room.file_path.toString() : '';
                            if (rawPath.indexOf(',') > -1) {
                                // Split by comma, take the first part, and trim whitespace
                                rawPath = rawPath.split(',')[0].trim();
                            }

                            const imgSrc = IMG_BASE_PATH + rawPath + '?v=' + Date.now();

                            // Changed to create div instead of a
                            const card = document.createElement('div');
                            // Removed href assignment
                            card.className = 'room-card-premium';
                            card.setAttribute('data-room-name', id);
                            card.innerHTML = `
                        <div class="premium-img-wrapper">
                            <img src="${imgSrc}" class="room-image" style="opacity:0; transition: opacity 0.5s;">
                        </div>
                        <div class="premium-details">
                            <div style="flex:1; padding-right:10px;">
                                <div class="premium-title">${escapeHtml(id)}</div>
                                <div style="font-size:12px; color:#888; overflow: hidden; white-space: nowrap; text-overflow: ellipsis;">
                                    ${escapeHtml(room.description || '')}
                                </div>
                            </div>
                            <div class="amenities-row">
                                <div class="amenity-item"><i class="fa-solid fa-wifi"></i> <span>WIFI</span></div>
                                <div class="amenity-item"><i class="fa-solid fa-bed"></i> <span>KING</span></div>
                                <div class="amenity-item"><i class="fa-solid fa-maximize"></i> <span>35SQM</span></div>
                            </div>
                        </div>
                    `;
                            track.appendChild(card);
                            setTimeout(() => { card.querySelector('img').style.opacity = 1; }, 50);
                        }
                    });

                    if (track.children.length > 1 && !track.querySelector('.active')) {
                        track.children[1].classList.add('active');
                    }
                })
                .catch(e => console.error(e));
        }

        function escapeHtml(str) {
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        setInterval(fetchRoomUpdates, 3000);
    </script>
</body>

</html>
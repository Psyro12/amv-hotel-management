<?php
// USER/PHP/privacy_policy.php

// --- 1. Security Headers ---
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https://ui-avatars.com; upgrade-insecure-requests;");

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

// --- 6. Helper: Auto-Icon Generator ---
function getPrivacyIcon($title)
{
    $t = strtolower(strip_tags($title));
    if (strpos($t, 'introduction') !== false)
        return 'fa-solid fa-info-circle';
    if (strpos($t, 'collect') !== false)
        return 'fa-solid fa-database';
    if (strpos($t, 'usage') !== false)
        return 'fa-solid fa-chart-pie';
    if (strpos($t, 'mobile') !== false)
        return 'fa-solid fa-mobile-screen';
    if (strpos($t, 'use') !== false)
        return 'fa-solid fa-hands-holding-circle';
    if (strpos($t, 'disclosure') !== false)
        return 'fa-solid fa-file-contract';
    if (strpos($t, 'security') !== false)
        return 'fa-solid fa-shield-halved';
    if (strpos($t, 'contact') !== false)
        return 'fa-solid fa-headset';
    return 'fa-solid fa-file-shield';
}

// --- 7. Fetch Privacy Policies from Database ---
$privacy_policies = [];
$sql_priv = "SELECT * FROM privacy_policy ORDER BY display_order ASC";
$res_priv = @mysqli_query($conn, $sql_priv);

if ($res_priv && mysqli_num_rows($res_priv) > 0) {
    while ($row = mysqli_fetch_assoc($res_priv)) {

        // Dynamic Icon Logic
        $icon = (isset($row['icon_class']) && !empty($row['icon_class']))
            ? $row['icon_class']
            : getPrivacyIcon($row['section_title']);

        $privacy_policies[] = [
            'title' => $row['section_title'],
            'content' => $row['content'],
            'icon_class' => $icon
        ];
    }
} else {
    // Fallback
    $privacy_policies[] = [
        'title' => 'Privacy Policy',
        'content' => '<p>Privacy policies are currently being updated by the administrator.</p>',
        'icon_class' => 'fa-solid fa-user-shield'
    ];
}

// --- 8. Fetch "Last Updated" Date ---
$last_updated_date = date('F Y');
$date_sql = "SELECT updated_at FROM privacy_policy ORDER BY updated_at DESC LIMIT 1";
$date_res = @mysqli_query($conn, $date_sql);

if ($date_res && mysqli_num_rows($date_res) > 0) {
    $d = mysqli_fetch_assoc($date_res);
    if (!empty($d['updated_at'])) {
        $last_updated_date = date('F j, Y', strtotime($d['updated_at']));
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - AMV Hotel</title>
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

        body {
            font-family: 'Montserrat', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f9f9f9;
            overflow-x: hidden;
            color: #333;
        }

        /* --- WRAPPER --- */
        .page-content-wrapper {
            background-color: #f9f9f9;
            position: relative;
            z-index: 10;
            margin-bottom: 250px;
            padding-bottom: 60px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
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

        /* --- NAV --- */
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
            transition: color 0.3s;
            text-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
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
            color: #fff !important;
            border: 1px solid #fff;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-radius: 4px;
            transition: 0.3s;
            text-decoration: none;
        }

        .btn-header-book:hover {
            background-color: #fff;
            color: #333 !important;
        }

        header.scrolled .btn-header-book {
            border-color: #b8860b;
            color: #b8860b !important;
        }

        header.scrolled .btn-header-book:hover {
            background-color: #b8860b;
            color: #fff !important;
        }

        /* --- MOBILE --- */
        .burger-menu {
            font-size: 24px;
            cursor: pointer;
            display: none;
            color: #fff;
            z-index: 1100;
        }

        header.scrolled .burger-menu {
            color: #333;
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

        /* --- HERO --- */
        .page-header {
            position: relative;
            width: 100%;
            height: 50vh;
            min-height: 350px;
            background-image: url('../../IMG/hotel_background.png');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1;
        }

        /* 🔴 1. ADDED PADDING BOTTOM to push text up */
        .header-content {
            position: relative;
            z-index: 2;
            padding: 0 20px 60px 20px;
            /* Added 60px bottom padding */
            animation: fadeUp 1s ease-out;
            margin-top: 40px;
        }

        .page-title {
            font-size: 3rem;
            font-weight: 700;
            color: #fff;
            margin: 0 0 10px 0;
            text-transform: uppercase;
            letter-spacing: 3px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .page-subtitle {
            color: #f0f0f0;
            font-size: 1rem;
            max-width: 700px;
            margin: 0 auto;
            letter-spacing: 1px;
            text-shadow: 0 1px 4px rgba(0, 0, 0, 0.3);
        }

        .updated-badge {
            display: inline-block;
            margin-top: 15px;
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* --- POLICY CONTENT --- */
        .gallery-container {
            padding: 0 5% 80px 5%;
            max-width: 1600px;
            margin: 0 auto;
        }

        /* 🔴 2. ADJUSTED MARGIN-TOP to separate card from title */
        .policy-container {
            max-width: 1000px;
            margin: -80px auto 0;
            /* Changed from -60px to -40px */
            position: relative;
            z-index: 5;
            padding: 50px;
            background: #fff;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border-radius: 8px;
        }

        .policy-last-updated {
            display: block;
            margin-bottom: 30px;
            font-size: 0.85rem;
            color: #999;
            font-style: italic;
            border-bottom: 1px solid #eee;
            padding-bottom: 20px;
        }

        .policy-section {
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 1px solid #f9f9f9;
        }

        .policy-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .policy-section h2 {
            font-size: 1.4rem;
            color: #b8860b;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .policy-section h2 i {
            background: #FFF8E1;
            padding: 10px;
            border-radius: 50%;
            font-size: 1.1rem;
        }

        /* HTML Content Styling */
        .policy-content-body p {
            font-size: 0.95rem;
            color: #555;
            line-height: 1.8;
            margin-bottom: 15px;
        }

        .policy-content-body ul {
            padding-left: 20px;
            margin-bottom: 20px;
        }

        .policy-content-body li {
            margin-bottom: 8px;
            font-size: 0.95rem;
            color: #555;
            line-height: 1.6;
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

        .footer-contact-item.active-footer-link {
            color: #D4AF37;
            font-weight: 700;
            pointer-events: none;
            cursor: default;
            transform: none;
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

        .chat-input:focus {
            outline: none;
            border-color: #D4AF37;
            background: #fff;
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

        .close-bubble:hover {
            color: #2D0F35;
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

            .page-header {
                height: 40vh;
            }

            .page-title {
                font-size: 2rem;
            }

            .policy-container {
                padding: 30px 20px;
                margin-top: -40px;
                width: 90%;
            }

            .policy-content ul {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <?php include 'loader.php'; ?>

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
                <a href="check_availability.php">Reservations</a>
                <a href="about_us.php">About Us</a>
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

        <div class="page-header">
            <div class="header-content">
                <h1 class="page-title">Privacy Policy</h1>
                <p class="page-subtitle">Committed to protecting your privacy and personal data.</p>
                <span class="updated-badge">Last Updated: <?php echo $last_updated_date; ?></span>
            </div>
        </div>

        <div class="gallery-container">
            <div class="policy-container">

                <?php
                if (!empty($privacy_policies)) {
                    foreach ($privacy_policies as $policy) {
                        $iconClass = getPrivacyIcon($policy['title']);
                        ?>
                        <div class="policy-section reveal">
                            <h2>
                                <i class="<?php echo $iconClass; ?>"></i>
                                <?php echo htmlspecialchars($policy['title']); ?>
                            </h2>

                            <div class="policy-content-body">
                                <?php echo $policy['content']; // HTML allowed from DB ?>
                            </div>
                        </div>
                        <?php
                    }
                } else {
                    echo "<p style='text-align:center;'>No privacy policies found.</p>";
                }
                ?>

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
                    <a href="tel:<?php echo $hotel_phone_link; ?>" class="footer-contact-item"><i
                            class="fas fa-phone-alt"></i>
                        <span><?php echo $hotel_phone; ?></span></a>
                    <a href="mailto:<?php echo $hotel_email; ?>" class="footer-contact-item"><i
                            class="fas fa-envelope"></i>
                        <span><?php echo $hotel_email; ?></span></a>
                    <a href="https://facebook.com" target="_blank" class="footer-contact-item"><i
                            class="fab fa-facebook-f"></i> <span>AMV Hotel Official</span></a>
                </div>
                <div class="footer-contact-list">
                    <a href="term_conditions.php" class="footer-contact-item"><i class="fas fa-file-contract"></i>
                        <span>Hotel Policies</span></a>

                    <a href="#" class="footer-contact-item active-footer-link">
                        <i class="fas fa-user-shield"></i> <span>Privacy Policy</span>
                    </a>
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
        // 1. Header Logic
        const header = document.getElementById('mainHeader');
        function checkScroll() {
            const scrollPos = Math.max(0, window.scrollY);
            if (scrollPos > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        }
        window.addEventListener('scroll', checkScroll);
        document.addEventListener('DOMContentLoaded', checkScroll);

        // 2. Mobile Menu
        function toggleMobileMenu() {
            document.getElementById('mobileMenu').classList.toggle('active');
            document.getElementById('mobileBackdrop').classList.toggle('active');
        }

        // 3. Chat Toggle
        function toggleChat() {
            var bubble = document.getElementById("chatBubble");
            bubble.classList.toggle("active");
        }

        // 4. Close Chat when clicking outside
        document.addEventListener('click', function (event) {
            var bubble = document.getElementById("chatBubble");
            var btn = document.querySelector('.btn-icon-msg');
            if (bubble && btn && !bubble.contains(event.target) && !btn.contains(event.target)) {
                bubble.classList.remove("active");
            }
        });

        // 5. Auto-resize textarea
        const ta = document.getElementById('msgAreaFooter');
        if (ta) {
            ta.addEventListener('input', function () {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }

        // 6. Reveal Animation
        const observerOptions = {
            root: null, rootMargin: '0px', threshold: 0.1
        };
        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                    obs.unobserve(entry.target);
                }
            });
        }, observerOptions);

        document.querySelectorAll('.reveal').forEach((el) => {
            observer.observe(el);
        });
    </script>
</body>

</html>
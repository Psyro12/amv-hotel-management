<?php
// auth.php
session_start();
require 'db_connect.php';

// Ensure user came from login
if (!isset($_SESSION['temp_user'])) {
    header('Location: login.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Combine inputs
    if (isset($_POST['otp']) && is_array($_POST['otp'])) {
        $submitted_otp = implode('', $_POST['otp']);
    } else {
        $submitted_otp = '';
    }

    // 2. Retrieve OTP and Expiry from SESSION
    $sessionOtp = $_SESSION['auth_otp'] ?? null;
    $sessionExpiry = $_SESSION['auth_otp_expiry'] ?? 0;

    // 3. Verify
    if ($sessionOtp && $submitted_otp === (string) $sessionOtp) {

        // Check Expiration (Current time vs Saved Expiry time)
        if (time() <= $sessionExpiry) {

            // 🟢 EXCLUSIVE ACCESS: Set current time as active
            $user_id = $_SESSION['temp_user']['id'];
            $nowStr = date('Y-m-d H:i:s');
            $currentIp = $_SERVER['REMOTE_ADDR'];
            $currentSessionId = session_id();

            $update_stmt = $conn->prepare("UPDATE admin_user SET last_activity = ?, last_ip = ?, last_session_id = ? WHERE ID = ?");
            $update_stmt->bind_param("sssi", $nowStr, $currentIp, $currentSessionId, $user_id);
            $update_stmt->execute();
            $update_stmt->close();

            // SUCCESS! Set real session
            $_SESSION['user'] = $_SESSION['temp_user'];

            // Start the inactivity timer
            $_SESSION['timeout'] = time();

            // Clean up temporary session data
            unset($_SESSION['temp_user']);
            unset($_SESSION['auth_otp']);
            unset($_SESSION['auth_otp_expiry']);

            header('Location: loading.php');
            exit();
        } else {
            $error = "This code has expired (10 minute limit). Please login again.";
        }
    } else {
        $error = "Invalid OTP code. Please try again.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMV - Admin Authentication</title>
    <link rel="icon" type="image/png" href="../../IMG/5.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="../STYLE/auth-styles.css">
    <script type="module" src="https://cdn.jsdelivr.net/npm/ldrs/dist/auto/ring.js"></script>
    <style>
        /* (Keep your existing CSS here) */
        body,
        html {
            height: 100%;
            margin: 0;
            overflow: hidden;
        }

        .hero-slider-wrapper {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
            background-color: #000;
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
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: -1;
        }

        .auth-form {
            background: rgba(255, 255, 255, 0.95);
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 400px;
        }

        .container {
            position: relative;
            z-index: 5;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .main-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .brand-text {
            color: white;
            text-shadow: 0 2px 5px rgba(0, 0, 0, 0.5);
            font-weight: 700;
            font-size: 1.5rem;
            margin-left: 10px;
        }

        .error-msg {
            color: #DC2626;
            text-align: center;
            margin-bottom: 15px;
            font-size: 0.9rem;
            font-weight: 600;
            background: #FEE2E2;
            padding: 10px;
            border-radius: 6px;
        }

        .instruction-text {
            color: #666;
            font-size: 0.9rem;
            text-align: center;
            margin-top: 15px;
            line-height: 1.5;
        }
    </style>
</head>

<body>
    <div class="hero-slider-wrapper">
        <div class="hero-slide active" style="background-image: url('../../IMG/hotel_background.png');"></div>
        <div class="hero-slide" style="background-image: url('../../IMG/hotel_rooms.png');"></div>
        <div class="hero-slide" style="background-image: url('../../IMG/hotel_foods.jpg');"></div>
        <div class="hero-slide" style="background-image: url('../../IMG/hotel_events.png');"></div>
    </div>
    <div class="hero-overlay"></div>

    <div class="container">
        <header class="header">
            <div class="logo">
                <div class="logo-icon">
                    <img src="../../IMG/4.png" alt="AMV Logo"
                        style="height: 64px; width: auto; display: block; margin: 0 auto;">
                </div>
                <span class="brand-text">AMV</span>
            </div>
        </header>
        <main class="main-content">
            <div class="auth-form">
                <h1 class="form-title" style="text-align:center; margin-bottom:20px; color:#333;">Admin Authentication
                </h1>

                <?php if ($error): ?>
                    <div class="error-msg"><?php echo $error; ?></div>
                <?php endif; ?>

                <form id="authForm" class="form" method="POST">
                    <div class="form-group">
                        <label for="otp" class="form-label"
                            style="display:block; text-align:center; margin-bottom:10px; color:#555; font-weight:600;">ENTER
                            OTP CODE</label>
                        <div class="otp-inputs"
                            style="display:flex; justify-content:center; gap:8px; margin-bottom:15px;">
                            <input type="text" class="otp-input" name="otp[]" maxlength="1" data-index="0" required
                                style="width:40px; height:50px; text-align:center; font-size:1.2rem; border:1px solid #ccc; border-radius:6px;">
                            <input type="text" class="otp-input" name="otp[]" maxlength="1" data-index="1" required
                                style="width:40px; height:50px; text-align:center; font-size:1.2rem; border:1px solid #ccc; border-radius:6px;">
                            <input type="text" class="otp-input" name="otp[]" maxlength="1" data-index="2" required
                                style="width:40px; height:50px; text-align:center; font-size:1.2rem; border:1px solid #ccc; border-radius:6px;">
                            <input type="text" class="otp-input" name="otp[]" maxlength="1" data-index="3" required
                                style="width:40px; height:50px; text-align:center; font-size:1.2rem; border:1px solid #ccc; border-radius:6px;">
                            <input type="text" class="otp-input" name="otp[]" maxlength="1" data-index="4" required
                                style="width:40px; height:50px; text-align:center; font-size:1.2rem; border:1px solid #ccc; border-radius:6px;">
                            <input type="text" class="otp-input" name="otp[]" maxlength="1" data-index="5" required
                                style="width:40px; height:50px; text-align:center; font-size:1.2rem; border:1px solid #ccc; border-radius:6px;">
                        </div>
                        <p class="instruction-text">
                            Enter the 6-digit code sent to<br>
                            <strong><?php echo htmlspecialchars($_SESSION['temp_user']['email'] ?? 'your email'); ?></strong>
                        </p>
                    </div>

                    <button type="submit" class="auth-button" id="verifyBtn"
                        style="display:flex; justify-content:center; align-items:center; gap:10px; width:100%; padding:12px; background:#B88E2F; color:white; border:none; border-radius:6px; font-weight:600; font-size:1rem; cursor:pointer; margin-top:20px;">
                        Verify & Login
                    </button>
                </form>

                <div style="text-align:center; margin-top:15px;">
                    <a href="login.php" style="color:#888; font-size:0.85rem; text-decoration:none;">Back to Login</a>
                </div>
            </div>
        </main>
    </div>

    <script src="../SCRIPT/auth-script.js"></script>

    <script>
        // 3. BACKGROUND SLIDER LOGIC
        document.addEventListener("DOMContentLoaded", function () {
            const slides = document.querySelectorAll('.hero-slide');
            let currentSlide = 0;
            const slideInterval = 5000; // 5 Seconds

            if (slides.length > 0) {
                setInterval(() => {
                    slides[currentSlide].classList.remove('active');
                    currentSlide = (currentSlide + 1) % slides.length;
                    slides[currentSlide].classList.add('active');
                }, slideInterval);
            }
        });

        // 4. BUTTON LOADER LOGIC
        document.getElementById('authForm').onsubmit = function () {
            const btn = document.getElementById('verifyBtn');
            btn.innerHTML = `
                <l-ring size="24" stroke="3" bg-opacity="0" speed="2" color="white"></l-ring>
                <span>Verifying...</span>
            `;
            btn.style.opacity = '0.8';
            btn.style.cursor = 'not-allowed';
            setTimeout(() => { btn.disabled = true; }, 10);
        };
    </script>
</body>

</html>
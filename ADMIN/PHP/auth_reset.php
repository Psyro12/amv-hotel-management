<?php
// auth_reset.php
session_start();
require 'db_connect.php';

// Ensure user came from forgot_password
if (!isset($_SESSION['reset_email'])) {
    header('Location: forgot_password.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['otp']) && is_array($_POST['otp'])) {
        $submitted_otp = implode('', $_POST['otp']);
    } else {
        $submitted_otp = '';
    }

    $sessionOtp = $_SESSION['reset_otp'] ?? null;
    $sessionExpiry = $_SESSION['reset_otp_expiry'] ?? 0;

    if ($sessionOtp && $submitted_otp === (string) $sessionOtp) {
        if (time() <= $sessionExpiry) {
            // SUCCESS
            $_SESSION['reset_verified'] = true;
            unset($_SESSION['reset_otp']);
            unset($_SESSION['reset_otp_expiry']);
            header('Location: reset_password.php');
            exit();
        } else {
            $error = "This code has expired. Please request a new one.";
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
    <title>AMV - Verify Reset Code</title>
    <link rel="icon" type="image/png" href="../../IMG/5.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../STYLE/auth-styles.css">
    <script type="module" src="https://cdn.jsdelivr.net/npm/ldrs/dist/auto/ring.js"></script>
    <style>
        body, html { height: 100%; margin: 0; overflow: hidden; }
        .hero-slider-wrapper { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -2; background-color: #000; }
        .hero-slide { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background-size: cover; background-position: center; opacity: 0; transform: scale(1); transition: opacity 1.5s ease-in-out, transform 6s linear; z-index: 0; }
        .hero-slide.active { opacity: 1; transform: scale(1.1); z-index: 1; }
        .hero-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); z-index: -1; }
        .auth-form { background: rgba(255, 255, 255, 0.95); padding: 40px; border-radius: 12px; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2); position: relative; z-index: 10; width: 100%; max-width: 400px; }
        .container { position: relative; z-index: 5; height: 100vh; display: flex; flex-direction: column; }
        .main-content { flex: 1; display: flex; align-items: center; justify-content: center; }
        .brand-text { color: white; text-shadow: 0 2px 5px rgba(0, 0, 0, 0.5); font-weight: 700; font-size: 1.5rem; margin-left: 10px; }
        .error-msg { color: #DC2626; text-align: center; margin-bottom: 15px; font-size: 0.9rem; font-weight: 600; background: #FEE2E2; padding: 10px; border-radius: 6px; }
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
                <div class="logo-icon"><img src="../../IMG/4.png" alt="AMV Logo" style="height: 64px; width: auto;"></div>
                <span class="brand-text">AMV</span>
            </div>
        </header>

        <main class="main-content">
            <div class="auth-form">
                <h1 class="form-title" style="text-align:center; margin-bottom:20px;">Verify Reset Code</h1>

                <?php if ($error): ?>
                    <div class="error-msg"><?php echo $error; ?></div>
                <?php endif; ?>

                <form id="authForm" class="form" method="POST">
                    <div class="form-group">
                        <label class="form-label" style="display:block; text-align:center; margin-bottom:10px; font-weight:600;">ENTER 6-DIGIT CODE</label>
                        <div class="otp-inputs" style="display:flex; justify-content:center; gap:8px; margin-bottom:15px;">
                            <?php for($i=0; $i<6; $i++): ?>
                                <input type="text" class="otp-input" name="otp[]" maxlength="1" data-index="<?php echo $i; ?>" required
                                    style="width:40px; height:50px; text-align:center; font-size:1.2rem; border:1px solid #ccc; border-radius:6px;">
                            <?php endfor; ?>
                        </div>
                        <p style="text-align:center; color:#666; font-size:0.85rem;">Sent to <strong><?php echo htmlspecialchars($_SESSION['reset_email']); ?></strong></p>
                    </div>

                    <button type="submit" class="auth-button" id="verifyBtn"
                        style="width:100%; padding:12px; background:#B88E2F; color:white; border:none; border-radius:6px; font-weight:600; cursor:pointer; margin-top:20px;">
                        Verify Code
                    </button>
                </form>
            </div>
        </main>
    </div>

    <script src="../SCRIPT/auth-script.js"></script>
    <script>
        // BACKGROUND SLIDER LOGIC
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

        document.getElementById('authForm').onsubmit = function () {
            const btn = document.getElementById('verifyBtn');
            btn.innerHTML = `<l-ring size="24" stroke="3" bg-opacity="0" speed="2" color="white"></l-ring><span>Verifying...</span>`;
            btn.disabled = true;
        };
    </script>
</body>
</html>

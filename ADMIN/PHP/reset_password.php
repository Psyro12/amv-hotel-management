<?php
// reset_password.php
session_start();
require 'db_connect.php';

// Ensure user is verified
if (!isset($_SESSION['reset_verified']) || !isset($_SESSION['reset_email'])) {
    header('Location: forgot_password.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $email = $_SESSION['reset_email'];

        // Update password and clear any lockouts
        $stmt = $conn->prepare("UPDATE admin_user SET password = ?, failed_attempts = 0, lockout_until = NULL, suspension_count = 0, blocked_until = NULL, active_session_id = NULL WHERE email = ?");
        $stmt->bind_param("ss", $hashed_password, $email);
        
        if ($stmt->execute()) {
            $success = "Password reset successful! Redirecting to login...";
            // Clean up session
            session_unset();
            session_destroy();
            header("refresh:3;url=login.php");
        } else {
            $error = "Database error. Please try again.";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMV - New Password</title>
    <link rel="icon" type="image/png" href="../../IMG/5.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../STYLE/styles.css">
    <script type="module" src="https://cdn.jsdelivr.net/npm/ldrs/dist/auto/ring.js"></script>
    <style>
        body, html { height: 100%; margin: 0; overflow: hidden; }
        .hero-slider-wrapper { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -2; background-color: #000; }
        .hero-slide { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background-size: cover; background-position: center; opacity: 0; transform: scale(1); transition: opacity 1.5s ease-in-out, transform 6s linear; z-index: 0; }
        .hero-slide.active { opacity: 1; transform: scale(1.1); z-index: 1; }
        .hero-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); z-index: -1; }
        .login-form { background: rgba(255, 255, 255, 0.95); padding: 40px; border-radius: 12px; box-shadow: 0 15px 35px rgba(0,0,0,0.2); position: relative; z-index: 10; max-width: 400px; width: 90%; }
        .container { position: relative; z-index: 5; height: 100vh; display: flex; flex-direction: column; }
        .main-content { flex: 1; display: flex; align-items: center; justify-content: center; }
        .brand-text { color: white; text-shadow: 0 2px 5px rgba(0,0,0,0.5); font-weight: 700; font-size: 1.5rem; margin-left: 10px; }
        .password-container { position: relative; display: flex; align-items: center; width: 100%; }
        .password-container input { padding-right: 45px !important; width: 100%; }
        .password-toggle { position: absolute; right: 12px; cursor: pointer; color: #6b7280; z-index: 5; padding: 8px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
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
            <div class="login-form">
                <h1 class="form-title" style="margin-bottom: 15px;">Set New Password</h1>
                
                <?php if ($error): ?>
                    <div class="error-message" style="color:red; margin-bottom:15px; text-align:center; background: #fee2e2; padding: 10px; border-radius: 8px; font-weight: 600;">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="success-message" style="color:#166534; margin-bottom:15px; text-align:center; background: #dcfce7; padding: 20px; border-radius: 8px; font-weight: 600; display: flex; flex-direction: column; align-items: center; gap: 15px;">
                        <l-ring size="40" stroke="4" bg-opacity="0" speed="2" color="#166534"></l-ring>
                        <span><?php echo $success; ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if (!$success): ?>
                <form method="POST" class="form" id="resetForm">
                    <div class="form-group">
                        <label for="password" class="form-label">New Password</label>
                        <div class="password-container">
                            <input type="password" id="password" name="password" class="form-input" required minlength="8">
                            <span class="password-toggle" onclick="togglePass('password', this)">
                                <i class="fa-solid fa-eye"></i>
                            </span>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:10px;">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <div class="password-container">
                            <input type="password" id="confirm_password" name="confirm_password" class="form-input" required minlength="8">
                            <span class="password-toggle" onclick="togglePass('confirm_password', this)">
                                <i class="fa-solid fa-eye"></i>
                            </span>
                        </div>
                    </div>
                    
                    <button type="submit" class="login-button" id="submitBtn" style="width:100%; margin-top:20px; display:flex; justify-content:center; align-items:center; gap:10px;">
                        Update Password
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </main>
    </div>

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

        // Form submission loader
        if (document.getElementById('resetForm')) {
            document.getElementById('resetForm').onsubmit = function() {
                const btn = document.getElementById('submitBtn');
                btn.innerHTML = `<l-ring size="24" stroke="3" bg-opacity="0" speed="2" color="white"></l-ring><span>Updating...</span>`;
                btn.disabled = true;
            };
        }
        function togglePass(id, btn) {
            const input = document.getElementById(id);
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>

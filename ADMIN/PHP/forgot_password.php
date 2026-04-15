<?php
// forgot_password.php
session_start();
require 'db_connect.php';

// Load PHPMailer
require '../../USER/PHPMailer-master/src/Exception.php';
require '../../USER/PHPMailer-master/src/PHPMailer.php';
require '../../USER/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$error = '';
$success = '';
$user_ip = $_SERVER['REMOTE_ADDR'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];

    // 1. Fetch User
    $stmt = $conn->prepare("SELECT * FROM admin_user WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // 2. Generate OTP
        $otp = rand(100000, 999999);

        // 3. STORE OTP IN SESSION
        $_SESSION['reset_otp'] = $otp;
        $_SESSION['reset_otp_expiry'] = time() + (10 * 60); // 10 minutes
        $_SESSION['reset_email'] = $email;

        // 4. Send Email
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'periolarren@gmail.com'; 
            $mail->Password = 'ftvp ilfl utmq pdgg';    
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            $mail->setFrom('periolarren@gmail.com', 'AMV Admin System');
            $mail->addAddress($email, $user['name']);

            $mail->isHTML(true);
            $mail->Subject = 'Password Reset OTP - AMV Admin';
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                    <h2 style='color: #B88E2F;'>Password Reset Request</h2>
                    <p>Hello {$user['name']},</p>
                    <p>We received a request to reset your password. Use the code below to proceed:</p>
                    <h1 style='color: #2563EB; letter-spacing: 5px; background: #f4f4f4; padding: 15px; text-align: center; border-radius: 8px;'>{$otp}</h1>
                    <p>This code expires in 10 minutes. If you didn't request this, please ignore this email.</p>
                </div>
            ";

            $mail->send();
            header('Location: auth_reset.php');
            exit();

        } catch (Exception $e) {
            $error = "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    } else {
        $error = 'Email not found in our records.';
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMV - Forgot Password</title>
    <link rel="icon" type="image/png" href="../../IMG/5.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
        .brand-text { color: white; text-shadow: 0 2px 5px rgba(0,0,0,0.5); font-weight: 700; font-size: 1.5rem; margin-left: 10px;}
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
                <h1 class="form-title" style="margin-bottom: 10px;">Forgot Password</h1>
                <p style="text-align:center; color:#666; font-size:0.9rem; margin-bottom:20px;">Enter your email to receive a reset code.</p>
                
                <?php if ($error): ?>
                    <div class="error-message" style="color:red; margin-bottom:15px; text-align:center; background: #fee2e2; padding: 10px; border-radius: 8px; font-weight: 600;">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="form" id="forgotForm">
                    <div class="form-group">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" id="email" name="email" class="form-input" required>
                    </div>
                    
                    <button type="submit" class="login-button" id="submitBtn" style="display:flex; justify-content:center; align-items:center; gap:10px; width:100%;">
                        Send Reset Code
                    </button>

                    <div style="text-align:center; margin-top:15px;">
                        <a href="login.php" style="color:#666; font-size:0.85rem; text-decoration:none;">Back to Login</a>
                    </div>
                </form>
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

        document.getElementById('forgotForm').onsubmit = function() {
            const btn = document.getElementById('submitBtn');
            btn.innerHTML = `<l-ring size="24" stroke="3" bg-opacity="0" speed="2" color="white"></l-ring><span>Sending...</span>`;
            btn.disabled = true;
        };
    </script>
</body>
</html>

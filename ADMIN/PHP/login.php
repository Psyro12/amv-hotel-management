<?php
// login.php
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
$isLocked = false;
$user_ip = $_SERVER['REMOTE_ADDR'];
$now = new DateTime();

// 🟢 DEVICE RESTRICTION CHECK
$userAgent = $_SERVER['HTTP_USER_AGENT'];
$isMobile = preg_match('/Mobile|Android|BlackBerry|iPhone|iPod|Opera Mini|IEMobile/i', $userAgent);
$isAndroidTablet = preg_match('/Android/i', $userAgent) && !preg_match('/Mobile/i', $userAgent);

if ($isMobile || $isAndroidTablet) {
    // iPads are usually not caught by 'Mobile' but caught by 'Macintosh' with touch support or 'iPad'
    // This logic blocks iPhone/Android (Phone) and Android Tablets.
    // It allows Desktop (Windows/Mac) and iPad (iOS Tablet).
    $error = "🚨 Access Denied: Admin Dashboard is restricted to Desktop or Tablet (Non-Android) only.";
    $isLocked = true;
}

// 🟢 INITIAL IP LOCKOUT CHECK (Always runs, even on initial load)
$ip_stmt = $conn->prepare("SELECT * FROM ip_lockouts WHERE ip_address = ?");
$ip_stmt->bind_param("s", $user_ip);
$ip_stmt->execute();
$ip_res = $ip_stmt->get_result();
$ip_lock = $ip_res->fetch_assoc();
$ip_stmt->close();

if (isset($_GET['error'])) {
    if ($_GET['error'] === 'device_restricted') {
        $error = "🚨 Access Denied: Admin Dashboard is restricted to Desktop or Tablet (Non-Android) only.";
        $isLocked = true;
    } elseif ($_GET['error'] === 'multiple_tabs') {
        $error = "⚠️ Access Denied: You already have an active Admin session open in another tab.";
        $isLocked = true; // 🟢 FIX: Lock the form fields
    } elseif ($_GET['error'] === 'timeout') {
        $error = "⌛ Session Timeout: You have been logged out due to inactivity.";
    }
}

// 🟢 1. CHECK FOR GLOBAL ACTIVE SESSION (Cross-Browser Detection)
$global_check = $conn->query("SELECT last_activity, name FROM admin_user WHERE last_activity > DATE_SUB(NOW(), INTERVAL 2 MINUTE) LIMIT 1");
$active_admin = $global_check->fetch_assoc();

if ($active_admin && !isset($_SESSION['user'])) {
    $error = "⚠️ Access Denied: An active Admin session is currently open on another device or browser. Please close the other session first.";
    $isLocked = true;
}

// 🟢 2. PREVENT BYPASS by checking if session is already active in THIS browser
if (!$isLocked && isset($_SESSION['user'])) {
    $error = "⚠️ Access Denied: You already have an active Admin session open in another tab. Please close this tab.";
    $isLocked = true;
}

if ($ip_lock) {
    if ($ip_lock['blocked_until'] && $now < new DateTime($ip_lock['blocked_until'])) {
        $blockedUntil = new DateTime($ip_lock['blocked_until']);
        $remainingSeconds = $blockedUntil->getTimestamp() - $now->getTimestamp();
        $error = "IP blocked. Try again in <span id='countdown' data-seconds='$remainingSeconds'></span>";
        $isLocked = true;
    } elseif ($ip_lock['lockout_until'] && $now < new DateTime($ip_lock['lockout_until'])) {
        $lockoutUntil = new DateTime($ip_lock['lockout_until']);
        $remainingSeconds = $lockoutUntil->getTimestamp() - $now->getTimestamp();
        $error = "IP Suspended. Try again in <span id='countdown' data-seconds='$remainingSeconds'></span>";
        $isLocked = true;
    }
}

// 🟢 INITIAL ACCOUNT LOCKOUT CHECK (For the last attempted email)
if (!$isLocked && isset($_SESSION['last_attempted_email'])) {
    $lastEmail = $_SESSION['last_attempted_email'];
    $stmt_check = $conn->prepare("SELECT * FROM admin_user WHERE email = ?");
    $stmt_check->bind_param("s", $lastEmail);
    $stmt_check->execute();
    $u_res = $stmt_check->get_result();
    if ($user = $u_res->fetch_assoc()) {
        if ($user['blocked_until'] && $now < new DateTime($user['blocked_until'])) {
            $blockedUntil = new DateTime($user['blocked_until']);
            $remainingSeconds = $blockedUntil->getTimestamp() - $now->getTimestamp();
            $error = "Account blocked. Try again in <span id='countdown' data-seconds='$remainingSeconds'></span>";
            $isLocked = true;
        } elseif ($user['lockout_until'] && $now < new DateTime($user['lockout_until'])) {
            $lockoutUntil = new DateTime($user['lockout_until']);
            $remainingSeconds = $lockoutUntil->getTimestamp() - $now->getTimestamp();
            $error = "Too many failed attempts. Suspended for <span id='countdown' data-seconds='$remainingSeconds'></span>";
            $isLocked = true;
        }
    }
    $stmt_check->close();
}

// 🟢 GLOBAL EXCLUSIVE ACCESS CHECK: Disable login fields if ANY admin is active
if (!$isLocked) {
    // Check for the most recent activity across all admin accounts
    $act_stmt = $conn->prepare("SELECT last_activity, name, last_ip FROM admin_user WHERE last_activity IS NOT NULL ORDER BY last_activity DESC LIMIT 1");
    $act_stmt->execute();
    $act_res = $act_stmt->get_result();
    
    // (Exclusive Access Check removed in favor of Single-Session Token logic)
    $act_stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLocked) {
    $email = $_POST['email'];
    $password = $_POST['password'];
    $_SESSION['last_attempted_email'] = $email; // Store for refresh check

    // 1. Fetch User and Check Status
    $stmt = $conn->prepare("SELECT * FROM admin_user WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // (Exclusive Access Check removed in favor of Single-Session Token logic)

        if (!$isLocked && password_verify($password, $user['password'])) {

            // SUCCESS: Reset all failure counters (ACCOUNT + IP)
            $reset_stmt = $conn->prepare("UPDATE admin_user SET failed_attempts = 0, lockout_until = NULL, suspension_count = 0, blocked_until = NULL WHERE ID = ?");
            $reset_stmt->bind_param("i", $user['ID']);
            $reset_stmt->execute();
            $reset_stmt->close();

            $ip_reset = $conn->prepare("DELETE FROM ip_lockouts WHERE ip_address = ?");
            $ip_reset->bind_param("s", $user_ip);
            $ip_reset->execute();
            $ip_reset->close();

            // 🟢 SECURITY FIX: Single Session Token 🟢
            // 1. Generate unique token
            $session_token = bin2hex(random_bytes(32));

            // 2. Save token to Database (This invalidates other sessions)
            $update_stmt = $conn->prepare("UPDATE admin_user SET active_session_id = ? WHERE ID = ?");
            $update_stmt->bind_param("si", $session_token, $user['ID']);
            $update_stmt->execute();
            $update_stmt->close();

            // 3. Save token to Session (to compare later)
            $_SESSION['active_session_id'] = $session_token;
            // ----------------------------------------

            // 2. Generate OTP
            $otp = rand(100000, 999999);

            // 3. STORE OTP IN SESSION
            $_SESSION['auth_otp'] = $otp;
            $_SESSION['auth_otp_expiry'] = time() + (10 * 60); // 10 minutes

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

                // 🟢 RELAX SSL VERIFICATION (Workaround for some server configs)
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
                $mail->Subject = 'Your Admin Login OTP Code';
                $mail->Body = "
                    <h2>Admin Authentication</h2>
                    <p>Hello {$user['name']},</p>
                    <p>Your login verification code is:</p>
                    <h1 style='color: #2563EB; letter-spacing: 5px;'>{$otp}</h1>
                    <p>This code expires in 10 minutes.</p>
                ";

                $mail->send();

                // 5. Store temp user info and Redirect
                $db_id = isset($user['id']) ? $user['id'] : $user['ID'];

                $_SESSION['temp_user'] = [
                    'id' => $db_id,
                    'name' => $user['name'],
                    'email' => $email
                ];

            header('Location: auth.php');
                exit();

            } catch (Exception $e) {
                $error = "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
            }
        } else {
            // FAILURE: Wrong Password
            $attempts = $user['failed_attempts'] + 1;
            $suspensions = $user['suspension_count'];
            $lockout_until = null;
            $blocked_until = null;

            if ($attempts >= 5) {
                $suspensions++;
                $attempts = 0;
                $lockoutDate = (new DateTime())->modify('+10 minutes');
                $lockout_until = $lockoutDate->format('Y-m-d H:i:s');
                $isLocked = true;
                
                if ($suspensions >= 3) {
                    $blockedDate = (new DateTime())->modify('+1 day');
                    $blocked_until = $blockedDate->format('Y-m-d H:i:s');
                    $suspensions = 0;
                    $remainingSeconds = $blockedDate->getTimestamp() - $now->getTimestamp();
                    $error = "Maximum suspensions reached. Account blocked for <span id='countdown' data-seconds='$remainingSeconds'></span>";
                } else {
                    $remainingSeconds = $lockoutDate->getTimestamp() - $now->getTimestamp();
                    $error = "Too many failed attempts. Login suspended for <span id='countdown' data-seconds='$remainingSeconds'></span>";
                }
            } else {
                $remaining = 5 - $attempts;
                $error = "Invalid password. {$remaining} attempts remaining before suspension.";
            }

            $up_stmt = $conn->prepare("UPDATE admin_user SET failed_attempts = ?, suspension_count = ?, lockout_until = ?, blocked_until = ? WHERE ID = ?");
            $up_stmt->bind_param("iissi", $attempts, $suspensions, $lockout_until, $blocked_until, $user['ID']);
            $up_stmt->execute();
            $up_stmt->close();

            // 🔴 Update IP Tracker TOO
            track_ip_failure($conn, $user_ip);
        }
    } else {
        $error = 'User not found.';
        // 🔴 Still track IP failure for non-existent users
        track_ip_failure($conn, $user_ip);
    }
    $stmt->close();
}

// 🟢 HELPER: IP TRACKER FUNCTION
function track_ip_failure($conn, $ip) {
    $now = new DateTime();
    $stmt = $conn->prepare("SELECT * FROM ip_lockouts WHERE ip_address = ?");
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $res = $stmt->get_result();
    $lock = $res->fetch_assoc();
    $stmt->close();

    if (!$lock) {
        $ins = $conn->prepare("INSERT INTO ip_lockouts (ip_address, failed_attempts) VALUES (?, 1)");
        $ins->bind_param("s", $ip);
        $ins->execute();
        $ins->close();
    } else {
        $attempts = $lock['failed_attempts'] + 1;
        $suspensions = $lock['suspension_count'];
        $lockout_until = null;
        $blocked_until = null;

        if ($attempts >= 5) {
            $suspensions++;
            $attempts = 0;
            $lockout_until = (new DateTime())->modify('+10 minutes')->format('Y-m-d H:i:s');
            if ($suspensions >= 3) {
                $blocked_until = (new DateTime())->modify('+1 day')->format('Y-m-d H:i:s');
                $suspensions = 0;
            }
        }

        $up = $conn->prepare("UPDATE ip_lockouts SET failed_attempts = ?, suspension_count = ?, lockout_until = ?, blocked_until = ? WHERE ip_address = ?");
        $up->bind_param("iisss", $attempts, $suspensions, $lockout_until, $blocked_until, $ip);
        $up->execute();
        $up->close();
    }
}

render_page: // Jump target for blocked users
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMV - Admin Login Access</title>
    <link rel="icon" type="image/png" href="../../IMG/5.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../STYLE/styles.css">

    <script type="module" src="https://cdn.jsdelivr.net/npm/ldrs/dist/auto/ring.js"></script>

    <style>
        /* --- BACKGROUND SLIDER STYLES --- */
        body, html {
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

        .login-form {
            background: rgba(255, 255, 255, 0.95);
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            position: relative;
            z-index: 10;
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
            text-shadow: 0 2px 5px rgba(0,0,0,0.5);
        }

        /* Locked Field Styles */
        .locked-field {
            background-color: #f3f4f6 !important;
            cursor: not-allowed !important;
            opacity: 0.7;
        }
        .login-button:disabled {
            background-color: #9ca3af !important;
            cursor: not-allowed !important;
        }

        /* Password Peeking Styles */
        .password-container {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }
        .password-container input {
            padding-right: 45px !important;
            width: 100%;
        }
        .password-toggle {
            position: absolute;
            right: 12px;
            cursor: pointer;
            color: #6b7280;
            transition: all 0.3s ease;
            z-index: 5;
            padding: 8px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .password-toggle:hover {
            color: #2563eb;
            background: rgba(37, 99, 235, 0.1);
        }
        .password-toggle i {
            transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .password-toggle.active i {
            transform: scale(1.2);
            color: #2563eb;
        }

        /* Smooth reveal animation for the password field */
        @keyframes peekReveal {
            from { opacity: 0.5; transform: translateY(2px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .peeking {
            animation: peekReveal 0.3s ease forwards;
        }

        /* Forgot Password Link Styling */
        .forgot-password-link {
            display: inline-block;
            color: #6b7280;
            font-size: 0.85rem;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            margin-top: -5px;
        }
        .forgot-password-link:hover {
            color: #B88E2F;
            transform: translateX(-2px);
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
                    <img src="../../IMG/4.png" alt="AMV Logo" style="height: 64px; width: auto; display: block; margin: 0 auto;">
                </div>
                <span class="brand-text" style="color: #fff; font-weight: 700; font-size: 1.5rem; margin-left: 10px;">AMV</span>
            </div>
        </header>

        <main class="main-content">
            <div class="login-form">
                <h1 class="form-title">Admin Login Access</h1>
                <?php if ($error): ?>
                    <div class="error-message" style="color:red; margin-bottom:15px; text-align:center; background: #fee2e2; padding: 10px; border-radius: 8px; font-weight: 600;">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="form" id="loginForm">
                    <div class="form-group">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" id="email" name="email" class="form-input <?php echo $isLocked ? 'locked-field' : ''; ?>" <?php echo $isLocked ? 'disabled' : ''; ?> required>
                    </div>
                    <div class="form-group">
                        <label for="password" class="form-label">Password</label>
                        <div class="password-container">
                            <input type="password" id="password" name="password" 
                                   class="form-input <?php echo $isLocked ? 'locked-field' : ''; ?>" 
                                   <?php echo $isLocked ? 'disabled' : ''; ?> required>
                            <span class="password-toggle" id="togglePassword">
                                <i class="fa-solid fa-eye" id="eyeIcon"></i>
                            </span>
                        </div>
                    </div>
                    <div style="text-align: right; margin-bottom: 25px;">
                        <a href="<?php echo $isLocked ? 'javascript:void(0)' : 'forgot_password.php'; ?>" 
                           class="forgot-password-link <?php echo $isLocked ? 'locked-field' : ''; ?>"
                           <?php echo $isLocked ? 'style="pointer-events: none; opacity: 0.6;"' : ''; ?>>
                            <i class="fa-solid fa-lock" style="font-size: 0.75rem; margin-right: 4px; opacity: 0.7;"></i>
                            Forgot Password?
                        </a>
                    </div>
                    
                    <button type="submit" class="login-button" id="loginBtn" 
                            <?php echo $isLocked ? 'disabled' : ''; ?>
                            style="display:flex; justify-content:center; align-items:center; gap:10px;">
                        Login
                    </button>
                </form>
            </div>
        </main>
    </div>

    <script>
        // LIVE COUNTDOWN TIMER
        document.addEventListener("DOMContentLoaded", function () {
            const timerEl = document.getElementById('countdown');
            if (timerEl) {
                let seconds = parseInt(timerEl.getAttribute('data-seconds'));

                const updateTimer = () => {
                    if (seconds <= 0) {
                        location.reload();
                        return;
                    }

                    const h = Math.floor(seconds / 3600);
                    const m = Math.floor((seconds % 3600) / 60);
                    const s = seconds % 60;

                    let timeStr = "";
                    if (h > 0) timeStr += `${h}h `;
                    if (m > 0 || h > 0) timeStr += `${m}m `;
                    timeStr += `${s}s`;

                    timerEl.textContent = timeStr;
                    seconds--;
                };

                updateTimer();
                setInterval(updateTimer, 1000);
            }
        });

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

        // PASSWORD TOGGLE & PEAKING ANIMATION
        document.addEventListener("DOMContentLoaded", function () {
            const togglePassword = document.querySelector('#togglePassword');
            const password = document.querySelector('#password');
            const eyeIcon = document.querySelector('#eyeIcon');

            if (togglePassword && password) {
                togglePassword.addEventListener('click', function () {
                    // Toggle the type attribute
                    const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                    password.setAttribute('type', type);
                    
                    // Toggle the eye icon
                    eyeIcon.classList.toggle('fa-eye');
                    eyeIcon.classList.toggle('fa-eye-slash');
                    
                    // Toggle active class for scaling animation
                    this.classList.toggle('active');

                    // Add peeking animation class to input
                    password.classList.remove('peeking');
                    void password.offsetWidth; // Trigger reflow
                    password.classList.add('peeking');
                });
            }
        });

        // BUTTON LOADER LOGIC
        document.getElementById('loginForm').onsubmit = function() {
            const btn = document.getElementById('loginBtn');
            btn.innerHTML = `
                <l-ring size="24" stroke="3" bg-opacity="0" speed="2" color="white"></l-ring>
                <span>Processing...</span>
            `;
            btn.style.opacity = '0.8';
            btn.style.cursor = 'not-allowed';
            setTimeout(() => { btn.disabled = true; }, 10);
        };
    </script>
</body>
</html>
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

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

// --- 🟢 NEW: Initialize Failed Logins Table if not exists ---
$conn->query("CREATE TABLE IF NOT EXISTS failed_logins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

$lockout_type = 'none'; 
$remaining_seconds = 0;
$daily_limit = 12;
$temp_limit = 4;

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    $check_email = $_POST['email'] ?? ($_SESSION['last_tried_email'] ?? '');
    
    if ($check_email) {
        $stmt_total = $conn->prepare("SELECT COUNT(*) FROM failed_logins WHERE email = ? AND DATE(attempt_time) = CURDATE()");
        $stmt_total->bind_param("s", $check_email);
        $stmt_total->execute();
        $stmt_total->bind_result($total_fails);
        $stmt_total->fetch();
        $stmt_total->close();

        if ($total_fails >= $daily_limit) {
            $lockout_type = 'daily';
            $error = "This email is blocked until tomorrow due to excessive failed attempts ($total_fails).";
        } 
        elseif ($total_fails > 0 && ($total_fails % $temp_limit == 0)) {
            $stmt_last = $conn->prepare("SELECT attempt_time FROM failed_logins WHERE email = ? ORDER BY attempt_time DESC LIMIT 1");
            $stmt_last->bind_param("s", $check_email);
            $stmt_last->execute();
            $stmt_last->bind_result($last_time);
            $stmt_last->fetch();
            $stmt_last->close();

            $last_attempt = strtotime($last_time);
            $diff = time() - $last_attempt;
            
            if ($diff < 60) {
                $lockout_type = 'temp';
                $remaining_seconds = 60 - $diff;
                $error = "Too many failed attempts. Inputs disabled for 1 minute.";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lockout_type === 'none') {
    $email = $_POST['email'];
    $password = $_POST['password'];
    $_SESSION['last_tried_email'] = $email;

    // 1. Check Credentials
    $stmt = $conn->prepare("SELECT * FROM admin_user WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password'])) {
            // SUCCESS
            $stmt_clear = $conn->prepare("DELETE FROM failed_logins WHERE email = ?");
            $stmt_clear->bind_param("s", $email);
            $stmt_clear->execute();
            $stmt_clear->close();

            // 2. Generate OTP
            $otp = rand(100000, 999999);
            $_SESSION['auth_otp'] = $otp;
            $_SESSION['auth_otp_expiry'] = time() + (10 * 60); 

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

                // 🟢 RELAX SSL VERIFICATION
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
                $mail->Body = "<h2>Admin Authentication</h2><p>Hello {$user['name']},</p><p>Your login verification code is:</p><h1 style='color: #2563EB; letter-spacing: 5px;'>{$otp}</h1><p>This code expires in 10 minutes.</p>";
                $mail->send();

                $db_id = isset($user['id']) ? $user['id'] : $user['ID'];
                $_SESSION['temp_user'] = ['id' => $db_id, 'name' => $user['name'], 'email' => $email];

                header('Location: auth.php');
                exit();
            } catch (Exception $e) {
                $error = "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
            }
                    } else {
                        $_SESSION['login_error'] = 'Invalid password.';
                        record_failed_attempt($conn, $email);
                        header("Location: login1.php");
                        exit;
                    }
                } else {
                    $_SESSION['login_error'] = 'User not found.';
                    record_failed_attempt($conn, $email);
                    header("Location: login1.php");
                    exit;
                }    $stmt->close();
}

function record_failed_attempt($conn, $email) {
    $stmt = $conn->prepare("INSERT INTO failed_logins (email) VALUES (?)");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->close();
}

$isDisabled = ($lockout_type !== 'none');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMV - Admin Login Access</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="../STYLE/styles.css">
</head>

<body>
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
            <div class="login-form">
                <h1 class="form-title">Admin Login Access</h1>
                <?php if ($error): ?>
                    <div class="error-message" style="color:red; margin-bottom:15px; text-align:center;">
                        <?php echo $error; ?></div>
                <?php endif; ?>
                <form method="POST" class="form">
                    <div class="form-group">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" id="email" name="email" class="form-input" 
                               value="<?php echo ($lockout_type !== 'none') ? htmlspecialchars($check_email) : ''; ?>" 
                               <?php echo $isDisabled ? 'disabled' : ''; ?> required>
                    </div>
                    <div class="form-group">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" id="password" name="password" class="form-input" 
                               <?php echo $isDisabled ? 'disabled' : ''; ?> required>
                    </div>

                    <?php if ($lockout_type === 'temp'): ?>
                        <div id="lockoutTimer" style="text-align:center; margin-bottom:15px; font-weight:700; color:#DC2626;">
                            Unlock in: <span id="seconds"><?php echo $remaining_seconds; ?></span>s
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="login-button" 
                            <?php echo $isDisabled ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : ''; ?>>
                        Login
                    </button>
                </form>
            </div>
        </main>
    </div>

    <script>
        <?php if ($lockout_type === 'temp'): ?>
        let timeLeft = <?php echo $remaining_seconds; ?>;
        const timerSpan = document.getElementById('seconds');
        const interval = setInterval(() => {
            timeLeft--;
            if (timeLeft <= 0) {
                clearInterval(interval);
                location.reload();
            }
            if (timerSpan) timerSpan.innerText = timeLeft;
        }, 1000);
        <?php endif; ?>
    </script>
</body>

</html>
<?php
// confirmation.php

// 1. SET TIMEZONE
date_default_timezone_set('Asia/Manila');

// 2. Load PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../PHPMailer-master/src/Exception.php';
require '../PHPMailer-master/src/PHPMailer.php';
require '../PHPMailer-master/src/SMTP.php';

// 3. Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
// Allow quickchart.io for QR codes
header("Content-Security-Policy: default-src 'self'; connect-src 'self' https://nominatim.openstreetmap.org; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https://quickchart.io; upgrade-insecure-requests;");

// 4. Secure Session Settings
if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => true, // Ensure TRUE for production
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require 'db_connect.php';

// Disable errors for production (Enable for debugging)
ini_set('display_errors', 0);
error_reporting(E_ALL);

// --- 5. VALIDATE REFERENCE ---
// 🟢 NEW: Priority to Session for security, fallback to GET (if needed for older links, but sanitized)
$ref = $_SESSION['booking_success_ref'] ?? $_GET['ref'] ?? null;

if (!$ref) {
    header("Location: index.php");
    exit;
}

// 🟢 SESSION VALIDATION: Prevent unauthorized access even with a valid ref in URL
if (!isset($_SESSION['booking_success_ref']) || ($ref !== $_SESSION['booking_success_ref'])) {
    // If there is no session match, we don't allow viewing
    header("Location: index.php");
    exit;
}

$ref = trim($ref);

// Basic format validation (AMV-XXXXXX)
if (!preg_match('/^AMV-[A-F0-9]{6,}$/', $ref)) {
    // If ref format is obviously wrong, don't even query DB
    $success = false;
    $error_message = "Invalid booking reference format.";
} else {
    $success = false;
    $error_message = "";

    // --- 6. FETCH BOOKING DETAILS ---
    $sql = "SELECT 
                b.*, 
                bg.salutation, bg.first_name, bg.last_name, bg.email, bg.phone,
                u.account_source,
                GROUP_CONCAT(br.room_name SEPARATOR ', ') as room_names
            FROM bookings b
            JOIN booking_guests bg ON b.id = bg.booking_id
            JOIN booking_rooms br ON b.id = br.booking_id
            LEFT JOIN users u ON b.user_id = u.id
            WHERE b.booking_reference = ?
            GROUP BY b.id";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $ref);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $success = true;

        // Map DB columns
        $booking_reference = $row['booking_reference'];
        $salutation = $row['salutation'];
        $first_name = $row['first_name'];
        $last_name = $row['last_name'];
        $email = $row['email'];
        $checkin = $row['check_in'];
        $checkout = $row['check_out'];
        $roomNamesString = $row['room_names'];
        $total_price = $row['total_price'];
        $amount_paid = $row['amount_paid'];
        $payment_method = $row['payment_method'];
        $account_source = $row['account_source'] ?? 'guest';
        $booking_status = $row['status']; // 🟢 Capture Status

        // --- 7. SEND EMAIL & NOTIFICATIONS (RUNS ONLY ONCE) ---
        if (!isset($_SESSION['has_notified_' . $ref])) {

            try {
                // A. Insert Admin Dashboard Notification
                $notif_desc = "Guest $first_name $last_name booked $roomNamesString. Ref: $booking_reference";
                $stmtNotif = $conn->prepare("INSERT INTO system_notifications (title, description, type, is_read, created_at) VALUES ('New Online Booking', ?, 'booking', 0, NOW())");
                $stmtNotif->bind_param("s", $notif_desc);
                $stmtNotif->execute();
                $stmtNotif->close();

                // B. Insert App Notification (Only if user exists/has account)
                if (!empty($email)) {
                    $guest_title = ($booking_status === 'pending') ? "Booking Submitted" : "Booking Confirmed";
                    $guest_msg = ($booking_status === 'pending') 
                        ? "Your booking ($booking_reference) is pending verification. Check email for details."
                        : "Your booking ($booking_reference) is confirmed! See you soon.";
                    
                    // Default to 'guest' if source is null
                    $src = $account_source ?: 'guest';

                    $stmtApp = $conn->prepare("INSERT INTO guest_notifications (email, account_source, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, 'booking', 0, NOW())");
                    $stmtApp->bind_param("ssss", $email, $src, $guest_title, $guest_msg);
                    $stmtApp->execute();
                    $stmtApp->close();
                }

                // C. Send Email (PHPMailer)
                $mail = new PHPMailer(true);
                // Sanitize URL for QR
                $qrCodeUrl = "https://quickchart.io/qr?text=" . urlencode($booking_reference) . "&size=300&ecLevel=H";

                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'periolarren@gmail.com';
                $mail->Password = 'ftvp ilfl utmq pdgg'; // Note: Ensure this App Password is kept secret in production
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

                $mail->setFrom('periolarren@gmail.com', 'AMV Hotel Reservations');
                $mail->addAddress($email, "$first_name $last_name");

                $mail->isHTML(true);
                
                if ($booking_status === 'pending') {
                    $mail->Subject = "Booking Received - $booking_reference (Pending Verification)";
                    $statusHeader = "Booking Received";
                    $statusMessage = "Thank you for choosing AMV Hotel. Your reservation has been received and is currently <strong>pending verification</strong> of your payment proof.";
                    $qrSection = "
                        <div style='text-align: center; margin: 30px 0; background-color: #fff9e6; padding: 20px; border: 1px solid #ffeeba; border-radius: 8px;'>
                            <p style='margin: 0; font-weight: bold; color: #856404;'>Verification in Progress</p>
                            <p style='margin: 10px 0 0; font-size: 0.9em; color: #856404;'>Once our admin verifies your receipt, we will send you a second email containing your <strong>Check-in QR Code</strong>.</p>
                        </div>";
                    $arrivalInstruction = "Please wait for the confirmation email before proceeding to the hotel.";
                } else {
                    $mail->Subject = "Booking Confirmation - $booking_reference";
                    $statusHeader = "Booking Confirmed";
                    $statusMessage = "Thank you for choosing AMV Hotel. Your reservation has been successfully confirmed.";
                    $qrSection = "
                        <div style='text-align: center; margin: 30px 0; background-color: #f8f9fa; padding: 20px; border-radius: 8px;'>
                            <p style='margin-bottom: 10px; font-weight: bold; color: #555;'>Your Check-in QR Code</p>
                            <img src='$qrCodeUrl' alt='Booking QR Code' width='200' height='200'>
                            <h3 style='margin-top: 10px; color: #333; letter-spacing: 2px;'>$booking_reference</h3>
                        </div>";
                    $arrivalInstruction = "Please present this QR code at the front desk upon arrival.";
                }

                $mailBody = "
                <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #eee;'>
                    <div style='background-color: #9e8236; padding: 20px; text-align: center; color: white;'>
                        <h2 style='margin:0;'>$statusHeader</h2>
                    </div>
                    <div style='padding: 20px;'>
                        <p>Dear $salutation $first_name $last_name,</p>
                        <p>$statusMessage</p>
                        
                        $qrSection

                        <div style='background: #f9f9f9; padding: 20px; border-left: 4px solid #9e8236; line-height: 1.6;'>
                            <h4 style='margin-top: 0; color: #9e8236;'>Reservation Summary</h4>
                            <p><strong>Check-in:</strong> " . date('M d, Y', strtotime($checkin)) . "</p>
                            <p><strong>Check-out:</strong> " . date('M d, Y', strtotime($checkout)) . "</p>
                            <p><strong>Rooms:</strong> $roomNamesString</p>
                            <hr style='border: 0; border-top: 1px solid #ddd; margin: 10px 0;'>
                            <p><strong>Total Price:</strong> ₱" . number_format($total_price, 2) . "</p>
                            <p><strong>Amount Paid:</strong> ₱" . number_format($amount_paid, 2) . "</p>
                        </div>
                        
                        <p style='margin-top: 20px;'>$arrivalInstruction</p>
                        <p>Best Regards,<br><strong>AMV Hotel Management</strong></p>
                    </div>
                </div>";

                $mail->Body = $mailBody;
                $mail->send();

                // Mark as notified
                $_SESSION['has_notified_' . $ref] = true;

            } catch (Exception $e) {
                // Log error internally, don't show to user
                error_log("Email/Notif Error: " . $e->getMessage());
            }
        }
    } else {
        $success = false;
        $error_message = "Booking reference not found.";
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Booking Confirmation - AMV Hotel</title>
    <link rel="icon" type="image/png" href="../../IMG/5.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }

        .confirm-container {
            max-width: 800px;
            width: 100%;
            background: #fff;
            padding: 50px;
            border-radius: 8px;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.05);
            text-align: center;
            margin: 0;
            box-sizing: border-box;
        }

        .success-icon {
            font-size: 4rem;
            color: #4CAF50;
            margin-bottom: 20px;
        }

        .confirm-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .ref-box {
            background-color: #f9f9f9;
            border: 2px dashed #9e8236;
            padding: 20px;
            display: inline-block;
            margin-bottom: 30px;
            border-radius: 4px;
            max-width: 100%;
            box-sizing: border-box;
        }

        .ref-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #9e8236;
            letter-spacing: 2px;
            word-break: break-all;
        }

        .details-grid {
            text-align: left;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
            padding: 20px;
            background: #fcfcfc;
            border-radius: 4px;
        }

        .detail-item strong {
            display: block;
            font-size: 0.8rem;
            color: #888;
            text-transform: uppercase;
        }

        .detail-item span {
            font-size: 0.95rem;
            word-break: break-word;
        }

        /* --- NEW STATUS STYLES --- */
        .status-desc {
            color: #555;
            margin-bottom: 25px;
            font-size: 1.05rem;
            line-height: 1.6;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .pending-alert {
            color: #856404;
            background: #fff9e6;
            padding: 20px;
            border-radius: 12px;
            display: flex; /* 🟢 Changed to flex for side-by-side alignment */
            align-items: flex-start;
            gap: 15px;
            border: 1px solid #ffeeba;
            text-align: left;
            margin: 0 auto 30px;
            font-size: 0.95rem;
            max-width: 550px;
            box-shadow: 0 4px 12px rgba(133, 100, 4, 0.05);
            line-height: 1.5;
            box-sizing: border-box;
        }

        .pending-alert i {
            font-size: 1.2rem;
            color: #b8860b;
            margin-top: 2px; /* 🟢 Fine-tune vertical alignment with first line of text */
            flex-shrink: 0;
        }

        .pending-alert span {
            flex: 1;
        }

        .btn-home {
            background-color: #333;
            color: #fff;
            padding: 15px 40px;
            text-decoration: none;
            font-weight: 700;
            text-transform: uppercase;
            border-radius: 4px;
            display: inline-block;
            transition: 0.3s;
            max-width: 100%;
            box-sizing: border-box;
        }

        .btn-home:hover {
            background-color: #555;
        }

        .btn-retry {
            background-color: #d32f2f;
            color: #fff;
            padding: 15px 40px;
            text-decoration: none;
            font-weight: 700;
            text-transform: uppercase;
            border-radius: 4px;
            display: inline-block;
        }

        @media (max-width: 600px) {
            .confirm-container {
                padding: 30px 15px;
            }

            .confirm-title {
                font-size: 1.5rem;
            }

            .success-icon {
                font-size: 3rem;
            }

            .ref-number {
                font-size: 1.2rem;
                letter-spacing: 1px;
            }

            .status-desc {
                font-size: 0.95rem;
                padding: 0 5px;
            }

            .pending-alert {
                padding: 15px;
                font-size: 0.85rem;
            }

            .details-grid {
                grid-template-columns: 1fr;
                padding: 15px;
                gap: 15px;
            }

            .detail-item strong {
                font-size: 0.7rem;
            }

            .detail-item span {
                font-size: 0.9rem;
            }

            .btn-home {
                padding: 12px 30px;
                font-size: 0.9rem;
                width: 100%;
            }
        }

        /* 📱 ULTRA-SMALL CELLPHONES (e.g., iPhone SE) */
        @media (max-width: 380px) {
            .confirm-title {
                font-size: 1.3rem;
            }
            .ref-number {
                font-size: 1rem;
            }
            .status-desc {
                font-size: 0.85rem;
            }
            .pending-alert {
                font-size: 0.8rem;
                padding: 12px;
            }
            .detail-item span {
                font-size: 0.85rem;
            }
        }
    
        .app-promo-card {
            background: #f8f5f0;
            border: 1px solid #e0d8c8;
            padding: 40px;
            border-radius: 16px;
            margin: 40px 0;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 40px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
            box-sizing: border-box;
        }

        .app-promo-card::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 150px;
            height: 150px;
            background: rgba(184, 134, 11, 0.05);
            border-radius: 50%;
        }

        .app-promo-content {
            flex: 1;
        }

        .app-promo-title {
            color: #2D0F35;
            font-weight: 800;
            margin-bottom: 10px;
            font-size: 1.5rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            line-height: 1.2;
        }

        .app-promo-subtitle {
            color: #b8860b;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 20px;
            display: block;
        }

        .app-benefits {
            list-style: none;
            padding: 0;
            margin: 0 0 30px 0;
        }

        .app-benefits li {
            font-size: 0.9rem;
            color: #555;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            line-height: 1.4;
        }

        .app-benefits li i {
            color: #b8860b;
            font-size: 1rem;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        .btn-app-download {
            background-color: #2D0F35;
            color: #D4AF37;
            padding: 14px 30px;
            text-decoration: none;
            font-weight: 700;
            text-transform: uppercase;
            border-radius: 50px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            font-size: 0.9rem;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 8px 20px rgba(45, 15, 53, 0.2);
            width: fit-content;
            max-width: 100%;
            box-sizing: border-box;
        }

        .btn-app-download:hover {
            transform: translateY(-5px) scale(1.02);
            background-color: #3d1547;
            box-shadow: 0 12px 30px rgba(45, 15, 53, 0.3);
        }

        .app-visual {
            flex: 0 0 120px;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .app-icon-wrapper {
            width: 100px;
            height: 100px;
            background: #fff;
            border-radius: 24px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 3.5rem;
            color: #b8860b;
            box-shadow: 0 10px 25px rgba(184, 134, 11, 0.15);
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        @media (max-width: 768px) {
            .app-promo-card {
                flex-direction: column;
                padding: 30px 20px;
                text-align: center;
                gap: 30px;
            }

            .app-promo-title {
                font-size: 1.2rem;
            }

            .app-benefits li {
                justify-content: flex-start;
                font-size: 0.85rem;
            }

            .app-visual {
                order: -1;
            }

            .btn-app-download {
                width: 100%;
                font-size: 0.85rem;
                padding: 12px 20px;
            }
        }
    </style>
</head>

<body>

    <div class="confirm-container">
        <?php if ($success): ?>
            <?php if ($booking_status === 'pending'): ?>
                <div class="success-icon" style="color: #f1c40f;"><i class="fa-regular fa-clock"></i></div>
                <h1 class="confirm-title">Booking Received!</h1>

                <p class="status-desc">
                    Your reservation has been received and is <strong>pending verification</strong>. 
                    An email has been sent to <strong><?php echo htmlspecialchars($email); ?></strong>.
                </p>

                <div class="pending-alert">
                    <i class="fa-solid fa-circle-info"></i>
                    <span>
                        Please wait for our admin to verify your payment. 
                        Once verified, you will receive your <strong>Check-in QR Code</strong> via email.
                    </span>
                </div>
            <?php else: ?>
                <div class="success-icon"><i class="fa-regular fa-circle-check"></i></div>
                <h1 class="confirm-title">Booking Confirmed!</h1>

                <p class="status-desc">
                    Your reservation has been saved. A confirmation email with your <strong>Check-in QR Code</strong> has been
                    sent to <strong><?php echo htmlspecialchars($email); ?></strong>.
                </p>
            <?php endif; ?>

            <div class="ref-box">
                <span style="display:block; font-size:0.8rem; color:#888; text-transform:uppercase;">Booking
                    Reference</span>
                <span class="ref-number"><?php echo htmlspecialchars($booking_reference); ?></span>
            </div>

            <div class="details-grid">
                <div class="detail-item">
                    <strong>Guest Name</strong>
                    <span><?php echo htmlspecialchars($salutation . " " . $first_name . " " . $last_name); ?></span>
                </div>
                <div class="detail-item">
                    <strong>Check-in</strong>
                    <span><?php echo htmlspecialchars(date('M d, Y', strtotime($checkin))); ?></span>
                </div>
                <div class="detail-item">
                    <strong>Check-out</strong>
                    <span><?php echo htmlspecialchars(date('M d, Y', strtotime($checkout))); ?></span>
                </div>
                <div class="detail-item">
                    <strong>Rooms</strong>
                    <span><?php echo htmlspecialchars($roomNamesString); ?></span>
                </div>
                <div class="detail-item">
                    <strong>Total Price</strong>
                    <span style="color:#333; font-weight:700;">₱<?php echo number_format($total_price, 2); ?></span>
                </div>
                <div class="detail-item">
                    <strong>Amount Paid</strong>
                    <span style="color:#9e8236; font-weight:700;">₱<?php echo number_format($amount_paid, 2); ?></span>
                </div>
                <div class="detail-item">
                    <strong>Payment Method</strong>
                    <span><?php echo htmlspecialchars($payment_method); ?></span>
                </div>
            </div>

            <?php if ($booking_status !== 'pending'): ?>
                <div style="margin-bottom: 30px; font-size: 0.9rem; color: #777;">
                    <i class="fa-solid fa-circle-info"></i> Please present your ID and the QR code from your email upon arrival.
                </div>
            <?php endif; ?>

            <!-- 🟢 ENHANCED MOBILE APP PROMO -->
            <div class="app-promo-card">
                <div class="app-promo-content">
                    <span class="app-promo-subtitle">Make your stay even better</span>
                    <div class="app-promo-title">Get the AMV Hotel App</div>
                    <ul class="app-benefits">
                        <li><i class="fa-solid fa-utensils"></i> Instant Room Service Ordering</li>
                        <li><i class="fa-solid fa-message"></i> Direct Chat with Front Desk</li>
                        <li><i class="fa-solid fa-bell"></i> Real-time Booking & Order Updates</li>
                        <li><i class="fa-solid fa-newspaper"></i> Exclusive Promos & Hotel News</li>
                    </ul>
                    <a href="javascript:void(0)" onclick="secureDownload()" class="btn-app-download">
                        <i class="fa-brands fa-android"></i> Get the Android App
                    </a>
                </div>
                <div class="app-visual">
                    <div class="app-icon-wrapper">
                        <i class="fa-solid fa-mobile-screen-button"></i>
                    </div>
                </div>
            </div>

            <script>
            function secureDownload() {
                fetch('prep_download.php')
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'authorized') {
                            window.open('app_get.php', '_blank');
                        }
                    })
                    .catch(err => {
                        console.error('Download prep failed:', err);
                        window.open('app_get.php', '_blank');
                    });
            }
            </script>

            <a href="index.php" class="btn-home">Back to Home</a>

        <?php else: ?>
            <div style="color:red; margin-bottom:20px;">
                <i class="fa-solid fa-circle-exclamation" style="font-size:3rem; margin-bottom:15px;"></i>
                <h2 style="color: #333;">Booking Not Found</h2>
                <p><?php echo htmlspecialchars($error_message); ?></p>
            </div>
            <a href="check_availability.php" class="btn-retry">Try Again</a>
        <?php endif; ?>
    </div>

    <script>
    // 🟢 URL MASKING: Immediately remove the 'ref' parameter from the address bar
    // This prevents the URL from being copy-pasted with the secret reference
    if (window.history.replaceState) {
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({path: cleanUrl}, '', cleanUrl);
    }
    </script>

</body>

</html>
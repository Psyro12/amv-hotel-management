<?php
// confirmation.php

// 1. Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:;");

// 2. Secure Session Settings
$cookieParams = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => $cookieParams['lifetime'],
    'path' => $cookieParams['path'],
    'domain' => $cookieParams['domain'],
    'secure' => false, // Set to TRUE for live HTTPS
    'httponly' => true,
    'samesite' => 'Strict'
]);

session_start();

// Database Connection
require 'db_connect.php'; 

// 3. Disable Error Reporting (Security)
ini_set('display_errors', 0);
error_reporting(E_ALL);

// --- 4. SECURITY CHECK: CSRF ---
// If the token check fails, stop everything.
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        // Log this event security violation
        error_log("CSRF Token Mismatch for user session: " . session_id());
        die("Security check failed (CSRF). Please go back and try again.");
    }
} else {
    header("Location: index.php");
    exit;
}

// --- 5. PROCESS FORM SUBMISSION ---
$booking_reference = "";
$success = false;
$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Get Data & Sanitize
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1; 
    
    $checkin = $_POST['checkin'];
    $checkout = $_POST['checkout'];
    $total_price = (float)$_POST['total_price']; // Cast to float for safety
    $payment_method = htmlspecialchars($_POST['payment_method']);
    
    // Validate Room Data
    $selected_rooms = json_decode($_POST['selected_rooms'], true);
    if (!is_array($selected_rooms)) {
        die("Invalid room data.");
    }

    // Guest Info Inputs (Sanitized)
    $salutation = htmlspecialchars($_POST['salutation']);
    $first_name = htmlspecialchars($_POST['first_name']);
    $last_name = htmlspecialchars($_POST['last_name']);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $phone = htmlspecialchars($_POST['contact_number']);
    $nationality = htmlspecialchars($_POST['nationality']);
    $gender = htmlspecialchars($_POST['gender']);
    $birthdate = htmlspecialchars($_POST['birthdate']);
    $address = htmlspecialchars($_POST['address']);
    $arrival_time = htmlspecialchars($_POST['arrival_time']);
    $special_requests = htmlspecialchars($_POST['requests']);
    $adults = (int)$_POST['adults'];
    $children = (int)$_POST['children'];

    // Generate Reference
    $booking_reference = 'AMV-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));

    // --- TRANSACTION ---
    $conn->begin_transaction();

    try {
        // A. Insert into 'bookings' 
        $stmt1 = $conn->prepare("INSERT INTO bookings (user_id, check_in, check_out, total_price, payment_method, booking_reference, status) VALUES (?, ?, ?, ?, ?, ?, 'confirmed')");
        if (!$stmt1) throw new Exception("Prepare failed (Bookings): " . $conn->error);
        
        $stmt1->bind_param("issdss", $user_id, $checkin, $checkout, $total_price, $payment_method, $booking_reference);
        if (!$stmt1->execute()) throw new Exception("Execute failed (Bookings): " . $stmt1->error);
        
        $booking_id = $conn->insert_id;
        $stmt1->close();

        // B. Insert into 'booking_guests'
        $stmt2 = $conn->prepare("INSERT INTO booking_guests (booking_id, salutation, first_name, last_name, email, phone, nationality, gender, birthdate, address, arrival_time, special_requests, adults_count, children_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt2) throw new Exception("Prepare failed (Guests): " . $conn->error);

        $stmt2->bind_param("isssssssssssii", $booking_id, $salutation, $first_name, $last_name, $email, $phone, $nationality, $gender, $birthdate, $address, $arrival_time, $special_requests, $adults, $children);
        if (!$stmt2->execute()) throw new Exception("Execute failed (Guests): " . $stmt2->error);
        $stmt2->close();

        // C. Insert into 'booking_rooms'
        if (!empty($selected_rooms)) {
            $stmt3 = $conn->prepare("INSERT INTO booking_rooms (booking_id, room_id, room_name, price_per_night) VALUES (?, ?, ?, ?)");
            if (!$stmt3) throw new Exception("Prepare failed (Rooms): " . $conn->error);

            foreach ($selected_rooms as $room) {
                // Ensure room name is safe string
                $safe_room_name = htmlspecialchars($room['name']);
                $stmt3->bind_param("iisd", $booking_id, $room['id'], $safe_room_name, $room['price']);
                if (!$stmt3->execute()) throw new Exception("Execute failed (Rooms): " . $stmt3->error);
            }
            $stmt3->close();
        }

        // Commit
        $conn->commit();
        $success = true;

    } catch (Exception $e) {
        $conn->rollback();
        // Log the actual error for debugging
        error_log("Booking Error: " . $e->getMessage());
        
        // Show generic message to user (security best practice)
        $error_message = "An error occurred while processing your booking. Please try again.";
        
        // --- EMERGENCY DEBUGGING ---
        // UNCOMMENT THE LINE BELOW if it still fails, to see the exact SQL error on screen.
        // $error_message = "DEBUG: " . $e->getMessage();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmation - AMV Hotel</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { font-family: 'Montserrat', sans-serif; background-color: #f5f5f5; margin: 0; color: #333; }
        
        /* HEADER */
        header { position: fixed; top: 0; width: 100%; padding: 15px 5%; display: flex; justify-content: space-between; align-items: center; z-index: 1000; background-color: #fff; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05); }
        .logo-container { display: flex; align-items: center; gap: 10px; }
        .logo-text span { color: #333; font-weight: 700; display: block; line-height: 1; }
        .logo-text span:first-child { font-size: 20px; color: #9e8236; }
        .logo-text span:last-child { font-size: 12px; letter-spacing: 1px; }
        nav { display: flex; align-items: center; }
        nav a { color: #333; text-decoration: none; margin-right: 25px; text-transform: uppercase; font-size: 0.8rem; font-weight: 600; letter-spacing: 1px; transition: color 0.3s; }
        
        /* CONTAINER */
        .confirm-container {
            max-width: 800px;
            margin: 120px auto 60px;
            background: #fff;
            padding: 50px;
            border-radius: 8px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.05);
            text-align: center;
        }

        .success-icon {
            font-size: 4rem;
            color: #4CAF50;
            margin-bottom: 20px;
        }

        .confirm-title {
            font-size: 2rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 10px;
        }

        .confirm-sub {
            color: #666;
            margin-bottom: 30px;
        }

        .ref-box {
            background-color: #f9f9f9;
            border: 2px dashed #9e8236;
            padding: 20px;
            display: inline-block;
            margin-bottom: 30px;
            border-radius: 4px;
        }

        .ref-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            color: #888;
            margin-bottom: 5px;
            display: block;
        }

        .ref-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #9e8236;
            letter-spacing: 2px;
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

        .detail-item strong { display: block; font-size: 0.8rem; color: #888; text-transform: uppercase; }
        .detail-item span { font-weight: 600; color: #333; }

        .btn-home {
            background-color: #333;
            color: #fff;
            padding: 15px 40px;
            text-decoration: none;
            font-weight: 700;
            text-transform: uppercase;
            border-radius: 4px;
            transition: 0.3s;
            display: inline-block;
        }
        .btn-home:hover { background-color: #555; }
    </style>
</head>
<body>

    <header>
        <div class="logo-container">
            <img src="../../IMG/5.png" alt="AMV Logo" style="height:40px;">
            <div class="logo-text">
                <span>AMV</span>
                <span>Hotel</span>
            </div>
        </div>
        <nav>
            <a href="index.php">Home</a>
            <div class="icon-circle"><i class="fa-solid fa-user"></i></div>
        </nav>
    </header>

    <div class="confirm-container">
        <?php if ($success): ?>
            <div class="success-icon"><i class="fa-regular fa-circle-check"></i></div>
            <h1 class="confirm-title">Booking Confirmed!</h1>
            <p class="confirm-sub">Thank you for choosing AMV Hotel. Your reservation has been successfully saved.</p>

            <div class="ref-box">
                <span class="ref-label">Booking Reference</span>
                <span class="ref-number"><?php echo $booking_reference; ?></span>
            </div>

            <div class="details-grid">
                <div class="detail-item">
                    <strong>Guest Name</strong>
                    <span><?php echo htmlspecialchars($salutation . " " . $first_name . " " . $last_name); ?></span>
                </div>
                <div class="detail-item">
                    <strong>Total Amount</strong>
                    <span style="color:#9e8236;">$<?php echo number_format($total_price, 2); ?></span>
                </div>
                <div class="detail-item">
                    <strong>Check-in</strong>
                    <span><?php echo date('M d, Y', strtotime($checkin)); ?></span>
                </div>
                <div class="detail-item">
                    <strong>Check-out</strong>
                    <span><?php echo date('M d, Y', strtotime($checkout)); ?></span>
                </div>
                <div class="detail-item">
                    <strong>Payment Method</strong>
                    <span><?php echo $payment_method; ?></span>
                </div>
            </div>

            <a href="index.php" class="btn-home">Back to Home</a>

        <?php else: ?>
            <div style="color:red; margin-bottom:20px;">
                <i class="fa-solid fa-circle-exclamation" style="font-size:3rem;"></i>
                <h2>Booking Failed</h2>
                <p>Sorry, something went wrong while processing your booking.</p>
                <p><?php echo $error_message; ?></p>
            </div>
            <a href="check_availability.php" class="btn-home">Try Again</a>
        <?php endif; ?>
    </div>

</body>
</html>
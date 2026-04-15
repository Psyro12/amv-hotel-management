<?php
session_start();

// Helper escape function
function h($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// Capture All Data (Booking + Guest)
$room_name      = $_POST['room_name'] ?? 'Unknown';
$check_in_date  = $_POST['check_in_date'] ?? 'N/A';
$check_out_date = $_POST['check_out_date'] ?? 'N/A';
$nights         = $_POST['nights'] ?? 0;
$total_price    = $_POST['total_price'] ?? 0;
$adults         = $_POST['adults'] ?? 0;
$children       = $_POST['children'] ?? 0;

$full_name      = $_POST['full_name'] ?? 'Guest';
$email          = $_POST['email'] ?? 'N/A';
$phone          = $_POST['phone'] ?? 'N/A';
$country        = $_POST['country'] ?? 'N/A';
$special_requests = $_POST['special_requests'] ?? 'None';

// Generate a fake Booking ID
$booking_ref = 'AMV-' . strtoupper(substr(md5(uniqid()), 0, 8));

// TODO: HERE IS WHERE YOU WOULD INSERT DATA INTO YOUR DATABASE
// $stmt = $conn->prepare("INSERT INTO bookings (...) VALUES (...)");
// $stmt->execute(...);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmation - AMV Hotel</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../STYLE/room_booking.css">
    <link rel="stylesheet" href="../STYLE/utilities.css">
    <style>
        .confirmation-box {
            background: white;
            max-width: 800px;
            margin: 40px auto;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
        }
        .success-icon {
            color: #27ae60;
            font-size: 4rem;
            margin-bottom: 20px;
        }
        .ref-number {
            background: #f1c40f;
            color: #2c3e50;
            padding: 10px 20px;
            border-radius: 4px;
            font-weight: 700;
            display: inline-block;
            margin: 20px 0;
            font-size: 1.2rem;
        }
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            text-align: left;
            gap: 20px;
            margin-top: 30px;
            border-top: 1px solid #eee;
            padding-top: 20px;
        }
        @media(max-width: 600px) {
            .details-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header>
        <div class="logo-container">
            <img src="../../IMG/5.png" alt="AMV Logo">
            <div class="logo-text"><span>AMV</span><span>Hotel</span></div>
        </div>
        <nav>
            <a href="home_page_test.php" class="btn" style="padding: 8px 15px;">Home</a>
        </nav>
    </header>

    <section class="hero" style="height: 30vh;">
        <div class="hero-content">
            <h1>Booking Confirmed</h1>
        </div>
    </section>

    <div class="container">
        <div class="confirmation-box">
            <i class="fa-solid fa-circle-check success-icon"></i>
            <h2>Thank You, <?php echo h($full_name); ?>!</h2>
            <p>Your reservation has been received. We look forward to seeing you.</p>

            <div class="ref-number">
                Booking Reference: <?php echo h($booking_ref); ?>
            </div>

            <div class="details-grid">
                <div>
                    <h4>Reservation Details</h4>
                    <p><strong>Room:</strong> <?php echo h($room_name); ?></p>
                    <p><strong>Check-in:</strong> <?php echo h($check_in_date); ?></p>
                    <p><strong>Check-out:</strong> <?php echo h($check_out_date); ?></p>
                    <p><strong>Guests:</strong> <?php echo h($adults); ?> Adults, <?php echo h($children); ?> Children</p>
                </div>
                <div>
                    <h4>Guest Details</h4>
                    <p><strong>Name:</strong> <?php echo h($full_name); ?></p>
                    <p><strong>Email:</strong> <?php echo h($email); ?></p>
                    <p><strong>Phone:</strong> <?php echo h($phone); ?></p>
                    <p><strong>Country:</strong> <?php echo h($country); ?></p>
                </div>
            </div>
            
            <div style="margin-top: 20px; text-align: left; border-top: 1px solid #eee; padding-top: 15px;">
                <p><strong>Special Requests:</strong> <?php echo h($special_requests); ?></p>
                <div class="total-price mt-3" style="text-align: center;">
                    <h3>Total Paid: ₱<?php echo number_format((float)$total_price, 2); ?></h3>
                </div>
            </div>

            <br>
            <button onclick="window.print()" class="btn" style="background:#3498db;">Print Confirmation</button>
            <a href="home_page_test.php" class="btn">Back to Home</a>
        </div>
    </div>

    <footer>
        <div class="container">
            <p>&copy; 2025 AMV Hotel. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
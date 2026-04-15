<?php
session_start();

// 1. Capture Data from Previous Page
$checkin = isset($_REQUEST['checkin']) ? $_REQUEST['checkin'] : '';
$checkout = isset($_REQUEST['checkout']) ? $_REQUEST['checkout'] : '';
$adults = isset($_REQUEST['adults']) ? $_REQUEST['adults'] : 1;
$children = isset($_REQUEST['children']) ? $_REQUEST['children'] : 0;
$selected_rooms_json = isset($_REQUEST['selected_rooms']) ? $_REQUEST['selected_rooms'] : '[]';
$total_price = isset($_REQUEST['total_price']) ? $_REQUEST['total_price'] : 0;

// Decode selected rooms
$selected_rooms = json_decode($selected_rooms_json, true);

// Format Dates
$nights = 0;
$formatted_checkin = "";
$formatted_checkout = "";

if($checkin && $checkout) {
    $date_in = new DateTime($checkin);
    $date_out = new DateTime($checkout);
    $interval = $date_in->diff($date_out);
    $nights = $interval->days;
    $formatted_checkin = $date_in->format('D, M d, Y');
    $formatted_checkout = $date_out->format('D, M d, Y');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Information - AMV Hotel</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        /* --- GLOBAL STYLES --- */
        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            color: #333;
        }

        * { box-sizing: border-box; }

        /* HEADER */
        header {
            position: fixed;
            top: 0;
            width: 100%;
            padding: 15px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
            background-color: #fff;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .logo-container { display: flex; align-items: center; gap: 10px; }
        .logo-text span { color: #333; font-weight: 700; display: block; line-height: 1; }
        .logo-text span:first-child { font-size: 20px; color: #9e8236; }
        .logo-text span:last-child { font-size: 12px; letter-spacing: 1px; }

        nav { display: flex; align-items: center; }
        nav a {
            color: #333; text-decoration: none; margin-right: 25px;
            text-transform: uppercase; font-size: 0.8rem; font-weight: 600;
            letter-spacing: 1px; transition: color 0.3s;
        }
        nav a:hover { color: #9e8236; }
        .icon-circle {
            color: #555; border: 1px solid #ddd; width: 35px; height: 35px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
        }

        /* --- STEPPER --- */
        .booking-stepper {
            margin-top: 80px;
            background-color: #fff;
            padding: 20px 0;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: center;
            gap: 50px;
        }

        .step-item {
            display: flex; flex-direction: column; align-items: center;
            color: #ccc; font-size: 0.85rem; font-weight: 500;
            text-transform: uppercase; letter-spacing: 1px;
        }
        
        .step-item.completed { color: #333; }
        .step-item.active { color: #9e8236; font-weight: 700; }

        .step-icon {
            width: 40px; height: 40px; border-radius: 50%;
            border: 2px solid #ccc; display: flex; align-items: center; justify-content: center;
            margin-bottom: 10px; font-size: 1.2rem;
        }

        .step-item.completed .step-icon { border-color: #333; background-color: #333; color: #fff; }
        .step-item.active .step-icon { border-color: #9e8236; background-color: #9e8236; color: #fff; }

        /* --- MAIN LAYOUT --- */
        .main-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: 2.5fr 1fr; /* Form is wider */
            gap: 30px;
        }

        /* --- LEFT COLUMN: FORM STYLING --- */
        .guest-form-container {
            background: #fff;
            padding: 30px;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #f0f0f0;
        }

        .form-title { font-size: 1.5rem; font-weight: 700; color: #333; margin: 0; }

        .btn-signin-header {
            background-color: #f0f0f0; color: #333; border: 1px solid #ddd;
            padding: 10px 20px; border-radius: 4px; font-size: 0.9rem;
            cursor: pointer; transition: 0.2s;
        }
        .btn-signin-header:hover { background-color: #e0e0e0; }

        .form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .form-grid-1 { margin-bottom: 20px; }

        .form-group { display: flex; flex-direction: column; }
        .form-label { font-size: 0.9rem; font-weight: 700; color: #444; margin-bottom: 8px; }
        .form-label span { color: #d32f2f; }

        .form-input, .form-select {
            padding: 12px; border: 1px solid #ddd; border-radius: 4px;
            font-family: 'Montserrat', sans-serif; font-size: 0.95rem;
            color: #555; background-color: #fff; width: 100%;
        }
        .form-input:focus, .form-select:focus { outline: none; border-color: #9e8236; }

        .checkbox-wrapper { display: flex; align-items: center; gap: 10px; margin-top: 20px; font-size: 1rem; color: #555; }
        .checkbox-wrapper input[type="checkbox"] { width: 18px; height: 18px; accent-color: #9e8236; }

        /* --- RIGHT COLUMN: SIDEBAR SUMMARY --- */
        .sidebar { position: sticky; top: 100px; height: fit-content; }

        .summary-box {
            background: #fff; border-radius: 4px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05); overflow: hidden;
            margin-bottom: 20px;
        }

        .summary-header {
            background-color: #333; color: #fff; padding: 20px;
            font-weight: 700; font-size: 1rem; text-transform: uppercase; letter-spacing: 1px;
        }

        .summary-content { padding: 25px; }
        .summary-item { margin-bottom: 20px; border-bottom: 1px solid #f0f0f0; padding-bottom: 15px; }
        .summary-item:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }

        .s-label { font-size: 0.75rem; color: #888; text-transform: uppercase; font-weight: 600; display: block; margin-bottom: 5px; }
        .s-value { font-size: 1rem; font-weight: 600; color: #333; }
        .s-room-name { font-size: 0.95rem; font-weight: 700; color: #9e8236; margin-bottom: 4px; display: block; }
        .s-room-price { font-size: 0.85rem; color: #555; float: right; }

        .total-wrapper {
            background-color: #f9f9f9; padding: 20px 25px; border-top: 1px solid #eee;
            display: flex; justify-content: space-between; align-items: center;
        }
        .total-label { font-weight: 700; color: #333; font-size: 1.1rem; }
        .total-amount { font-weight: 700; color: #9e8236; font-size: 1.4rem; }

        /* --- PAYMENT SECTION STYLES --- */
        .payment-box {
            background: #fff; border-radius: 4px; box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            padding: 20px;
        }
        .payment-title { font-weight: 700; color: #333; margin-bottom: 15px; }
        
        .payment-option {
            border: 1px solid #ddd; border-radius: 4px; padding: 15px;
            margin-bottom: 10px; cursor: pointer; transition: 0.2s;
            display: flex; align-items: center; gap: 10px;
        }
        
        .payment-option:hover { border-color: #9e8236; background-color: #fdfdfd; }
        
        .payment-option input[type="radio"] { accent-color: #9e8236; transform: scale(1.2); }
        
        .payment-logo {
            height: 25px; /* Adjust based on your actual images */
            width: auto;
            font-weight: 700; color: #0056e0; /* Fallback text color */
        }

        .btn-submit {
            width: 100%; padding: 15px; background-color: #9e8236; color: #fff;
            border: none; font-size: 1rem; font-weight: 700; text-transform: uppercase;
            cursor: pointer; border-radius: 4px; transition: 0.3s; margin-top: 20px;
        }
        .btn-submit:hover { background-color: #8c7330; }

        @media (max-width: 900px) {
            .main-container { grid-template-columns: 1fr; }
            .form-grid-3, .form-grid-2 { grid-template-columns: 1fr; }
            .sidebar { order: 1; } /* Form first, then payment/summary on mobile */
        }
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
            <a href="about_us.php">About</a>
            <a href="check_availability.php" style="color:#9e8236;">Book Now</a>
            <div class="nav-icons">
                <div class="icon-circle"><i class="fa-solid fa-user"></i></div>
            </div>
        </nav>
    </header>

    <div class="booking-stepper">
        <div class="step-item completed">
            <div class="step-icon"><i class="fa-regular fa-calendar-check"></i></div>
            Dates
        </div>
        <div class="step-item completed">
            <div class="step-icon"><i class="fa-solid fa-bed"></i></div>
            Select Rooms
        </div>
        <div class="step-item active">
            <div class="step-icon"><i class="fa-regular fa-id-card"></i></div>
            Guest Info
        </div>
        <div class="step-item">
            <div class="step-icon"><i class="fa-solid fa-check"></i></div>
            Confirmation
        </div>
    </div>

    <form action="confirmation.php" method="POST">
        <div class="main-container">

            <div class="left-col">
                <div class="guest-form-container">
                    <div class="form-header">
                        <h2 class="form-title">Personal Information</h2>
                        <button type="button" class="btn-signin-header">Sign in to book faster</button>
                    </div>

                    <div class="form-grid-3">
                        <div class="form-group">
                            <label class="form-label">Salutation<span>*</span></label>
                            <select class="form-select" name="salutation" required>
                                <option value="">- Select -</option>
                                <option>Mr.</option>
                                <option>Ms.</option>
                                <option>Mrs.</option>
                                <option>Dr.</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">First Name<span>*</span></label>
                            <input type="text" class="form-input" name="first_name" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Last Name<span>*</span></label>
                            <input type="text" class="form-input" name="last_name" required>
                        </div>
                    </div>

                    <div class="form-grid-3">
                        <div class="form-group">
                            <label class="form-label">Gender<span>*</span></label>
                            <select class="form-select" name="gender" required>
                                <option value="">- Select -</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Prefer not to say</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Birthdate<span>*</span></label>
                            <input type="date" class="form-input" name="birthdate" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Nationality<span>*</span></label>
                            <select class="form-select" name="nationality" required>
                                <option value="">- Select -</option>
                                <option value="Filipino">Filipino</option>
                                <option value="American">American</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Email Address<span>*</span></label>
                            <input type="email" class="form-input" name="email" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Re-type Email Address<span>*</span></label>
                            <input type="email" class="form-input" name="retype_email" required>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Contact Number<span>*</span></label>
                            <input type="tel" class="form-input" name="contact_number" placeholder="+63-901-2345678" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Estimated Arrival Time<span>*</span></label>
                            <select class="form-select" name="arrival_time" required>
                                <option value="">- Select -</option>
                                <option>Standard Check-in (2:00 PM)</option>
                                <option>Late Arrival (After 6:00 PM)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid-1">
                        <div class="form-group">
                            <label class="form-label">Address<span>*</span></label>
                            <input type="text" class="form-input" name="address" required>
                        </div>
                    </div>

                    <div class="form-grid-1">
                        <div class="form-group">
                            <label class="form-label">Special Requests</label>
                            <textarea class="form-input" name="requests" style="height:100px; resize:vertical;"></textarea>
                        </div>
                    </div>

                    <div class="checkbox-wrapper">
                        <input type="checkbox" name="specify_guest_names" id="guestNamesCheck">
                        <label for="guestNamesCheck">Specify Guest Name Per Room</label>
                    </div>
                </div>
            </div>

            <div class="sidebar">
                
                <div class="summary-box">
                    <div class="summary-header">Your Booking</div>
                    <div class="summary-content">
                        <div class="summary-item">
                            <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                                <div>
                                    <span class="s-label">Check-in</span>
                                    <span class="s-value"><?php echo $formatted_checkin; ?></span>
                                </div>
                                <div style="text-align:right;">
                                    <span class="s-label">Check-out</span>
                                    <span class="s-value"><?php echo $formatted_checkout; ?></span>
                                </div>
                            </div>
                            <div style="font-size:0.85rem; color:#666; margin-top:5px;">
                                <?php echo $nights; ?> Night(s)
                            </div>
                        </div>

                        <div class="summary-item">
                            <span class="s-label">Guests</span>
                            <span class="s-value"><?php echo $adults; ?> Adults, <?php echo $children; ?> Children</span>
                        </div>

                        <div class="summary-item">
                            <span class="s-label">Selected Rooms</span>
                            <?php if (!empty($selected_rooms)): ?>
                                <?php foreach($selected_rooms as $room): ?>
                                    <div style="margin-top: 8px; border-bottom: 1px dashed #eee; padding-bottom: 5px;">
                                        <span class="s-room-name"><?php echo htmlspecialchars($room['name']); ?></span>
                                        <span class="s-room-price">$<?php echo $room['price']; ?> / night</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="s-value">No room selected</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="total-wrapper">
                        <span class="total-label">TOTAL</span>
                        <span class="total-amount">$<?php echo number_format($total_price, 2); ?></span>
                    </div>
                </div>

                <div class="payment-box">
                    <div class="payment-title">Payment Method</div>
                    
                    <label class="payment-option">
                        <input type="radio" name="payment_method" value="GCash" required checked>
                        <span style="font-weight:600; color:#007bff;">GCash</span>
                    </label>

                    <label class="payment-option">
                        <input type="radio" name="payment_method" value="Maya" required>
                        <span style="font-weight:600; color:#000;">Maya</span>
                    </label>
                </div>

                <input type="hidden" name="checkin" value="<?php echo $checkin; ?>">
                <input type="hidden" name="checkout" value="<?php echo $checkout; ?>">
                <input type="hidden" name="adults" value="<?php echo $adults; ?>">
                <input type="hidden" name="children" value="<?php echo $children; ?>">
                <input type="hidden" name="total_price" value="<?php echo $total_price; ?>">
                <input type="hidden" name="selected_rooms" value='<?php echo $selected_rooms_json; ?>'>

                <button type="submit" class="btn-submit">CONFIRM & BOOK <i class="fa-solid fa-chevron-right"></i></button>

            </div>

        </div>
    </form>

    <footer>
        <div style="background:#333; color:#fff; padding:30px; text-align:center;">
            <p>© 2025 AMV Hotel. All rights reserved.</p>
        </div>
    </footer>

</body>
</html>
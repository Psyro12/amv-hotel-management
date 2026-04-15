<?php
session_start();

// Helper escape function
function h($s)
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// 1. Capture data sent from room_booking_test.php
$room_name = $_POST['room_name'] ?? 'Unknown Room';
$price_per_night = $_POST['price_per_night'] ?? 0;
$check_in_date = $_POST['check_in_date'] ?? date('Y-m-d');
$check_out_date = $_POST['check_out_date'] ?? date('Y-m-d', strtotime('+1 day'));
$adults = $_POST['adults'] ?? 1;
$children = $_POST['children'] ?? 0;
$nights = $_POST['nights'] ?? 0;
$total_price = $_POST['total_price'] ?? 0;

// Format dates for display
$date_fmt_in = date("M d, Y", strtotime($check_in_date));
$date_fmt_out = date("M d, Y", strtotime($check_out_date));
$date_range = $date_fmt_in . ' - ' . $date_fmt_out;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Information - AMV Hotel</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../STYLE/room_booking.css">
    <link rel="stylesheet" href="../STYLE/utilities.css">

    <style>
        /* --- HERO ADJUSTMENTS --- */
        /* Ensure hero uses flex column to stack content + progress bar */
        .hero {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            /* Ensure height is sufficient */
            /* min-height: 40vh;  */
            padding: 20px 0;
            gap: 20px;
        }

        /* --- PROGRESS INDICATOR (High Contrast) --- */
        .progress-track {
            display: flex;
            justify-content: center;
            width: 100%;
            max-width: 600px;
            position: relative;
            z-index: 10;
        }

        .progress-step {
            flex: 1;
            text-align: center;
            position: relative;
            z-index: 1;
        }

        /* Connecting Lines (Semi-transparent White) */
        .progress-step::before {
            content: '';
            position: absolute;
            top: 15px;
            right: 50%;
            width: 92%;
            height: 3px;
            background-color: rgba(255, 255, 255, 0.4);
            z-index: -1;
        }

        .progress-step:first-child::before {
            display: none;
        }

        /* Circle Base Style */
        .step-circle {
            width: 35px;
            height: 35px;
            background-color: rgba(255, 255, 255, 0.2);
            color: #fff;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto 8px auto;
            font-weight: 700;
            border: 2px solid rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(4px);
        }

        /* Labels (White Text with Shadow) */
        .step-label {
            font-size: 0.9rem;
            color: #fff;
            font-weight: 500;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.6);
        }

        /* ACTIVE State */
        .active .step-circle {
            background-color: #FFA500;
            /* Orange */
            color: white;
            border-color: #fff;
            box-shadow: 0 0 0 4px rgba(255, 165, 0, 0.3);
        }

        .active .step-label {
            color: #FFA500;
            font-weight: 700;
        }

        /* COMPLETED State */
        .completed .step-circle {
            background-color: #27ae60;
            /* Green */
            color: white;
            border-color: #27ae60;
        }

        .completed .step-label {
            color: #27ae60;
            font-weight: 600;
        }

        .completed::before,
        .active::before {
            background-color: #27ae60;
        }



        /* --- FORM LAYOUT STYLES --- */
        .personal-info-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
        }

        .signin-btn {
            background-color: #f0f0f0;
            color: #333;
            font-size: 0.85rem;
            padding: 8px 15px;
            border: 1px solid #ccc;
            border-radius: 4px;
            cursor: pointer;
        }

        .form-row {
            display: grid;
            gap: 20px;
            margin-bottom: 20px;
        }

        .row-name {
            grid-template-columns: 1fr 2fr 2fr;
        }

        .row-thirds {
            grid-template-columns: 1fr 1fr 1fr;
        }

        .row-halves {
            grid-template-columns: 1fr 1fr;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.9rem;
            color: #333;
            font-weight: 600;
        }

        .required-star {
            color: #e74c3c;
            margin-left: 2px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: 'Montserrat', sans-serif;
            background-color: #fff;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }

        .checkbox-group input {
            width: auto;
        }

        /* --- SUMMARY CARD STYLES --- */
        .summary-card {
            background-color: #fff;
            border-radius: 4px;
            overflow: hidden;
            font-family: 'Montserrat', sans-serif;
            color: #333;
            padding-bottom: 20px;
        }

        .summary-card h3 {
            /* background-color: #EBEBEB; */
            color: #333;
            padding: 15px;
            margin: 0;
            font-weight: 600;
            font-size: 1.3rem;
        }

        .summary-section {
            background-color: #E0E0E0;
            padding: 0;
            margin-bottom: 10px;
        }

        .summary-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #EBD9D9;
            padding: 10px 15px;
            font-size: 0.9rem;
            color: #333;
            font-weight: 600;
            cursor: pointer;
        }

        .edit-link {
            text-decoration: underline;
            color: #333;
            font-size: 0.85rem;
            cursor: pointer;
        }

        .summary-content {
            background-color: #EBEBEB;
            padding: 15px;
            display: none;
        }

        .summary-content.active {
            display: block;
        }

        .summary-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.95rem;
        }

        .summary-line span:first-child {
            color: #555;
        }

        .summary-line span:last-child {
            font-weight: 600;
            color: #333;
            display: flex;
            align-items: center;
            gap: 5px;
            text-align: right;
            max-width: 60%;
        }

        .booked-rooms-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 15px 5px 15px;
            color: white;
            font-size: 1.1rem;
        }

        .reset-btn {
            background-color: #999;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .total-charge-bar {
            background-color: #EBD9D9;
            color: #333;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 1.2rem;
            margin: 10px 15px;
        }

        .continue-btn-orange {
            display: block;
            width: calc(100% - 30px);
            margin: 10px 15px;
            background-color: #FFA500;
            color: white;
            border: none;
            padding: 15px;
            font-size: 1.1rem;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
            text-align: center;
        }

        .continue-btn-orange:hover {
            background-color: #e69500;
        }

        @media (max-width: 768px) {

            .row-name,
            .row-thirds,
            .row-halves {
                grid-template-columns: 1fr;
            }

            .personal-info-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }

        /* --- AJAX TRANSITIONS --- */

        /* Wrapper for smooth fading */
        .content-wrapper {
            transition: opacity 0.4s ease-in-out, transform 0.4s ease-in-out;
            opacity: 1;
            transform: translateY(0);
        }

        /* State when leaving the page */
        .content-wrapper.fade-out {
            opacity: 0;
            transform: translateY(-20px);
        }

        /* State when entering the page */
        .content-wrapper.fade-in {
            animation: fadeIn 0.5s ease-in-out forwards;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Smooth Line Transition for Progress Bar */
        .progress-step::before {
            transition: background-color 0.6s ease-in-out;
        }

        .step-circle {
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
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
            <button onclick="history.back()" class="go-back-icon-btn"><i class="fa-solid fa-arrow-left"></i></button>
        </nav>
    </header>

    <section class="hero">
        <!-- <div class="hero-content">
            <h1>Guest Information</h1>
            <p>Almost there! Please enter your details to complete the booking.</p>
        </div> -->

        <div class="progress-track">
            <div class="progress-step completed">
                <div class="step-circle"><i class="fa fa-check"></i></div>
                <div class="step-label">Choose Date</div>
            </div>
            <div class="progress-step active">
                <div class="step-circle">2</div>
                <div class="step-label">Guest Info</div>
            </div>
            <div class="progress-step">
                <div class="step-circle">3</div>
                <div class="step-label">Confirmation</div>
            </div>
        </div>
    </section>

    <div class="container d-grid grid-cols-2 g-3 mt-3">

        <div class="booking-container">
            <div class="room-details-container">
                <div class="room-details p-4">
                    <div class="personal-info-header">
                        <h2 style="font-size: 1.3rem; color: #333;">Personal Information</h2>
                        <button type="button" class="signin-btn">Sign in to book faster</button>
                    </div>

                    <form id="guestInfoForm" method="POST" action="booking_confirmation.php">
                        <input type="hidden" name="room_name" value="<?php echo h($room_name); ?>">
                        <input type="hidden" name="check_in_date" value="<?php echo h($check_in_date); ?>">
                        <input type="hidden" name="check_out_date" value="<?php echo h($check_out_date); ?>">
                        <input type="hidden" name="nights" value="<?php echo h($nights); ?>">
                        <input type="hidden" name="adults" value="<?php echo h($adults); ?>">
                        <input type="hidden" name="children" value="<?php echo h($children); ?>">
                        <input type="hidden" name="total_price" value="<?php echo h($total_price); ?>">

                        <div class="form-row row-name">
                            <div class="form-group">
                                <label>Salutation<span class="required-star">*</span></label>
                                <select name="salutation" required>
                                    <option value="">- Select -</option>
                                    <option value="Mr">Mr.</option>
                                    <option value="Ms">Ms.</option>
                                    <option value="Mrs">Mrs.</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>First Name<span class="required-star">*</span></label>
                                <input type="text" name="first_name" required>
                            </div>
                            <div class="form-group">
                                <label>Last Name<span class="required-star">*</span></label>
                                <input type="text" name="last_name" required>
                            </div>
                        </div>

                        <div class="form-row row-thirds">
                            <div class="form-group">
                                <label>Gender<span class="required-star">*</span></label>
                                <select name="gender" required>
                                    <option value="">- Select -</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Prefer not to say</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Birthdate<span class="required-star">*</span></label>
                                <input type="date" name="birthdate" placeholder="yyyy-mm-dd" required>
                            </div>
                            <div class="form-group">
                                <label>Nationality<span class="required-star">*</span></label>
                                <select name="nationality" required>
                                    <option value="">- Select -</option>
                                    <option value="Filipino">Filipino</option>
                                    <option value="American">American</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row row-halves">
                            <div class="form-group">
                                <label>Email Address<span class="required-star">*</span></label>
                                <input type="email" name="email" required>
                            </div>
                            <div class="form-group">
                                <label>Re-type Email Address<span class="required-star">*</span></label>
                                <input type="email" name="retype_email" required>
                            </div>
                        </div>

                        <div class="form-row row-halves">
                            <div class="form-group">
                                <label>Contact Number<span class="required-star">*</span></label>
                                <input type="tel" name="phone" placeholder="+63-901-2345678" required>
                            </div>
                            <div class="form-group">
                                <label>Estimated Arrival Time<span class="required-star">*</span></label>
                                <select name="arrival_time" required>
                                    <option value="">- Select -</option>
                                    <option value="12:00 PM - 02:00 PM">12:00 PM - 02:00 PM</option>
                                    <option value="02:00 PM - 04:00 PM">02:00 PM - 04:00 PM</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Address<span class="required-star">*</span></label>
                            <input type="text" name="address" required>
                        </div>

                        <div class="checkbox-group">
                            <input type="checkbox" id="guestNamePerRoom" name="guest_name_per_room">
                            <label for="guestNamePerRoom" style="margin:0; font-weight: 400;">Specify Guest Name Per
                                Room</label>
                        </div>

                        <input type="hidden" name="full_name" id="combinedFullName">
                    </form>
                </div>
            </div>
        </div>

        <div class="grid">
            <div class="summary-card">
                <h3>Booking Summary</h3>

                <div class="summary-section mx-3">
                    <div class="summary-header-row" onclick="toggleSummary('dateDetails')">
                        <span>Date : <?php echo h($date_range); ?></span>
                        <span class="edit-link">Edit</span>
                    </div>

                    <div id="dateDetails" class="summary-content">
                        <div class="summary-line">
                            <span>Room</span>
                            <span>: <?php echo h($room_name); ?></span>
                        </div>
                        <div class="summary-line">
                            <span>Check-in Date</span>
                            <span>: <?php echo h($date_fmt_in); ?> <i class="fa fa-calendar"></i></span>
                        </div>
                        <div class="summary-line">
                            <span>Check-out Date</span>
                            <span>: <?php echo h($date_fmt_out); ?> <i class="fa fa-calendar"></i></span>
                        </div>
                        <div class="summary-line">
                            <span>Guests</span>
                            <span>: Adult: <?php echo h($adults); ?>, Children: <?php echo h($children); ?></span>
                        </div>
                        <div class="summary-line">
                            <span>Night(s)</span>
                            <span>: <?php echo h($nights); ?></span>
                        </div>
                        <div class="summary-line">
                            <span>Weekend(s)</span>
                            <span>: 0</span>
                        </div>
                    </div>
                </div>

                <!-- <div class="summary-section mx-3">
                    <div class="summary-header-row" onclick="toggleSummary('codeDetails')">
                        <span>Special Code : (No Input)</span>
                        <span class="edit-link">Edit</span>
                    </div>
                    <div id="codeDetails" class="summary-content">
                        <div class="form-group">
                            <input type="text" placeholder="Enter Code">
                        </div>
                    </div>
                </div> -->

                <div class="booked-rooms-header">
                    <span>Booked Rooms</span>
                    <button class="reset-btn" onclick="location.href='home_page_test.php'">
                        <i class="fa-solid fa-rotate-right"></i> Reset
                    </button>
                </div>

                <div class="total-charge-bar">
                    <span>Total Charge</span>
                    <span>PHP <?php echo number_format((float) $total_price, 2); ?></span>
                </div>

                <button type="button" class="continue-btn-orange" onclick="submitGuestForm()">CONTINUE</button>

            </div>
        </div>
    </div>

    <footer>
        <div class="container">
            <p>&copy; 2025 AMV Hotel. All rights reserved.</p>
        </div>
    </footer>

    <script>
        function toggleSummary(id) {
            const content = document.getElementById(id);
            if (content.style.display === "none" || content.style.display === "") {
                if (content.classList.contains('active')) {
                    content.classList.remove('active');
                    content.style.display = "none";
                } else {
                    content.classList.add('active');
                    content.style.display = "block";
                }
            } else {
                content.style.display = "none";
                content.classList.remove('active');
            }
        }

        function submitGuestForm() {
            const first = document.querySelector('input[name="first_name"]').value;
            const last = document.querySelector('input[name="last_name"]').value;
            document.getElementById('combinedFullName').value = first + ' ' + last;
            document.getElementById('guestInfoForm').submit();
        }
    </script>
</body>

</html>
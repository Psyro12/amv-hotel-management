<?php
session_start();

// Prefer canonical lookup by 'image_name', falling back to 'room' or 'room_name' URL params.
// Use the same DB connector used by other pages.
require __DIR__ . '/../DB-CONNECTIONS/db_connect_2.php'; // provides $conn (mysqli)
require __DIR__ . '/../DB-CONNECTIONS/db_connect_3.php'; // provides $pdo (PDO)

$amenities = get_all_amenities($pdo);

// Check if any error occurred during fetching
$error_message = '';
if ($amenities === false) {
    $error_message = "Could not load amenities. Please check the server logs for database query errors.";
}

// Helper escape
function h($s)
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// --- REVISED LOGIC ---
$roomParam = isset($_GET['room']) ? trim($_GET['room']) : (isset($_GET['room_name']) ? trim($_GET['room_name']) : '');

$room = null;

if ($roomParam !== '') {
    // Query the database using the unique image_name (room name)
    $stmt = mysqli_prepare($conn, "SELECT image_name, file_path, description FROM room_image_details WHERE image_name = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $roomParam);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res)
            $room = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
    }
}

// If not found, show a minimal 404-like page and exit
if (!$room) {
    http_response_code(404);
    ?>
    <!doctype html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <title>Room not found</title>
        <link rel="stylesheet" href="../STYLE/home_page.css">
    </head>

    <body>
        <div style="max-width:700px;margin:60px auto;padding:20px;background:#fff;border-radius:8px;">
            <h1>Room not found</h1>
            <p>The requested room could not be found. It may have been removed or renamed.</p>
            <p><a href="home_page_test.php">Back to rooms</a></p>
        </div>
    </body>

    </html>
    <?php
    exit;
}

// Construct canonical values
$roomName = $room['image_name'];
$imageUrl = '/image-storage/uploads/images/' . ($room['file_path'] ?? '');
$roomId = $room['id'] ?? 0;
$roomPricePerNight = 2500; // Hardcoded price
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Room - AMV Hotel</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../STYLE/room_booking.css">
    <link rel="stylesheet" href="../STYLE/utilities.css">
    <style>
        /* --- HERO ADJUSTMENTS (Compact) --- */
        .hero {
            /* display: flex; */
            /* flex-direction: column; */
            /* justify-content: center; */
            /* align-items: center; */
            /* REDUCED HEIGHT: Fits content tightly */
            /* min-height: 25vh;  */
            /* REDUCED GAP: Brings text and stepper closer */
            /* gap: 15px;  */
            padding: 20px 0;
            /* background-size: cover; */
            /* background-position: center; */
            /* position: relative; */
        }

        /* Dark overlay */
        .hero::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 0;
        }

        .hero-content {
            position: relative;
            z-index: 10;
            text-align: center;
        }

        .hero-content h1 {
            font-size: 1.8rem;
            color: #fff;
            margin-bottom: 5px;
        }

        .hero-content p {
            font-size: 0.95rem;
            color: #eee;
            margin: 0;
        }

        /* --- PROGRESS INDICATOR (High Contrast & Compact) --- */
        .progress-track {
            display: flex;
            justify-content: center;
            width: 100%;
            max-width: 600px;
            /* Slightly narrower */
            position: relative;
            z-index: 10;
            margin: 0 auto;
            /* Remove top/bottom margin */
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
            width: 90%;
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

        /* Labels */
        .step-label {
            font-size: 0.9rem;
            color: #fff;
            font-weight: 500;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.6);
        }

        /* ACTIVE State (Step 1 is Active here) */
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

        /* Completed State */
        .completed .step-circle {
            background-color: #27ae60;
            border-color: #27ae60;
        }

        .completed .step-label {
            color: #27ae60;
        }

        .completed::before,
        .active::before {
            background-color: #27ae60;
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
            <div class="logo-text">
                <span>AMV</span>
                <span>Hotel</span>
            </div>
        </div>

        <nav>
            <button onclick="history.back()" class="go-back-icon-btn">
                <i class="fa-solid fa-arrow-left"></i>
            </button>
        </nav>
    </header>

    <section class="hero" style="background-image: url('<?php echo h($imageUrl); ?>');">

        <div class="progress-track">
            <div class="progress-step active">
                <div class="step-circle">1</div>
                <div class="step-label">Choose Date</div>
            </div>
            <div class="progress-step">
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
                <h3><?php echo htmlspecialchars($roomName); ?></h3>
                <div class="room-details">
                    <img src="<?php echo h($imageUrl); ?>" alt="<?php echo h($roomName); ?>">

                    <div class="p-3">
                        <h2><?php echo h($roomName); ?></h2>
                        <p><?php echo nl2br(h($room['description'] ?? '')); ?></p>
                        <div class="price">₱<?php echo number_format($roomPricePerNight, 2); ?> / night</div>
                    </div>
                </div>
            </div>

            <div class="compact-amenities">
                <h3>AMENITIES</h3>

                <?php if (!empty($error_message)): ?>
                    <div class="error-message" role="alert">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <div class="compact-amenities-grid">
                    <?php if (is_array($amenities) && !empty($amenities)): ?>
                        <?php foreach ($amenities as $amenity): ?>
                            <div class="compact-amenity-item">
                                <div class="icon-wrapper">
                                    <div class="compact-amenity-icon">
                                        <i class="<?php echo htmlspecialchars($amenity['icon_class']); ?>"></i>
                                    </div>
                                </div>
                                <h4 class="compact-amenity-title">
                                    <?php echo htmlspecialchars($amenity['title']); ?>
                                </h4>
                                <p class="compact-amenity-description fs-sm">
                                    <?php echo htmlspecialchars($amenity['description']); ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    <?php elseif (is_array($amenities) && empty($amenities)): ?>
                        <p class="fallback-message">
                            ✅ Database connected successfully, but no amenities were found.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="calendar-section mt-3">
                <h2>AVAILABILITY CALENDAR</h2>
                <div class="calendar-container">
                    <div class="calendar">
                        <div class="calendar-header">
                            <button id="prevMonth"><i class="fa-solid fa-chevron-left"></i></button>
                            <h3 id="currentMonth">May 2025</h3>
                            <button id="nextMonth"><i class="fa-solid fa-chevron-right"></i></button>
                        </div>
                        <div class="calendar-grid" id="calendarGrid">
                        </div>
                    </div>

                    <div class="calendar">
                        <div class="calendar-header">
                            <button id="prevMonth2"><i class="fa-solid fa-chevron-left"></i></button>
                            <h3 id="currentMonth2">June 2025</h3>
                            <button id="nextMonth2"><i class="fa-solid fa-chevron-right"></i></button>
                        </div>
                        <div class="calendar-grid" id="calendarGrid2">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid">
            <div class="booking-form-container">
                <h2>Book Your Stay</h2>
                <form id="bookingForm" method="POST" action="guest_information.php">

                    <input type="hidden" name="room_id" value="<?php echo (int) $roomId; ?>">
                    <input type="hidden" name="room_name" value="<?php echo h($roomName); ?>">
                    <input type="hidden" name="price_per_night" value="<?php echo $roomPricePerNight; ?>">

                    <input type="hidden" name="total_price" id="totalPriceInput" value="0">
                    <input type="hidden" name="nights" id="nightsInput" value="0">

                    <div class="date-inputs">
                        <div class="form-group">
                            <label for="checkIn">Check-in Date</label>
                            <input type="date" id="checkIn" name="check_in_date" required>
                        </div>
                        <div class="form-group">
                            <label for="checkOut">Check-out Date</label>
                            <input type="date" id="checkOut" name="check_out_date" required>
                        </div>
                    </div>

                    <div id="nightsInfo" class="nights-info my-3">0 nights</div>

                    <div class="guest-inputs">
                        <div class="form-group">
                            <label for="adults">Adults</label>
                            <select id="adults" name="adults" required>
                                <option value="1">1 Adult</option>
                                <option value="2" selected>2 Adults</option>
                                <option value="3">3 Adults</option>
                                <option value="4">4 Adults</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="children">Children</label>
                            <select id="children" name="children">
                                <option value="0" selected>0 Children</option>
                                <option value="1">1 Child</option>
                                <option value="2">2 Children</option>
                                <option value="3">3 Children</option>
                            </select>
                        </div>
                    </div>

                    <div class="total-price my-3 py-2">
                        <h3>Total Price</h3>
                        <div class="total-amount" id="displayedTotalAmount">₱0</div>
                    </div>

                    <button type="submit" class="btn book-now-btn">Continue</button>
                </form>
            </div>
        </div>
    </div>

    <footer>
        <div class="container">
            <p>&copy; 2025 AMV Hotel. All rights reserved.</p>
        </div>
    </footer>

    <script src="../SCRIPT/room_booking.js?v=<?php echo time(); ?>"></script>
    <!-- <script src="../SCRIPT/ajax_navigation.js?v=<?php echo time(); ?>"></script> -->
</body>

</html>
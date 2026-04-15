<?php
// Include DB logic here because AJAX calls are isolated requests
require __DIR__ . '/../DB-CONNECTIONS/db_connect_2.php'; 
require __DIR__ . '/../DB-CONNECTIONS/db_connect_3.php';

function get_all_amenities($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM amenities");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return false; }
}

$amenities = get_all_amenities($pdo);
$roomParam = $_GET['room_name'] ?? '';

// Fetch Room Data Logic
$room = null;
if ($roomParam !== '') {
    $stmt = mysqli_prepare($conn, "SELECT * FROM room_image_details WHERE image_name = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $roomParam);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) $room = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
    }
}

$roomName = $room['image_name'] ?? 'Unknown';
$imageUrl = '/image-storage/uploads/images/' . ($room['file_path'] ?? '');
$roomId = $room['id'] ?? 0;
$roomPricePerNight = 2500; 

function h_part($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>

<div class="container d-grid grid-cols-2 g-3 mt-3">
    <div class="booking-container">
        <div class="room-details-container">
            <h3><?php echo h_part($roomName); ?></h3>
            <div class="room-details">
                <img src="<?php echo h_part($imageUrl); ?>" alt="<?php echo h_part($roomName); ?>">
                <div class="p-3">
                    <h2><?php echo h_part($roomName); ?></h2>
                    <p><?php echo nl2br(h_part($room['description'] ?? '')); ?></p>
                    <div class="price">₱<?php echo number_format($roomPricePerNight, 2); ?> / night</div>
                </div>
            </div>
        </div>

        <div class="compact-amenities">
            <h3>AMENITIES</h3>
            <div class="compact-amenities-grid">
                <?php if ($amenities): foreach ($amenities as $amenity): ?>
                    <div class="compact-amenity-item">
                        <div class="icon-wrapper"><div class="compact-amenity-icon"><i class="<?php echo h_part($amenity['icon_class']); ?>"></i></div></div>
                        <h4 class="compact-amenity-title"><?php echo h_part($amenity['title']); ?></h4>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <div class="grid">
        <div class="calendar-section mt-3">
            <h2>AVAILABILITY CALENDAR</h2>
            <div class="calendar-container">
                <div class="calendar">
                    <div class="calendar-header">
                        <button type="button" id="prevMonth"><i class="fa-solid fa-chevron-left"></i></button>
                        <h3 id="currentMonth">May 2025</h3>
                        <button type="button" id="nextMonth"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                    <div class="calendar-grid" id="calendarGrid"></div>
                </div>
                <div class="calendar">
                    <div class="calendar-header">
                        <button type="button" id="prevMonth2"><i class="fa-solid fa-chevron-left"></i></button>
                        <h3 id="currentMonth2">June 2025</h3>
                        <button type="button" id="nextMonth2"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                    <div class="calendar-grid" id="calendarGrid2"></div>
                </div>
            </div>
        </div>

        <div class="booking-form-container mt-3">
            <h2>Book Your Stay</h2>
            <form id="bookingForm">
                <input type="hidden" name="room_id" value="<?php echo (int) $roomId; ?>">
                <input type="hidden" name="room_name" value="<?php echo h_part($roomName); ?>">
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
                        <label>Adults</label>
                        <select id="adults" name="adults" required>
                            <option value="1">1 Adult</option>
                            <option value="2" selected>2 Adults</option>
                            <option value="3">3 Adults</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Children</label>
                        <select id="children" name="children">
                            <option value="0" selected>0 Children</option>
                            <option value="1">1 Child</option>
                            <option value="2">2 Children</option>
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
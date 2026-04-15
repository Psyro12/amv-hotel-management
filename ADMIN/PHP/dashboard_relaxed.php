<?php
// ADMIN/PHP/dashboard.php

// ADMIN/PHP/dashboard.php

/**
 * 1. PREVENT BROWSER CACHING
 * This ensures that if someone logs out and presses the "Back" button, 
 * or pastes the URL in a new tab after the session expires, 
 * the browser is forced to check with the server instead of showing a saved copy.
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // A date in the past

/**
 * 2. SECURITY HEADERS
 * X-Frame-Options: Prevents Clickjacking (your site being put in an <iframe>)
 * Content-Security-Policy: Restricts where scripts and images can be loaded from.
 */
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Content-Security-Policy: default-src 'self'; connect-src 'self' https://nominatim.openstreetmap.org https://cdn.jsdelivr.net https://unpkg.com https://cdn.quilljs.com; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://unpkg.com https://cdn.quilljs.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://cdn.quilljs.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https://placehold.co;");

session_start();

/**
 * 2.5 DEVICE RESTRICTION
 * Block cellphones and Android tablets, allow Desktop and iPads.
 */
$userAgent = $_SERVER['HTTP_USER_AGENT'];
$isMobile = preg_match('/Mobile|Android|BlackBerry|iPhone|iPod|Opera Mini|IEMobile/i', $userAgent);
$isAndroidTablet = preg_match('/Android/i', $userAgent) && !preg_match('/Mobile/i', $userAgent);

if ($isMobile || $isAndroidTablet) {
    session_unset();
    session_destroy();
    header("Location: login.php?error=device_restricted");
    exit();
}

/**
 * 3. STRICT SESSION VALIDATION
 * If the session 'user' key is not found, immediately kick them to login.
 */
if (!isset($_SESSION['user'])) {
    session_unset();
    session_destroy();
    header("Location: login.php?error=unauthorized");
    exit();
}

/**
 * 4. SESSION HIJACKING PROTECTION
 * We check if the User Agent (browser type) has changed. 
 * If a hacker steals the session cookie but uses a different browser, they get kicked out.
 */
if (!isset($_SESSION['user_agent'])) {
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
} else {
    if ($_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
        session_unset();
        session_destroy();
        header("Location: login.php?error=session_breach");
        exit();
    }
}

/**
 * 5. INACTIVITY TIMEOUT (10 Minutes)
 */
$inactive = 10000; // 10 minutes in seconds
if (isset($_SESSION['timeout'])) {
    $session_life = time() - $_SESSION['timeout'];
    if ($session_life > $inactive) {
        session_unset();
        session_destroy();
        header("Location: login.php?error=timeout");
        exit();
    }
}
$_SESSION['timeout'] = time(); // Reset timer on every page load

/**
 * 6. CSRF TOKEN GENERATION
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- CSRF CHECK ON POST REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $_POST['csrf_token'] ?? $input['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die(json_encode(['status' => 'error', 'message' => 'Security check failed (CSRF)']));
    }
}

require 'db_connect.php';
$user = $_SESSION['user'];
date_default_timezone_set('Asia/Manila');

// --- CSRF CHECK ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $_POST['csrf_token'] ?? $input['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        die(json_encode(['status' => 'error', 'message' => 'Security check failed (CSRF)']));
    }
}

require 'db_connect.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}
$user = $_SESSION['user'];

// =================================================================
// 0. AUTO-NOTIFICATION SYSTEM (UPDATED: Sends to App & Admin)
// =================================================================

$currentHour = (int) date('H');

// 1. Identify ALL Pending Arrivals for Today (Past 8 PM)
// 🔴 JOINED with users table to get account_source for the app
$sql_late_select = "SELECT b.id, b.booking_reference, b.check_in, bg.email, u.account_source
                    FROM bookings b 
                    JOIN booking_guests bg ON b.id = bg.booking_id
                    LEFT JOIN users u ON bg.email = u.email
                    WHERE b.status = 'confirmed' 
                    AND (b.arrival_status IS NULL OR b.arrival_status = '' OR b.arrival_status = 'awaiting_arrival' OR b.arrival_status = 'upcoming')
                    AND b.check_in = CURDATE()";

$res_late = $conn->query($sql_late_select);

if ($res_late) {
    while ($row = $res_late->fetch_assoc()) {
        $ref = $row['booking_reference'];
        $email = $row['email'];
        $source = $row['account_source'] ?? 'google'; // Default to google if missing

        // Only trigger the alert if it is actually past 8 PM (20:00)
        if ($currentHour >= 20) {
            $notifTitle = "Late Arrival Advisory";
            $notifDesc = "Ref: $ref - It is past 8 PM. If the guest has not arrived, you may manually mark this as No-Show.";
            $notifType = "reminder";

            // 3. DUPLICATE CHECK (Prevent spamming the same alert)
            $check_notif = "SELECT id FROM system_notifications 
                            WHERE description = ? AND DATE(created_at) = CURDATE()";
            $stmt_check = $conn->prepare($check_notif);
            $stmt_check->bind_param("s", $notifDesc);
            $stmt_check->execute();
            $stmt_check->store_result();

            // Only insert if we haven't alerted you today
            if ($stmt_check->num_rows == 0) {
                // A. Notify Admin
                $stmt_ins = $conn->prepare("INSERT INTO system_notifications (title, description, type, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
                $stmt_ins->bind_param("sss", $notifTitle, $notifDesc, $notifType);
                $stmt_ins->execute();
                $stmt_ins->close();

                // B. 🟢 Notify Guest App
                if (!empty($email)) {
                    $guestTitle = "Arrival Reminder";
                    $guestMsg = "It is past 8 PM. If you are running late, please contact the front desk to keep your reservation.";

                    $stmt_app = $conn->prepare("INSERT INTO guest_notifications (email, account_source, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, 'system', 0, NOW())");
                    $stmt_app->bind_param("ssss", $email, $source, $guestTitle, $guestMsg);
                    $stmt_app->execute();
                    $stmt_app->close();
                }
            }
            $stmt_check->close();
        }
    }
}

// =================================================================
// 3. CHECKOUT NOTIFICATION SYSTEM (UPDATED: Sends to App & Admin)
// =================================================================
// Logic: Triggers only if it is 12:00 PM (Noon) or later.

if ((int) date('H') >= 12) {

    // 1. Find guests who are STILL "In House" and due Today
    // 🔴 JOINED with users table to get account_source
    $sql_due = "SELECT b.id, b.booking_reference, bg.first_name, bg.last_name, bg.email, u.account_source,
                GROUP_CONCAT(br.room_name SEPARATOR ', ') as room_names
                FROM bookings b
                JOIN booking_guests bg ON b.id = bg.booking_id
                LEFT JOIN users u ON bg.email = u.email
                JOIN booking_rooms br ON b.id = br.booking_id
                WHERE b.arrival_status = 'in_house' 
                AND b.check_out = CURDATE()
                GROUP BY b.id";

    $res_due = $conn->query($sql_due);

    if ($res_due) {
        while ($row_due = $res_due->fetch_assoc()) {
            $guestName = $row_due['first_name'] . ' ' . $row_due['last_name'];
            $rooms = $row_due['room_names'];
            $ref = $row_due['booking_reference'];
            $email = $row_due['email'];
            $source = $row_due['account_source'] ?? 'google';

            // Notification Content
            $notifTitle = "Checkout Due";
            $notifDesc = "Ref: $ref - Standard checkout time (12:00 PM) has passed for $guestName ($rooms).";
            $notifType = "reminder";

            // 2. DUPLICATE CHECK
            $check_sql = "SELECT id FROM system_notifications 
                          WHERE description = ? 
                          AND type = ? 
                          AND DATE(created_at) = CURDATE()";

            $stmt_check = $conn->prepare($check_sql);
            $stmt_check->bind_param("ss", $notifDesc, $notifType);
            $stmt_check->execute();
            $stmt_check->store_result();

            // 3. Insert Notification only if it doesn't exist for today
            if ($stmt_check->num_rows == 0) {
                // A. Notify Admin
                $stmt_ins = $conn->prepare("INSERT INTO system_notifications (title, description, type, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
                $stmt_ins->bind_param("sss", $notifTitle, $notifDesc, $notifType);
                $stmt_ins->execute();
                $stmt_ins->close();

                // B. 🟢 Notify Guest App
                if (!empty($email)) {
                    $guestTitle = "Checkout Time Passed";
                    $guestMsg = "Standard checkout time is 12:00 PM. Please visit the front desk to check out or extend your stay.";

                    $stmt_app = $conn->prepare("INSERT INTO guest_notifications (email, account_source, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, 'system', 0, NOW())");
                    $stmt_app->bind_param("ssss", $email, $source, $guestTitle, $guestMsg);
                    $stmt_app->execute();
                    $stmt_app->close();
                }
            }
            $stmt_check->close();
        }
    }
}


// Check for guests who are "In House" and due to check out "Today"
// 1. ADD 'b.booking_reference' TO THE SELECT LIST
$sql_due = "SELECT b.id, b.booking_reference, bg.first_name, bg.last_name, 
            GROUP_CONCAT(br.room_name SEPARATOR ', ') as room_names
            FROM bookings b
            JOIN booking_guests bg ON b.id = bg.booking_id
            JOIN booking_rooms br ON b.id = br.booking_id
            WHERE b.arrival_status = 'in_house' 
            AND b.check_out <= CURDATE()
            GROUP BY b.id";

$res_due = $conn->query($sql_due);

if ($res_due) {
    while ($row_due = $res_due->fetch_assoc()) {
        $guestName = $row_due['first_name'] . ' ' . $row_due['last_name'];
        $rooms = $row_due['room_names'];
        $ref = $row_due['booking_reference']; // <--- 2. GET THE REFERENCE

        // Prepare the Notification Message
        $notifTitle = "Checkout Reminder";

        // 3. UPDATE THE DESCRIPTION TO INCLUDE THE REFERENCE
        $notifDesc = "Ref: $ref - Guest $guestName ($rooms) is due for checkout today.";
        $notifType = "reminder";

        // --- DUPLICATE CHECK ---
        $check_sql = "SELECT id FROM system_notifications 
                      WHERE description = ? 
                      AND type = ? 
                      AND DATE(created_at) = CURDATE()";

        $stmt_check = $conn->prepare($check_sql);
        $stmt_check->bind_param("ss", $notifDesc, $notifType);
        $stmt_check->execute();
        $stmt_check->store_result();

        // Only Insert if it doesn't exist yet
        if ($stmt_check->num_rows == 0) {
            $stmt_ins = $conn->prepare("INSERT INTO system_notifications (title, description, type, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
            $stmt_ins->bind_param("sss", $notifTitle, $notifDesc, $notifType);
            $stmt_ins->execute();
            $stmt_ins->close();
        }
        $stmt_check->close();
    }
}

// =================================================================
// 1.5 CHECK FOR LATE ARRIVALS (REAL-TIME CARD)
// =================================================================
$current_time = date('H:i:s');
$lateGuestCount = 0;

// Find confirmed bookings for TODAY where the arrival time (+1 Hour Buffer) has passed
$sql_late_check = "SELECT COUNT(*) as total 
                   FROM bookings b
                   JOIN booking_guests bg ON b.id = bg.booking_id
                   WHERE b.status = 'confirmed' 
                   AND b.check_in = CURDATE()
                   AND (b.arrival_status IS NULL OR b.arrival_status = '' OR b.arrival_status = 'awaiting_arrival')
                   /* 🔴 UPDATED: Only count as late if current time is past (Arrival Time + 1 Hour) */
                   AND ADDTIME(bg.arrival_time, '01:00:00') < '$current_time'";

$res_late = $conn->query($sql_late_check);
if ($res_late) {
    $lateGuestCount = $res_late->fetch_assoc()['total'] ?? 0;
}

// =================================================================
// 2. FETCH DASHBOARD STATS (DEFAULT: OVERALL)
// =================================================================

// A. Total Active Bookings (In-House + Confirmed)
$sql_active = "SELECT COUNT(*) as total FROM bookings 
               WHERE arrival_status = 'in_house' 
               OR status = 'confirmed'";
$res_active = $conn->query($sql_active);
$activeBookings = $res_active->fetch_assoc()['total'] ?? 0;

// B. Overall Revenue (Confirmed bookings + Food Orders)
$sql_rev_bookings = "SELECT SUM(total_price) as total FROM bookings 
                       WHERE status = 'confirmed' AND arrival_status != 'cancelled'";
$res_rev_bookings = $conn->query($sql_rev_bookings);
$bookingRevenue = $res_rev_bookings->fetch_assoc()['total'] ?? 0;

$sql_rev_orders = "SELECT SUM(total_price) as total FROM orders 
                     WHERE status IN ('Preparing', 'Delivered', 'PAID', 'Completed')";
$res_rev_orders = $conn->query($sql_rev_orders);
$orderRevenue = $res_rev_orders->fetch_assoc()['total'] ?? 0;

$overallRevenue = $bookingRevenue + $orderRevenue;

// C. Overall Occupancy Rate Calculation
$sql_t_rooms = "SELECT COUNT(*) as total FROM rooms WHERE is_active = 1";
$res_t_rooms = $conn->query($sql_t_rooms);
$totalRoomsCount = $res_t_rooms->fetch_assoc()['total'] ?? 1;

$res_min_date = $conn->query("SELECT MIN(check_in) as first_date FROM bookings WHERE status = 'confirmed'");
$first_date = $res_min_date->fetch_assoc()['first_date'] ?? date('Y-m-d');
$totalDaysElapsed = (int) date_diff(date_create($first_date), date_create('now'))->format('%a') + 1;
if ($totalDaysElapsed < 1)
    $totalDaysElapsed = 1;

$totalAvailableNights = $totalRoomsCount * $totalDaysElapsed;

$sql_occ = "SELECT SUM(DATEDIFF(LEAST(check_out, CURDATE()), GREATEST(check_in, '$first_date'))) as nights 
            FROM bookings 
            WHERE status = 'confirmed' 
            AND arrival_status NOT IN ('no_show', 'cancelled')
            AND check_in <= CURDATE() AND check_out > '$first_date'";

$res_occ = $conn->query($sql_occ);
$nightsSold = $res_occ->fetch_assoc()['nights'] ?? 0;
$occupancyRate = ($totalAvailableNights > 0) ? round(($nightsSold / $totalAvailableNights) * 100) : 0;

// D. Pending Requests (Needs Approval)
$sql_pending = "SELECT COUNT(*) as total FROM bookings 
                WHERE status = 'confirmed' 
                AND check_in = CURDATE() 
                AND arrival_status NOT IN ('in_house', 'checked_out')";

$res_pending = $conn->query($sql_pending);
$pendingRequests = $res_pending->fetch_assoc()['total'] ?? 0;

// E. Total Orders (Active Kitchen Queue)
$sql_orders_count = "SELECT COUNT(*) as total FROM orders WHERE status IN ('Pending', 'Preparing')";
$res_orders_count = $conn->query($sql_orders_count);
$totalOrders = $res_orders_count->fetch_assoc()['total'] ?? 0;

// =================================================================
// 3. FETCH CHART DATA (OVERALL PIE STATS)
// =================================================================
$pieStats = [
    'complete' => 0,
    'noshow' => 0,
    'cancelled' => 0
];
$barData = array_fill(1, 12, 0);
$currentYear = date('Y');
$curY = date('Y');
$today = date('Y-m-d');

$sql_charts = "SELECT status, arrival_status, check_in, check_out, total_price, MONTH(check_in) as month, YEAR(check_in) as year 
               FROM bookings";

$result_charts = $conn->query($sql_charts);
$now = new DateTime();

while ($row = $result_charts->fetch_assoc()) {
    // Pie Chart Logic (Overall)
    if ($row['status'] === 'cancelled' || $row['arrival_status'] === 'cancelled') {
        $pieStats['cancelled']++;
    } elseif ($row['arrival_status'] === 'checked_out') {
        $pieStats['complete']++;
    } elseif ($row['arrival_status'] === 'no_show') {
        $pieStats['noshow']++;
    } elseif ($row['status'] === 'confirmed' && empty($row['arrival_status'])) {
        $checkInTime = new DateTime($row['check_in'] . ' 14:00:00');
        if ($now >= $checkInTime) {
            $pieStats['noshow']++;
        }
    }

    // Bar Chart Logic (Current Year Only)
    if ($row['status'] === 'confirmed' && $row['year'] == $currentYear && $row['arrival_status'] != 'cancelled') {
        $month = (int) $row['month'];
        $barData[$month] += $row['total_price'];
    }
}

// 🟢 NEW: ADD FOOD ORDERS TO BAR CHART (YEARLY)
$sql_order_charts = "SELECT total_price, MONTH(order_date) as month 
                    FROM orders 
                    WHERE status IN ('Preparing', 'Delivered', 'PAID', 'Completed') 
                    AND YEAR(order_date) = '$curY'";
$result_order_charts = $conn->query($sql_order_charts);
if ($result_order_charts) {
    while ($orow = $result_order_charts->fetch_assoc()) {
        $month = (int) $orow['month'];
        if (isset($barData[$month])) {
            $barData[$month] += (float) $orow['total_price'];
        }
    }
}

// Calculate Percentages
$totalPie = array_sum($pieStats);
$pctComplete = $totalPie > 0 ? round(($pieStats['complete'] / $totalPie) * 100) : 0;
$pctNoShow = $totalPie > 0 ? round(($pieStats['noshow'] / $totalPie) * 100) : 0;
$pctCancelled = $totalPie > 0 ? round(($pieStats['cancelled'] / $totalPie) * 100) : 0;

// =================================================================
// 4. CALENDAR DATA LOGIC
$all_amenities = [];
$res_amenities = $conn->query("SELECT * FROM amenities ORDER BY title ASC");
if ($res_amenities) {
    while ($am_row = $res_amenities->fetch_assoc()) {
        $all_amenities[] = $am_row;
    }
}

// =================================================================
$calendarData = [];
$allRoomsDB = [];

$sql_rooms_fetch = "SELECT * FROM rooms ORDER BY is_active DESC, name ASC";
$result_rooms_fetch = $conn->query($sql_rooms_fetch);

if ($result_rooms_fetch) {
    while ($row_r = $result_rooms_fetch->fetch_assoc()) {
        $r_id = $row_r['id'];

        // Smart Lock Logic
        $status_sql = "SELECT 1 FROM booking_rooms br 
               JOIN bookings b ON br.booking_id = b.id 
               WHERE br.room_id = '$r_id' 
               AND b.status IN ('confirmed', 'pending') 
               AND b.arrival_status NOT IN ('checked_out', 'no_show') 
               AND b.check_out > CURDATE() 
               LIMIT 1";

        $status_res = $conn->query($status_sql);
        $is_currently_booked = ($status_res && $status_res->num_rows > 0);

        $allRoomsDB[] = [
            'id' => $row_r['id'],
            'name' => htmlspecialchars($row_r['name']),
            'price' => $row_r['price'] ?? 0,
            'type' => $row_r['type'] ?? 'Standard',
            'bed_type' => $row_r['bed_type'] ?? 'Standard',
            'capacity' => $row_r['capacity'] ?? 2,
            'size' => $row_r['size'] ?? '',
            'description' => $row_r['description'] ?? '',
            'file_path' => $row_r['image_path'] ?? '',
            'amenities' => $row_r['amenities'] ?? '',
            'is_active' => $row_r['is_active'],
            'is_booked' => $is_currently_booked
        ];
    }
}
$js_allRoomsJSON = json_encode($allRoomsDB);

// B. Fetch Calendar Bookings
$viewMonth = isset($_GET['month']) ? $_GET['month'] : date('m');
$viewYear = isset($_GET['year']) ? $_GET['year'] : date('Y');

$startDate = date('Y-m-d', strtotime("$viewYear-$viewMonth-01 - 7 days"));
$endDate = date('Y-m-d', strtotime("$viewYear-$viewMonth-01 + 1 month + 7 days"));

// 🔴 FIX 1: Add 'awaiting_arrival' to the allowed statuses in SQL
// We must fetch these bookings so they show up as Reserved (Yellow) before 8 PM
$sql_cal = "SELECT 
                b.id, b.check_in, b.check_out, b.status, b.arrival_status, 
                br.room_id, br.room_name, 
                CONCAT(bg.first_name, ' ', bg.last_name) as guest_name,
                bg.email, bg.phone
            FROM bookings b 
            JOIN booking_rooms br ON b.id = br.booking_id 
            JOIN booking_guests bg ON b.id = bg.booking_id
            WHERE b.status IN ('confirmed', 'pending')
            AND (
                b.arrival_status IS NULL 
                OR b.arrival_status = ''
                OR b.arrival_status = 'awaiting_arrival'  /* <--- ADDED THIS */
                OR b.arrival_status = 'in_house' 
                OR b.arrival_status = 'checked_out'
            )
            AND b.check_out > '$startDate' 
            AND b.check_in < '$endDate'";

$result_cal = $conn->query($sql_cal);
$currentDateTime = date('Y-m-d H:i:s');

while ($row = $result_cal->fetch_assoc()) {

    // 🔴 FIX 2: Update PHP Logic to handle 'awaiting_arrival'
    $checkInDate = $row['check_in'];
    // $cutoffTime = date('Y-m-d 20:00:00', strtotime($checkInDate)); // 8:00 PM

    // Check if status is Confirmed AND (Arrival is Empty OR Awaiting)
    if ($row['status'] == 'confirmed' && (empty($row['arrival_status']) || $row['arrival_status'] == 'awaiting_arrival')) {

        // If it is past 8 PM on check-in day, skip this booking (Ghost No-Show)
        // if ($currentDateTime >= $cutoffTime) {
        //     continue;
        // }
    }

    $start = new DateTime($row['check_in']);
    $end = new DateTime($row['check_out']);

    // 🔴 LOGIC CHANGE: If In House, block the room on checkout day too
    if ($row['arrival_status'] == 'in_house') {
        $end->modify('+1 day');
    }

    for ($date = clone $start; $date < $end; $date->modify('+1 day')) {
        $dateStr = $date->format('Y-m-d');

        if ($row['arrival_status'] === 'checked_out' && $dateStr >= $today) {
            continue;
        }

        if (!isset($calendarData[$dateStr])) {
            $calendarData[$dateStr] = [];
        }

        $colorType = 'future'; // Default Yellow

        // Override color if In House
        if ($row['arrival_status'] == 'in_house') {
            $colorType = 'in_house'; // Gold
        }
        // 🟢 ADD THIS BLOCK:
        elseif ($row['arrival_status'] == 'checked_out') {
            $colorType = 'checked_out'; // Grey
        }

        $calendarData[$dateStr][] = [
            'room_id' => $row['room_id'],
            'room_name' => htmlspecialchars($row['room_name']),
            'guest' => htmlspecialchars($row['guest_name']),
            'email' => htmlspecialchars($row['email']),
            'phone' => htmlspecialchars($row['phone']),
            // ... rest of your data mapping ...
            'status' => $row['status'],
            'type' => $colorType,
            'check_in' => $row['check_in'],
            'check_out' => $row['check_out']
        ];
    }
}

// =================================================================
// 5. FETCH BOOKING LIST
// =================================================================
$search = isset($_GET['search']) ? $_GET['search'] : '';

// 🟢 FIX: Clean SQL string (Removed comments inside the query)
$sql_list = "SELECT
        b.id, b.booking_reference, b.check_in, b.check_out,
        b.status, b.total_price, b.booking_source, b.arrival_status,
        b.amount_paid, b.payment_status, b.payment_term,
        b.created_at,
        u.name as user_name, bg.first_name, bg.last_name,
        bg.arrival_time, bg.special_requests,
        GROUP_CONCAT(br.room_name SEPARATOR ', ') as room_names,
        MAX(br.price_per_night) as daily_price
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.id
    LEFT JOIN booking_guests bg ON b.id = bg.booking_id
    LEFT JOIN booking_rooms br ON b.id = br.booking_id
    WHERE DATE(b.check_in) = CURDATE()
    AND (b.status != 'pending' AND b.status != 'Pending')";
// Add search conditions if needed
if ($search) {
    $sql_list .= " AND (
        b.booking_reference LIKE ? OR 
        u.name LIKE ? OR 
        bg.first_name LIKE ? OR 
        bg.last_name LIKE ? OR
        CONCAT(bg.first_name, ' ', bg.last_name) LIKE ?
    )";
}

// 🟢 FIX: Update the ORDER BY list and add LIMIT 10
$sql_list .= " GROUP BY b.id ORDER BY FIELD(b.status, 'confirmed', 'cancelled'), b.check_in ASC LIMIT 10";

$stmt = $conn->prepare($sql_list);

if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    die("Internal Server Error");
}

if ($search) {
    $searchTerm = "%" . $search . "%";
    $stmt->bind_param("sssss", $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}

$stmt->execute();
$result_list = $stmt->get_result();

// =================================================================
// 6. FETCH GUESTS LIST & NEWS
// =================================================================
$sql_guests = "SELECT 
        MAX(first_name) as first_name, 
        MAX(last_name) as last_name, 
        email, 
        MAX(phone) as phone, 
        MAX(nationality) as nationality, 
        COUNT(id) as total_stays 
    FROM booking_guests 
    GROUP BY email 
    ORDER BY MAX(last_name) ASC";
$result_guests = $conn->query($sql_guests);

// Pass Data to JS
$js_pieData = json_encode([$pieStats['complete'], $pieStats['noshow'], $pieStats['cancelled']]);
$js_barData = json_encode(array_values($barData));
$js_calendarData = json_encode($calendarData);

// Fetch Hotel News
$sql_news = "SELECT * FROM hotel_news ORDER BY news_date DESC";
$result_news = $conn->query($sql_news);

// Fetch Hotel Events
$sql_events = "SELECT * FROM hotel_events ORDER BY event_date DESC";
$result_events = $conn->query($sql_events);

// Fetch Guest Feedback
$sql_reviews = "SELECT * FROM guest_feedback ORDER BY created_at DESC";
$result_reviews = $conn->query($sql_reviews);

// Helper function to render stars (Optional, but makes HTML cleaner)
function renderStarRating($rating)
{
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        $color = ($i <= $rating) ? '#FFC107' : '#E0E0E0'; // Gold vs Grey
        $stars .= "<i class='fas fa-star' style='color: {$color}; font-size: 0.8rem;'></i>";
    }
    return $stars;
}
// =================================================================
// 7. FETCH TERMS & CONDITIONS (Specific Table)
// =================================================================

$sql_terms = "SELECT title, content FROM terms_conditions ORDER BY id ASC";
$res_terms = $conn->query($sql_terms);

$termsArray = [];
if ($res_terms) {
    while ($row = $res_terms->fetch_assoc()) {
        $termsArray[] = [
            'title' => $row['title'],
            'content' => $row['content']
        ];
    }
}
// Pass this array to the frontend
$termsData = json_encode($termsArray);

// =================================================================
// 8. FETCH PRIVACY POLICY (Add this block)
// =================================================================

$sql_priv = "SELECT section_title as title, content FROM privacy_policy ORDER BY display_order ASC";
$res_priv = $conn->query($sql_priv);
$privacyArray = [];
if ($res_priv) {
    while ($row = $res_priv->fetch_assoc()) {
        $privacyArray[] = [
            'title' => $row['title'],
            'content' => $row['content']
        ];
    }
}
$privacyData = json_encode($privacyArray);

// =================================================================
// 9. FETCH FOOD ORDERS (UPDATED: Excludes Pending)
// =================================================================
// Only shows orders that have been accepted (Preparing, Delivered, etc.)
$sql_orders = "SELECT o.*, u.name as guest_name 
               FROM orders o 
               LEFT JOIN users u ON o.user_id = u.id 
               WHERE o.status != 'Pending' 
               AND (
                   o.status = 'Preparing' 
                   OR DATE(o.order_date) = CURDATE()
               )
               ORDER BY 
               CASE 
                   WHEN o.status = 'Preparing' THEN 1 
                   ELSE 2 
               END, 
               o.order_date DESC";

$result_orders = $conn->query($sql_orders);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMV - Admin Dashboard</title>
    <link rel="icon" type="image/png" href="../../IMG/5.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../STYLE/dashboard-styles.css">
    <link rel="stylesheet" href="../STYLE/utilities.css">
    <script> const csrfToken = "<?php echo $_SESSION['csrf_token']; ?>";</script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/rangePlugin.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/style.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/index.js"></script>
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <style>
        /* 🟢 ENHANCED YEAR SWITCH BUTTONS - BLENDED WITH SYSTEM */
        .year-switch-btn {
            background: #f8fafc;
            /* Matches main-content background */
            border: 1px solid #e2e8f0;
            /* Matches top-header border */
            color: #64748b;
            /* Muted slate color for professional look */
            width: 38px;
            height: 38px;
            border-radius: 8px;
            /* Squared-circle matches dashboard card style */
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: none;
            font-size: 0.9rem;
            position: relative;
        }

        .year-switch-btn:hover {
            background: #fff;
            color: #B88E2F;
            border-color: #B88E2F;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            transform: translateY(-1px);
        }

        .year-switch-btn:active {
            transform: translateY(0) scale(0.95);
            background: #f1f5f9;
        }

        /* 🟢 CALENDAR MODAL FILTER BUTTONS */
        .modal-filter-btn {
            background: #fff;
            border: 1px solid #e2e8f0;
            color: #64748b;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .modal-filter-btn:hover {
            border-color: #cbd5e1;
            background: #f1f5f9;
            color: #334155;
        }

        .modal-filter-btn.active {
            background: #B88E2F;
            color: white;
            border-color: #B88E2F;
            box-shadow: 0 4px 10px rgba(184, 142, 47, 0.2);
        }

        .modal-filter-btn i {
            font-size: 0.75rem;
        }

        /* 🟢 TITLE CHANGE ANIMATION */
        @keyframes titleBounce {
            0% {
                transform: translateY(0);
                opacity: 1;
            }

            50% {
                transform: translateY(-5px);
                opacity: 0.7;
            }

            100% {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .title-bounce {
            animation: titleBounce 0.4s ease;
        }

        /* --- UPDATED QUILL EDITOR STYLES (Auto-Expand) --- */
        .ql-toolbar {
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            background-color: #f3f4f6;
            border-color: #e5e7eb !important;
            /* Optional: Sticks toolbar to top if the editor gets very long */
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .ql-container {
            border-bottom-left-radius: 8px;
            border-bottom-right-radius: 8px;
            background-color: #fff;
            border-color: #e5e7eb !important;
            font-family: 'Montserrat', sans-serif !important;
            font-size: 0.95rem;

            /* 🔴 AUTO-EXPAND LOGIC 🔴 */
            height: auto !important;
            min-height: 200px;
            /* Start at this height */
            overflow-y: visible !important;
            /* Allow growing */
        }

        .ql-editor {
            min-height: 200px;
            height: auto !important;
            overflow-y: visible !important;
            /* No internal scrollbar */
        }

        /* --- LOCK PAGE & DISABLE SCROLL --- */
        html {
            scrollbar-gutter: stable;
            /* 🟢 Reserve space for scrollbar to prevent jumping */
        }

        body {
            height: 100%;
            margin: 0;
            overflow-y: scroll;
            /* 🟢 Force scrollbar to stay visible for consistency */
        }

        .dashboard-container {
            min-height: 100vh;
            display: flex;
            overflow: visible;
        }

        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            /* 🟢 RESPONSIVE WIDTH FIX: Calculate width excluding sidebar */
            width: calc(100% - 280px);
            min-width: 0;
            /* Prevents flex children from stretching parent */
            overflow: visible;
        }

        .page-content {
            flex: 1;
            width: 100%;
            display: flex;
            flex-direction: column;
            overflow: visible;
            max-width: none;
            /* 🟢 Remove 1400px limit from dashboard-styles.css */
        }

        /* Dashboard Page Specific Layout to Stretch Charts */
        #dashboard.page.active {
            display: flex;
            flex-direction: column;
            height: auto;
            min-height: 100%;
            gap: 1rem;
        }

        .page {
            display: none;
            width: 100%;
            padding: 20px;
            box-sizing: border-box;
            overflow-y: auto;
            min-height: 100%;
            /* 🟢 REMOVED height: auto; which was causing stretching issues */
        }

        /* 🟢 REVERTED TO DISPLAY: BLOCK FOR SIMPLICITY */
        .page.active {
            display: block;
        }

        /* Adjust sidebar and main-content for smaller screens */
        @media (max-width: 1024px) {
            .main-content {
                width: calc(100% - 240px);
            }
        }

        /* 🟢 NEW: LAPTOP/DESKTOP SETTINGS SCROLLING FIX */
        @media (min-width: 1025px) {
            #settings.page {
                overflow: hidden !important;
                /* Prevent main settings page from scrolling */
                height: 100%;
            }

            #settings .settings-view {
                max-height: 100%;
                overflow-y: auto;
                /* Allow internal view to scroll if needed */
            }

            #settings-home {
                overflow-y: auto;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                width: 100%;
                margin-left: 0;
            }
        }

        /* --- DASHBOARD OVERVIEW GRID (Refined Text Size) --- */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            /* 🟢 Responsive columns */
            gap: 20px;
            /* margin-bottom: 30px; */
        }

        .stat-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 25px 15px;
            /* Slightly tighter padding */
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
            border: 1px solid rgba(0, 0, 0, 0.02);

            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;

            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.08);
        }

        /* Icon Container - Reduced Size */
        .stat-icon {
            width: 48px;
            /* Was 60px */
            height: 48px;
            /* Was 60px */
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            /* Was 1.5rem */
            margin-bottom: 12px;
        }

        /* The Big Number - Reduced Size */
        .stat-value {
            font-size: 1.5rem;
            /* Was 1.8rem */
            font-weight: 700;
            color: #2d3748;
            margin: 0 0 4px 0;
            line-height: 1.2;
        }

        /* The Label - Reduced Size */
        .stat-label {
            font-size: 0.8rem;
            /* Was 0.9rem */
            color: #a0aec0;
            /* Slightly lighter grey for a premium look */
            font-weight: 500;
            margin: 0;
            letter-spacing: 0.3px;
        }

        /* --- RESPONSIVENESS --- */
        @media (max-width: 1600px) {
            .stats-grid {
                gap: 15px;
            }

            .stat-card {
                padding: 20px 10px;
            }

            .stat-value {
                font-size: 1.35rem;
                /* Shrink slightly more on laptop screens */
            }
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }


        /* Charts Grid fills remaining space */
        .charts-grid-dashboard {
            flex: 1;
            min-height: 0;
            /* Allows flex child to shrink properly */
            display: flex;
            gap: 1rem;
            align-items: stretch;
            /* Stretch children vertically */
        }

        /* --- 🔴 NEW: ADD THIS MEDIA QUERY BLOCK BELOW IT --- */
        @media (max-width: 1100px) {

            /* 1. Force Stack Vertical */
            .charts-grid-dashboard {
                flex-direction: column;
                height: auto !important;
                /* Allow it to grow tall */
                flex: 0 0 auto;
                /* Stop trying to fill just the viewport height */
            }

            /* 2. Make Pie Chart Card Full Width */
            .charts-grid-dashboard>div:first-child {
                flex: 0 0 auto !important;
                width: 100% !important;
                min-width: 100% !important;
                flex-direction: row !important;
                /* Keep legend side-by-side if you want */
                flex-wrap: wrap;
                /* Wrap on very small screens */
            }

            /* 3. Make Bar Chart Card Full Width & Give it Height */
            .charts-grid-dashboard>.chart-card {
                flex: 0 0 400px !important;
                /* Force a height for the bar chart */
                width: 100% !important;
                min-width: 100% !important;
            }

            /* 4. Allow the dashboard page to scroll */
            #dashboard.page.active {
                overflow-y: auto !important;
                display: block !important;
                /* Remove flex constraint on main page */
            }
        }

        /* Chart Cards stretch to fill grid */
        .chart-card {
            height: 100%;
        }

        .chart-card-,
        .chart-card {
            display: flex;
            flex-direction: column;
            background: #fff;
            border-radius: 8px;
            /* Assuming rounded corners */
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        /* Chart containers expand to fill card space */
        .chart-container {
            flex: 1;
            min-height: 0;
            position: relative;
            width: 100%;
        }

        @media (max-width: 1920px) {
            .chart-container {
                height: 160px;
                /* Slightly shorter cells on mobile */

            }
        }

        /* --- MINIMALIST ELEGANT CALENDAR --- */

        .calendar-wrapper {
            background: #ffffff;
            border-radius: 12px;
            /* Very subtle shadow for depth */
            box-shadow: 0 4px 25px rgba(0, 0, 0, 0.04);
            border: 1px solid #e0e0e0;
            height: 100%;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Header: Clean White with Dark Text */
        .calendar-header-styled {
            background: #ffffff;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #f0f0f0;
        }

        .calendar-header-styled h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 700;
            color: #1F2937;
            /* Dark Grey */
            font-family: 'Montserrat', sans-serif;
            letter-spacing: -0.5px;
        }

        /* Minimalist Nav Buttons */
        .cal-nav-btn {
            background: transparent;
            border: 1px solid #e5e7eb;
            color: #4B5563;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .cal-nav-btn:hover {
            border-color: #B88E2F;
            color: #B88E2F;
            background-color: #FFFDF5;
        }

        /* Days Header */
        .calendar-days-styled {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            padding: 15px;
            background: #fff;
            border-bottom: 1px solid #f0f0f0;
        }

        .calendar-days-styled span {
            text-align: center;
            color: #9CA3AF;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* The Grid: Uses gap to create thin lines */
        .calendar-grid-styled {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            flex: 1;
            overflow-y: auto;
            background-color: #F3F4F6;
            /* Color of the grid lines */
            gap: 1px;
            /* Creates 1px border lines */
            border-bottom: 1px solid #f0f0f0;
        }

        /* Individual Day Cell */
        .cal-cell {
            background-color: #ffffff;
            min-height: 100px;
            padding: 10px;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: background 0.2s;
        }

        .cal-cell:hover {
            background-color: #FAFAFA;
        }

        /* Date Number */
        .cal-cell-number {
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        /* "Today" Indicator: Elegant Gold Circle */
        .cal-cell.is-today .cal-cell-number {
            background-color: #B88E2F;
            color: white;
            box-shadow: 0 2px 5px rgba(184, 142, 47, 0.3);
        }

        /* Status Text Container (Bottom of cell) */
        .cal-stats {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        /* Small status rows inside the day */
        .status-row {
            font-size: 0.7rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            color: #555;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        /* Status Colors */
        .dot-occupied {
            background-color: #7E22CE;
        }

        /* Purple */
        .dot-reserved {
            background-color: #F59E0B;
        }

        /* Gold */
        .dot-full {
            background-color: #EF4444;
        }

        /* Red */

        /* Empty Month Cells */
        .cal-cell.other-month {
            background-color: #F9FAFB;
            color: #E5E7EB;
            pointer-events: none;
        }

        /* --- FULLY BOOKED STATUS (Red Alert) --- */
        .cal-cell.status-full {
            background-color: #FEE2E2 !important;
            /* Light Red Background */
            border: 1px solid #EF4444 !important;
            /* Thinner Red Border */
        }

        /* Change the number circle color */
        .cal-cell.status-full .cal-cell-number {
            color: #B91C1C;
            /* Dark Red Text */
            background-color: #FECACA;
            /* Slightly darker red circle */
        }

        /* The "SOLD OUT" pill itself */
        .cal-cell.status-full .status-pill {
            background-color: #DC2626;
            /* Deep Red background */
            color: white;
            /* White text */
            font-size: 0.65rem;
            /* Much smaller font size */
            font-weight: 700;
            padding: 3px 8px;
            /* Smaller padding */
            border-radius: 12px;
            /* Rounded corners for a pill shape */
            display: inline-block;
            /* Makes the box only as wide as the text */
            margin-top: auto;
            /* Pushes it to the very bottom of the cell */
            align-self: flex-start;
            /* Aligns it to the left side */
            text-transform: uppercase;
            /* Keeps it capitalized */
            letter-spacing: 0.5px;
            box-shadow: none;
            /* Remove any shadow that might make it look bulky */
        }

        /* Hover effect for red cells */
        .cal-cell.status-full:hover {
            background-color: #FECACA !important;
            border-color: #B91C1C !important;
        }


        /* --- PROFESSIONAL ROOM STATUS MODAL --- */

        /* The Modal Container */
        .modal-content-calendar {
            background: #ffffff;
            border-radius: 20px;
            /* This sets the roundness */
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            width: 90%;
            max-width: 480px;
            margin: auto;
            overflow: hidden;
            /* CRITICAL: This forces inner content to respect the border-radius */
            position: relative;
            border: 1px solid #f0f0f0;
            /* Transition is handled via JS for precision */
        }

        /* Animation for cards appearing */
        @keyframes cardFadeIn {
            from {
                opacity: 0;
                transform: translateY(15px) scale(0.98);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .room-status-card.show-anim {
            animation: cardFadeIn 0.3s ease forwards;
        }

        /* Modal Header */
        .room-modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ECEFF1;
            /* Add this to match the top corners of the parent container */
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
        }

        .room-modal-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: #111827;
            margin: 0;
            letter-spacing: -0.5px;
        }

        /* Close Button */
        .room-modal-close {
            background: #f3f4f6;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #4b5563;
            transition: all 0.2s;
        }

        .room-modal-close:hover {
            background: #e5e7eb;
            color: #111;
        }

        /* The Scrollable List */
        .room-list-container {
            max-height: 60vh;
            overflow-y: auto;
            padding: 0;
            /* Flush to edges */
        }

        /* Individual Room Card */
        .room-status-card {
            display: flex;
            align-items: center;
            padding: 16px 25px;
            border-bottom: 1px solid #f9f9f9;
            transition: background 0.2s ease;
        }

        .room-status-card:hover {
            background-color: #F9FAFB;
        }

        .room-status-card:last-child {
            border-bottom: none;
        }

        /* Room Number Box (Left Icon) */
        .room-id-box {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-weight: 700;
            font-size: 0.85rem;
            line-height: 1;
            flex-shrink: 0;
        }

        .room-id-label {
            font-size: 0.55rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 2px;
            opacity: 0.8;
        }

        /* Text Info Area */
        .room-info-area {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .room-guest-name {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 2px;
        }

        .room-status-detail {
            font-size: 0.75rem;
            color: #6b7280;
            font-weight: 500;
        }

        /* Status Badges (Right Side) */
        .status-badge-pill {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* --- THEME COLORS --- */

        /* 1. AVAILABLE (Green) */
        .theme-available .room-id-box {
            background: #ECFDF5;
            color: #059669;
        }

        .theme-available .status-badge-pill {
            background: #ECFDF5;
            color: #059669;
            border: 1px solid #D1FAE5;
        }

        /* 2. OCCUPIED (Purple) */
        .theme-occupied .room-id-box {
            background: #F3E8FF;
            color: #7E22CE;
        }

        .theme-occupied .status-badge-pill {
            background: #F3E8FF;
            color: #7E22CE;
            border: 1px solid #E9D5FF;
        }

        /* 3. RESERVED (Gold/Yellow) */
        .theme-reserved .room-id-box {
            background: #FFFBEB;
            color: #B45309;
        }

        .theme-reserved .status-badge-pill {
            background: #FFFBEB;
            color: #B45309;
            border: 1px solid #FEF3C7;
        }


        /* --- NEW MODAL STYLES (Paste this into your <style> tag) --- */

        /* The row container */
        .room-status-row {
            display: flex;
            align-items: center;
            background-color: #FAFAFA;
            /* Very light grey background for the whole strip */
            border-radius: 8px;
            margin-bottom: 12px;
            overflow: hidden;
            /* Ensures the box stays inside rounded corners */
        }

        /* The colored square with the number */
        .room-number-box {
            width: 70px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Montserrat', sans-serif;
            font-weight: 600;
            font-size: 1.1rem;
            color: #fff;
            /* Default text color */
            flex-shrink: 0;
        }

        /* The text details on the right */
        .room-details-text {
            padding-left: 15px;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.9rem;
            color: #000;
            font-weight: 500;
        }

        /* Specific styling for bold dates */
        .room-details-text b {
            font-weight: 700;
        }

        /* --- COLOR VARIANTS --- */

        /* 1. OCCUPIED (In House) - Darker Gold/Greenish */
        .box-occupied {
            background-color: #CDBD46;
        }

        /* 2. RESERVED (Future) - Lighter Yellow */
        .box-reserved {
            background-color: #F5E875;
            color: #333;
            /* Or #333 if you want dark text on light yellow */
        }

        /* 3. AVAILABLE - Grey */
        .box-available {
            background-color: #D6D6D6;
            color: #333;
            /* Dark text for grey box */
        }


        /* Modal Room List Styling */
        .room-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
        }

        .room-item.booked {
            background-color: #fff3cd;
        }

        .room-item .status-badge {
            font-size: 0.8rem;
            padding: 2px 6px;
            border-radius: 4px;
        }

        /* --- STYLES FOR DOUGHNUT CHART CARD --- */
        .progress-bar-bg {
            background-color: #f3f4f6;
            border-radius: 999px;
            height: 8px;
            width: 100%;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            border-radius: 999px;
        }

        .fs-xxs {
            font-size: 0.75rem;
            color: #666;
            font-weight: 500;
        }

        .legend-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }

        .d-flex-between {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .progress-list {
            padding-top: 10px;
            flex-shrink: 0;
        }

        /* --- BOOKING LIST STYLES --- */
        .booking-table-container {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);

            /* --- UPDATED FOR RESPONSIVENESS --- */
            min-height: 400px;
            height: auto;
            max-height: 75vh;
            overflow-y: auto;
            /* Enables vertical scrolling */
        }

        .booking-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
            /* Ensure it doesn't squish on mobile */
        }

        /* 🟢 NEW: Override for Modal History Tables to prevent horizontal scroll */
        .history-view .booking-table {
            min-width: 100% !important;
        }

        .booking-table th {
            background-color: #f4f4f4;
            /* Background is required so text doesn't show through */
            color: #555;
            font-weight: 600;
            text-align: left;
            padding: 15px;
            font-size: 0.85rem;

            /* --- NEW STICKY PROPERTIES --- */
            position: sticky;
            top: 0;
            z-index: 10;
            /* Ensures header stays on top of the body content */

            /* Replace border-bottom with box-shadow for better sticky behavior */
            border-bottom: none;
            box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.1);
        }

        .booking-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
            color: #333;
            font-size: 0.9rem;
            vertical-align: middle;
        }

        .booking-table tr:hover {
            background-color: #fafafa;
        }

        /* STATUS BADGES */
        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;

            /* --- FIX: Reset position so it stays inside the table cell --- */
            position: static;
            top: auto;
            right: auto;
            transform: none;
        }


        .badge-pending {
            background-color: #FFF3CD;
            color: #856404;
            border: 1px solid #FFEEBA;
        }

        .badge-confirmed {
            background-color: #D4EDDA;
            color: #155724;
            border: 1px solid #C3E6CB;
        }

        .badge-cancelled {
            background-color: #F8D7DA;
            color: #721C24;
            border: 1px solid #F5C6CB;
        }

        /* SOURCE BADGES (Online vs Walk-in) */
        .source-tag {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            margin-right: 5px;
            color: #888;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .source-online {
            color: #3B82F6;
        }

        /* Blue */
        .source-walkin {
            color: #8B5CF6;
        }

        /* Purple */

        /* --- TAB BUTTON STYLES --- */
        .tabs-container {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .tabs-container :last-child {
            margin-left: auto;
        }

        .tab-btn {
            padding: 8px 20px;
            border-radius: 8px;
            /* Rounded corners like pill */
            border: 1px solid #e0e0e0;
            background-color: #fff;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            color: #555;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .tab-btn:hover {
            background-color: #f9f9f9;
        }

        /* Active State (Yellow like "Incoming") */
        .tab-btn.active {
            background-color: #FFC107;
            /* Yellow */
            color: #fff;
            /* White text */
            border-color: #FFC107;
            box-shadow: 0 2px 5px rgba(255, 193, 7, 0.3);
        }

        /* Optional: Different color for Confirmed if you want (e.g., Blue) */
        .tab-btn[data-target="confirmed"].active {
            background-color: #10B981;
            /* Green to match confirmed badge */
            border-color: #10B981;
        }

        .hidden-row {
            display: none;
        }

        /* --- ARRIVAL STATUS BADGES --- */
        .arrival-badge {
            font-size: 0.7rem;
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: 600;
            margin-top: 4px;
            display: inline-block;
        }

        .arrival-awaiting {
            background-color: #E0F2FE;
            /* Light Blue */
            color: #0284C7;
            border: 1px solid #BAE6FD;
        }

        .arrival-inhouse {
            background-color: #F3E8FF;
            /* Light Purple */
            color: #7E22CE;
            border: 1px solid #E9D5FF;
        }

        .arrival-checkedout {
            background-color: #F3F4F6;
            /* Grey */
            color: #4B5563;
            border: 1px solid #E5E7EB;
        }

        /* --- ADD BOOKING MODAL FORM STYLES --- */

        /* Grid layouts for form rows */
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        /* Custom grid for the occupancy row (Select, Select, Checkboxes) */
        .form-grid-occupancy {
            display: grid;
            grid-template-columns: 1fr 1fr 1.5fr;
            /* Checkbox area is wider */
            gap: 20px;
            align-items: center;
        }

        /* Custom grid for the footer row */
        .form-grid-footer {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            /* Button area is wider */
            gap: 20px;
            margin-top: 30px;
        }

        /* The grey input fields and selects */
        .styled-input,
        .styled-select {
            width: 100%;
            padding: 15px;
            background-color: #EAEAEA;
            /* Match image grey */
            border: none;
            border-radius: 8px;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.9rem;
            color: #555;
            outline: none;
        }

        .styled-input.full-width {
            width: 100%;
        }

        .styled-select {
            appearance: none;
            /* Removes default arrow to look cleaner */
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23555' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
        }

        /* Date input icon container */
        .input-with-icon {
            position: relative;
        }

        .calendar-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            pointer-events: none;
            /* Let clicks pass through to the input */
        }

        /* Custom Checkboxes (to match the image style) */
        .checkbox-container {
            display: flex;
            align-items: center;
            position: relative;
            padding-left: 30px;
            margin-bottom: 10px;
            cursor: pointer;
            font-size: 0.9rem;
            color: #555;
            font-weight: 500;
            user-select: none;
        }

        .checkbox-container input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }

        .checkmark {
            position: absolute;
            top: 0;
            left: 0;
            height: 20px;
            width: 20px;
            background-color: #EAEAEA;
            border-radius: 4px;
        }

        .checkbox-container input:checked~.checkmark {
            background-color: #D4C85B;
            /* Gold when checked */
        }

        .checkmark:after {
            content: "";
            position: absolute;
            display: none;
        }

        .checkbox-container input:checked~.checkmark:after {
            display: block;
        }

        .checkbox-container .checkmark:after {
            left: 7px;
            top: 3px;
            width: 6px;
            height: 12px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        /* The Gold Submit Button */
        .gold-submit-btn {
            width: 100%;
            padding: 15px;
            background-color: #D4C85B;
            /* The specific gold from the image */
            color: white;
            border: none;
            border-radius: 8px;
            font-family: 'Montserrat', sans-serif;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .gold-submit-btn:hover {
            background-color: #C3B750;
            /* Slightly darker on hover */
        }

        /* Helper utilities used in modal */
        .mb-3 {
            margin-bottom: 15px;
        }

        .mb-4 {
            margin-bottom: 25px;
        }

        .pb-3 {
            padding-bottom: 15px;
        }

        .pt-2 {
            padding-top: 10px;
        }

        /* --- ISOLATED ADD BOOKING MODAL STYLES (Prefix: ab-) --- */
        .ab-modal-content {
            background-color: #ECEFF1;
            border-radius: 12px;
            /* Remove main padding so scrollbar hits the edge nicely */
            padding: 0;

            /* Sizing */
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            /* Limit height to 85% of screen */

            /* CENTERING MAGIC */
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            margin: 0;
            /* Remove the old 5% margin */

            /* Flex Layout for Internal Scrolling */
            display: flex;
            flex-direction: column;

            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            font-family: 'Montserrat', sans-serif;
            overflow: hidden;
            /* prevents double scrollbars */
        }

        .ab-modal-header {
            /* Header stays fixed at top */
            flex-shrink: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;

            /* Add padding here since we removed it from parent */
            padding: 25px 30px 15px 30px;
            background-color: #ECEFF1;
            /* Match bg */
            z-index: 10;
        }

        .ab-modal-body {
            /* Body scrolls independently */
            overflow-y: auto;
            flex-grow: 1;
            padding: 0 30px 30px 30px;

            /* Add padding here */
            padding: 0 30px 30px 30px;
        }

        /* Adjust close button position to fit new padding */
        .ab-close-btn {
            position: absolute;
            right: 30px;
            /* Aligned with padding */
            top: 25px;
            /* Aligned with padding */
            background: none;
            border: none;
            font-size: 1.2rem;
            color: #666;
            cursor: pointer;
        }

        /* Grids */
        .ab-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .ab-grid-occupancy {
            display: grid;
            grid-template-columns: 1fr 1fr 1.8fr;
            gap: 15px;
            align-items: center;
        }

        .ab-grid-footer {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 15px;
            margin-top: 25px;
        }

        /* Inputs & Selects */
        .ab-input,
        .ab-select {
            width: 100%;
            padding: 12px 15px;
            background-color: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            font-size: 0.9rem;
            color: #555;
            outline: none;
            box-sizing: border-box;
            /* Critical for layout */
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
        }

        .ab-input::placeholder {
            color: #9CA3AF;
        }

        /* Specific Select Styles */
        .ab-select {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
            cursor: pointer;
        }

        /* Date Icons Wrapper */
        .ab-input-wrapper {
            position: relative;
        }

        .ab-calendar-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #333;
            font-size: 1rem;
            pointer-events: none;
        }

        /* Checkboxes */
        .ab-checkbox-group {
            display: flex;
            justify-content: space-around;
        }

        .ab-checkbox {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            cursor: pointer;
            font-size: 0.8rem;
            color: #374151;
            font-weight: 600;
            user-select: none;
            gap: 5px;
        }

        /* Hide default checkbox */
        .ab-checkbox input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }

        /* Custom Checkmark */
        .ab-checkmark {
            height: 20px;
            width: 20px;
            background-color: #FFFFFF;
            border: 1px solid #9CA3AF;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .ab-checkbox input:checked~.ab-checkmark {
            background-color: #FFA000;
            border-color: #FFA000;
        }

        /* Submit Button */
        .ab-submit-btn {
            width: 100%;
            padding: 12px;
            background-color: #FFA000;
            /* Bright Orange */
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .ab-submit-btn:hover {
            background-color: #FF8F00;
        }

        /* Utilities */
        .ab-mb-3 {
            margin-bottom: 15px;
        }

        .ab-mb-4 {
            margin-bottom: 20px;
        }

        .ab-full-width {
            width: 100%;
        }

        /* --- MULTI-STEP WIZARD STYLES --- */

        /* Hide steps by default (managed by JS) */
        .ab-step {
            display: none;
            animation: fadeIn 0.3s ease-in-out;
        }

        .ab-step.active {
            display: block;
        }


        .ab-grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }

        /* Label Styling */
        .ab-label {
            display: block;
            font-size: 0.85rem;
            font-weight: 700;
            color: #4b5563;
            margin-bottom: 8px;
        }

        /* Red Asterisk for required fields */
        .ab-label span {
            color: #dc2626;
            /* Red color */
        }

        /* Header Container in Step 3 */
        .step-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
        }

        .step-header-title {
            font-size: 1.3rem;
            font-weight: 800;
            color: #333;
            margin: 0;
        }

        .btn-signin {
            background-color: #f3f4f6;
            border: 1px solid #d1d5db;
            color: #374151;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-signin:hover {
            background-color: #e5e7eb;
        }

        /* Responsive adjustment for small screens */
        @media (max-width: 600px) {
            .ab-grid-3 {
                grid-template-columns: 1fr;
                /* Stack vertically on mobile */
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Step 2: Room Selection Cards */
        .room-selection-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
            padding: 5px;
            /* Prevent shadow clipping */
        }

        .room-card {
            background: #fff;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            overflow: hidden;
            /* Clips image at corners */
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .room-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.05);
            border-color: #FFA000;
        }

        .room-card.selected {
            border: 2px solid #FFA000;
            background-color: #FFFDF5;
        }

        /* Image Area */
        .room-card-image {
            width: 100%;
            height: 140px;
            background-color: #eee;
            object-fit: cover;
        }

        /* Content Area */
        .room-card-body {
            padding: 15px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .room-card-header {
            font-weight: 700;
            color: #374151;
            font-size: 1rem;
        }

        .room-card-details {
            font-size: 0.75rem;
            color: #6B7280;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 5px;
        }

        .detail-badge {
            background: #F3F4F6;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .room-card-price {
            color: #FFA000;
            font-weight: 700;
            font-size: 1.1rem;
            margin-top: auto;
            /* Push to bottom */
        }

        /* Selection Checkmark */
        .room-card-check {
            position: absolute;
            top: 10px;
            right: 10px;
            height: 24px;
            width: 24px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid #D1D5DB;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .room-card.selected .room-card-check {
            background: #FFA000;
            border-color: #FFA000;
            color: white;
        }

        .room-card.selected .room-card-check::after {
            content: "✓";
            font-weight: bold;
            font-size: 14px;
        }

        /* Navigation Buttons */
        .step-nav-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            margin-bottom: 10px;
            /* Safety margin */
        }

        .btn-secondary {
            background: #eee;
            color: #555;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        /* --- CUSTOM FLATPICKR STYLES TO MATCH YOUR THEME --- */

        /* Make the calendar fit the modal (Scale down slightly if needed) */
        /* --- FIXED DATE PICKER STYLES --- */

        /* 1. Force the Calendar to be Wide (Fixes the "thin line" issue) */
        /* 1. Default Calendar Styles */
        .flatpickr-calendar {
            /* REMOVED: width: 630px !important; -- This caused the issue */
            max-width: 90vw;
            font-family: 'Montserrat', sans-serif;
            border: none !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2) !important;
            z-index: 9999 !important;
            margin-top: 10px;
        }

        /* 2. Specific Class for 2-Month Calendars (Add Booking) */
        .flatpickr-calendar.double-month-theme {
            width: 630px !important;
        }

        /* 3. Specific Class for 1-Month Calendars (Reschedule/Extend) */
        .flatpickr-calendar.compact-theme {
            width: 310px !important;
        }

        .flatpickr-calendar.single-month {
            width: 308px !important;
            /* Standard width for 1 month */
            max-width: 90vw;
        }

        /* 2. Style the Months Header (Gold/Grey Theme) */
        .flatpickr-months .flatpickr-month {
            background: #545454;
            color: #fff;
            fill: #fff;
            height: 50px;
            border-top-left-radius: 5px;
            border-top-right-radius: 5px;
        }

        .flatpickr-current-month .flatpickr-monthDropdown-months {
            background: #545454;
        }

        .flatpickr-weekdays {
            background: #f8f8f8;
        }

        span.flatpickr-weekday {
            color: #B88E2F;
            font-weight: bold;
        }

        /* 3. Style Selected Dates (Gold) */
        .flatpickr-day.selected,
        .flatpickr-day.startRange,
        .flatpickr-day.endRange,
        .flatpickr-day.selected.inRange,
        .flatpickr-day.startRange.inRange,
        .flatpickr-day.endRange.inRange,
        .flatpickr-day:hover {
            background: #B88E2F !important;
            border-color: #B88E2F !important;
            color: white !important;
        }

        /* 4. Range Highlight (Lighter Gold) */
        .flatpickr-day.inRange {
            box-shadow: -5px 0 0 #F5E875, 5px 0 0 #F5E875 !important;
            background: #F5E875 !important;
            border-color: #F5E875 !important;
            color: #333 !important;
        }

        /* 5. Input Field Styling (Gold Border Focus) */
        .custom-date-input {
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            /* Rounded corners like reference */
            padding: 12px 15px;
            font-size: 0.95rem;
            color: #333;
            width: 100%;
            cursor: pointer;
            outline: none;
            transition: all 0.2s ease;
            /* Calendar Icon */
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23B88E2F' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='4' width='18' height='18' rx='2' ry='2'%3E%3C/rect%3E%3Cline x1='16' y1='2' x2='16' y2='6'%3E%3C/line%3E%3Cline x1='8' y1='2' x2='8' y2='6'%3E%3C/line%3E%3Cline x1='3' y1='10' x2='21' y2='10'%3E%3C/line%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 18px;
            box-sizing: border-box;
            /* IMPORTANT: Keeps padding inside width */
        }

        .custom-date-input:focus,
        .custom-date-input.active {
            border-color: #B88E2F;
            box-shadow: 0 0 0 3px rgba(184, 142, 47, 0.1);
        }

        .input-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: #888;
            margin-bottom: 8px;
            display: block;
            text-transform: uppercase;
        }

        /* 6. Layout Fixes (Symmetry) */
        .input-row-flex {
            display: flex;
            gap: 20px;
            width: 100%;
            margin-top: 20px;
            margin-bottom: 30px;
        }

        .input-col {
            flex: 1;
            /* Forces both inputs to be exactly 50% width */
            min-width: 0;
        }

        /* Search Input Styling */
        .search-input {
            padding: 8px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.9rem;
            outline: none;
            width: 200px;
            /* Adjust width as needed */
            transition: all 0.2s;
        }

        .search-input:focus {
            border-color: #FFA000;
            /* Gold border on focus */
            box-shadow: 0 0 0 2px rgba(255, 160, 0, 0.1);
        }

        /* Ensure tabs don't stretch too wide on mobile */
        @media (max-width: 600px) {
            .tabs-container {
                flex-direction: column;
            }

            .tabs-container div {
                margin-left: 0 !important;
                width: 100%;
            }

            .search-input {
                width: 100%;
            }
        }

        /* --- FIX: BOOKINGS TOOLBAR --- */
        .bookings-toolbar {
            display: flex;
            justify-content: space-between;
            /* Tabs left, Search right */
            align-items: center;
            /* CRITICAL: Prevents buttons from stretching vertically */
            flex-wrap: wrap;
            /* Responsive on mobile */
            gap: 15px;
            margin-bottom: 20px;
            flex-shrink: 0;
            /* CRITICAL: Prevents the toolbar from expanding to fill the page */
            width: 100%;
        }

        .toolbar-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Ensure search input looks good */
        .search-input {
            padding: 10px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 0.9rem;
            width: 250px;
            outline: none;
        }

        .search-input:focus {
            border-color: #FFA000;
        }


        /* --- NEW ARRIVAL STATUS BADGES --- */
        .arrival-badge {
            font-size: 0.7rem;
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: 600;
            margin-top: 4px;
            display: inline-block;
        }

        /* Today */
        .arrival-today {
            background-color: #E0F2FE;
            /* Light Blue */
            color: #0284C7;
            border: 1px solid #BAE6FD;
        }

        /* Future */
        .arrival-upcoming {
            background-color: #FEF3C7;
            /* Light Yellow/Orange */
            color: #D97706;
            border: 1px solid #FDE68A;
        }

        /* Past/Overdue */
        .arrival-overdue {
            background-color: #FEE2E2;
            /* Light Red */
            color: #DC2626;
            border: 1px solid #FECACA;
        }

        /* Active States */
        .arrival-inhouse {
            background-color: #F3E8FF;
            /* Light Purple */
            color: #7E22CE;
            border: 1px solid #E9D5FF;
        }

        .arrival-checkedout {
            background-color: #F3F4F6;
            /* Grey */
            color: #4B5563;
            border: 1px solid #E5E7EB;
        }

        /* --- SETTINGS TREE VIEW STYLES --- */

        /* Controls switching between views */
        .settings-view {
            display: none;
            animation: fadeIn 0.3s ease-in-out;
        }

        .settings-view.active {
            display: flex !important;
            flex-direction: column;
            height: 100%;
            width: 100%;
        }

        /* The Grid for the Menu Items */
        .settings-tree-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            padding: 20px;
        }

        /* The Clickable Card Items */
        .tree-item-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            padding: 25px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .tree-item-card:hover {
            /* transform: translateY(-3px); */
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.05);
            border-color: #D4C85B;
            /* Gold Border on hover */
        }

        .tree-icon {
            font-size: 1.3rem;
            /* Icon size */
            background: #F9FAFB;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            color: #555;
            transition: all 0.3s ease;

            /* CRITICAL FIX: Prevents the circle from becoming an oval */
            flex-shrink: 0;
        }

        .tree-item-card:hover .tree-icon {
            background-color: #FFF3CD;
            color: #D4C85B;
            transform: scale(1.1);
        }

        .tree-item-card:last-child:hover .tree-icon {
            background-color: #FECACA;
            color: #B91C1C;
            transform: scale(1.1);
        }

        .tree-info h4 {
            margin: 0 0 5px 0;
            font-size: 1.1rem;
            color: #333;
        }

        .tree-info p {
            margin: 0;
            font-size: 0.8rem;
            color: #888;
        }

        /* Back Button Header */
        .settings-header {
            display: flex;
            align-items: center;
            /* margin-bottom: 20px; */
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
            gap: 15px;
        }

        .back-btn-settings {
            background: #f3f4f6;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            color: #555;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: background 0.2s;
        }

        .back-btn-settings:hover {
            background: #e5e7eb;
        }

        /* Simple Table for Sub-pages */
        .simple-list-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .simple-list-table th,
        .simple-list-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        .simple-list-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #555;
        }

        /* --- 1. Apply the Chevron Icon to BOTH Nationality Inputs --- */
        #nationalityInput,
        #edit_nation {
            /* 🔴 Added #edit_nation */
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23555' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
            cursor: pointer;
            position: relative;
        }

        /* --- 2. Hide the default browser arrow for BOTH --- */
        #nationalityInput::-webkit-calendar-picker-indicator,
        #edit_nation::-webkit-calendar-picker-indicator {
            /* 🔴 Added #edit_nation selector */
            opacity: 0;
            cursor: pointer;
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            width: 40px;
            margin: 0;
            padding: 0;
        }

        /* Ensure the wrapper allows positioning context for the arrow fix */
        #nationalityInput {
            position: relative;
        }

        /* --- ADMIN ADDRESS SEARCH STYLES --- */

        /* Spinner positioned inside the input */
        #adminAddrLoader {
            position: absolute;
            right: 15px;
            top: 38px;
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #D4C85B;
            /* Gold */
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: none;
            pointer-events: none;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Results Dropdown */
        #adminAddrResults {
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            display: none;
        }

        .address-result-item {
            padding: 10px 15px;
            font-size: 0.85rem;
            color: #333;
            cursor: pointer;
            border-bottom: 1px solid #f5f5f5;
            background: #fff;
        }

        .address-result-item:hover {
            background-color: #FFFDF5;
            /* Light Gold */
            color: #000;
        }

        /* Custom Chevron for Nationality Input */
        #adminNationalityInput {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23555' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
            cursor: pointer;
        }

        #adminNationalityInput::-webkit-calendar-picker-indicator {
            opacity: 0;
        }

        .simple-list-table tr {
            transition: background-color 0.2s;
        }

        .simple-list-table tr:hover {
            background-color: #fafafa;
        }

        .simple-list-table td {
            vertical-align: middle;
            /* Ensures text is centered vertically relative to the image */
        }

        /* Add this to your <style> section */

        #guestSearchInput {
            width: 400px;
            /* Standard length is 250px, this makes it significantly longer */
            max-width: 100%;
            /* Ensures it shrinks on mobile screens */
        }

        .room-table-container {
            min-height: 400px;
            height: auto;
            max-height: 75vh;
            overflow-y: auto;
        }

        #news_date_picker {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23B88E2F' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='4' width='18' height='18' rx='2' ry='2'%3E%3C/rect%3E%3Cline x1='16' y1='2' x2='16' y2='6'%3E%3C/line%3E%3Cline x1='8' y1='2' x2='8' y2='6'%3E%3C/line%3E%3Cline x1='3' y1='10' x2='21' y2='10'%3E%3C/line%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 18px;
            padding-right: 40px;
        }

        /* --- WIDE SINGLE CALENDAR STYLES --- */

        /* 1. Force the specific instance to be wide */
        .flatpickr-calendar.wide-news-calendar {
            width: 415px !important;
            /* Same width as your double calendar */

            /* Force the calendar down by 35 pixels */
            transform: translateY(-15px) !important;

            /* Ensure it stays on top */
            z-index: 9999 !important;
        }

        /* 2. Stretch the internal containers */
        .flatpickr-calendar.wide-news-calendar .flatpickr-months,
        .flatpickr-calendar.wide-news-calendar .flatpickr-weekdays,
        .flatpickr-calendar.wide-news-calendar .flatpickr-days {
            width: 100% !important;
        }

        /* 3. Ensure the day container fills the width */
        .flatpickr-calendar.wide-news-calendar .dayContainer {
            width: 100% !important;
            min-width: 100% !important;
            max-width: none !important;
            box-shadow: none !important;
            /* Remove separator lines if any */
        }

        /* 4. Make the individual days stretch to fill the row */
        .flatpickr-calendar.wide-news-calendar .flatpickr-day {
            max-width: none !important;
            flex-basis: 14.28% !important;
            /* 100% divided by 7 days */
            /* height: 50px; */
            /* Optional: Make them taller for a better look */
            /* line-height: 50px; */
        }

        .flatpickr-calendar.wide-news-calendar:before,
        .flatpickr-calendar.wide-news-calendar:after {
            display: none !important;
        }

        .tox-tinymce-aux {
            z-index: 9999 !important;
        }

        /* --- NEW NEWS MODAL STYLES --- */
        .news-layout-grid {
            display: grid;
            grid-template-columns: 200px 1fr;
            /* Fixed width image, flexible inputs */
            gap: 25px;
            margin-bottom: 20px;
        }

        /* Image Uploader Styling to match image */
        .news-image-box {
            width: 100%;
            height: 200px;
            /* Make it square */
            background-color: #F3F4F6;
            /* Light grey */
            border-radius: 12px;
            border: 2px dashed #E5E7EB;
            /* Subtle dashed border */
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: all 0.2s;
        }

        .news-image-box:hover {
            border-color: #D4C85B;
            background-color: #FFFDF5;
        }

        /* The small "Add" icon in top right corner */
        .news-add-icon-corner {
            position: absolute;
            top: 0;
            right: 0;
            background: #D1D5DB;
            /* Grey corner */
            width: 40px;
            height: 40px;
            border-bottom-left-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        /* Right side inputs wrapper */
        .news-inputs-col {
            display: flex;
            flex-direction: column;
            justify-content: center;
            /* Center vertically */
            gap: 20px;
        }

        /* Specific input styling for the clean look */
        .news-clean-input {
            background-color: #F9FAFB;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            padding: 12px 15px;
            width: 100%;
            outline: none;
            font-size: 0.9rem;
            color: #333;
        }

        .news-clean-input:focus {
            border-color: #D4C85B;
            background-color: #fff;
        }

        .news-label-clean {
            font-size: 0.85rem;
            font-weight: 600;
            color: #111;
            margin-bottom: 8px;
            display: block;
        }

        /* Mobile responsive fix */
        @media (max-width: 600px) {
            .news-layout-grid {
                grid-template-columns: 1fr;
                /* Stack vertically on phone */
                height: auto;
            }

            .news-image-box {
                height: 180px;
            }
        }

        /* --- NEW ROOM MODAL LAYOUT STYLES --- */
        .room-modal-grid-top {
            display: grid;
            grid-template-columns: 220px 1fr;
            /* Image takes 220px, inputs take rest */
            gap: 25px;
            margin-bottom: 20px;
        }

        .room-image-uploader {
            width: 100%;
            height: 220px;
            /* Square aspect ratio like image */
            background-color: #F3F4F6;
            border-radius: 12px;
            border: 2px dashed #E5E7EB;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: all 0.2s;
        }

        .room-image-uploader:hover {
            border-color: #D4C85B;
            background-color: #FFFDF5;
        }

        .room-image-preview {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: none;
        }

        .room-upload-icon-corner {
            position: absolute;
            top: 0;
            right: 0;
            background: #D1D5DB;
            width: 45px;
            height: 45px;
            border-bottom-left-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .room-inputs-col {
            display: flex;
            flex-direction: column;
            gap: 20px;
            justify-content: flex-start;
        }

        /* Middle Row: 3 Columns */
        .room-modal-grid-mid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        /* Styled Inputs matching the gray look in your image */
        .rm-input {
            width: 100%;
            padding: 12px 15px;
            background-color: #F5F5F5;
            /* Light Gray background */
            border: 1px solid #E0E0E0;
            border-radius: 8px;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.9rem;
            color: #333;
            outline: none;
        }

        .rm-input:focus {
            background-color: #fff;
            border-color: #D4C85B;
        }

        .rm-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: #111;
            margin-bottom: 8px;
        }

        /* Responsive for mobile */
        @media (max-width: 600px) {

            .room-modal-grid-top,
            .room-modal-grid-mid {
                grid-template-columns: 1fr;
            }

            .room-image-uploader {
                height: 200px;
            }
        }

        /* --- HEADER ACTION BUTTONS (High Visibility) --- */
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 30px;
            background-color: #fff;
        }

        /* --- HEADER ACTIONS --- */
        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
        }

        /* Wrapper to hold the button and the dropdown together */
        .action-wrapper {
            position: relative;
        }

        /* Buttons (Keep your existing gold style if you like, or revert to grey/white) */
        .icon-btn {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            font-size: 1.2rem;
            border: none;
            background-color: #f4f4f4;
            /* Light grey bg like prototype */
            color: #666;
        }

        .icon-btn:hover,
        .icon-btn.active {
            background-color: #e0e0e0;
            color: #333;
        }

        /* Notification Red Dot */
        .icon-badge {
            position: absolute;
            top: 0;
            right: 0;
            background-color: #EF4444;
            color: white;
            font-size: 0.6rem;
            font-weight: 700;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #fff;
        }

        /* --- THE DROPDOWN MENU (POPOVER STYLE) --- */
        .dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            /* Align right edge with the button */
            top: 60px;
            /* Distance from button */
            width: 380px;
            /* Wider like prototype */
            background-color: #fff;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            /* Soft, deep shadow */
            z-index: 2000;
            border: 1px solid #f0f0f0;

            /* Animation Origin: Top Right (from the button) */
            transform-origin: top right;
            animation: popIn 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .dropdown-menu.show {
            display: block;
        }

        /* The Triangle Arrow pointing up */
        .dropdown-menu::before {
            content: "";
            position: absolute;
            top: -8px;
            right: 18px;
            /* Center with the button */
            width: 16px;
            height: 16px;
            background-color: #fff;
            transform: rotate(45deg);
            border-top: 1px solid #f0f0f0;
            border-left: 1px solid #f0f0f0;
        }

        @keyframes popIn {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(-10px);
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        /* --- DROPDOWN CONTENT --- */
        .dropdown-header {
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #f5f5f5;
        }

        .dropdown-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: #1f2937;
            margin: 0;
        }

        .filter-btn {
            background: none;
            border: none;
            color: #9ca3af;
            font-size: 0.85rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 600;
        }

        .filter-btn:hover {
            color: #B88E2F;
        }

        .dropdown-list {
            max-height: 400px;
            overflow-y: auto;
            padding: 10px 0;
        }

        /* --- ITEMS STYLING --- */
        .dropdown-item-row {
            display: flex;
            padding: 15px 25px;
            cursor: pointer;
            transition: background 0.2s;
            gap: 15px;
            align-items: flex-start;
        }

        .dropdown-item-row:hover {
            background-color: #f9fafb;
        }

        /* Images/Icons */
        .item-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
        }

        .item-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        /* Icon Colors */
        .icon-gold {
            background-color: #FFF8E1;
            color: #B88E2F;
        }

        .icon-red {
            background-color: #FEE2E2;
            color: #EF4444;
        }

        .icon-blue {
            background-color: #DBEAFE;
            color: #3B82F6;
        }

        /* Text Content */
        .item-content {
            flex: 1;
            min-width: 0;
        }

        .item-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
        }

        .item-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: #111;
        }

        .item-time {
            font-size: 0.75rem;
            color: #9ca3af;
            font-weight: 500;
        }

        .item-desc {
            font-size: 0.8rem;
            color: #6b7280;
            line-height: 1.4;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* --- HEADER STYLES --- */
        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
        }

        .action-wrapper {
            position: relative;
        }

        /* Buttons */
        .icon-btn {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.2rem;
            border: none;
            background-color: #f4f4f4;
            color: #666;
            transition: all 0.2s;
            position: relative;
        }

        .icon-btn:hover {
            background-color: #e0e0e0;
            color: #333;
        }

        .icon-badge {
            position: absolute;
            top: 0;
            right: 0;
            background-color: #EF4444;
            color: white;
            font-size: 0.6rem;
            font-weight: 700;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #fff;
        }

        /* Dropdown (Popover) */
        .dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            top: 60px;
            width: 380px;
            background-color: #fff;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            z-index: 2000;
            border: 1px solid #f0f0f0;
            transform-origin: top right;
            animation: popIn 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .dropdown-menu.show {
            display: block;
        }

        .dropdown-menu::before {
            content: "";
            position: absolute;
            top: -8px;
            right: 18px;
            width: 16px;
            height: 16px;
            background-color: #fff;
            transform: rotate(45deg);
            border-top: 1px solid #f0f0f0;
            border-left: 1px solid #f0f0f0;
        }

        @keyframes popIn {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(-10px);
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        /* List Items */
        .dropdown-header {
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid #f5f5f5;
        }

        .dropdown-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: #1f2937;
            margin: 0;
        }

        .dropdown-list {
            max-height: 400px;
            overflow-y: auto;
            padding: 10px 0;
        }

        .dropdown-item-row {
            display: flex;
            padding: 15px 25px;
            cursor: pointer;
            transition: background 0.2s;
            gap: 15px;
            align-items: flex-start;
        }

        .dropdown-item-row:hover {
            background-color: #f9fafb;
        }

        .item-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
        }

        .item-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        /* Colors */
        .icon-gold {
            background-color: #FFF8E1;
            color: #B88E2F;
        }

        .icon-red {
            background-color: #FEE2E2;
            color: #EF4444;
        }

        .icon-blue {
            background-color: #DBEAFE;
            color: #3B82F6;
        }

        .item-content {
            flex: 1;
            min-width: 0;
        }

        .item-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
        }

        .item-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: #111;
        }

        .item-time {
            font-size: 0.75rem;
            color: #9ca3af;
        }

        .item-desc {
            font-size: 0.8rem;
            color: #6b7280;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* --- UPDATE THIS CSS CLASS --- */
        .dropdown-list {
            max-height: 350px;
            /* Limits height to ~5 items */
            overflow-y: auto !important;
            /* Forces vertical scrolling */
            padding: 10px 0;

            /* Optional: Makes scrollbar thin and pretty */
        }

        /* Find this class in your <style> section and update it: */
        .filter-menu-container {
            display: none;
            position: absolute;
            top: 50px;
            right: 20px;
            background: #fff;
            border: 1px solid #eee;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            z-index: 2001;
            min-width: 220px;
            /* Made slightly wider */
            /* overflow: hidden;  <-- REMOVE THIS LINE so the calendar can show */
        }

        /* Add this new style for the date input inside the filter */
        .filter-date-input {
            width: 90%;
            margin: 10px auto;
            display: block;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.85rem;
            font-family: 'Montserrat', sans-serif;
        }

        .filter-option {
            padding: 10px 15px;
            font-size: 0.85rem;
            color: #555;
            cursor: pointer;
            transition: background 0.1s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .filter-option:hover {
            background-color: #f9fafb;
            color: #B88E2F;
            /* Gold highlight */
        }

        .filter-option.active {
            background-color: #FFF8E1;
            color: #B88E2F;
            font-weight: 700;
        }

        /* --- REUSE ADDRESS SEARCH STYLES FOR EDIT PROFILE --- */

        /* The Spinner */
        #editAddrLoader {
            position: absolute;
            right: 15px;
            top: 38px;
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #D4C85B;
            /* Gold */
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            pointer-events: none;
        }

        /* The Dropdown List */
        #editAddrResults {
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            z-index: 2000;
            /* Higher Z-Index to sit above modal content */
        }

        /* --- ENGAGING NOTIFICATION TOAST --- */
        .new-booking-alert {
            display: none;
            /* Hidden by default */
            position: absolute;
            top: 90px;
            right: 30px;
            z-index: 1000;
            /* Ensure it floats above everything */

            /* Modern Card Look */
            background: #ffffff;
            border-radius: 16px;
            padding: 12px 18px;
            min-width: 340px;

            /* Deep "Floating" Shadow */
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1), 0 5px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.04);

            /* Flex Layout */
            display: flex;
            /* overridden by JS, but sets context */
            align-items: center;
            gap: 15px;

            /* Engaging Animation */
            transform-origin: top right;
            animation: elasticPop 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275);

            /* 🟢 FIX: Prevent flickering/blinking during animations */
            backface-visibility: hidden;
            -webkit-backface-visibility: hidden;
            will-change: transform, opacity;

            /* Remove old border styling */
            border-left: none !important;
        }

        /* Springy Entrance Animation */
        @keyframes elasticPop {
            0% {
                opacity: 0;
                transform: translateX(50px) scale(0.8);
            }

            100% {
                opacity: 1;
                transform: translateX(0) scale(1);
            }
        }

        /* 🟢 NEW: Toast Exit Animation */
        @keyframes toastSlideOut {
            0% {
                opacity: 1;
                transform: translateX(0) scale(1);
            }

            100% {
                opacity: 0;
                transform: translateX(100px) scale(0.9);
            }
        }

        .toast-out {
            animation: toastSlideOut 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards !important;
        }

        /* 🟢 NEW: Toast Progress Bar */
        .toast-progress-container {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: rgba(0, 0, 0, 0.05);
            border-bottom-left-radius: 16px;
            border-bottom-right-radius: 16px;
            overflow: hidden;
        }

        .toast-progress-bar {
            height: 100%;
            width: 100%;
            transform-origin: left;
        }

        .nb-content {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
            /* Pushes button to the right */
        }

        /* Icon Box with Dynamic Tint */
        .nb-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
            position: relative;
            overflow: hidden;
            background: transparent !important;
            /* Reset inline bg if any */
            border: none !important;
        }

        /* This creates a light background matching the icon's color automatically */
        .nb-icon-box::before {
            content: "";
            position: absolute;
            inset: 0;
            background-color: currentColor;
            /* Uses the text color set in HTML */
            opacity: 0.12;
            /* Low opacity creates the pastel tint */
        }

        /* Text Styling */
        .nb-text {
            display: flex;
            flex-direction: column;
        }

        .nb-text h4 {
            margin: 0;
            font-size: 0.9rem;
            font-weight: 800;
            color: #1F2937;
            letter-spacing: -0.3px;
        }

        .nb-text p {
            margin: 2px 0 0 0;
            font-size: 0.8rem;
            color: #6B7280;
            font-weight: 500;
        }

        /* Modern Pill Button */
        .nb-action-btn {
            background: #F3F4F6;
            border: none;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #4B5563;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .nb-action-btn:hover {
            background: #111827;
            /* Dark hover */
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }

        /* --- ADMIN PROFILE CARD (UPDATED) --- */
        .admin-profile-card {
            background: #fff;
            border-radius: 12px;
            padding: 0;
            /* REMOVED PADDING: Image will touch edges */
            display: flex;
            gap: 0;
            /* REMOVED GAP: Content sits side-by-side perfectly */
            align-items: stretch;
            /* Stretches image to full height */
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
            /* Ensures image fits inside rounded corners */
            /* width: 100%; */
            /* max-width: 900px; Increased overall width */

            width: auto;
            /* Or a fixed width like 800px */
            max-width: 1000px;
            /* Ensures it doesn't get too wide on large screens */

            /* These two lines do the centering */
            /* margin-left: auto;
            margin-right: auto; */
            margin-inline: 20px;

            /* Keep your existing styles (background color, border-radius, etc.) below */
            display: flex;
            background-color: white;
        }

        /* The Grey Card / Avatar Box */
        .admin-avatar {
            width: 320px;
            /* INCREASED SIZE: Was 180px */
            min-height: 320px;
            /* With these lines: */
            background-image: url('../../IMG/hotel_background.png');
            /* Path to your image */
            background-size: cover;
            /* Ensures the image covers the entire container */
            background-position: center;
            /* Centers the image within the container */
            background-repeat: no-repeat;
            /* Prevents the image from repeating */
            /* The Grey Color */
            border-radius: 0;
            /* Remove internal radius to flush with card */
            flex-shrink: 0;
            object-fit: cover;
        }

        /* The Text Area */
        .admin-info-group {
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 40px;
            /* Reduced from 20px to match smaller text */
            padding: 30px;
            /* Reduced from 40px for better proportion */
            flex-grow: 1;
        }

        .admin-label-val h4 {
            margin: 0 0 4px 0;
            font-size: 0.85rem;
            /* Was 1.2rem -> Now smaller and sharper */
            font-weight: 700;
            color: #333;
            /* Lighter grey to distinguish it as a label */
            text-transform: uppercase;
            /* Optional: Makes it look cleaner/professional */
            letter-spacing: 0.5px;
        }

        .admin-label-val p {
            margin: 0;
            font-size: 1rem;
            /* Was 1.05rem -> Now standard readable size */
            color: #888;
            /* Darker for readability */
            font-weight: 500;
        }

        /* Gold Edit Button Position Adjustment */
        .btn-gold-edit {
            background-color: #D4C85B;
            color: #fff;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            position: absolute;
            bottom: 30px;
            right: 30px;
            transition: background 0.2s;
            box-shadow: 0 4px 10px rgba(212, 200, 91, 0.3);
        }

        /* --- MOBILE RESPONSIVE UPDATE --- */
        @media (max-width: 768px) {
            .admin-profile-card {
                flex-direction: column;
                /* Stack vertically on mobile */
                align-items: center;
                text-align: center;
                padding-bottom: 80px;
                /* Make room for button */
            }

            .admin-avatar {
                width: 100%;
                /* Full width on mobile */
                height: 250px;
            }

            .admin-info-group {
                padding: 30px 20px;
                width: 100%;
            }

            .btn-gold-edit {
                position: absolute;
                bottom: 20px;
                right: 50%;
                transform: translateX(50%);
                /* Center button on mobile */
                width: 80%;
                justify-content: center;
            }
        }

        /* --- ADMIN EDIT MODAL/FORM (Matches Image 2) --- */
        .admin-edit-container {
            background: #fff;
            padding: 30px 40px;
            /* Wider padding */
            border-radius: 12px;
            max-width: 600px;
            margin: 0 auto;
            position: relative;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .edit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .edit-header h3 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 700;
            color: #333;
        }

        .close-x-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #888;
            cursor: pointer;
        }

        .edit-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .edit-row-1 {
            margin-bottom: 20px;
        }

        .edit-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .edit-input {
            width: 100%;
            padding: 12px 15px;
            background-color: #F5F5F5;
            /* Light grey bg */
            border: 1px solid #E0E0E0;
            border-radius: 8px;
            font-size: 0.95rem;
            outline: none;
            color: #333;
        }

        .edit-input:focus {
            border-color: #D4C85B;
            background-color: #fff;
        }

        .btn-gold-confirm {
            width: 100%;
            background-color: #D4C85B;
            color: white;
            border: none;
            padding: 15px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
        }

        .btn-gold-confirm:hover {
            background-color: #C3B750;
        }

        /* Mobile responsive */
        @media (max-width: 600px) {
            .admin-profile-card {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }

            .btn-gold-edit {
                position: static;
                width: 100%;
                justify-content: center;
                margin-top: 20px;
            }

            .edit-row-2 {
                grid-template-columns: 1fr;
            }
        }

        /* Policy Builder Styles */
        .policy-card {
            background: #F9FAFB;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            position: relative;
            transition: all 0.2s;
        }

        .policy-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border-color: #D1D5DB;
        }

        .policy-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            gap: 15px;
        }

        .policy-title-input {
            flex-grow: 1;
            font-size: 1.1rem;
            font-weight: 700;
            padding: 10px;
            border: 1px solid #D1D5DB;
            border-radius: 6px;
            outline: none;
            color: #333;
        }

        .policy-title-input:focus {
            border-color: #FFA000;
            background: #fff;
        }

        .btn-delete-policy {
            background: #FEE2E2;
            color: #DC2626;
            border: 1px solid #FECACA;
            padding: 10px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            white-space: nowrap;
            transition: background 0.2s;
        }

        .btn-delete-policy:hover {
            background: #FECACA;
        }

        /* --- SCROLLABLE POLICY CONTAINER --- */
        /* 1. Make the white card a fixed height Flex Container */
        .settings-section.flex-card {
            display: flex;
            flex-direction: column;
            height: 75vh;
            /* Occupies 75% of the viewport height */
            overflow: hidden;
            /* Prevents the card itself from growing */
        }

        /* 2. Header Area (Stays put) */
        .settings-title-area {
            flex-shrink: 0;
        }

        #policy-builder-container {
            flex-grow: 1;
            /* Takes up all available empty space */
            overflow-y: auto;
            /* Enables scroll inside this area only */
            min-height: 0;
            /* Critical for Flexbox scrolling */

            /* Cosmetic Tweaks */
            padding-right: 10px;
            margin-bottom: 10px;
            border: 1px solid #f0f0f0;
            border-radius: 6px;
            background-color: #fafafa;
        }

        .settings-footer {
            flex-shrink: 0;
            /* Prevents it from being squished */
            background: #fff;
            padding-top: 10px;
            border-top: 1px solid #eee;
            z-index: 10;
        }

        /* --- UNIVERSAL CUSTOM SCROLLBAR (All Scrollable Areas) --- */
        * {
            scrollbar-width: thin;
            scrollbar-color: #ccc #f1f1f1;
        }

        /* 1. Define width */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        /* 2. Track (Background) */
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        /* 3. Thumb (The moving part) */
        ::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 4px;
        }

        /* 4. Hover (Gold Highlight) */
        ::-webkit-scrollbar-thumb:hover {
            background: #B88E2F;
        }

        /* --- 4-IMAGE GALLERY STYLES --- */
        .room-gallery-grid {
            width: 220px;
            /* Matches your previous single image width */
            height: 220px;
        }

        .gallery-box {
            background-color: #F3F4F6;
            border: 2px dashed #E5E7EB;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            overflow: hidden;
            position: relative;
            transition: all 0.2s;
            height: 105px;
            /* Fits 2 rows in 220px height */
        }

        .gallery-box:hover {
            background-color: #FFFDF5;
            border-color: #D4C85B;
        }

        .gallery-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .gallery-placeholder {
            font-size: 0.8rem;
            color: #999;
            font-weight: 600;
        }

        .gallery-input {
            display: none;
        }

        /* --- FIX FOR SINGLE/COMPACT CALENDARS --- */
        .flatpickr-calendar.compact-theme {
            width: 310px !important;
            /* Force standard single width */
            max-width: 90vw !important;
        }

        .flatpickr-calendar.compact-theme .flatpickr-days {
            width: 310px !important;
        }

        .flatpickr-calendar.compact-theme .flatpickr-month {
            border-radius: 5px 5px 0 0;
            /* Fix header radius */
        }

        /* --- FIX: Month Dropdown Contrast --- */

        /* 1. Target the dropdown container that appears when clicked */
        .flatpickr-monthDropdown-months {
            background-color: #545454 !important;
            color: #fff !important;
            border: none;
            outline: none;
            -moz-appearance: none;
            -webkit-appearance: none;
            appearance: none;
            padding: 0 5px;
            cursor: pointer;
        }

        /* 2. Style the dropdown options for ALL browsers */
        .flatpickr-monthDropdown-months option {
            background-color: #fff !important;
            color: #333 !important;
            font-weight: 500;
            padding: 8px;
        }

        /* 3. Ensure the dropdown arrow is visible */
        .flatpickr-monthDropdown-months::-ms-expand {
            display: none;
            /* Hide default arrow in IE */
        }

        /* 4. Fix the dropdown when hovered/open */
        .flatpickr-monthDropdown-months:hover,
        .flatpickr-monthDropdown-months:focus {
            background-color: #545454 !important;
            color: #fff !important;
        }

        /* 5. Fix the Year input to match */
        .flatpickr-current-month .numInputWrapper span.arrowUp:after {
            border-bottom-color: #fff !important;
        }

        .flatpickr-current-month .numInputWrapper span.arrowDown:after {
            border-top-color: #fff !important;
        }

        /* 6. Additional WebKit/Blink browsers fix */
        .flatpickr-monthDropdown-months:-webkit-any-select {
            background-color: #545454 !important;
            color: #fff !important;
        }

        .flatpickr-monthDropdown-months:-webkit-any-select option {
            background-color: #fff !important;
            color: #333 !important;
        }

        @media (max-width: 600px) {
            .hide-on-mobile {
                display: none;
            }
        }

        /* Custom styling for the QR Scanner container */
        #reader {
            width: 100% !important;
            border: none !important;
        }

        #reader__dashboard_section_csr button {
            background-color: #9e8236 !important;
            color: white !important;
            border: none !important;
            padding: 10px 20px !important;
            border-radius: 5px !important;
            cursor: pointer !important;
        }

        /* --- 🟢 FIXED CUSTOM SELECT CSS (PORTAL MODE) --- */

        /* 1. Hide original select */
        select.ab-select,
        select.rm-input {
            display: none !important;
        }

        .custom-select-wrapper {
            position: relative;
            user-select: none;
            width: 100%;
            font-family: 'Montserrat', sans-serif;
        }

        /* Trigger Box */
        .custom-select-trigger {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 15px;
            font-size: 0.9rem;
            color: #555;
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .hover-button-add-booking {
            background: #FFA500;
        }

        .hover-button-add-booking:hover {
            background: #EA580C;

        }

        .btn-primary {
            background: #333;
        }

        .btn-primary:hover {
            background: #8f8989;
        }




        .custom-select-wrapper.open .custom-select-trigger {
            border-color: #FFA000;
            box-shadow: 0 0 0 2px rgba(255, 160, 0, 0.1);
        }

        .custom-arrow {
            font-size: 0.8rem;
            color: #9CA3AF;
            transition: transform 0.3s ease;
        }

        .custom-select-wrapper.open .custom-arrow {
            transform: rotate(180deg);
            color: #FFA000;
        }

        /* 🔴 THE DROPDOWN LIST (Now detached from modal) */
        .custom-options {
            position: fixed;
            /* Fixed relative to screen, not modal */
            background: #fff;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            z-index: 10001;
            /* Higher than all modals */

            /* Initial Hidden State */
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px) scale(0.95);
            pointer-events: none;

            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            transform-origin: top center;

            max-height: 250px;
            overflow-y: auto;
            width: auto;
            /* Width will be set by JS */
        }

        /* Open State (Applied directly to the options div) */
        .custom-options.open {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
            pointer-events: auto;
        }

        /* Option Item */
        .custom-option {
            padding: 12px 15px;
            font-size: 0.9rem;
            color: #4B5563;
            cursor: pointer;
            border-bottom: 1px solid #f9fafb;
        }

        .custom-option:hover {
            background-color: #FFF8E1;
            color: #FFA000;
            padding-left: 20px;
        }

        .custom-option.selected {
            background-color: #FFA000;
            color: #fff;
        }

        /* --- 🟢 FINAL: VERTICAL STACKED PROFILE VIEW (Figma Style) --- */

        /* 1. Main View Container */
        #view-profile.settings-view.active {
            display: flex !important;
            flex-direction: column;
            height: 100%;
            /* Fill parent height */
            background-color: #F8F9FA;
            /* Light gray background like standard dashboards */
        }

        /* 2. Fixed Header */
        .pp-fixed-header {
            padding-left: 20px;
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            gap: 15px;
            z-index: 10;
        }

        /* 3. Scrollable Content Wrapper */
        .pp-scroll-area {
            flex: 1;
            /* Take remaining height */
            overflow-y: auto;
            /* Scroll vertically */
            padding-inline: 30px;
            padding-top: 15px;
            /* Spacing around cards */
            display: flex;
            flex-direction: column;
            /* Stack items */
            align-items: center;
            /* Center cards horizontally */
            gap: 30px;
            /* Space between Admin & Payment cards */
        }

        /* 4. Section Container (Title + Card) */
        .pp-section-group {
            width: 100%;
            max-width: 1000px;
            /* Limit width for readability on wide screens */
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        /* 5. Section Titles */
        .pp-section-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #4B5563;
            /* Dark Grey */
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0;
            padding-left: 15px;
            border-left: 4px solid #D4C85B;
            /* Gold Accent */
        }

        /* 6. The Card Design (Uniform Look) */
        .pp-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            border: 1px solid #f0f0f0;
            display: flex;
            flex-direction: row;
            overflow: hidden;
            min-height: 240px;
            /* Increased from 220px to give room */
            height: auto;
            /* Allows it to grow if content is added */
            transition: transform 0.2s ease;
        }

        /* Image/Avatar Area (Left Side) */
        .pp-card-left {
            width: 260px;
            /* Slightly wider to match new height ratio */
            background-color: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            border-right: 1px solid #eee;
            flex-shrink: 0;
            position: relative;
            /* Ensure image covers the area */
            align-self: stretch;
        }

        .pp-avatar-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Info Area (Right Side) */
        .pp-card-right {
            flex-grow: 1;
            padding: 30px;
            /* Increased padding */
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            /* Spreads content out (Details top, Button bottom) */
            gap: 20px;
            /* Adds breathing room between Grid and Button */
        }

        .pp-info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            /* Two info columns */
            gap: 20px;
            /* margin-bottom: 20px; */
        }

        .pp-label {
            font-size: 0.75rem;
            color: #9CA3AF;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .pp-value {
            font-size: 1rem;
            /* Slightly smaller font to fit email addresses */
            white-space: nowrap;
            /* Prevents wrapping */
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Edit Button (Absolute positioned bottom-right of card) */
        .pp-edit-btn {
            align-self: flex-start;
            background-color: #fff;
            border: 2px solid #D4C85B;
            color: #D4C85B;
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .pp-edit-btn:hover {
            background-color: #D4C85B;
            color: white;
        }

        /* --- RESPONSIVENESS FOR TABLET (Latitude 5285) --- */
        @media (max-width: 900px) {
            .pp-card {
                flex-direction: column;
                /* Stack image on top of text */
                min-height: auto;
            }

            .pp-card-left {
                width: 100%;
                height: 200px;
                border-right: none;
                border-bottom: 1px solid #eee;
            }

            .pp-info-grid {
                grid-template-columns: 1fr;
                /* Stack info items */
                gap: 15px;
            }

            .pp-edit-btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* --- PENDING BOOKINGS DRAWER STYLES --- */

        /* The Button Styling (Orange Pulse) */
        .btn-pending {
            position: relative;
            background-color: #FFF7ED;
            /* Light Orange */
            color: #EA580C;
            border: 1px solid #FED7AA;
        }

        .btn-pending:hover {
            background-color: #FFEDD5;
        }

        .pulse-dot {
            position: absolute;
            top: 0px;
            right: 0px;
            width: 12px;
            height: 12px;
            background-color: #EA580C;
            border-radius: 50%;
            border: 2px solid #fff;
            animation: pulse-orange 2s infinite;
        }

        @keyframes pulse-orange {
            0% {
                box-shadow: 0 0 0 0 rgba(234, 88, 12, 0.7);
            }

            70% {
                box-shadow: 0 0 0 6px rgba(234, 88, 12, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(234, 88, 12, 0);
            }
        }

        /* The Sliding Drawer */
        .side-drawer {
            position: fixed;
            top: 0;
            right: -450px;
            /* Hidden by default */
            width: 400px;
            height: 100vh;
            background: #fff;
            box-shadow: -5px 0 25px rgba(0, 0, 0, 0.15);
            z-index: 3000;
            /* On top of everything */
            transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
        }

        .side-drawer.open {
            right: 0;
            /* Slide in */
        }

        /* Drawer Components */
        .drawer-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff;
        }

        .drawer-body {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
            background: #F9FAFB;
        }

        /* Booking Card inside Drawer */
        .pending-card {
            background: #fff;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid #E5E7EB;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.03);
            transition: transform 0.2s;
        }

        .pending-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        .receipt-preview-box {
            width: 100%;
            height: 150px;
            background-color: #f3f4f6;
            border-radius: 8px;
            margin: 10px 0;
            overflow: hidden;
            cursor: pointer;
            border: 1px solid #eee;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .receipt-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }

        .receipt-img:hover {
            transform: scale(1.05);
        }

        .drawer-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 10px;
        }

        /* Image Lightbox (Zoom View) */
        .lightbox-modal {
            display: none;
            position: fixed;
            z-index: 4000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.85);
            align-items: center;
            justify-content: center;
        }

        .lightbox-img {
            max-width: 90%;
            max-height: 90%;
            border-radius: 8px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
        }

        .lightbox-close {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 40px;
            cursor: pointer;
        }

        /* Overlay backing for drawer */
        .drawer-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.3);
            z-index: 2999;
        }

        .drawer-overlay.show {
            display: block;
        }

        /* Add this to your CSS or <style> block */
        .receipt-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            padding-bottom: 50px;
        }

        .receipt-card {
            background: #fff;
            border: 1px solid #eee;
            border-radius: 8px;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }

        .receipt-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.08);
            border-color: #B88E2F;
        }

        .receipt-thumb {
            width: 100%;
            height: 250px;
            /* Tall receipt format */
            object-fit: cover;
            object-position: top;
            background-color: #f9f9f9;
            border-bottom: 1px solid #eee;
        }

        .receipt-info {
            padding: 12px;
        }

        .r-type {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #B88E2F;
            display: block;
            margin-bottom: 3px;
        }

        .r-ref {
            font-size: 0.95rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }

        .r-date {
            font-size: 0.8rem;
            color: #888;
        }

        /* Transaction Table Hover Effect */
        .transaction-row {
            transition: background-color 0.2s ease;
        }

        .transaction-row:hover {
            background-color: #f9fafb !important;
            /* Light grey hover */
        }

        /* 1. Define the Windows 11 Easing and Keyframes */
        @keyframes win11Show {
            0% {
                opacity: 0;
                /* Start slightly lower (-46%) and slightly smaller (0.95) */
                transform: translate(-50%, -46%) scale(0.95);
            }

            100% {
                opacity: 1;
                /* Snap to perfect center and full size */
                transform: translate(-50%, -50%) scale(1);
            }
        }

        @keyframes fadeInBackdrop {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        /* 2. Apply to the Background Overlay */
        .modal {
            animation: fadeInBackdrop 0.2s ease-out forwards;
        }

        /* 3. Apply to Your Specific Modal Cards */
        .ab-modal-content,
        .modal-content-calendar,
        .modal-content {
            /* For the logout modal */
            /* 0.3s duration 
       cubic-bezier(0.1, 0.9, 0.2, 1) = The specific "Fluent" snap deceleration 
    */
            animation: win11Show 0.3s cubic-bezier(0.1, 0.9, 0.2, 1) forwards;

            /* Ensure it starts centered so the animation logic works */
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        /* --- PREMIUM STORYBOARD LOGOUT --- */
        .logout-card-premium {
            max-width: 550px !important;
            /* Wider for the split layout */
            display: flex !important;
            flex-direction: row;
            border-radius: 20px !important;
            overflow: hidden;
            background: #fff !important;
            border: none !important;
        }

        /* Left Side: Visual/Branding */
        .logout-visual {
            flex: 1;
            background: linear-gradient(135deg, #B88E2F 0%, #D4C85B 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            padding: 40px 20px;
            text-align: center;
        }

        .logout-visual img {
            width: 70px;
            height: auto;
            margin-bottom: 15px;
            opacity: 0.9;
        }

        /* Right Side: Actions */
        .logout-content-area {
            flex: 1.2;
            padding: 40px 30px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .logout-content-area h3 {
            font-size: 1.5rem;
            font-weight: 800;
            color: #111827;
            margin: 0 0 10px 0;
        }

        .logout-content-area p {
            color: #6B7280;
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 25px;
        }

        .logout-btn-stack {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .btn-logout-main {
            background: #111827;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-logout-main:hover {
            background: #374151;
            transform: scale(1.02);
        }

        .btn-logout-secondary {
            background: #F3F4F6;
            color: #4B5563;
            border: none;
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-logout-secondary:hover {
            background: #E5E7EB;
            color: #111827;
        }

        /* Responsive fix for small mobile */
        @media (max-width: 500px) {
            .logout-card-premium {
                flex-direction: column;
                max-width: 90% !important;
            }

            .logout-visual {
                padding: 20px;
            }
        }

        /* --- 1. Fix the Sidebar Spacing --- */
        .sidebar {
            padding-top: 20px;
            padding-right: 40px;
            /* Removes the white gap at the top */
            background-color: #fff;
            /* Ensure bg is clean */
            border-right: 1px solid #e0e0e0;
        }

        /* --- 2. Compact & Elegant Header --- */
        .sidebar-header {
            /* Height Control: Keeps it small but breathable */
            height: 70px;
            padding: 0 20px;

            /* Layout: Horizontal Flex */
            display: flex;
            align-items: center;
            /* Vertically center */
            justify-content: flex-start;
            /* Align left */

            background-color: #ffffff;
            border-bottom: 1px solid #f0f0f0;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 9px;
            /* Space between logo and text */
            width: 100%;
        }

        /* --- 3. Refined Logo Image --- */
        .logo-icon-wrapper {
            width: 56px;
            /* Small footprint */
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        /* --- 4. Typography (Gold & Dark Grey) --- */
        .logo-text-group {
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .brand-text {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.7rem;
            font-weight: 800;
            /* Bold/Premium look */
            color: #333333;
            /* Dark Grey for readability */
            line-height: 1;
            letter-spacing: -0.5px;
            /* Tighter styling */
        }

        .brand-subtext {
            font-family: 'Montserrat', sans-serif;
            font-size: 0.6rem;
            color: #B88E2F;
            /* System Gold Color */
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            /* Wide spacing for elegance */
            margin-top: 2px;
        }

        /* --- 5. Add spacing back to the menu items --- */
        /* 🟢 NEW: INTERACTIVE PAGINATION STYLES */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            background: #ffffff;
            border-top: 1px solid #f3f4f6;
            border-radius: 0 0 16px 16px;
        }

        .pagination-info {
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 500;
        }

        .pagination-info span {
            color: #111827;
            font-weight: 600;
        }

        .pagination-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .pg-btn {
            height: 36px;
            min-width: 36px;
            padding: 0 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            color: #374151;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
        }

        .pg-btn:hover:not(:disabled) {
            background: #f9fafb;
            border-color: #d1d5db;
            color: #111827;
            transform: translateY(-1px);
        }

        .pg-btn:active:not(:disabled) {
            transform: translateY(0);
        }

        .pg-btn.active {
            background: #10B981;
            border-color: #10B981;
            color: #ffffff;
            box-shadow: 0 4px 10px rgba(16, 185, 129, 0.2);
        }

        .pg-btn:disabled {
            background: #f3f4f6;
            border-color: #e5e7eb;
            color: #9ca3af;
            cursor: not-allowed;
        }

        .pg-btn-nav {
            padding: 0 10px;
            gap: 6px;
        }

        .pg-dots {
            color: #9ca3af;
            padding: 0 4px;
            font-weight: 700;
        }

        .nav-menu {
            padding-top: 40px;
            /* Pushes the menu down slightly from the header */
        }
    </style>
</head>

<body>

    <!-- 🟢 GLOBAL UI LOCKER (Loading Overlay) -->
    <div id="globalLoadingOverlay"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; backdrop-filter:blur(2px); flex-direction:column; align-items:center; justify-content:center; color:white; font-family:'Montserrat', sans-serif;">
        <div
            style="border: 5px solid #f3f3f3; border-top: 5px solid #B88E2F; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite;">
        </div>
        <p
            style="margin-top:20px; font-weight:700; font-size:1.1rem; letter-spacing:1px; text-shadow: 0 2px 4px rgba(0,0,0,0.3);">
            PROCESSING STAY UPDATE...</p>
        <p style="margin-top:5px; font-size:0.85rem; opacity:0.8;">Please do not close or refresh the page.</p>
    </div>

    <style>
        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }
    </style>

    <!-- <div
        style="background:red; color:white; padding:10px; text-align:center; font-weight:bold; z-index:9999; position:relative;">
        PHP Server Time: <?php echo date('Y-m-d h:i:s A'); ?> <br>
        Your PC Time:
        <script>document.write(new Date().toLocaleTimeString());</script>
    </div> -->
    <div class="dashboard-container">
        <nav class="sidebar" style="font-size:0.85rem;">
            <div class="sidebar-header">
                <div class="logo-container">
                    <div class="logo-icon-wrapper">
                        <img src="../../IMG/5.png" alt="AMV" class="logo-img">
                    </div>

                    <div class="logo-text-group">
                        <span class="brand-text">AMV</span>
                        <span class="brand-subtext">Admin</span>
                    </div>
                </div>
            </div>
            <ul class="nav-menu">
                <li class="nav-item" data-page="dashboard">
                    <a href="#" class="nav-link">
                        <i class="fas fa-th-large"></i>
                        <span>Overview</span>
                    </a>
                </li>

                <li class="nav-item" data-page="calendar">
                    <a href="#" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Calendar</span>
                    </a>
                </li>

                <li class="nav-item" data-page="guests">
                    <a href="#" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Guests</span>
                    </a>
                </li>

                <li class="nav-item" data-page="bookings">
                    <a href="#" class="nav-link">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Bookings</span>
                    </a>
                </li>

                <li class="nav-item" data-page="food-ordered">
                    <a href="#" class="nav-link">
                        <i class="fas fa-utensils"></i>
                        <span>Food Ordered</span>
                    </a>
                </li>

                <li class="nav-item" data-page="transactions">
                    <a href="#" class="nav-link">
                        <i class="fas fa-credit-card"></i>
                        <span>Transactions</span>
                    </a>
                </li>

                <li class="nav-item" data-page="settings">
                    <a href="#" class="nav-link">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
                    </a>
                </li>
            </ul>

            <div class="sidebar-footer">
                <a href="#" class="logout-btn" id="logoutBtn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </nav>

        <main class="main-content">
            <header class="top-header">
                <div class="header-left">
                    <h1 class="page-title ml-2 fs-md">Dashboard</h1>
                </div>

                <div class="header-right">
                    <div class="action-wrapper">
                        <button class="icon-btn btn-compose" onclick="toggleDropdown('msgDropdown', event)">
                            <i class="fas fa-envelope"></i>
                            <span class="icon-badge" style="display:none">0</span>
                        </button>

                        <div id="msgDropdown" class="dropdown-menu">
                            <div class="dropdown-header"
                                style="position: relative; display:flex; flex-direction:column; gap:10px;">
                                <div
                                    style="display:flex; justify-content:space-between; width:100%; align-items:center;">
                                    <h4 class="dropdown-title">Messages</h4>
                                    <div style="display:flex; gap: 8px;">
                                        <button class="filter-btn" style="color: #2563EB; font-size: 0.7rem;"
                                            onclick="markAllMessagesRead(event)">
                                            <i class="fas fa-check-double"></i> Mark All
                                        </button>
                                        <button class="filter-btn" onclick="toggleMsgFilter(event)">
                                            <i class="fas fa-sliders-h"></i> Filter
                                        </button>
                                    </div>
                                </div>

                                <button onclick="openComposeModal()"
                                    style="width:100%; background:#2563EB; color:white; border:none; padding:10px; border-radius:6px; font-weight:600; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px;">
                                    <i class="fas fa-pen"></i> Compose New Message
                                </button>

                                <div id="msgFilterMenu" class="filter-menu-container" style="top: 90px;">
                                    <div class="filter-option active" onclick="applyMsgFilter('all', this)">All</div>
                                    <div class="filter-option" onclick="applyMsgFilter('unread', this)">Unread</div>
                                    <div style="height:1px; background:#eee; margin:2px 0;"></div>
                                    <div style="padding-bottom: 10px;">
                                        <div
                                            style="padding: 5px 15px; font-size: 0.75rem; color: #888; font-weight: 700;">
                                            FILTER BY DATE</div>
                                        <input type="text" id="msgDateFilter" class="filter-date-input"
                                            placeholder="Select Date..." readonly
                                            onclick="if(!this._flatpickr) { flatpickr(this, { dateFormat: 'Y-m-d', static: true, onChange: (d,s) => { currentMsgDate=s; filterAndRenderMessages(); } }).open(); }">
                                        <div style="text-align: center;">
                                            <button onclick="clearMsgDate(event)"
                                                style="background:none; border:none; color:#EF4444; font-size:0.75rem; cursor:pointer; text-decoration:underline;">Clear
                                                Date</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="dropdown-list"></div>
                        </div>
                    </div>

                    <div class="action-wrapper">
                        <button class="icon-btn btn-notify" onclick="toggleDropdown('notifDropdown', event)">
                            <i class="fas fa-bell"></i>
                            <span class="icon-badge" style="display:none">0</span>
                        </button>

                        <div id="notifDropdown" class="dropdown-menu">
                            <div class="dropdown-header" style="position: relative;">
                                <h4 class="dropdown-title">Notifications</h4>

                                <button class="filter-btn" onclick="toggleNotifFilter(event)">
                                    <i class="fas fa-sliders-h"></i> Filter
                                </button>

                                <div id="notifFilterMenu" class="filter-menu-container">
                                    <div class="filter-option active" onclick="applyNotifFilter('all', this)">All Types
                                    </div>
                                    <div class="filter-option" onclick="applyNotifFilter('unread', this)">Unread Only
                                    </div>

                                    <div style="height:1px; background:#eee; margin:2px 0;"></div>

                                    <div class="filter-option" onclick="applyNotifFilter('booking', this)">Bookings
                                    </div>
                                    <div class="filter-option" onclick="applyNotifFilter('cancel', this)">Cancellations
                                    </div>
                                    <div class="filter-option" onclick="applyNotifFilter('reminder', this)">Reminders
                                    </div>

                                    <div style="height:1px; background:#eee; margin:2px 0;"></div>

                                    <div style="padding-bottom: 10px;">
                                        <div
                                            style="padding: 5px 15px; font-size: 0.75rem; color: #888; font-weight: 700;">
                                            FILTER BY DATE</div>
                                        <input type="text" id="notifDateFilter" class="filter-date-input"
                                            placeholder="Select Date..." readonly
                                            onclick="if(!this._flatpickr) { flatpickr(this, { dateFormat: 'Y-m-d', static: true, onChange: (d,s) => { currentNotifDate=s; filterAndRender(); } }).open(); }">
                                        <div style="text-align: center;">
                                            <button onclick="clearNotifDate()"
                                                style="background:none; border:none; color:#EF4444; font-size:0.75rem; cursor:pointer; text-decoration:underline;">Clear
                                                Date</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="dropdown-list"></div>
                        </div>
                    </div>

                    <!-- <div style="width:45px; height:45px; border-radius:50%; overflow:hidden; border:1px solid #ddd;">
                        <!-- <img src="../../IMG/default_avatar.png"
                            onerror="this.src='https://ui-avatars.com/api/?name=Admin&background=B88E2F&color=fff'"
                            style="width:100%; height:100%;"> -->
                    <!-- </div> -->

                    <div class="action-wrapper">
                        <button class="icon-btn btn-pending" onclick="toggleOrderDrawer()"
                            style="color: #EA580C; border-color: #FED7AA; background-color: #FFF7ED;">
                            <i class="fas fa-utensils"></i>
                            <span id="orderPulse" class="pulse-dot"
                                style="display:none; background-color: #EA580C;"></span>
                        </button>
                    </div>

                    <div class="action-wrapper">
                        <button class="icon-btn btn-pending" onclick="togglePendingDrawer()">
                            <i class="fas fa-clipboard-list"></i>
                            <span id="pendingPulse" class="pulse-dot" style="display:none;"></span>
                        </button>
                    </div>
                </div>
            </header>

            <div id="newNotificationAlert" class="new-booking-alert" style="display: none; border-left-color: #3B82F6;">
                <div class="nb-content">
                    <div class="nb-icon-box" style="color: #3B82F6;">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div class="nb-text">
                        <h4>New Notifications</h4>
                        <p>You have <strong id="nb_count_display" style="color:#3B82F6;">0</strong> unread
                            update(s).</p>
                    </div>
                </div>
                <button class="nb-action-btn" onclick="openNotificationPanel(event)">View All</button>
                <div class="toast-progress-container">
                    <div class="toast-progress-bar" style="background: #3B82F6;"></div>
                </div>
            </div>

            <div id="newMessageAlert" class="new-booking-alert" style="display: none; border-left-color: #10B981;">
                <div class="nb-content">
                    <div class="nb-icon-box" style="color: #10B981; background-color: #ECFDF5; border-color: #D1FAE5;">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div class="nb-text">
                        <h4 style="color: #065F46;">New Message</h4>
                        <p>You have <strong id="msg_count_display" style="color:#10B981;">0</strong> new message(s).</p>
                    </div>
                </div>
                <button class="nb-action-btn" onclick="openUnreadMessages(event)">Read</button>
                <button class="nb-action-btn"
                    style="background:none; border:1px solid #eee; color:#9CA3AF; padding: 6px 10px;"
                    onclick="markAllMessagesRead(event)">Mark All</button>
                <div class="toast-progress-container">
                    <div class="toast-progress-bar" style="background: #10B981;"></div>
                </div>
            </div>

            <div id="lateArrivalAlert" class="new-booking-alert" style="display: none; 
            border-left-color: #F59E0B; 
            top: 185px; /* Positioned below the blue card (90px + height + gap) */
            z-index: 999;">

                <div class="nb-content">
                    <div class="nb-icon-box" style="color: #F59E0B; background-color: #FFFBEB; border-color: #FDE68A;">
                        <i class="fas fa-user-clock"></i>
                    </div>
                    <div class="nb-text">
                        <h4>Late Arrivals</h4>
                        <p>
                            <strong id="late_count_display"
                                style="color: #D97706;"><?php echo $lateGuestCount; ?></strong>
                            guest(s) missed their arrival time.
                        </p>
                    </div>
                </div>

                <button class="nb-action-btn" style="color: #B45309; border-color: #FCD34D;"
                    onclick="goToBookingsTab('late')"> Review
                </button>
                <div class="toast-progress-container">
                    <div class="toast-progress-bar" style="background: #F59E0B;"></div>
                </div>
            </div>

            <div id="newOrderAlert" class="new-booking-alert" style="display: none; border-left-color: #7E22CE;">
                <div class="nb-content">
                    <div class="nb-icon-box" style="color: #7E22CE; background-color: #F3E8FF; border-color: #E9D5FF;">
                        <i class="fas fa-utensils"></i>
                    </div>
                    <div class="nb-text">
                        <h4 style="color: #6B21A8;">New Food Order</h4>
                        <p>You have <strong id="order_count_display" style="color:#7E22CE;">0</strong> new order(s).</p>
                    </div>
                </div>
                <button class="nb-action-btn" onclick="toggleOrderDrawer(event)">Review Orders</button>
                <div class="toast-progress-container">
                    <div class="toast-progress-bar" style="background: #7E22CE;"></div>
                </div>
            </div>

            <div class="page-content">
                <div class="page" id="dashboard" style="padding: 20px">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h2 class="fs-md" style="margin: 0;">Overview</h2>

                        <div
                            style="display: flex; align-items: center; gap: 12px; background: #fff; padding: 10px 20px; border-radius: 12px; border: 1px solid #E5E7EB; box-shadow: 0 2px 5px rgba(0,0,0,0.02);">
                            <label
                                style="font-size: 0.85rem; font-weight: 700; color: #4B5563; text-transform: uppercase; letter-spacing: 0.5px;">View
                                Analytics:</label>

                            <div style="width: 200px;">
                                <select id="dashboardMonthPicker" class="ab-select"
                                    onchange="toggleMonthInput(this.value); fetchDashboardCards()">
                                    <option value="overall" selected>Overall Analytics</option>
                                    <option value="custom">Specific Month</option>
                                </select>
                            </div>

                            <div id="customMonthWrapper" style="display: none; width: 180px; position: relative;">
                                <input type="text" id="customMonthInput" class="custom-date-input"
                                    placeholder="Select Month..." readonly>
                            </div>

                            <!-- 🟢 NEW: Most Booked Dates Button -->
                            <button class="most-booked-btn" onclick="openDateLeaderboardModal()" 
                                    style="background: #FFF8E1; color: #B88E2F; border: 1px solid #FFECB3; padding: 10px 18px; border-radius: 12px; font-weight: 700; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-calendar-star"></i> Most Booked Dates
                            </button>
                            </div>
                            </div>

                            <div class="stats-grid">                        <div class="stat-card">
                            <h3 class="stat-value" id="stat_guests"><?php echo $activeBookings; ?></h3>
                            <p class="stat-label" id="label_guests">Total Successful Bookings (Cumulative)</p>
                        </div>

                        <div class="stat-card">
                            <h3 class="stat-value"><span
                                    id="stat_revenue">₱<?php echo number_format($overallRevenue, 0); ?></span></h3>
                            <p class="stat-label" id="label_revenue">Overall Revenue</p>
                        </div>

                        <div class="stat-card">
                            <h3 class="stat-value"><span id="stat_occupancy"><?php echo $occupancyRate; ?>%</span></h3>
                            <p class="stat-label" id="label_occupancy">Overall Occupancy</p>
                        </div>

                        <div class="stat-card">
                            <h3 class="stat-value" id="stat_pending"><?php echo $pendingRequests; ?></h3>
                            <p class="stat-label">Arriving Today</p>
                        </div>

                        <div class="stat-card">
                            <h3 class="stat-value" id="stat_orders"><?php echo $totalOrders; ?></h3>
                            <p class="stat-label">Active Orders</p>
                        </div>
                    </div>

                    <div class="charts-grid charts-grid-dashboard">

                        <div
                            style="display: flex; flex-direction: column; gap: 1rem; flex: 0 1 320px; min-width: 300px;">

                            <div class="chart-card" style="flex: 1; padding: 20px; justify-content: center;">
                                <h3 class="chart-title fs-sm mb-2 text-center">Booking Outcomes</h3>

                                <div class="chart-container" style="flex: 1; position: relative; max-height: 160px;">
                                    <canvas id="pieBookings"></canvas>
                                </div>

                                <div class="d-flex justify-center g-3 mt-2">
                                    <div class="fs-xxs"><span class="legend-dot"
                                            style="background:#10B981"></span>Complete</div>
                                    <div class="fs-xxs"><span class="legend-dot"
                                            style="background:#F59E0B"></span>No-Show</div>
                                    <div class="fs-xxs"><span class="legend-dot"
                                            style="background:#EF4444"></span>Cancelled</div>
                                </div>
                            </div>

                            <div class="chart-card-" style="flex: 0 0 auto; padding: 20px; width: 100%;">
                                <div class="progress-list">

                                    <div class="mb-2">
                                        <div class="d-flex-between fs-xxs mb-1">
                                            <span>Complete</span>
                                            <span id="prog_text_complete"><?php echo $pctComplete; ?>%</span>
                                        </div>
                                        <div class="progress-bar-bg">
                                            <div id="prog_bar_complete" class="progress-bar-fill"
                                                style="width: <?php echo $pctComplete; ?>%; background: #10B981;"></div>
                                        </div>
                                    </div>

                                    <div class="mb-2">
                                        <div class="d-flex-between fs-xxs mb-1">
                                            <span>No-Show</span>
                                            <span id="prog_text_noshow"><?php echo $pctNoShow; ?>%</span>
                                        </div>
                                        <div class="progress-bar-bg">
                                            <div id="prog_bar_noshow" class="progress-bar-fill"
                                                style="width: <?php echo $pctNoShow; ?>%; background: #F59E0B;"></div>
                                        </div>
                                    </div>

                                    <div>
                                        <div class="d-flex-between fs-xxs mb-1">
                                            <span>Cancelled</span>
                                            <span id="prog_text_cancelled"><?php echo $pctCancelled; ?>%</span>
                                        </div>
                                        <div class="progress-bar-bg">
                                            <div id="prog_bar_cancelled" class="progress-bar-fill"
                                                style="width: <?php echo $pctCancelled; ?>%; background: #EF4444;">
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </div>

                        </div>
                        <div class="chart-card"
                            style="flex: 1 1 600px; min-width: 400px; display: flex; flex-direction: column;">

                            <div
                                style="display:flex; align-items:center; justify-content:space-between; padding: 20px 20px 10px 20px;">
                                <button id="prevYearBtn" class="year-switch-btn" onclick="changeChartYear(-1)"
                                    title="Previous Year">
                                    <i class="fas fa-chevron-left"></i>
                                </button>

                                <div style="display:flex; flex-direction:column; align-items:center; width: 100%;">
                                    <h3 class="chart-title" id="revenueChartTitle"
                                        style="margin:0; font-size: 1.1rem; color: #333; font-weight:700;">
                                        Revenue <?php echo date('Y'); ?>
                                    </h3>

                                    <div
                                        style="display:flex; align-items:center; width:100%; margin-top:10px; min-height: 32px;">
                                        <!-- Left Spacer to keep buttons centered -->
                                        <div style="flex: 1;"></div>

                                        <!-- Centered Buttons -->
                                        <div class="chart-toggle-buttons"
                                            style="display: flex; gap: 8px; flex: 0 0 auto; position: relative; z-index: 10;">
                                            <button id="btnRevenue" onclick="setChartViewMode('revenue')"
                                                style="background: #B88E2F; color: white; border: none; padding: 5px 15px; border-radius: 20px; cursor: pointer; font-size: 0.8rem; font-weight: 600; transition: all 0.3s; box-shadow: 0 2px 4px rgba(0,0,0,0.1); pointer-events: auto;">Revenue</button>
                                            <button id="btnLeaderboard" onclick="setChartViewMode('leaderboard')"
                                                style="background: #f4f4f4; color: #555; border: none; padding: 5px 15px; border-radius: 20px; cursor: pointer; font-size: 0.8rem; font-weight: 600; transition: all 0.3s; pointer-events: auto;">Rooms</button>
                                            <button id="btnFood" onclick="setChartViewMode('food')"
                                                style="background: #f4f4f4; color: #555; border: none; padding: 5px 15px; border-radius: 20px; cursor: pointer; font-size: 0.8rem; font-weight: 600; transition: all 0.3s; pointer-events: auto;">Foods</button>
                                        </div>                                        <!-- Right-aligned Search -->
                                        <div style="flex: 1; display: flex; justify-content: flex-end;">
                                            <div id="leaderboardSearchContainer"
                                                style="display:none; width: 180px; position: relative;">
                                                <i class="fas fa-search"
                                                    style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.75rem;"></i>
                                                <input type="text" id="leaderboardSearchInput"
                                                    onkeyup="handleLeaderboardSearch(this.value)"
                                                    placeholder="Search rooms..."
                                                    style="width: 100%; padding: 6px 10px 6px 30px; border-radius: 20px; border: 1px solid #e2e8f0; background: #fff; font-size: 0.75rem; color: #334155; outline: none; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <button id="nextYearBtn" class="year-switch-btn" onclick="changeChartYear(1)"
                                    title="Next Year">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>

                            <div id="barChartContainer" class="chart-container"
                                style="flex: 1; padding: 0 20px 20px 20px; position: relative; min-height: 0;">
                                <canvas id="barMonthly"></canvas>
                            </div>

                        </div>
                    </div>
                </div>

                <div class="page" id="calendar" style="overflow-y: auto; padding: 20px;">
                    <div class="calendar-wrapper">
                        <div class="calendar-header-styled">
                            <h3 id="currentMonthYear">Month YYYY</h3>
                            <div class="d-flex g-2">
                                <button class="cal-nav-btn" id="prevMonthBtn">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="15 18 9 12 15 6"></polyline>
                                    </svg>
                                </button>
                                <button class="cal-nav-btn" id="nextMonthBtn">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="9 18 15 12 9 6"></polyline>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="calendar-days-styled">
                            <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                        </div>
                        <div class="calendar-grid-styled" id="calendarRealtimeGrid"></div>
                    </div>

                    <div class="modal" id="calendarModal" style="z-index: 2000;">
                        <div class="modal-content-calendar">
                            <div class="room-modal-header">
                                <h3 class="room-modal-title" id="calendarModalTitle">Room Status</h3>
                                <button class="room-modal-close" id="closeCalendarModal">✕</button>
                            </div>

                            <div class="modal-body" id="calendarModalBody" style="padding: 0;"></div>
                        </div>
                    </div>
                </div>


                <!-- Guest Page -->
                <div class="page" id="guests" style="overflow-y: auto;">
                    <div class="p-3" style="display: flex; flex-direction: column; height: 100%;">

                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h2 class="fs-md">Guest Database</h2>

                            <div style="position:relative;">
                                <input type="text" id="guestSearchInput" class="search-input"
                                    placeholder="Search Name or Email...">
                                <i class="fa-solid fa-magnifying-glass"
                                    style="position:absolute; right:15px; top:50%; transform:translateY(-50%); color:#999; font-size: 0.9rem; pointer-events:none;"></i>
                            </div>
                        </div>

                        <!-- guest table -->
                        <div class="booking-table-container">
                            <table class="booking-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Nationality</th>
                                        <th>Total Bookings</th>
                                        <th>Total Orders</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="guestTableBody">
                                </tbody>
                            </table>

                            <div id="noGuestDataMessage"
                                style="display:none; text-align:center; padding:20px; color:#888;">
                                No guests found matching your search.
                            </div>
                        </div>

                        <!-- 🟢 PAGINATION CONTROLS FOR GUESTS -->
                        <div id="guestPagination" class="pagination-container">
                            <div class="pagination-info">
                                Showing <span><span id="guestPageStart">0</span> - <span
                                        id="guestPageEnd">0</span></span> of <span id="guestTotalCount">0</span> records
                            </div>
                            <div class="pagination-buttons" id="guestPageButtons">
                                <!-- Page numbers generated here -->
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Guest Profile Modal -->
                <div id="guestProfileModal" class="modal" style="z-index: 1200;">
                    <div class="ab-modal-content" style="max-width: 900px;">
                        <div class="ab-modal-header">
                            <h3 class="ab-modal-title">Guest Profile</h3>
                            <button class="ab-close-btn" onclick="closeGuestModal()">✕</button>
                        </div>

                        <div class="ab-modal-body">
                            <div id="guestProfileLoader" class="text-center" style="padding:40px;">Loading details...
                            </div>

                            <div id="guestProfileContent" style="display:none;">

                                <div id="gp_view_mode">
                                    <div
                                        style="background:#f9fafb; padding:20px; border-radius:8px; margin-bottom:25px; border:1px solid #eee; position:relative;">

                                        <button onclick="toggleGuestEdit(true)"
                                            style="position:absolute; top:20px; right:20px; border:none; background:transparent; color:#B88E2F; cursor:pointer; font-weight:600;">
                                            <i class="fas fa-edit"></i> Edit Details
                                        </button>

                                        <div class="ab-grid-3">
                                            <div>
                                                <span class="fs-xxs" style="text-transform:uppercase; color:#888;">Full
                                                    Name</span>
                                                <div style="font-weight:700; font-size:1.1rem; color:#333;"
                                                    id="gp_name"></div>
                                            </div>
                                            <div>
                                                <span class="fs-xxs"
                                                    style="text-transform:uppercase; color:#888;">Email</span>
                                                <div
                                                    style="display:flex; justify-content:space-between; align-items:center;">
                                                    <span style="font-weight:600; color:#555;" id="gp_email"></span>
                                                    <button onclick="editEmailAddress()"
                                                        style="border:none; background:none; cursor:pointer; color:#999;"
                                                        title="Edit Email">
                                                        <i class="fas fa-pen"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div>
                                                <span class="fs-xxs"
                                                    style="text-transform:uppercase; color:#888;">Phone</span>
                                                <div style="font-weight:600; color:#555;" id="gp_phone"></div>
                                            </div>
                                            <div>
                                                <span class="fs-xxs"
                                                    style="text-transform:uppercase; color:#888;">Nationality</span>
                                                <div style="font-weight:600; color:#555;" id="gp_nation"></div>
                                            </div>
                                            <div>
                                                <span class="fs-xxs"
                                                    style="text-transform:uppercase; color:#888;">Gender</span>
                                                <div style="font-weight:600; color:#555;" id="gp_gender"></div>
                                            </div>
                                            <div>
                                                <span class="fs-xxs"
                                                    style="text-transform:uppercase; color:#888;">Birthdate</span>
                                                <div style="font-weight:600; color:#555;" id="gp_dob"></div>
                                            </div>
                                        </div>
                                        <div style="margin-top:15px;">
                                            <span class="fs-xxs"
                                                style="text-transform:uppercase; color:#888;">Address</span>
                                            <div style="font-weight:600; color:#555;" id="gp_address"></div>
                                        </div>
                                    </div>

                                    <div
                                        style="margin-top: 30px; margin-bottom: 15px; border-bottom: 1px solid #eee; display: flex; gap: 20px;">
                                        <button class="tab-btn-modal active" onclick="switchGuestHistoryTab('bookings')"
                                            id="tab-btn-bookings"
                                            style="padding: 10px 0; background: none; border: none; font-weight: 700; color: #B88E2F; border-bottom: 3px solid #B88E2F; cursor: pointer;">
                                            Booking History
                                        </button>
                                        <button class="tab-btn-modal" onclick="switchGuestHistoryTab('orders')"
                                            id="tab-btn-orders"
                                            style="padding: 10px 0; background: none; border: none; font-weight: 600; color: #888; border-bottom: 3px solid transparent; cursor: pointer;">
                                            Order History
                                        </button>
                                    </div>

                                    <div id="gp_history_container" class="history-view">
                                        <div class="booking-table-container" style="height:auto; max-height:300px;">
                                            <table class="booking-table">
                                                <thead>
                                                    <tr>
                                                        <th>Ref</th>
                                                        <th>Dates</th>
                                                        <th>Rooms</th>
                                                        <th>Total</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="gp_history_body"></tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <div id="gp_orders_container" class="history-view" style="display: none;">
                                        <div class="booking-table-container" style="height:auto; max-height:300px;">
                                            <table class="booking-table">
                                                <thead>
                                                    <tr>
                                                        <th>Order #</th>
                                                        <th>Date</th>
                                                        <th>Items</th>
                                                        <th>Total</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="gp_orders_body"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <div id="gp_edit_mode" style="display:none;">
                                    <form id="guestEditForm" onsubmit="saveGuestProfile(event)">
                                        <input type="hidden" id="edit_original_email" name="original_email">

                                        <div
                                            style="background:#fff; padding: 20px; border-radius:8px; margin-bottom:20px;">
                                            <h4
                                                style="margin-top:0; color:#B88E2F; border-bottom:1px solid #eee; padding-bottom:10px;">
                                                Editing Profile</h4>

                                            <div class="ab-grid-2 ab-mb-3" style="margin-top:15px;">
                                                <div>
                                                    <label class="ab-label">First Name</label>
                                                    <input type="text" class="ab-input" id="edit_fname" name="firstname"
                                                        required>
                                                </div>
                                                <div>
                                                    <label class="ab-label">Last Name</label>
                                                    <input type="text" class="ab-input" id="edit_lname" name="lastname"
                                                        required>
                                                </div>
                                            </div>

                                            <div class="ab-grid-2 ab-mb-3">
                                                <div>
                                                    <label class="ab-label">Email (ID)</label>
                                                    <input type="email" class="ab-input" id="edit_email" name="email"
                                                        required style="background:#f0f0f0;" readonly>
                                                    <small style="color:#888;">To change email, use the "Edit Email"
                                                        tool in View mode.</small>
                                                </div>
                                                <div>
                                                    <label class="ab-label">Phone</label>
                                                    <input type="text" class="ab-input" id="edit_phone" name="phone"
                                                        maxlength="12"
                                                        oninput="this.value = this.value.replace(/[^0-9#]/g, ''); if(this.value.length > 12) this.value = this.value.slice(0, 12);">
                                                </div>
                                            </div>

                                            <div class="ab-grid-3 ab-mb-3">
                                                <div>
                                                    <label class="ab-label">Nationality</label>
                                                    <input type="text" class="ab-input" id="edit_nation"
                                                        name="nationality" list="nationality_options" autocomplete="off"
                                                        placeholder="Select Country">
                                                </div>
                                                <div>
                                                    <label class="ab-label">Gender</label>
                                                    <select class="ab-select" id="edit_gender" name="gender">
                                                        <option value="Male">Male</option>
                                                        <option value="Female">Female</option>
                                                        <option value="Other">Other</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="ab-label">Birthdate</label>
                                                    <input type="text" class="ab-input custom-date-input" id="edit_dob"
                                                        name="birthdate" placeholder="YYYY-MM-DD">
                                                </div>
                                            </div>

                                            <div class="ab-mb-4" style="position:relative;">
                                                <label class="ab-label">Address</label>
                                                <input type="text" class="ab-input" id="edit_address" name="address"
                                                    autocomplete="off" placeholder="Start typing to search...">
                                                <div class="spinner" id="editAddrLoader" style="display:none;"></div>
                                                <div class="address-results-list" id="editAddrResults"
                                                    style="display:none;"></div>
                                            </div>

                                            <div class="ab-grid-footer">
                                                <button type="button" class="btn-secondary"
                                                    onclick="toggleGuestEdit(false)">Cancel</button>
                                                <button type="submit" class="ab-submit-btn">Save Changes</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Booking Page -->
                <div class="page" id="bookings" style="overflow-y: auto;">
                    <div class="p-3" style="display: flex; flex-direction: column; height: 100%;">

                        <div class="bookings-toolbar">

                            <div class="toolbar-group" style="flex-wrap: nowrap; overflow-x: auto;">
                                <button class="tab-btn active" onclick="filterTable('today')" data-target="today">
                                    Today
                                </button>
                                <button class="tab-btn" onclick="filterTable('recent')" data-target="recent">
                                    Recent
                                </button>
                                <button class="tab-btn" onclick="filterTable('all')" data-target="all">
                                    All
                                </button>
                            </div>

                            <div class="toolbar-group"
                                style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">

                                <div style="position:relative; flex-grow: 1;">
                                    <input type="text" id="bookingSearchInput" class="search-input"
                                        style="width: 100%; min-width: 200px;" placeholder="Search Reference...">
                                    <i class="fa-solid fa-magnifying-glass"
                                        style="position:absolute; right:15px; top:50%; transform:translateY(-50%); color:#999; font-size: 0.9rem; pointer-events:none;"></i>
                                </div>

                                <div style="display: flex; gap: 10px; align-items: center;">

                                    <button class="btn-primary" onclick="openScannerModal()"
                                        style="color:white; border:none; padding:10px 20px; border-radius:6px; cursor:pointer; font-weight:600; white-space:nowrap; display:flex; align-items:center; gap:8px; height: 42px;">
                                        <i class="fas fa-qrcode"></i>
                                        <span class="hide-on-mobile">Scan QR</span>
                                    </button>

                                    <div class="custom-select-wrapper" id="addBookingWrapper"
                                        style="width: 180px; position: relative;">

                                        <div class="custom-select-trigger hover-button-add-booking"
                                            onclick="toggleAddBookingSelect(event)"
                                            style="color: white; border: none; height: 42px; justify-content: center; gap: 10px; padding: 0 15px;">
                                            <span style="font-weight: 600;">+ Add Booking</span>
                                            <i class="fas fa-chevron-down custom-arrow"
                                                style="color: white; margin-left: auto;"></i>
                                        </div>

                                        <div class="custom-options"
                                            style="position: absolute; top: 115%; right: 0; left: auto; width: 100%; min-width: 180px; z-index: 1500;">

                                            <div class="custom-option" onclick="openAddBookingModal('reservation')">
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <div class="item-icon-box icon-blue"
                                                        style="width: 30px; height: 30px; font-size: 0.9rem;">
                                                        <i class="fas fa-calendar-alt"></i>
                                                    </div>
                                                    <div>
                                                        <div style="font-weight: 600; color: #333;">Reservation</div>
                                                        <div style="font-size: 0.75rem; color: #888;">Select Dates</div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="custom-option" onclick="openAddBookingModal('walk-in')">
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <div class="item-icon-box icon-gold"
                                                        style="width: 30px; height: 30px; font-size: 0.9rem;">
                                                        <i class="fas fa-walking"></i>
                                                    </div>
                                                    <div>
                                                        <div style="font-weight: 600; color: #333;">Walk-in</div>
                                                        <div style="font-size: 0.75rem; color: #888;">Instant Book</div>
                                                    </div>
                                                </div>
                                            </div>

                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>

                        <!-- Booking Table -->
                        <div class="booking-table-container">
                            <table class="booking-table">
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>Guest Name</th>
                                        <th>Source</th>
                                        <th>Arrival Status</th>
                                        <th>Est. Arrival</th>
                                        <th>Rooms</th>
                                        <th>Dates</th>
                                        <th>Price</th>
                                        <th>Paid</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="bookingTableBody">
                                    <?php if ($result_list->num_rows > 0): ?>
                                        <?php
                                        // Get Current Time objects once to save processing
                                        $now = new DateTime();
                                        $todayDateOnly = date('Y-m-d');
                                        ?>

                                        <?php while ($row = $result_list->fetch_assoc()): ?>
                                            <?php
                                            // --- 1. DEFINE VARIABLES ---
                                            $guestName = !empty($row['first_name']) ? $row['first_name'] . ' ' . $row['last_name'] : $row['user_name'];
                                            $checkinDisplay = date('M d', strtotime($row['check_in']));
                                            $checkoutDisplay = date('M d', strtotime($row['check_out']));
                                            $paymentStatus = $row['payment_status'];
                                            $amountPaid = $row['amount_paid'] ?? 0;
                                            $balance = $row['total_price'] - $amountPaid;

                                            // Handle Creation Date (For 3-Day Cancellation Rule)
                                            // Fallback to check_in date if created_at is missing from SQL
                                            $createdDate = !empty($row['created_at']) ? $row['created_at'] : $row['check_in'];

                                            // Source Icons
                                            // --- 1. DETERMINE SOURCE TEXT ---
                                            // If database is empty, default to 'Reservation'
                                            $sourceText = !empty($row['booking_source']) ? $row['booking_source'] : 'Reservation';

                                            // --- UPDATED SOURCE ICONS ---
                                    
                                            // 1. Walk-in
                                            if (strcasecmp($sourceText, 'walk-in') === 0) {
                                                $sourceClass = 'source-walkin'; // Purple
                                                $sourceIcon = '<i class="fas fa-walking"></i>';
                                            }
                                            // 2. Reservation (Admin)
                                            elseif (strcasecmp($sourceText, 'reservation') === 0) {
                                                $sourceClass = 'source-online'; // Blue
                                                $sourceIcon = '<i class="far fa-calendar-alt"></i>';
                                            }
                                            // 3. Mobile App 
                                            elseif (strcasecmp($sourceText, 'mobile_app') === 0) {
                                                $sourceClass = 'source-walkin'; // Re-using purple or pick a new color class
                                                $sourceIcon = '<i class="fas fa-mobile-alt"></i>';
                                            }
                                            // 4. Online (Web) / Default
                                            else {
                                                $sourceClass = 'source-online'; // Blue
                                                $sourceIcon = '<i class="fas fa-globe"></i>';
                                            }

                                            // --- 2. TIME CALCULATIONS ---
                                            // Guest Arrival Time (Default 2:00 PM if not set)
                                            $guestTimeStr = !empty($row['arrival_time']) ? $row['arrival_time'] : '14:00';

                                            // Exact Timestamp of arrival (e.g., "2025-12-30 14:00:00")
                                            $guestArrivalDT = new DateTime($row['check_in'] . ' ' . date('H:i:s', strtotime($guestTimeStr)));

                                            // Late Cutoff (1 Hour Grace Period)
                                            $lateCutoff = clone $guestArrivalDT;
                                            $lateCutoff->modify('+1 hour');

                                            // Standard Checkout Time
                                            $checkOutStandard = new DateTime($row['check_out'] . ' 12:00:00');

                                            // Date String Comparison
                                            $checkInDateOnly = date('Y-m-d', strtotime($row['check_in']));

                                            // --- 3. DETERMINE STATUS LABEL (MANUAL MODE ONLY) ---
                                            $arrivalLabel = '';
                                            $arrivalClass = '';

                                            // 1. Check for specific "Arriving Today" status from DB (New Value)
                                            if ($row['arrival_status'] == 'arriving_today') {
                                                $arrivalLabel = 'Arriving Today';
                                                $arrivalClass = 'arrival-today'; // Blue
                                            }
                                            // 2. Check "No-Show" (Manual status)
                                            elseif ($row['arrival_status'] == 'no_show') {
                                                $arrivalLabel = 'No-Show';
                                                $arrivalClass = 'arrival-overdue'; // Red
                                            }
                                            // 3. Check "In House"
                                            elseif ($row['arrival_status'] == 'in_house' || ($row['booking_source'] == 'walk-in' && $row['arrival_status'] != 'checked_out')) {
                                                $arrivalLabel = 'In House';
                                                $arrivalClass = 'arrival-inhouse'; // Purple
                                            }
                                            // 4. Check "Checked Out"
                                            elseif ($row['arrival_status'] == 'checked_out') {
                                                $arrivalLabel = 'Checked Out';
                                                $arrivalClass = 'arrival-checkedout'; // Grey
                                            }
                                            // 5. Check "Confirmed" (Default Reservation)
                                            elseif ($row['status'] == 'confirmed') {

                                                // LOGIC: If date is TODAY, force "Arriving Today" (Blue). 
                                                // Ignore time. Ignore freshness.
                                                if ($checkInDateOnly === $todayDateOnly) {
                                                    $arrivalLabel = 'Arriving Today';
                                                    $arrivalClass = 'arrival-today'; // Blue Badge
                                                }
                                                // If date is in the future
                                                elseif ($checkInDateOnly > $todayDateOnly) {
                                                    $arrivalLabel = 'Upcoming';
                                                    $arrivalClass = 'arrival-upcoming'; // Yellow Badge
                                                }
                                                // If date is in the past (Overdue) - Only turns red if date is completely passed
                                                else {
                                                    $arrivalLabel = 'Late Arrival';
                                                    $arrivalClass = 'arrival-overdue'; // Red Badge
                                                }
                                            }
                                            // 6. Check "Pending" (Safety Net)
                                            elseif ($row['status'] == 'pending') {
                                                $arrivalLabel = 'Verifying';
                                                $arrivalClass = 'badge-pending'; // Yellow badge
                                            }
                                            // 7. Finally, default to Cancelled
                                            else {
                                                $arrivalLabel = 'Cancelled';
                                                $arrivalClass = 'badge-cancelled';
                                            }
                                            ?>

                                            <?php
                                            // 1. Calculate the deadline (Expected Arrival + 30 Minutes)
                                            // We use $guestArrivalDT which you defined earlier in the loop
                                            $jsThreshold = clone $guestArrivalDT;
                                            $jsThreshold->modify('+30 minutes');
                                            ?>

                                            <tr class="booking-row" data-status="<?php echo $row['status']; ?>"
                                                data-checkin="<?php echo $row['check_in']; ?>"
                                                data-cutoff="<?php echo $row['check_in'] . ' 20:00:00'; ?>"
                                                data-checkout="<?php echo $row['check_out']; ?>"
                                                data-arrival="<?php echo $row['arrival_status']; ?>"
                                                data-created="<?php echo $createdDate; ?>" id="row-<?php echo $row['id']; ?>">

                                                <td><strong><?php echo $row['booking_reference']; ?></strong></td>

                                                <td>
                                                    <div style="font-weight:600; font-size:0.9rem;"><?php echo $guestName; ?>
                                                    </div>
                                                    <div class="fs-xxs" style="color:#888;">ID: <?php echo $row['id']; ?></div>
                                                </td>

                                                <td>
                                                    <div class="source-tag <?php echo $sourceClass; ?>">
                                                        <span><?php echo $sourceIcon; ?></span>
                                                        <?php echo strtoupper($sourceText); ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <div class="arrival-badge <?php echo $arrivalClass; ?>">
                                                        <?php echo $arrivalLabel; ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <div style="font-weight:600; color:#555; font-size:0.9rem;">
                                                        <i class="far fa-clock" style="color:#888; margin-right:4px;"></i>
                                                        <?php echo date('h:i A', strtotime($guestTimeStr)); ?>
                                                    </div>
                                                </td>

                                                <td><?php echo $row['room_names']; ?></td>
                                                <td><?php echo $checkinDisplay . ' - ' . $checkoutDisplay; ?></td>
                                                <td>₱<?php echo number_format($row['total_price'], 2); ?></td>

                                                <td>
                                                    <?php if ($paymentStatus == 'paid'): ?>
                                                        <span style="color:#10B981; font-weight:700; font-size:0.8rem;">Fully
                                                            Paid</span>
                                                    <?php elseif ($paymentStatus == 'partial'): ?>
                                                        <div style="font-size:0.75rem; color:#F59E0B; font-weight:600;">Paid:
                                                            ₱<?php echo number_format($amountPaid, 0); ?></div>
                                                        <div style="font-size:0.75rem; color:#EF4444; font-weight:600;">Bal:
                                                            ₱<?php echo number_format($balance, 0); ?></div>
                                                    <?php else: ?>
                                                        <span
                                                            style="color:#EF4444; font-weight:600; font-size:0.8rem;">Unpaid</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <button class="btn-secondary" style="padding:5px 10px; font-size:0.8rem;"
                                                        onclick="openBookingAction(
                                                         '<?php echo $row['id']; ?>',
                                                         '<?php echo addslashes($guestName); ?>',
                                                         '<?php echo $row['booking_reference']; ?>',
                                                         '<?php echo addslashes($row['room_names']); ?>',
                                                         '<?php echo $row['check_in']; ?>',
                                                         '<?php echo $row['check_out']; ?>',
                                                         '<?php echo $row['total_price']; ?>',
                                                         '<?php echo $row['arrival_status']; ?>',                                                             
                                                         '<?php echo $amountPaid; ?>',
                                                         '<?php echo $arrivalLabel; ?>',
                                                         '<?php echo $createdDate; ?>',
                                                         '<?php echo $row['booking_source']; ?>',
                                                         '<?php echo $row['daily_price']; ?>',
                                                         '<?php echo addslashes(str_replace(["\r", "\n"], [" ", " "], $row['special_requests'] ?? '')); ?>'
                                                        )">
                                                        View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" style="text-align:center; padding:30px; color:#888;">
                                                No bookings found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <div id="noDataMessage" style="display:none; text-align:center; padding:20px; color:#888;">
                                No bookings found in this category.
                            </div>

                            <!-- 🟢 PAGINATION CONTROLS FOR BOOKINGS (ATTACHED) -->
                            <div id="bookingPagination" class="pagination-container">
                                <div class="pagination-info">
                                    Showing <span><span id="bookingPageStart">0</span> - <span
                                            id="bookingPageEnd">0</span></span> of <span id="bookingTotalCount">0</span>
                                    records
                                </div>
                                <div class="pagination-buttons" id="bookingPageButtons">
                                    <!-- Page numbers generated here -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="page" id="food-ordered" style="overflow-y: auto;">
                    <div class="p-3" style="display: flex; flex-direction: column; height: 100%;">

                        <div class="bookings-toolbar">
                            <h2 class="fs-md">Incoming Food Orders</h2>
                            <button class="btn-primary" onclick="location.reload()"
                                style="color:white; border:none; padding:8px 15px; border-radius:6px; cursor:pointer;">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>

                        <div class="booking-table-container">
                            <table class="booking-table">
                                <thead>
                                    <tr>
                                        <th style="width: 80px;">ID</th>
                                        <th style="width: 150px;">Room / Guest</th>
                                        <th>Order Details</th>
                                        <th>Special Instructions</th>
                                        <th>Total</th>
                                        <th>Payment</th>
                                        <th>Status</th>
                                        <th>Time</th>
                                        <th style="text-align: right;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="foodTableBody">
                                    <?php if ($result_orders && $result_orders->num_rows > 0): ?>
                                        <?php while ($order = $result_orders->fetch_assoc()): ?>
                                            <?php
                                            // 1. Decode Items
                                            $items = json_decode($order['items'], true);
                                            $itemsList = "";
                                            if (is_array($items)) {
                                                foreach ($items as $name => $qty) {
                                                    $itemsList .= "<div style='font-size:0.85rem; color:#555;'><b>{$qty}x</b> " . htmlspecialchars($name) . "</div>";
                                                }
                                            }

                                            // 2. Status Badge Logic
                                            $status = $order['status'];
                                            $badgeClass = 'badge-pending'; // Default yellow
                                            if ($status === 'Preparing')
                                                $badgeClass = 'arrival-today'; // Blue
                                            if ($status === 'Delivered')
                                                $badgeClass = 'badge-confirmed'; // Green
                                            if ($status === 'Cancelled')
                                                $badgeClass = 'badge-cancelled'; // Red
                                    
                                            // 3. Payment Method Icon
                                            $payMethod = $order['payment_method'];
                                            $payIcon = '<i class="fas fa-money-bill-wave" style="color:#10B981;"></i>';
                                            if ($payMethod === 'GCash')
                                                $payIcon = '<i class="fas fa-mobile-alt" style="color:#3B82F6;"></i>';
                                            if ($payMethod === 'Charge to Room')
                                                $payIcon = '<i class="fas fa-door-open" style="color:#F59E0B;"></i>';
                                            ?>
                                            <tr id="order-row-<?php echo $order['id']; ?>">
                                                <td style="font-weight:700; color:#888;">#<?php echo $order['id']; ?></td>

                                                <td>
                                                    <div style="font-weight:700; color:#333; font-size:0.95rem;">
                                                        <?php echo htmlspecialchars($order['room_number']); ?>
                                                    </div>
                                                    <div style="font-size:0.8rem; color:#666;">
                                                        <?php echo htmlspecialchars($order['guest_name']); ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <?php echo $itemsList; ?>
                                                    <?php if (!empty($order['notes'])): ?>
                                                        <div
                                                            style="font-size:0.75rem; color:#d97706; margin-top:4px; font-style:italic;">
                                                            <i class="fas fa-sticky-note"></i>
                                                            <?php echo htmlspecialchars($order['notes']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>

                                                <td style="font-weight:700; color:#333;">
                                                    ₱<?php echo number_format($order['total_price'], 2); ?></td>

                                                <td>
                                                    <div
                                                        style="display:flex; align-items:center; gap:6px; font-size:0.85rem; color:#555;">
                                                        <?php echo $payIcon; ?>         <?php echo $payMethod; ?>
                                                    </div>
                                                </td>

                                                <td>
                                                    <span class="badge <?php echo $badgeClass; ?>"><?php echo $status; ?></span>
                                                </td>

                                                <td style="font-size:0.8rem; color:#888;">
                                                    <div><?php echo date('M d', strtotime($order['order_date'])); ?></div>
                                                    <div><?php echo date('h:i A', strtotime($order['order_date'])); ?></div>
                                                </td>

                                                <td style="text-align: right;">
                                                    <?php if ($status === 'Pending'): ?>
                                                        <button class="btn-secondary"
                                                            style="background:#E0F2FE; color:#0284C7; border:1px solid #BAE6FD;"
                                                            onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'prepare')">
                                                            <i class="fas fa-fire"></i> Prepare
                                                        </button>
                                                    <?php elseif ($status === 'Preparing'): ?>
                                                        <button class="btn-secondary"
                                                            style="background:#DCFCE7; color:#166534; border:1px solid #BBF7D0;"
                                                            onclick="updateOrderStatus(<?php echo $order['id']; ?>, 'deliver')">
                                                            <i class="fas fa-check"></i> Serve
                                                        </button>
                                                    <?php else: ?>
                                                        <span style="font-size:0.8rem; color:#aaa;">Completed</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" style="text-align:center; padding:50px; color:#888;">
                                                <i class="fas fa-utensils"
                                                    style="font-size:2rem; opacity:0.3; margin-bottom:10px;"></i><br>
                                                No food orders found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>

                            <!-- 🟢 PAGINATION CONTROLS FOR FOOD ORDERS (ATTACHED) -->
                            <div id="foodPagination" class="pagination-container">
                                <div class="pagination-info">
                                    Showing <span><span id="foodPageStart">0</span> - <span
                                            id="foodPageEnd">0</span></span> of <span id="foodTotalCount">0</span>
                                    records
                                </div>
                                <div class="pagination-buttons" id="foodPageButtons">
                                    <!-- Page numbers generated here -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="page" id="transactions" style="overflow-y: auto;">
                    <div class="p-3" style="display: flex; flex-direction: column; height: 100%;">

                        <div class="bookings-toolbar">
                            <div>
                                <h2 class="fs-md" style="margin-bottom:5px;">Transaction History</h2>
                                <p style="font-size:0.85rem; color:#666; margin:0;">Monitor revenue from Bookings and
                                    Food Orders.</p>
                            </div>

                            <div style="display:flex; gap:10px; align-items:center;">
                                <select id="transFilterType" onchange="loadTransactions()" class="ab-select"
                                    style="width:150px;">
                                    <option value="all">All Types</option>
                                    <option value="Booking">Bookings</option>
                                    <option value="Food Order">Food Orders</option>
                                </select>
                                <button class="btn-primary" onclick="loadTransactions()"
                                    style="color:white; border:none; padding:10px 15px; border-radius:6px; cursor:pointer;">
                                    <i class="fas fa-sync-alt"></i> Refresh
                                </button>
                            </div>
                        </div>

                        <div class="booking-table-container">
                            <table class="booking-table">
                                <thead>
                                    <tr>
                                        <th style="width: 80px;">ID</th>
                                        <th>User / Guest</th>
                                        <th>Reference</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th style="text-align: right;">Date</th>
                                    </tr>
                                </thead>
                                <tbody id="transactions_body">
                                    <tr>
                                        <td colspan="8" style="text-align:center; padding:30px; color:#999;">Loading
                                            data...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- 🟢 PAGINATION CONTROLS -->
                        <div id="transPagination" class="pagination-container">
                            <div class="pagination-info">
                                Showing <span><span id="transPageStart">0</span> - <span
                                        id="transPageEnd">0</span></span> of <span id="transTotalCount">0</span> records
                            </div>
                            <div class="pagination-buttons" id="transPageButtons">
                                <!-- Page numbers will be generated here -->
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Settings Page -->
                <div class="page" id="settings" style="overflow-y: auto;">
                    <div class="p-3">

                        <div id="settings-home" class="settings-view">
                            <h2 class="fs-md mb-3">Settings Menu</h2>

                            <div class="settings-tree-grid">

                                <div class="tree-item-card" onclick="openSettingsView('view-profile')">
                                    <div class="tree-icon">
                                        <i class="fas fa-user-cog"></i>
                                    </div>
                                    <div class="tree-info">
                                        <h4>Admin Profile</h4>
                                        <p>Manage email, password, and terms.</p>
                                    </div>
                                </div>

                                <div class="tree-item-card" onclick="openSettingsView('view-rooms')">
                                    <div class="tree-icon">
                                        <i class="fas fa-bed"></i>
                                    </div>
                                    <div class="tree-info">
                                        <h4>Room Details</h4>
                                        <p>Add, edit, or remove room types.</p>
                                    </div>
                                </div>

                                <div class="tree-item-card" onclick="openSettingsView('view-news')">
                                    <div class="tree-icon">
                                        <i class="fas fa-newspaper"></i>
                                    </div>
                                    <div class="tree-info">
                                        <h4>Hotel News</h4>
                                        <p>Manage announcements and updates.</p>
                                    </div>
                                </div>

                                <div class="tree-item-card" onclick="openSettingsView('view-food-menu')">
                                    <div class="tree-icon">
                                        <i class="fas fa-utensils"></i>
                                    </div>
                                    <div class="tree-info">
                                        <h4>Food & Beverages</h4>
                                        <p>Manage menu items, prices, and categories.</p>
                                    </div>
                                </div>

                                <div class="tree-item-card" onclick="openSettingsView('view-events')">
                                    <div class="tree-icon">
                                        <i class="fas fa-glass-cheers"></i>
                                    </div>
                                    <div class="tree-info">
                                        <h4>Events Management</h4>
                                        <p>Manage hotel parties, meetings, and special events.</p>
                                    </div>
                                </div>

                                <div class="tree-item-card" onclick="openSettingsView('view-reviews')">
                                    <div class="tree-icon">
                                        <i class="fas fa-star"></i>
                                    </div>
                                    <div class="tree-info">
                                        <h4>Guest Reviews</h4>
                                        <p>View ratings and feedback.</p>
                                    </div>
                                </div>

                                <div class="tree-item-card" onclick="openSettingsView('view-terms')">
                                    <div class="tree-icon">
                                        <i class="fas fa-file-contract"></i>
                                    </div>
                                    <div class="tree-info">
                                        <h4>Terms & Conditions</h4>
                                        <p>Update hotel policies and rules.</p>
                                    </div>
                                </div>

                                <div class="tree-item-card" onclick="openSettingsView('view-privacy')">
                                    <div class="tree-icon">
                                        <i class="fas fa-user-shield"></i>
                                    </div>
                                    <div class="tree-info">
                                        <h4>Privacy Policy</h4>
                                        <p>Manage data collection rules.</p>
                                    </div>
                                </div>

                                <div class="tree-item-card" onclick="openSettingsView('view-receipts')">
                                    <div class="tree-icon">
                                        <i class="fas fa-receipt"></i>
                                    </div>
                                    <div class="tree-info">
                                        <h4>Payment Archive</h4>
                                        <p>View all uploaded payment receipts.</p>
                                    </div>
                                </div>

                                <div class="tree-item-card" onclick="openSettingsView('view-amenities')">
                                    <div class="tree-icon">
                                        <i class="fas fa-concierge-bell"></i>
                                    </div>
                                    <div class="tree-info">
                                        <h4>Amenities Details</h4>
                                        <p>Manage room features and icons.</p>
                                    </div>
                                </div>

                                <!--<div class="settings-section p-3 mb-3"-->
                                <!--    style="background:#fff; border:1px solid #e0e0e0; border-radius:8px; margin-top: 20px;">-->
                                <!--    <h4 style="margin-top:0; color:#333;">Automation Tools</h4>-->
                                <!--    <p style="font-size:0.85rem; color:#666; margin-bottom:15px;">Manually trigger daily-->
                                <!--        system tasks.</p>-->
                                <!--    <button class="ab-submit-btn" style="width:auto; background-color:#2563EB;"-->
                                <!--        onclick="triggerReminders()">-->
                                <!--        Send Checkout Reminders Now-->
                                <!--    </button>-->
                                <!--</div>-->

                            </div>



                            <div class="settings-tree-grid">
                            </div>


                        </div>

                        <script>
                            function triggerReminders() {
                                if (!confirm("Send emails to all guests checking out TODAY (12:00 PM)?")) return;

                                // Show loading state
                                const btn = document.querySelector('button[onclick="triggerReminders()"]');
                                const originalText = btn.innerText;
                                btn.innerText = "Sending...";
                                btn.disabled = true;

                                fetch('send_reminders.php')
                                    .then(res => res.text())
                                    .then(data => {
                                        alert("Process Complete:\n" + data);
                                        fetchHeaderData();
                                    })
                                    .catch(err => {
                                        alert("Error: " + err);
                                    })
                                    .finally(() => {
                                        btn.innerText = originalText;
                                        btn.disabled = false;
                                    });
                            }
                        </script>

                    </div>

                    <!-- Profile View -->
                    <div id="view-profile" class="settings-view pp-container">

                        <div class="pp-fixed-header">
                            <button class="back-btn-settings" onclick="openSettingsView('settings-home')">⬅
                                Back</button>
                            <h3 class="m-0">Profile & Payment Settings</h3>
                        </div>

                        <div class="pp-scroll-area">

                            <div class="pp-section-group">
                                <h4 class="pp-section-title">Admin Profile</h4>

                                <div id="admin-view-mode" class="pp-card">
                                    <div class="pp-card-left">
                                        <img src="../../IMG/hotel_background.png" class="pp-avatar-img">
                                    </div>

                                    <div class="pp-card-right">
                                        <div class="pp-info-grid">

                                            <div>
                                                <div class="pp-label">Username</div>
                                                <div class="pp-value" id="disp_username">Loading...</div>
                                            </div>

                                            <div>
                                                <div class="pp-label">Contact</div>
                                                <div class="pp-value" id="disp_contact">...</div>
                                            </div>

                                            <div>
                                                <div class="pp-label">Email Address</div>
                                                <div class="pp-value" id="disp_email">...</div>
                                            </div>

                                            <div
                                                style="grid-column: 1 / -1; margin-top: 10px; background: #FFF8E1; padding: 12px 15px; border-radius: 8px; border: 1px solid #FFE082;">
                                                <div
                                                    style="display: flex; justify-content: space-between; align-items: flex-start;">
                                                    <div>
                                                        <div class="pp-label"
                                                            style="color: #B88E2F; margin-bottom: 4px; font-size: 0.7rem;">
                                                            <i class="fas fa-wifi"></i> WI-FI NAME (SSID)
                                                        </div>
                                                        <div class="pp-value" id="disp_wifi_ssid"
                                                            style="font-size: 0.95rem;">Loading...</div>
                                                    </div>
                                                    <div style="text-align: right;">
                                                        <div class="pp-label"
                                                            style="color: #B88E2F; margin-bottom: 4px; font-size: 0.7rem;">
                                                            <i class="fas fa-key"></i> PASSWORD
                                                        </div>
                                                        <div class="pp-value" id="disp_wifi_pass"
                                                            style="font-size: 0.95rem; font-family: monospace; letter-spacing: 0.5px;">
                                                            ...</div>
                                                    </div>
                                                </div>
                                            </div>

                                        </div>

                                        <button class="pp-edit-btn" onclick="toggleAdminEdit(true)">
                                            <i class="fas fa-pen"></i> Edit Profile
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="pp-section-group">
                                <h4 class="pp-section-title">Payment Settings</h4>

                                <div id="payment-view-mode" class="pp-card">
                                    <div class="pp-card-left" style="background: white; padding: 0;">

                                        <img id="disp_qr" src="" class="pp-avatar-img"
                                            style="object-fit: contain; padding: 20px; width: 100%; height: 100%; display: none;"
                                            onerror="this.style.display='none'; document.getElementById('qrFallback').style.display='flex';">

                                        <div id="qrFallback"
                                            style="display: flex; flex-direction:column; align-items:center; justify-content:center; width: 100%; height: 100%; color:#ccc;">
                                            <i class="fas fa-qrcode"
                                                style="font-size:3.5rem; margin-bottom:12px; opacity: 0.5;"></i>
                                            <div style="font-size:0.85rem; font-weight:600; color: #999;">No QR Uploaded
                                            </div>
                                        </div>

                                    </div>

                                    <div class="pp-card-right">
                                        <div class="pp-info-grid">

                                            <div>
                                                <div class="pp-label">Payment Method</div>
                                                <div class="pp-value" id="disp_pay_method">Loading...</div>
                                            </div>

                                            <div>
                                                <div class="pp-label">Account Name</div>
                                                <div class="pp-value" id="disp_acc_name">...</div>
                                            </div>

                                            <div>
                                                <div class="pp-label">Account Number</div>
                                                <div class="pp-value" id="disp_acc_num">...</div>
                                            </div>

                                        </div>

                                        <button class="pp-edit-btn" onclick="togglePaymentEdit(true)">
                                            <i class="fas fa-pen"></i> Edit Payment
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div style="height: 50px;"></div>

                        </div>
                    </div>


                    <!-- Room Details View -->
                    <div id="view-rooms" class="settings-view px-3 mb-3">
                        <div class="settings-header" style="justify-content: space-between;">
                            <div style="display:flex; align-items:center; gap:15px;">
                                <button class="back-btn-settings" onclick="openSettingsView('settings-home')">⬅
                                    Back</button>
                                <h3 class="m-0">Room Details Management</h3>
                            </div>

                            <div style="display:flex; gap: 10px;">
                                <button class="btn-secondary" id="toggleArchivedBtn" onclick="toggleArchivedRooms()">
                                    Show Archived
                                </button>

                                <button class="ab-submit-btn" style="width: auto; padding: 8px 15px;"
                                    onclick="openAddRoomModal()">
                                    + Add New Room
                                </button>
                            </div>
                        </div>
                        <div class="booking-table-container">
                            <table class="booking-table">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">ID</th>
                                        <th style="width: 150px;">Image</th>
                                        <th>Room Name</th>
                                        <th>Type</th>
                                        <th>Price</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="roomTableBody">
                                    <?php foreach ($allRoomsDB as $room): ?>
                                        <?php
                                        // 1. Image Logic
                                        $placeholder = "data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3QgZmlsbD0iI2RkZCIgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJhcmlhbCIgZm9udC1zaXplPSIxMiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzU1NSI+Tm8gSW1hZ2U8L3RleHQ+PC9zdmc+";

                                        $rawPath = $room['file_path'];

                                        // Check if there are multiple images (comma separated)
                                        if (strpos($rawPath, ',') !== false) {
                                            $pathParts = explode(',', $rawPath);
                                            $rawPath = trim($pathParts[0]); // Take only the first one
                                        }

                                        $imgUrl = !empty($rawPath)
                                            ? "../../room_includes/uploads/images/" . $rawPath
                                            : $placeholder;

                                        $isActive = $room['is_active'];
                                        $isBooked = $room['is_booked'];
                                        $rowStyle = ($isActive == 0) ? 'display:none; background-color: #f3f4f6; opacity: 0.8;' : '';
                                        $rowClass = ($isActive == 0) ? 'archived-room-row' : '';
                                        ?>

                                        <tr id="room-row-<?php echo $room['id']; ?>" class="<?php echo $rowClass; ?>"
                                            style="vertical-align: middle; <?php echo $rowStyle; ?>">

                                            <td style="font-weight: 600; color: #888;"><?php echo $room['id']; ?></td>

                                            <td>
                                                <div
                                                    style="width: 120px; height: 80px; background:#eee; border-radius:6px; overflow:hidden; border:1px solid #ddd;">
                                                    <img src="<?php echo $imgUrl; ?>"
                                                        style="width:100%; height:100%; object-fit:cover;"
                                                        onerror="this.src='<?php echo $placeholder; ?>'">
                                                </div>
                                            </td>

                                            <td>
                                                <div class="room-name"
                                                    style="font-weight: 600; font-size: 1rem; color: #333;">
                                                    <?php echo $room['name']; ?>
                                                    <?php if ($isActive == 0): ?>
                                                        <span
                                                            style="font-size:0.7rem; background:#999; color:white; padding:2px 5px; border-radius:4px;">ARCHIVED</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <td>
                                                <span class="room-bed"
                                                    style="background: #fff; padding: 4px 10px; border-radius: 4px; border:1px solid #eee; font-size: 0.85rem; font-weight: 500; color: #555;">
                                                    <?php echo $room['bed_type']; ?>
                                                </span>
                                            </td>

                                            <td class="room-price" style="font-weight: 700; color: #333;">
                                                ₱<?php echo number_format($room['price'], 2); ?></td>

                                            <td>
                                                <button class="btn-secondary"
                                                    style="padding:6px 12px; margin-right: 5px; <?php echo $isBooked ? 'border-color:orange; color:#d97706;' : ''; ?>"
                                                    onclick="openEditRoomModal(
                        '<?php echo $room['id']; ?>', 
                        '<?php echo addslashes($room['name']); ?>', 
                        '<?php echo $room['price']; ?>', 
                        '<?php echo addslashes($room['bed_type']); ?>', 
                        '<?php echo $room['capacity']; ?>',  
                        '<?php echo addslashes($room['size']); ?>', 
                        '<?php echo htmlspecialchars(addslashes(str_replace(array("\r", "\n"), ' ', $room['description'])), ENT_QUOTES); ?>', 
                        '<?php echo addslashes($room['file_path']); ?>',
                        <?php echo $isBooked ? 'true' : 'false'; ?>,
                        '<?php echo $room['amenities']; ?>' 
                    )">
                                                    <?php if ($isBooked): ?>
                                                        <i class="fas fa-exclamation-circle"></i> Edit Price
                                                    <?php else: ?>
                                                        <i class="fas fa-edit"></i> Edit
                                                    <?php endif; ?>
                                                </button>

                                                <?php if ($isActive == 1): ?>
                                                    <?php if ($isBooked): ?>
                                                        <button class="btn-secondary" disabled
                                                            style="padding:6px 12px; opacity: 0.4; cursor: not-allowed;"><i
                                                                class="fas fa-trash"></i></button>
                                                    <?php else: ?>
                                                        <button class="btn-secondary"
                                                            style="padding:6px 12px; color:#555; border-color: #FECACA; background: #FEF2F2;"
                                                            onclick="deleteRoom('<?php echo $room['id']; ?>')"><i
                                                                class="fas fa-trash"></i></button>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <button class="btn-secondary"
                                                        style="padding:6px 12px; color:#10B981; border-color: #A7F3D0; background: #ECFDF5;"
                                                        onclick="restoreRoom('<?php echo $room['id']; ?>')">
                                                        <i class="fas fa-trash-restore"></i> Restore
                                                    </button>

                                                    <button class="btn-secondary"
                                                        style="padding:6px 12px; color:white; border-color: #B91C1C; background: #DC2626; margin-left: 5px;"
                                                        onclick="permanentDeleteRoom('<?php echo $room['id']; ?>')">
                                                        <i class="fas fa-times"></i> Delete Forever
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Hotel Events View -->
                    <div id="view-news" class="settings-view px-3 mb-3">
                        <div class="settings-header" style="justify-content: space-between;">
                            <div style="display:flex; align-items:center; gap:15px;">
                                <button class="back-btn-settings" onclick="openSettingsView('settings-home')">⬅
                                    Back</button>
                                <h3 class="m-0">Hotel News & Announcements</h3>
                            </div>

                            <div style="display:flex; gap: 10px;">
                                <button class="btn-secondary" id="toggleArchivedNewsBtn" onclick="toggleArchivedNews()">
                                    Show Archived
                                </button>

                                <button class="ab-submit-btn" style="width: auto; padding: 8px 15px;"
                                    onclick="openAddNewsModal()">
                                    + Add News
                                </button>
                            </div>
                        </div>

                        <div class="booking-table-container">
                            <table class="booking-table">
                                <thead>
                                    <tr>
                                        <th style="width: 100px;">Image</th>
                                        <th>Title</th>
                                        <th>Date</th>
                                        <th>Description</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($result_news && $result_news->num_rows > 0): ?>
                                        <?php while ($news = $result_news->fetch_assoc()): ?>
                                            <?php
                                            $newsImg = !empty($news['image_path']) ? "../../room_includes/uploads/news/" . $news['image_path'] : "../../IMG/default_news.jpg";
                                            $cleanDesc = strip_tags($news['description']);
                                            $descShort = (strlen($cleanDesc) > 50) ? substr($cleanDesc, 0, 50) . '...' : $cleanDesc;

                                            // 🟢 FIX: Ensure is_active is checked correctly
                                            $isActive = isset($news['is_active']) ? $news['is_active'] : 1;

                                            // 🟢 FIX: Apply styling and class based on status
                                            $rowStyle = ($isActive == 0) ? 'display:none; background-color: #f3f4f6; opacity: 0.8;' : '';
                                            $rowClass = ($isActive == 0) ? 'archived-news-row' : '';
                                            ?>
                                            <tr id="news-row-<?php echo $news['id']; ?>" class="<?php echo $rowClass; ?>"
                                                style="<?php echo $rowStyle; ?>">
                                                <td>
                                                    <div
                                                        style="width: 80px; height: 60px; background:#eee; border-radius:6px; overflow:hidden; border:1px solid #ddd;">
                                                        <img src="<?php echo htmlspecialchars($newsImg); ?>"
                                                            style="width:100%; height:100%; object-fit:cover;"
                                                            onerror="this.style.display='none'">
                                                    </div>
                                                </td>
                                                <td style="font-weight: 600; color: #333;">
                                                    <?php echo htmlspecialchars($news['title']); ?>
                                                    <?php if ($isActive == 0): ?>
                                                        <span
                                                            style="font-size:0.7rem; background:#999; color:white; padding:2px 5px; border-radius:4px; margin-left:5px;">ARCHIVED</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="font-size: 0.9rem; color: #555;">
                                                    <?php echo date('M d, Y', strtotime($news['news_date'])); ?>
                                                </td>
                                                <td style="font-size: 0.85rem; color: #666;">
                                                    <?php echo $descShort; ?>
                                                </td>
                                                <td>
                                                    <button class="btn-secondary" style="padding:6px 12px; margin-right: 5px;"
                                                        onclick="openEditNewsModal(
                        '<?php echo $news['id']; ?>',
                        '<?php echo addslashes($news['title']); ?>',
                        '<?php echo $news['news_date']; ?>',
                        '<?php echo base64_encode($news['description']); ?>', 
                        '<?php echo $news['image_path']; ?>')">
                                                        <i class="fas fa-edit"></i>
                                                    </button>

                                                    <?php if ($isActive == 1): ?>
                                                        <button class="btn-secondary"
                                                            style="padding:6px 12px; color:#555; border-color: #FECACA; background: #FEF2F2;"
                                                            onclick="deleteNews('<?php echo $news['id']; ?>')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn-secondary"
                                                            style="padding:6px 12px; color:#10B981; border-color: #A7F3D0; background: #ECFDF5;"
                                                            onclick="restoreNews('<?php echo $news['id']; ?>')">
                                                            <i class="fas fa-trash-restore"></i>
                                                        </button>

                                                        <button class="btn-secondary"
                                                            style="padding:6px 12px; color:white; border-color: #B91C1C; background: #DC2626; margin-left: 5px;"
                                                            onclick="permanentDeleteNews('<?php echo $news['id']; ?>')">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center" style="padding:30px;">No news posted yet.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Food & Beverages Menu View -->
                    <div id="view-food-menu" class="settings-view px-3 mb-3">

                        <div class="settings-header" style="justify-content: space-between;">
                            <div style="display:flex; align-items:center; gap:15px;">
                                <button class="back-btn-settings" onclick="openSettingsView('settings-home')">⬅
                                    Back</button>
                                <h3 class="m-0">Food & Beverages Menu</h3>
                            </div>

                            <div style="display:flex; gap: 10px;">
                                <button class="btn-secondary" id="toggleArchivedFoodBtn" onclick="toggleArchivedFood()">
                                    Show Archived
                                </button>

                                <button class="ab-submit-btn" style="width: auto; padding: 8px 15px;"
                                    onclick="openAddFoodModal()">
                                    + Add New Item
                                </button>
                            </div>
                        </div>

                        <div class="booking-table-container">
                            <table class="booking-table">
                                <thead>
                                    <tr>
                                        <th style="width: 80px;">Image</th>
                                        <th style="width: 80px; text-align:center;">Type</th>
                                        <th style="text-align: left;">Item Name</th>
                                        <th>Category</th>
                                        <th>Price</th>
                                        <th style="text-align: right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="foodMenuTableBody">
                                    <?php
                                    // Fetch ALL items, active first, then inactive
                                    $sql_food = "SELECT * FROM food_menu ORDER BY is_active DESC, category DESC, item_name ASC";
                                    $res_food = $conn->query($sql_food);

                                    if ($res_food && $res_food->num_rows > 0):
                                        while ($food = $res_food->fetch_assoc()):

                                            // 1. Icon & Color Logic
                                            $cat = strtolower($food['category']);
                                            $iconClass = 'fa-concierge-bell';
                                            $iconColor = '#9CA3AF'; // Default Grey
                                    
                                            if (strpos($cat, 'beverage') !== false || strpos($cat, 'drink') !== false) {
                                                $iconClass = 'fa-glass-martini-alt';
                                                $iconColor = '#3B82F6';
                                            } elseif (strpos($cat, 'dessert') !== false) {
                                                $iconClass = 'fa-ice-cream';
                                                $iconColor = '#EC4899';
                                            } elseif (strpos($cat, 'snack') !== false) {
                                                $iconClass = 'fa-cookie-bite';
                                                $iconColor = '#F59E0B';
                                            } elseif (strpos($cat, 'soup') !== false) {
                                                $iconClass = 'fa-mug-hot';
                                                $iconColor = '#EA580C';
                                            } elseif (strpos($cat, 'breakfast') !== false) {
                                                $iconClass = 'fa-bacon';
                                                $iconColor = '#8B5CF6';
                                            } elseif (strpos($cat, 'main') !== false) {
                                                $iconClass = 'fa-utensils';
                                                $iconColor = '#10B981';
                                            }

                                            // 2. Image Logic
                                            $foodImg = !empty($food['image_path']) ? "../../room_includes/uploads/food/" . $food['image_path'] : "";

                                            // 3. Archive Logic
                                            // Ensure is_active defaults to 1 if column is missing/null
                                            $isActive = isset($food['is_active']) ? $food['is_active'] : 1;

                                            // If inactive, add specific class and hide by default
                                            $rowClass = ($isActive == 0) ? 'archived-food-row' : '';
                                            $rowStyle = ($isActive == 0) ? 'display:none; background-color: #f3f4f6; opacity: 0.8;' : '';

                                            $safeName = addslashes($food['item_name']);
                                            ?>
                                            <tr id="food-menu-row-<?php echo $food['id']; ?>" class="<?php echo $rowClass; ?>"
                                                style="<?php echo $rowStyle; ?>">
                                                <td>
                                                    <div
                                                        style="width: 60px; height: 50px; background:#eee; border-radius:6px; overflow:hidden; border:1px solid #ddd; display:flex; align-items:center; justify-content:center;">
                                                        <?php if ($foodImg): ?>
                                                            <img src="<?php echo htmlspecialchars($foodImg); ?>"
                                                                style="width:100%; height:100%; object-fit:cover;"
                                                                onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                                            <i class="fas <?php echo $iconClass; ?>"
                                                                style="color: <?php echo $iconColor; ?>; font-size: 1.1rem; display:none;"></i>
                                                        <?php else: ?>
                                                            <i class="fas <?php echo $iconClass; ?>"
                                                                style="color: <?php echo $iconColor; ?>; font-size: 1.1rem;"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td style="text-align:center;">
                                                    <i class="fas <?php echo $iconClass; ?>"
                                                        style="color: <?php echo $iconColor; ?>;"></i>
                                                </td>
                                                <td>
                                                    <div style="font-weight: 700; color: #333; font-size: 1rem;">
                                                        <?php echo htmlspecialchars($food['item_name']); ?>
                                                    </div>
                                                    <?php if ($isActive == 0): ?>
                                                        <span
                                                            style="font-size:0.7rem; background:#999; color:white; padding:2px 5px; border-radius:4px;">ARCHIVED</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge"
                                                        style="background:#F3F4F6; color:#555; border:1px solid #ddd; text-transform:uppercase; letter-spacing:0.5px;">
                                                        <?php echo htmlspecialchars($food['category']); ?>
                                                    </span>
                                                </td>
                                                <td style="font-weight: 700; color: #B88E2F;">
                                                    ₱<?php echo number_format($food['price'], 2); ?></td>
                                                <td style="text-align: right;">
                                                    <div style="display: flex; justify-content: flex-end; gap: 5px;">

                                                        <?php if ($isActive == 1): ?>
                                                            <button class="btn-secondary" style="padding:5px 10px;" onclick="openEditFoodModal(
                            '<?php echo $food['id']; ?>',
                            '<?php echo $safeName; ?>',
                            '<?php echo $food['category']; ?>',
                            '<?php echo $food['price']; ?>',
                            '<?php echo $food['image_path']; ?>'
                        )">
                                                                <i class="fas fa-edit"></i> Edit
                                                            </button>

                                                            <button class="btn-secondary"
                                                                style="padding:5px 10px; color:#DC2626; border-color: #FECACA; background: #FEF2F2;"
                                                                onclick="deleteFood('<?php echo $food['id']; ?>')">
                                                                <i class="fas fa-trash"></i>
                                                            </button>

                                                        <?php else: ?>
                                                            <button class="btn-secondary"
                                                                style="padding:5px 10px; color:#10B981; border-color: #A7F3D0; background: #ECFDF5;"
                                                                onclick="restoreFood('<?php echo $food['id']; ?>')">
                                                                <i class="fas fa-trash-restore"></i> Restore
                                                            </button>

                                                            <button class="btn-secondary"
                                                                style="padding:5px 10px; color:white; border-color: #B91C1C; background: #DC2626; margin-left:5px;"
                                                                onclick="permanentDeleteFood('<?php echo $food['id']; ?>')">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        <?php endif; ?>

                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center" style="padding:30px; color:#888;">No menu
                                                items
                                                found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Guest Reviews View -->
                    <div id="view-reviews" class="settings-view px-3 mb-3">
                        <div class="settings-header">
                            <button class="back-btn-settings" onclick="openSettingsView('settings-home')">⬅
                                Back</button>
                            <h3 class="m-0">Guest Feedback & Ratings</h3>
                        </div>

                        <div class="booking-table-container">
                            <table class="booking-table">
                                <thead>
                                    <tr>
                                        <th style="width: 120px;">Reference</th>
                                        <th style="width: 150px;">Overall Rating</th>
                                        <th>Categories</th>
                                        <th>Guest Comments</th>
                                        <th style="width: 150px;">Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($result_reviews && $result_reviews->num_rows > 0): ?>
                                        <?php while ($rev = $result_reviews->fetch_assoc()): ?>
                                            <tr>
                                                <td style="font-weight: 700; color: #555;">
                                                    <?php echo htmlspecialchars($rev['booking_reference']); ?>
                                                </td>
                                                <td>
                                                    <div style="display:flex; gap:2px;">
                                                        <?php echo renderStarRating($rev['rating_overall']); ?>
                                                    </div>
                                                    <div style="font-size:0.75rem; color:#888; margin-top:4px;">
                                                        Score: <strong>
                                                            <?php echo $rev['rating_overall']; ?>/5
                                                        </strong>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div style="font-size: 0.75rem; color: #666; line-height: 1.4;">
                                                        <div>Room: <strong style="color:#333;">
                                                                <?php echo $rev['rating_room']; ?>
                                                            </strong></div>
                                                        <div>Service: <strong style="color:#333;">
                                                                <?php echo $rev['rating_service']; ?>
                                                            </strong></div>
                                                        <div>Clean: <strong style="color:#333;">
                                                                <?php echo $rev['rating_cleanliness']; ?>
                                                            </strong></div>
                                                    </div>
                                                </td>
                                                <td style="font-size: 0.9rem; color: #444; line-height: 1.5;">
                                                    <?php if (!empty($rev['comments'])): ?>
                                                        <i class="fas fa-quote-left" style="color:#ddd; margin-right:5px;"></i>
                                                        <?php echo nl2br(htmlspecialchars($rev['comments'])); ?>
                                                    <?php else: ?>
                                                        <span style="color:#999; font-style:italic;">No comments provided.</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="font-size: 0.85rem; color: #888;">
                                                    <?php echo date('M d, Y', strtotime($rev['created_at'])); ?>
                                                    <div style="font-size:0.75rem; color:#ccc;">
                                                        <?php echo date('h:i A', strtotime($rev['created_at'])); ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center" style="padding: 40px; color: #888;">
                                                <i class="far fa-star"
                                                    style="font-size: 2rem; margin-bottom: 10px; opacity: 0.5;"></i><br>
                                                No reviews received yet.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Edit Terms and Conditions View -->
                    <div id="view-terms" class="settings-view px-3 mb-3">
                        <div class="settings-header">
                            <button class="back-btn-settings" onclick="openSettingsView('settings-home')">⬅
                                Back</button>
                            <h3 class="m-0">Edit Terms and Conditions</h3>
                        </div>

                        <div class="settings-content">
                            <div class="settings-section flex-card p-3"
                                style="background: #fff; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">

                                <div class="settings-title-area">
                                    <p style="font-size:0.9rem; color:#666; margin-bottom:15px;">
                                        Manage your policies below. Click <b>"Add New Policy"</b> to create a new
                                        section.
                                    </p>
                                </div>

                                <div id="policy-builder-container"></div>

                                <div class="settings-footer">
                                    <button class="btn-secondary"
                                        style="width:100%; border:2px dashed #ccc; margin: 10px 0; padding:12px; color:#555;"
                                        onclick="addPolicySection()">
                                        <i class="fas fa-plus-circle"></i> Add New Policy Section
                                    </button>

                                    <textarea id="rawDatabaseContent"
                                        style="display:none;"><?php echo htmlspecialchars($termsData); ?></textarea>

                                    <div class="d-flex justify-end">
                                        <button class="ab-submit-btn" style="width: auto; padding: 10px 25px;"
                                            onclick="saveTerms()">Save All Changes</button>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- Edit Privacy Policy View -->
                    <div id="view-privacy" class="settings-view px-3 mb-3">
                        <div class="settings-header">
                            <button class="back-btn-settings" onclick="openSettingsView('settings-home')">⬅
                                Back</button>
                            <h3 class="m-0">Edit Privacy Policy</h3>
                        </div>

                        <div class="settings-content">
                            <div class="settings-section flex-card p-3"
                                style="background: #fff; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">

                                <div class="settings-title-area">
                                    <p style="font-size:0.9rem; color:#666; margin-bottom:15px;">
                                        Define how user data is handled. Changes reflect immediately on the Privacy
                                        Policy
                                        page.
                                    </p>
                                </div>

                                <div id="privacy-builder-container"
                                    style="flex-grow: 1; overflow-y: auto; min-height: 0; padding-right: 10px; margin-bottom: 10px; border: 1px solid #f0f0f0; border-radius: 6px; background-color: #fafafa;">
                                </div>

                                <div class="settings-footer">
                                    <button class="btn-secondary"
                                        style="width:100%; border:2px dashed #ccc; margin: 10px 0; padding:12px; color:#555;"
                                        onclick="addPrivacySection()">
                                        <i class="fas fa-plus-circle"></i> Add New Section
                                    </button>

                                    <textarea id="rawPrivacyContent"
                                        style="display:none;"><?php echo htmlspecialchars($privacyData); ?></textarea>

                                    <div class="d-flex justify-end">
                                        <button class="ab-submit-btn" style="width: auto; padding: 10px 25px;"
                                            onclick="savePrivacy()">Save Privacy Policy</button>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- Hotel Events View -->
                    <div id="view-events" class="settings-view px-3 mb-3">
                        <div class="settings-header" style="justify-content: space-between;">
                            <div style="display:flex; align-items:center; gap:15px;">
                                <button class="back-btn-settings" onclick="openSettingsView('settings-home')">⬅
                                    Back</button>
                                <h3 class="m-0">Hotel Events</h3>
                            </div>

                            <div style="display:flex; gap: 10px;">
                                <button class="btn-secondary" id="toggleArchivedEventsBtn"
                                    onclick="toggleArchivedEvents()">
                                    Show Archived
                                </button>

                                <button class="ab-submit-btn" style="width: auto; padding: 8px 15px;"
                                    onclick="openAddEventModal()">
                                    + Add Event
                                </button>
                            </div>
                        </div>

                        <div class="booking-table-container">
                            <table class="booking-table">
                                <thead>
                                    <tr>
                                        <th style="width: 100px;">Image</th>
                                        <th>Event Title</th>
                                        <th>Date & Time</th>
                                        <th>Description</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($result_events && $result_events->num_rows > 0): ?>
                                        <?php while ($evt = $result_events->fetch_assoc()): ?>
                                            <?php
                                            $evtImg = !empty($evt['image_path']) ? "../../room_includes/uploads/events/" . $evt['image_path'] : "../../IMG/default_event.jpg";
                                            $cleanDesc = strip_tags($evt['description']);
                                            $descShort = (strlen($cleanDesc) > 50) ? substr($cleanDesc, 0, 50) . '...' : $cleanDesc;

                                            // 🟢 LOGIC: Active vs Archived
                                            $isActive = isset($evt['is_active']) ? $evt['is_active'] : 1;
                                            $rowStyle = ($isActive == 0) ? 'display:none; background-color: #f3f4f6; opacity: 0.8;' : '';
                                            $rowClass = ($isActive == 0) ? 'archived-event-row' : '';
                                            ?>
                                            <tr id="event-row-<?php echo $evt['id']; ?>" class="<?php echo $rowClass; ?>"
                                                style="<?php echo $rowStyle; ?>">
                                                <td>
                                                    <div
                                                        style="width: 80px; height: 60px; background:#eee; border-radius:6px; overflow:hidden;">
                                                        <img src="<?php echo htmlspecialchars($evtImg); ?>"
                                                            style="width:100%; height:100%; object-fit:cover;"
                                                            onerror="this.style.display='none'">
                                                    </div>
                                                </td>
                                                <td style="font-weight: 600; color: #333;">
                                                    <?php echo htmlspecialchars($evt['title']); ?>
                                                    <?php if ($isActive == 0): ?>
                                                        <span
                                                            style="font-size:0.7rem; background:#999; color:white; padding:2px 5px; border-radius:4px; margin-left:5px;">ARCHIVED</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="font-size: 0.9rem; color: #555;">
                                                    <?php echo date('M d, Y', strtotime($evt['event_date'])); ?><br>
                                                    <small
                                                        style="color:#888"><?php echo htmlspecialchars($evt['time_start']); ?></small>
                                                </td>
                                                <td style="font-size: 0.85rem; color: #666;"><?php echo $descShort; ?></td>
                                                <td>
                                                    <button class="btn-secondary" style="padding:6px 12px; margin-right: 5px;"
                                                        onclick="openEditEventModal(
                                        '<?php echo $evt['id']; ?>',
                                        '<?php echo addslashes($evt['title']); ?>',
                                        '<?php echo $evt['event_date']; ?>',
                                        '<?php echo addslashes($evt['time_start']); ?>',
                                        '<?php echo htmlspecialchars(addslashes(str_replace(array("\r", "\n"), ' ', $evt['description'])), ENT_QUOTES); ?>',
                                        '<?php echo $evt['image_path']; ?>'
                                    )">
                                                        <i class="fas fa-edit"></i>
                                                    </button>

                                                    <?php if ($isActive == 1): ?>
                                                        <button class="btn-secondary"
                                                            style="padding:6px 12px; color:#555; border-color: #FECACA; background: #FEF2F2;"
                                                            onclick="deleteEvent('<?php echo $evt['id']; ?>')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn-secondary"
                                                            style="padding:6px 12px; color:#10B981; border-color: #A7F3D0; background: #ECFDF5;"
                                                            onclick="restoreEvent('<?php echo $evt['id']; ?>')">
                                                            <i class="fas fa-trash-restore"></i> Restore
                                                        </button>

                                                        <button class="btn-secondary"
                                                            style="padding:6px 12px; color:white; border-color: #B91C1C; background: #DC2626; margin-left: 5px;"
                                                            onclick="permanentDeleteEvent('<?php echo $evt['id']; ?>')">
                                                            <i class="fas fa-times"></i> Delete Forever
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center" style="padding:30px;">No events scheduled.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Payment Receipts View -->
                    <div id="view-receipts" class="settings-view px-3 mb-3">
                        <div class="settings-header" style="justify-content: space-between;">
                            <div style="display:flex; align-items:center; gap:15px;">
                                <button class="back-btn-settings" onclick="openSettingsView('settings-home')">⬅
                                    Back</button>
                                <h3 class="m-0">Payment Receipts Archive</h3>
                            </div>

                            <div style="display:flex; align-items:center; gap:10px;">
                                <label style="font-size:0.85rem; color:#666;">Filter Date:</label>

                                <div style="position: relative;">
                                    <input type="text" id="receiptFilterDate" class="custom-date-input"
                                        placeholder="Select Date..." readonly
                                        style="width: 150px; padding-right: 35px; background-color: #fff; cursor: pointer;">
                                    <i class="fas fa-calendar-alt"
                                        style="position:absolute; right:12px; top:50%; transform:translateY(-50%); color:#B88E2F; pointer-events:none;"></i>
                                </div>

                                <button class="btn-secondary" onclick="clearReceiptFilter()"
                                    style="padding: 10px 15px; font-size:0.85rem; background-color:#f3f4f6; color:#555; border:1px solid #ddd;">
                                    Show All
                                </button>
                            </div>
                        </div>

                        <div class="receipt-gallery-container"
                            style="min-height: 400px; max-height: 75vh; overflow-y: auto; padding: 5px;">
                            <div id="receiptGrid" class="receipt-grid">
                            </div>
                        </div>
                    </div>

                    <!-- Amenities Management View -->
                    <div id="view-amenities" class="settings-view px-3 mb-3">
                        <div class="settings-header" style="justify-content: space-between;">
                            <div style="display:flex; align-items:center; gap:15px;">
                                <button class="back-btn-settings" onclick="openSettingsView('settings-home')">⬅
                                    Back</button>
                                <h3 class="m-0">Amenities Management</h3>
                            </div>

                            <button class="ab-submit-btn" style="width: auto; padding: 8px 15px;"
                                onclick="openAddAmenityModal()">
                                + Add New Amenity
                            </button>
                        </div>
                        <div class="booking-table-container">
                            <table class="booking-table">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">ID</th>
                                        <th style="width: 80px;">Icon</th>
                                        <th>Title</th>
                                        <th>Description</th>
                                        <th style="width: 150px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_amenities as $am): ?>
                                        <tr style="vertical-align: middle;">
                                            <td style="font-weight: 600; color: #888;"><?php echo $am['id']; ?></td>
                                            <td style="text-align:center;">
                                                <div style="font-size: 1.5rem; color: #B88E2F;">
                                                    <i class="<?php echo htmlspecialchars($am['icon_class']); ?>"></i>
                                                </div>
                                            </td>
                                            <td style="font-weight: 600; color: #333;">
                                                <?php echo htmlspecialchars($am['title']); ?>
                                            </td>
                                            <td style="color: #666; font-size: 0.85rem;">
                                                <?php echo htmlspecialchars($am['description']); ?>
                                            </td>
                                            <td>
                                                <button class="btn-secondary" style="padding:6px 12px; margin-right: 5px;"
                                                    onclick="openEditAmenityModal('<?php echo $am['id']; ?>', '<?php echo addslashes($am['title']); ?>', '<?php echo addslashes($am['icon_class']); ?>', '<?php echo addslashes($am['description']); ?>')">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="btn-secondary"
                                                    style="padding:6px 12px; color: #EF4444; border-color: #FCA5A5;"
                                                    onclick="deleteAmenity(<?php echo $am['id']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

        </main>
    </div>

    <!-- INITIAL PAGE PERSISTENCE SCRIPT (MOVED TO DOMCONTENTLOADED) -->


    <!-- Add Booking Modal -->
    <div id="addBookingModal" class="modal">
        <div class="ab-modal-content">

            <div class="ab-modal-header">
                <h3 class="ab-modal-title" id="abModalTitle">Step 1: Select Dates</h3>
                <button class="ab-close-btn" id="closeAddBookingModalX">✕</button>
            </div>

            <div class="ab-modal-body">
                <form id="addBookingForm">

                    <!-- Admin Side - Date Selection -->
                    <div id="ab-step-1" class="ab-step active">
                        <div class="input-row-flex">

                            <div class="input-col">
                                <label class="input-label">Check-In</label>
                                <input type="text" class="custom-date-input" id="checkin_picker" name="checkin"
                                    placeholder="Select Date" required readonly>
                            </div>

                            <div class="input-col">
                                <label class="input-label">Check-Out</label>
                                <input type="text" class="custom-date-input" id="checkout_picker" name="checkout"
                                    placeholder="Select Date" required readonly>
                            </div>

                        </div>

                        <div class="ab-grid-footer">
                            <div></div>
                            <button type="button" class="ab-submit-btn" onclick="goToStep(2)">Search Rooms</button>
                        </div>

                    </div>

                    <!-- Admin Side - Room Selection -->
                    <div id="ab-step-2" class="ab-step">
                        <p style="font-size:0.9rem; color:#666; margin-bottom:15px;">Select one or more rooms:</p>

                        <div class="room-selection-grid" id="roomSelectionContainer">
                        </div>

                        <div class="step-nav-buttons">
                            <button type="button" class="btn-secondary" onclick="goToStep(1)">Back</button>
                            <button type="button" class="ab-submit-btn" style="width:auto; padding: 10px 30px;"
                                onclick="goToStep(3)">Next: Guest Info</button>
                        </div>
                    </div>

                    <!-- Admin Side - Guest Information -->
                    <div id="ab-step-3" class="ab-step">

                        <div class="step-header-row">
                            <h3 class="step-header-title">Personal Information</h3>
                        </div>

                        <div class="ab-grid-3 ab-mb-3">
                            <div>
                                <label class="ab-label">Booking Type</label>
                                <input type="text" class="ab-input" id="bookingSourceDisplay" name="booking_source"
                                    readonly
                                    style="background-color: #E9ECEF; color: #555; pointer-events: none; text-transform: capitalize;">
                            </div>
                            <div>
                                <label class="ab-label">Salutation<span>*</span></label>
                                <select class="ab-select" name="salutation" required>
                                    <option value="" disabled selected>- Select -</option>
                                    <option value="Mr.">Mr.</option>
                                    <option value="Ms.">Ms.</option>
                                    <option value="Mrs.">Mrs.</option>
                                    <option value="Dr.">Dr.</option>
                                </select>
                            </div>
                            <div>
                                <label class="ab-label">First Name<span>*</span></label>
                                <input type="text" class="ab-input" name="firstname" required>
                            </div>
                        </div>

                        <div class="ab-grid-3 ab-mb-3">
                            <div>
                                <label class="ab-label">Last Name<span>*</span></label>
                                <input type="text" class="ab-input" name="lastname" required>
                            </div>
                            <div>
                                <label class="ab-label">Gender<span>*</span></label>
                                <select class="ab-select" name="gender" required>
                                    <option value="" disabled selected>- Select -</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Prefer not to say">Prefer not to say</option>
                                </select>
                            </div>
                            <div>
                                <label class="ab-label">Birthdate<span>*</span></label>
                                <input type="text" class="custom-date-input" id="birthdate_picker" name="birthdate"
                                    placeholder="YYYY-MM-DD" required>
                            </div>
                        </div>

                        <div class="ab-grid-2 ab-mb-3">
                            <div>
                                <label class="ab-label">Nationality<span>*</span></label>
                                <input class="ab-input" list="nationality_options" name="nationality"
                                    id="nationalityInput" placeholder="- Select -" autocomplete="off" required>
                                <datalist id="nationality_options"></datalist>
                            </div>
                            <div>
                                <label class="ab-label">Email Address<span>*</span></label>
                                <input type="email" class="ab-input" name="email" required>
                            </div>
                        </div>

                        <div class="ab-grid-2 ab-mb-3">
                            <div>
                                <label class="ab-label">Payment Method<span>*</span></label>
                                <select class="ab-select" name="payment_method" required>
                                    <option value="Cash" selected>Cash</option>
                                    <option value="GCash">GCash</option>
                                </select>
                            </div>

                            <div>
                                <label class="ab-label">Payment Option<span>*</span></label>
                                <select class="ab-select" id="payment_term_select" name="payment_term">
                                    <option value="full">Full Payment</option>
                                    <option value="partial">50% Down Payment</option>
                                </select>
                            </div>
                        </div>

                        <div class="ab-grid-2 ab-mb-3">
                            <div>
                                <label class="ab-label">Contact Number<span>*</span></label>
                                <input type="text" class="ab-input" name="contact" placeholder="#09123456789" required
                                    maxlength="12"
                                    oninput="this.value = this.value.replace(/[^0-9#]/g, ''); if(this.value.length > 12) this.value = this.value.slice(0, 12);">
                            </div>
                            <div id="arrivalTimeContainer">
                                <label class="ab-label">Estimated Arrival Time<span>*</span></label>
                                <select class="ab-select" name="arrival_time" id="arrival_time_select" required>
                                    <option value="" disabled selected>- Select -</option>
                                </select>
                            </div>
                        </div>

                        <div class="ab-mb-4" style="position:relative;">
                            <label class="ab-label">Address<span>*</span></label>
                            <input type="text" id="adminAddressInput" class="ab-input ab-full-width"
                                placeholder="Start typing address..." autocomplete="off" required>
                            <input type="hidden" name="address" id="adminAddressHidden">
                            <div class="spinner" id="adminAddrLoader"></div>
                            <div class="address-results-list" id="adminAddrResults"></div>
                        </div>

                        <div class="ab-grid-2 ab-mb-3">
                            <div>
                                <label class="ab-label">Adults</label>
                                <input type="text" class="ab-input" name="adults" value="1" required inputmode="numeric"
                                    oninput="this.value = this.value.replace(/[^0-9]/g, '')" maxlength="1">
                            </div>
                            <div>
                                <label class="ab-label">Children</label>
                                <input type="text" class="ab-input" name="children" value="0" inputmode="numeric"
                                    oninput="this.value = this.value.replace(/[^0-9]/g, '')" maxlength="1">
                            </div>
                        </div>

                        <div class="step-nav-buttons">
                            <button type="button" class="btn-secondary" onclick="goToStep(2)">Back</button>
                            <button type="button" class="ab-submit-btn" style="width:auto;"
                                onclick="validateAndReview()">Review & Confirm</button>
                        </div>

                    </div>

                </form>
            </div>
        </div>
    </div>

    <!-- Confimation Modal -->
    <div id="confirmationModal" class="modal" style="z-index: 1100;">
        <div class="ab-modal-content" style="max-width: 500px;">
            <div class="ab-modal-header">
                <h3 class="ab-modal-title">Confirm Booking</h3>
                <button class="ab-close-btn" id="closeConfirmModalX">✕</button>
            </div>
            <div class="ab-modal-body" style="text-align:center;">
                <div style="background:#f9f9f9; padding:20px; border-radius:8px; text-align:left; margin-bottom:20px;">
                    <p><strong>Guest:</strong> <span id="confirmName"></span></p>
                    <p><strong>Dates:</strong> <span id="confirmDates"></span></p>
                    <p><strong>Rooms:</strong> <span id="confirmRooms"></span></p>
                    <p><strong>Total:</strong> <span id="confirmTotal" style="color:#FFA000; font-weight:bold;"></span>
                    </p>
                </div>

                <button type="button" class="ab-submit-btn" id="finalConfirmBtn">Confirm & Save</button>
                <button type="button" class="btn-secondary" style="margin-top:10px; width:100%; background:none;"
                    id="cancelConfirmBtn">Back to Edit</button>
            </div>
        </div>
    </div>

    <!-- Booking Action Modal -->
    <div id="bookingActionModal" class="modal" style="z-index: 1300;">
        <div class="ab-modal-content" style="max-width: 500px; height: auto; max-height: 90vh;">
            <div class="ab-modal-header">
                <h3 class="ab-modal-title">Booking Details</h3>
                <button class="ab-close-btn" onclick="closeActionModal()">✕</button>
            </div>

            <div class="ab-modal-body" style="padding: 20px;">
                <div
                    style="background: #F9FAFB; padding: 15px; border-radius: 8px; border: 1px solid #eee; margin-bottom: 20px;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                        <span style="color:#888; font-size:0.8rem; font-weight:700;">GUEST</span>
                        <span style="font-weight:600; color:#333;" id="ba_guest"></span>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                        <span style="color:#888; font-size:0.8rem; font-weight:700;">REFERENCE</span>
                        <span style="font-weight:600; color:#333;" id="ba_ref"></span>
                    </div>
                    <div
                        style="display:flex; justify-content:space-between; margin-bottom:10px; align-items: flex-start;">
                        <span
                            style="color:#888; font-size:0.8rem; font-weight:700; white-space: nowrap; margin-right: 15px;">ROOMS</span>
                        <span
                            style="font-weight:600; color:#333; text-align: right; word-break: break-word; max-width: 70%;"
                            id="ba_room"></span>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                        <span style="color:#888; font-size:0.8rem; font-weight:700;">DATES</span>
                        <span style="font-weight:600; color:#333;" id="ba_dates"></span>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                        <span style="color:#888; font-size:0.8rem; font-weight:700;">TOTAL PRICE</span>
                        <span style="font-weight:700; color:#FFA000;" id="ba_price"></span>
                    </div>
                    <div style="margin-top:15px; padding-top:10px; border-top:1px dashed #ddd;">
                        <span
                            style="color:#888; font-size:0.8rem; font-weight:700; display:block; margin-bottom:5px;">SPECIAL
                            REQUEST</span>
                        <div id="ba_special_request"
                            style="font-size:0.85rem; color:#555; background:#fff; padding:8px; border-radius:4px; border:1px solid #f0f0f0; min-height:35px; font-style:italic;">
                        </div>
                    </div>
                </div>

                <div id="ba_action_container">
                </div>

                <p id="ba_warning"
                    style="display:none; color:#DC2626; font-size:0.85rem; text-align:center; background:#FEE2E2; padding:10px; border-radius:6px; margin-top:10px;">
                    Cannot confirm arrival: This booking is scheduled for the future.
                </p>
            </div>
        </div>
    </div>


    <!-- Room Details Modal -->
    <div id="roomModal" class="modal" style="z-index: 1400;">
        <div class="ab-modal-content" style="max-width: 700px;">
            <div class="ab-modal-header">
                <h3 class="ab-modal-title" id="roomModalTitle">Add New Room</h3>
                <button class="ab-close-btn"
                    onclick="document.getElementById('roomModal').style.display='none'">✕</button>
            </div>

            <div class="ab-modal-body" style="padding: 25px;">
                <form id="roomForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" id="roomAction" value="add">
                    <input type="hidden" name="room_id" id="roomId">

                    <div class="room-modal-grid-top">

                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <label class="rm-label">Room Images (Max 4)</label>
                            <div class="room-gallery-grid"
                                style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; width: 220px;">
                                <div class="gallery-box" onclick="triggerGalleryUpload(0)">
                                    <img id="preview_0" src="" class="gallery-img" style="display:none;">
                                    <div id="placeholder_0" class="gallery-placeholder">1 (Cover)</div>
                                    <input type="file" name="images[]" id="file_0" class="gallery-input"
                                        accept="image/*" onchange="previewGalleryImage(this, 0)">
                                </div>
                                <div class="gallery-box" onclick="triggerGalleryUpload(1)">
                                    <img id="preview_1" src="" class="gallery-img" style="display:none;">
                                    <div id="placeholder_1" class="gallery-placeholder">2</div>
                                    <input type="file" name="images[]" id="file_1" class="gallery-input"
                                        accept="image/*" onchange="previewGalleryImage(this, 1)">
                                </div>
                                <div class="gallery-box" onclick="triggerGalleryUpload(2)">
                                    <img id="preview_2" src="" class="gallery-img" style="display:none;">
                                    <div id="placeholder_2" class="gallery-placeholder">3</div>
                                    <input type="file" name="images[]" id="file_2" class="gallery-input"
                                        accept="image/*" onchange="previewGalleryImage(this, 2)">
                                </div>
                                <div class="gallery-box" onclick="triggerGalleryUpload(3)">
                                    <img id="preview_3" src="" class="gallery-img" style="display:none;">
                                    <div id="placeholder_3" class="gallery-placeholder">4</div>
                                    <input type="file" name="images[]" id="file_3" class="gallery-input"
                                        accept="image/*" onchange="previewGalleryImage(this, 3)">
                                </div>
                            </div>
                        </div>

                        <div class="room-inputs-col">
                            <div style="margin-top: 0;">
                                <label class="rm-label">Room Name / Number</label>
                                <input type="text" class="rm-input" name="room_name" id="roomNameInput" required
                                    placeholder="e.g. ROOM 201">
                            </div>
                            <div>
                                <label class="rm-label">Price Per Night (₱)</label>
                                <input type="number" class="rm-input" name="price" id="roomPriceInput" required
                                    placeholder="0.00" step="0.01">
                            </div>
                        </div>
                    </div>

                    <div class="room-modal-grid-mid">
                        <div>
                            <label class="rm-label">Capacity (Pax)</label>
                            <input type="number" class="rm-input" name="capacity" id="roomCapacityInput" required
                                placeholder="e.g. 2">
                        </div>
                        <div>
                            <label class="rm-label">Size</label>
                            <input type="text" class="rm-input" name="size" id="roomSizeInput" required
                                placeholder="e.g. 35 m²">
                        </div>
                        <div>
                            <label class="rm-label">Type</label>
                            <select class="rm-input pl-5" name="bed_type" id="roomBedTypeInput" required
                                style="cursor: pointer;">
                                <option value="" disabled selected>Select Type</option>
                                <option value="Luxury">Luxury</option>
                                <option value="Deluxe">Deluxe</option>
                            </select>
                        </div>
                    </div>

                    <div class="ab-mb-4">
                        <label class="rm-label">Description</label>
                        <textarea class="rm-input" name="description" id="roomDescInput" rows="4" required
                            style="resize:vertical;" placeholder="Enter room details..."></textarea>
                    </div>

                    <div class="ab-mb-4">
                        <label class="rm-label">Amenities</label>
                        <div id="amenitiesGrid"
                            style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; background: #f9f9f9; padding: 15px; border-radius: 8px; border: 1px solid #eee;">
                            <?php foreach ($all_amenities as $am): ?>
                                <label
                                    style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; cursor: pointer; color: #555;">
                                    <input type="checkbox" name="amenities[]" value="<?php echo $am['id']; ?>"
                                        class="am-checkbox">
                                    <i class="<?php echo htmlspecialchars($am['icon_class']); ?>"
                                        style="width: 16px; text-align: center;"></i>
                                    <?php echo htmlspecialchars($am['title']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="submit" class="ab-submit-btn">Save Room</button>
                </form>
            </div>
        </div>
    </div>

    <!-- News Details Modal -->
    <div id="newsModal" class="modal" style="z-index: 1400;">
        <div class="ab-modal-content" style="max-width: 700px; max-height: 90vh;">

            <div class="ab-modal-header">
                <h3 class="ab-modal-title" id="newsModalTitle">Add News</h3>
                <button class="ab-close-btn"
                    onclick="document.getElementById('newsModal').style.display='none'">✕</button>
            </div>

            <div class="ab-modal-body">
                <form id="newsForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" id="newsAction" value="add">
                    <input type="hidden" name="news_id" id="newsId">

                    <div class="news-layout-grid">

                        <div>
                            <div class="news-image-box" onclick="document.getElementById('newsImageInput').click()">

                                <img id="newsImagePreview" src=""
                                    style="width:100%; height:100%; object-fit:cover; display:none;">

                                <div id="newsImagePlaceholder" style="text-align:center;">
                                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#9CA3AF"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                </div>

                                <div class="news-add-icon-corner">
                                    <i class="fas fa-plus"></i>
                                </div>
                            </div>
                            <input type="file" name="image" id="newsImageInput" accept="image/*" style="display:none;"
                                onchange="previewNewsImage(this)">
                            <div style="text-align:center; font-size:0.75rem; color:#999; margin-top:5px;">Tap to upload
                                image</div>
                        </div>

                        <div class="news-inputs-col">
                            <div>
                                <label class="news-label-clean">Title</label>
                                <input type="text" class="news-clean-input" name="title" id="newsTitleInput" required
                                    placeholder="Enter headline...">
                            </div>

                            <div>
                                <label class="news-label-clean">Date</label>
                                <div style="position:relative;">
                                    <input type="text" class="news-clean-input" name="news_date" id="news_date_picker"
                                        required placeholder="Select Date">
                                    <!-- <i class="far fa-calendar"
                                        style="position:absolute; right:15px; top:50%; transform:translateY(-50%); color:#999; pointer-events:none;"></i> -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ab-mb-4">
                        <label class="news-label-clean">Description</label>
                        <input type="hidden" name="description" id="newsDescInput">
                        <div id="newsQuillEditor" style="background-color: white;"></div>
                    </div>

                    <button type="submit" class="ab-submit-btn">Save News</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Amenity Details Modal -->
    <div id="amenityModal" class="modal" style="z-index: 1500;">
        <div class="ab-modal-content" style="max-width: 500px;">
            <div class="ab-modal-header">
                <h3 class="ab-modal-title" id="amenityModalTitle">Add New Amenity</h3>
                <button class="ab-close-btn"
                    onclick="document.getElementById('amenityModal').style.display='none'">✕</button>
            </div>
            <div class="ab-modal-body" style="padding: 25px;">
                <form id="amenityForm">
                    <input type="hidden" name="action" id="amenityAction" value="add">
                    <input type="hidden" name="amenity_id" id="amenityId">

                    <div class="ab-mb-3">
                        <label class="rm-label">Amenity Title</label>
                        <input type="text" class="rm-input" name="title" id="amenityTitleInput" required
                            placeholder="e.g. Swimming Pool">
                    </div>

                    <div class="ab-mb-3">
                        <label class="rm-label">Icon Class (FontAwesome)</label>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <input type="text" class="rm-input" name="icon_class" id="amenityIconInput" required
                                placeholder="e.g. fas fa-swimming-pool" oninput="previewAmenityIcon(this.value)">
                            <div id="amenityIconPreview"
                                style="font-size: 1.5rem; color: #B88E2F; width: 40px; text-align:center;">
                                <i class="fas fa-question-circle"></i>
                            </div>
                        </div>
                        <div
                            style="background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px; padding: 10px; margin-top: 10px;">
                            <small style="color: #555; display: block; margin-bottom: 5px; font-weight: 600;">
                                <i class="fas fa-info-circle"></i> How to get an Icon:
                            </small>
                            <ol style="margin: 0; padding-left: 20px; font-size: 0.75rem; color: #666;">
                                <li>Go to <a href="https://fontawesome.com/v5/search?m=free" target="_blank"
                                        style="color: #B88E2F; font-weight: 700; text-decoration: underline;">FontAwesome
                                        5 Free Icons</a></li>
                                <li>Search for your desired icon (e.g. "wifi", "tv", "coffee").</li>
                                <li>Copy the class name (e.g. <b>fas fa-wifi</b>) and paste it here.</li>
                            </ol>
                            <small
                                style="display: block; margin-top: 8px; font-size: 0.7rem; color: #888; font-style: italic;">
                                Common: fas fa-wifi, fas fa-snowflake, fas fa-utensils, fas fa-tv
                            </small>
                        </div>
                    </div>

                    <div class="ab-mb-4">
                        <label class="rm-label">Description</label>
                        <textarea class="rm-input" name="description" id="amenityDescInput" rows="3"
                            placeholder="Brief details about this amenity..."></textarea>
                    </div>

                    <button type="submit" class="ab-submit-btn">Save Amenity</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Event Details Modal -->
    <div id="eventModal" class="modal" style="z-index: 1450;">
        <div class="ab-modal-content" style="max-width: 700px; max-height: 90vh;">
            <div class="ab-modal-header">
                <h3 class="ab-modal-title" id="eventModalTitle">Add Event</h3>
                <button class="ab-close-btn"
                    onclick="document.getElementById('eventModal').style.display='none'">✕</button>
            </div>
            <div class="ab-modal-body">
                <form id="eventForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" id="eventAction" value="add">
                    <input type="hidden" name="event_id" id="eventId">

                    <div class="news-layout-grid">
                        <div>
                            <div class="news-image-box" onclick="document.getElementById('eventImageInput').click()">
                                <img id="eventImagePreview" src=""
                                    style="width:100%; height:100%; object-fit:cover; display:none;">
                                <div id="eventImagePlaceholder" style="text-align:center;">
                                    <i class="fas fa-image" style="color:#ccc; font-size:1.5rem;"></i>
                                </div>
                                <div class="news-add-icon-corner"><i class="fas fa-plus"></i></div>
                            </div>
                            <input type="file" name="image" id="eventImageInput" accept="image/*" style="display:none;"
                                onchange="previewEventImage(this)">
                            <div style="text-align:center; font-size:0.75rem; color:#999; margin-top:5px;">Upload Cover
                            </div>
                        </div>

                        <div class="news-inputs-col">
                            <div>
                                <label class="news-label-clean">Event Title</label>
                                <input type="text" class="news-clean-input" name="title" id="eventTitleInput" required
                                    placeholder="e.g. Summer Pool Party">
                            </div>
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                                <div>
                                    <label class="news-label-clean">Date</label>
                                    <input type="text" class="news-clean-input" name="event_date" id="event_date_picker"
                                        required placeholder="Select Date">
                                </div>
                                <div>
                                    <label class="news-label-clean">Time</label>
                                    <input type="time" class="news-clean-input" name="time_start" id="eventTimeInput"
                                        required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ab-mb-4">
                        <label class="news-label-clean">Description</label>
                        <input type="hidden" name="description" id="eventDescInput">
                        <div id="eventQuillEditor" style="background-color: white;"></div>
                    </div>

                    <button type="submit" class="ab-submit-btn">Save Event</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Food Menu Item Modal -->
    <div id="foodModal" class="modal" style="z-index: 1450;">
        <div class="ab-modal-content" style="max-width: 700px;">
            <div class="ab-modal-header">
                <h3 class="ab-modal-title" id="foodModalTitle">Add Menu Item</h3>
                <button class="ab-close-btn"
                    onclick="document.getElementById('foodModal').style.display='none'">✕</button>
            </div>

            <div class="ab-modal-body">
                <form id="foodForm" enctype="multipart/form-data"> <input type="hidden" name="action" id="foodAction"
                        value="add">
                    <input type="hidden" name="food_id" id="foodId">

                    <div class="news-layout-grid">

                        <div>
                            <div class="news-image-box" onclick="document.getElementById('foodImageInput').click()"
                                style="height: 180px;">
                                <img id="foodImagePreview" src=""
                                    style="width:100%; height:100%; object-fit:cover; display:none;">
                                <div id="foodImagePlaceholder" style="text-align:center;">
                                    <i class="fas fa-utensils" style="color:#ccc; font-size:1.5rem;"></i>
                                </div>
                                <div class="news-add-icon-corner"><i class="fas fa-plus"></i></div>
                            </div>
                            <input type="file" name="image" id="foodImageInput" accept="image/*" style="display:none;"
                                onchange="previewFoodImage(this)">
                            <div style="text-align:center; font-size:0.75rem; color:#999; margin-top:5px;">Upload Food
                                Image</div>
                        </div>

                        <div class="news-inputs-col">
                            <div>
                                <label class="rm-label">Item Name</label>
                                <input type="text" class="rm-input" name="item_name" id="foodNameInput" required
                                    placeholder="e.g. Club Sandwich">
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div>
                                    <label class="rm-label">Category</label>
                                    <select class="rm-input" name="category" id="foodCategoryInput"
                                        style="padding-left: 15px;" required>
                                        <option value="Main Course">Main Course</option>
                                        <option value="Beverage">Beverage</option>
                                        <option value="Dessert">Dessert</option>
                                        <option value="Snack">Snack</option>
                                        <option value="Soup">Soup</option>
                                        <option value="Breakfast">Breakfast</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="rm-label">Price (₱)</label>
                                    <input type="number" class="rm-input" name="price" id="foodPriceInput" required
                                        step="0.01" placeholder="0.00">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ab-grid-footer" style="margin-top: 25px;">
                        <button type="button" class="btn-secondary"
                            onclick="document.getElementById('foodModal').style.display='none'">Cancel</button>
                        <button type="submit" class="ab-submit-btn">Save Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Message Details Modal -->
    <div id="messageModal" class="modal" style="z-index: 2500;">
        <div class="ab-modal-content" style="max-width: 500px;">
            <div class="ab-modal-header">
                <h3 class="ab-modal-title">Message Details</h3>
                <button class="ab-close-btn"
                    onclick="document.getElementById('messageModal').style.display='none'">✕</button>
            </div>
            <div class="ab-modal-body" style="padding: 20px;">
                <div style="margin-bottom: 15px;">
                    <label class="ab-label">From:</label>
                    <div id="msgModalName" style="font-weight: 700; color: #333;"></div>
                    <div id="msgModalEmail" style="font-size: 0.85rem; color: #666;"></div>
                </div>
                <div style="margin-bottom: 20px;">
                    <label class="ab-label">Message:</label>
                    <div id="msgModalBody"
                        style="background: #f9f9f9; padding: 15px; border-radius: 8px; border: 1px solid #eee; color: #555; line-height: 1.5;">
                    </div>
                </div>

                <!-- <label class="ab-label">Quick Reply:</label>
                <textarea class="ab-input" rows="3" placeholder="Type your reply here..."></textarea>

                <div class="ab-grid-footer" style="margin-top: 15px;">
                    <div></div>
                    <button class="ab-submit-btn"
                        onclick="document.getElementById('messageModal').style.display='none'">Send Reply</button>
                </div> -->
            </div>
        </div>
    </div>

    <!-- Extend Stay Modal -->
    <div id="extendModal" class="modal" style="z-index: 1600;">
        <div class="ab-modal-content" style="max-width: 600px; height: auto;">

            <div class="ab-modal-header">
                <h3 class="ab-modal-title">Extend Stay</h3>
                <button class="ab-close-btn" onclick="closeExtendModal()">✕</button>
            </div>

            <div class="ab-modal-body" style="padding: 25px;">
                <input type="hidden" id="ext_booking_id">

                <div id="ext_main_content">
                    <p style="margin-bottom: 5px; color: #666; font-size: 0.9rem;">Current Checkout Date:</p>
                    <h3 id="ext_current_date" style="margin-top: 0; color: #333;">2025-12-31</h3>

                    <div style="margin-top: 20px;">
                        <label class="ab-label">Currently Booked Rooms</label>
                        <div id="ext_rooms_list" style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 5px;">
                            <!-- Room tags will be injected here -->
                        </div>
                    </div>

                    <div style="margin-top: 20px;">
                        <label class="ab-label">Select New Checkout Date</label>
                        <div style="position: relative;">
                            <input type="text" id="extend_date_picker" class="custom-date-input"
                                placeholder="Select Date..." readonly>
                        </div>
                    </div>

                    <div style="margin-top: 20px;">
                        <label class="ab-label">Payment for Extension</label>
                        <select id="ext_payment_choice" class="ab-select">
                            <option value="pay_full">Pay Full Extension Amount</option>
                            <option value="pay_partial">Pay Partial (50% Down)</option>
                        </select>
                        <p style="font-size: 0.75rem; color: #888; margin-top: 5px;">
                            "Pay Partial" adds the remaining 50% to the guest's balance.
                        </p>
                    </div>

                    <!-- 🟢 PRICE PREVIEW BOX -->
                    <div id="ext_price_preview"
                        style="margin-top: 20px; padding: 15px; background: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 8px; display: none;">
                        <div
                            style="display: flex; justify-content: space-between; font-size: 0.85rem; color: #666; margin-bottom: 5px;">
                            <span>Daily Rate:</span>
                            <span id="ext_daily_rate">₱0</span>
                        </div>
                        <div
                            style="display: flex; justify-content: space-between; font-size: 0.85rem; color: #666; margin-bottom: 8px;">
                            <span>Extra Nights:</span>
                            <span id="ext_extra_nights">0</span>
                        </div>
                        <div
                            style="display: flex; justify-content: space-between; font-size: 1rem; font-weight: 700; color: #333; border-top: 1px dashed #ccc; padding-top: 8px;">
                            <span>Total Extension:</span>
                            <span id="ext_total_cost">₱0</span>
                        </div>
                    </div>

                    <div class="ab-grid-footer" style="margin-top: 25px;">
                        <button class="btn-secondary" onclick="closeExtendModal()">Cancel</button>
                        <button class="ab-submit-btn" onclick="submitExtension()">Confirm Extension</button>
                    </div>
                </div>

                <!-- 🟢 CONFLICT RESOLUTION AREA (Initially Hidden) -->
                <div id="ext_conflict_resolution" style="display:none;"></div>
            </div>
        </div>
    </div>

    <!-- Notification Details Modal -->
    <div id="notificationModal" class="modal" style="z-index: 2600;">
        <div class="ab-modal-content" style="max-width: 400px;">
            <div class="ab-modal-header">
                <h3 class="ab-modal-title">Notification</h3>
                <button class="ab-close-btn"
                    onclick="document.getElementById('notificationModal').style.display='none'">✕</button>
            </div>
            <div class="ab-modal-body" style="padding: 20px;">
                <div style="margin-bottom: 5px;">
                    <h4 id="notifModalTitle" style="margin: 0; color: #333; font-size: 1.1rem; font-weight: 700;"></h4>
                </div>
                <div style="margin-bottom: 15px;">
                    <span id="notifModalDate" style="font-size: 0.8rem; color: #888;"></span>
                </div>
                <div
                    style="background: #f9f9f9; padding: 15px; border-radius: 8px; border: 1px solid #eee; color: #555; line-height: 1.5;">
                    <p id="notifModalDesc" style="margin: 0;"></p>
                </div>
                <div style="margin-top: 20px; text-align: right;">
                    <button class="btn-secondary" style="padding: 8px 20px;"
                        onclick="document.getElementById('notificationModal').style.display='none'">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Admin Edit Profile Modal -->
    <div id="adminEditModal" class="modal" style="z-index: 1550;">
        <div class="ab-modal-content" style="max-width: 600px;">
            <div class="ab-modal-header">
                <h3 class="ab-modal-title">Edit Profile</h3>
                <button class="ab-close-btn" onclick="toggleAdminEdit(false)">✕</button>
            </div>

            <div class="ab-modal-body" style="padding: 30px;">
                <form id="adminEditForm" onsubmit="saveAdminProfile(event)">
                    <div class="edit-row-2"
                        style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div>
                            <label class="edit-label">Name</label>
                            <input type="text" class="edit-input" name="name" id="edit_username" required>
                        </div>
                        <div>
                            <label class="edit-label">Email</label>
                            <input type="email" class="edit-input" name="email" id="edit_admin_email" required>
                        </div>
                    </div>

                    <div class="edit-row-1" style="margin-bottom: 20px;">
                        <label class="edit-label">Contact Number</label>
                        <input type="text" class="edit-input" name="contact" id="edit_contact"
                            placeholder="09123456789#" maxlength="12"
                            oninput="this.value = this.value.replace(/[^0-9#]/g, ''); if(this.value.length > 12) this.value = this.value.slice(0, 12);">
                    </div>

                    <div class="edit-row-1" style="margin-bottom: 20px;">
                        <label class="edit-label">Password</label>
                        <input type="password" class="edit-input" name="password"
                            placeholder="Leave empty to keep current">
                    </div>

                    <div class="edit-row-1" style="margin-bottom: 20px;">
                        <label class="edit-label">Confirm Password</label>
                        <input type="password" class="edit-input" name="confirm_password"
                            placeholder="Confirm new password">
                    </div>

                    <div
                        style="background-color: #FFF8E1; border: 1px solid #FFE082; border-radius: 8px; padding: 15px; margin-bottom: 25px;">
                        <h4 style="margin: 0 0 10px 0; color: #B88E2F; font-size: 0.9rem; text-transform: uppercase;">
                            <i class="fas fa-wifi"></i> Guest Wi-Fi Settings
                        </h4>
                        <div class="edit-row-2"
                            style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 0;">
                            <div>
                                <label class="edit-label" style="color: #B88E2F;">Network Name (SSID)</label>
                                <input type="text" class="edit-input" name="wifi_ssid" id="edit_wifi_ssid"
                                    placeholder="e.g. AMV Guest">
                            </div>
                            <div>
                                <label class="edit-label" style="color: #B88E2F;">Wi-Fi Password</label>
                                <input type="text" class="edit-input" name="wifi_password" id="edit_wifi_pass"
                                    placeholder="Enter password">
                            </div>
                        </div>
                    </div>

                    <div class="ab-grid-footer"
                        style="margin-top: 25px; display: grid; grid-template-columns: 1fr 1.5fr; gap: 15px;">
                        <button type="button" class="btn-secondary" onclick="toggleAdminEdit(false)">Cancel</button>
                        <button type="submit" class="btn-gold-confirm">Confirm Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Payment Edit Modal -->
    <div id="paymentEditModal" class="modal" style="z-index: 1560;">
        <div class="ab-modal-content" style="max-width: 600px;">
            <div class="ab-modal-header">
                <h3 class="ab-modal-title">Edit Payment Details</h3>
                <button class="ab-close-btn" onclick="togglePaymentEdit(false)">✕</button>
            </div>

            <div class="ab-modal-body" style="padding: 30px;">
                <form id="paymentEditForm" onsubmit="savePaymentSettings(event)" enctype="multipart/form-data">

                    <div style="margin-bottom: 25px;">
                        <label class="edit-label">QR Code Image</label>
                        <div class="news-image-box" onclick="document.getElementById('editQrInput').click()"
                            style="height: 250px;">
                            <img id="editQrPreview" src=""
                                style="width:100%; height:100%; object-fit:contain; display:none;">
                            <div id="editQrPlaceholder" style="text-align:center;">
                                <i class="fas fa-qrcode" style="color:#ccc; font-size:2rem;"></i>
                                <div style="font-size:0.8rem; color:#999; margin-top:5px;">Click to upload QR</div>
                            </div>
                            <div class="news-add-icon-corner"><i class="fas fa-camera"></i></div>
                        </div>
                        <input type="file" name="qr_image" id="editQrInput" accept="image/*" style="display:none;"
                            onchange="previewPaymentQR(this)">
                    </div>

                    <div class="edit-row-2"
                        style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div>
                            <label class="edit-label">Method</label>
                            <select class="edit-input" name="payment_method" id="edit_pay_method"
                                style="cursor:pointer;">
                                <option value="GCash">GCash</option>
                                <option value="Maya">Maya</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                            </select>
                        </div>
                        <div>
                            <label class="edit-label">Account Number</label>
                            <input type="text" class="edit-input" name="account_number" id="edit_acc_num" required>
                        </div>
                    </div>

                    <div class="edit-row-1" style="margin-bottom: 20px;">
                        <label class="edit-label">Account Name</label>
                        <input type="text" class="edit-input" name="account_name" id="edit_acc_name" required>
                    </div>

                    <div class="ab-grid-footer"
                        style="margin-top: 25px; display: grid; grid-template-columns: 1fr 1.5fr; gap: 15px;">
                        <button type="button" class="btn-secondary" onclick="togglePaymentEdit(false)">Cancel</button>
                        <button type="submit" class="btn-gold-confirm">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reschedule Booking Modal -->
    <div id="rescheduleModal" class="modal" style="z-index: 1700;">
        <div class="ab-modal-content" style="max-width: 600px; height: auto;">

            <div class="ab-modal-header">
                <h3 class="ab-modal-title">Reschedule Booking</h3>
                <button class="ab-close-btn" onclick="closeRescheduleModal()">✕</button>
            </div>

            <div class="ab-modal-body" style="padding: 25px;">
                <div
                    style="background-color: #FFF3CD; color: #856404; padding: 10px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 20px; border: 1px solid #FFEEBA;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>3-Day Grace Period:</strong><br>
                    Guests can only reschedule within 72 hours of their booking confirmation.
                </div>

                <div class="ab-grid-2 ab-mb-3">
                    <div>
                        <label class="ab-label">New Check-In</label>
                        <div class="ab-input-wrapper">
                            <input type="text" id="resched_checkin" class="ab-input" placeholder="Select Date" readonly>
                            <i class="far fa-calendar-alt ab-calendar-icon"></i>
                        </div>
                    </div>
                    <div>
                        <label class="ab-label">New Check-Out</label>
                        <div class="ab-input-wrapper">
                            <input type="text" id="resched_checkout" class="ab-input" placeholder="Select Date"
                                readonly>
                            <i class="far fa-calendar-alt ab-calendar-icon"></i>
                        </div>
                    </div>
                </div>

                <input type="hidden" id="resched_ref_id">

                <div class="ab-grid-footer" style="margin-top: 15px;">
                    <button class="btn-secondary" onclick="closeRescheduleModal()">Cancel</button>
                    <button class="ab-submit-btn" onclick="submitReschedule()">Check Availability</button>
                </div>
            </div>
        </div>
    </div>

    <!-- QR Scanner Modal -->
    <div id="qrScannerModal" class="modal" style="z-index: 2000;">
        <div class="ab-modal-content" style="max-width: 500px;">
            <div class="ab-modal-header">
                <h3 class="ab-modal-title">Scan Guest QR</h3>
                <button class="ab-close-btn" onclick="closeScannerModal()">✕</button>
            </div>
            <div class="ab-modal-body" style="padding: 20px; text-align: center;">
                <div id="qr-reader" style="width: 100%; border-radius: 8px; overflow: hidden;"></div>
                <p id="qr-result" style="margin-top: 15px; font-weight: 600; color: #555;">Waiting for camera...</p>

                <button class="btn-secondary" style="margin-top: 10px;" onclick="closeScannerModal()">
                    Stop Camera
                </button>
            </div>
        </div>
    </div>

    <!-- Transaction Details Modal -->
    <div id="transactionModal" class="modal" style="z-index: 3000;">
        <div class="ab-modal-content" style="max-width: 500px;">
            <div class="ab-modal-header">
                <h3 class="ab-modal-title">Transaction Details</h3>
                <button class="ab-close-btn"
                    onclick="document.getElementById('transactionModal').style.display='none'">✕</button>
            </div>
            <div class="ab-modal-body" style="padding: 25px;">

                <div style="text-align: center; margin-bottom: 20px;">
                    <div id="trans_status_badge"
                        style="display:inline-block; padding: 8px 20px; border-radius: 20px; font-weight: 700; font-size: 0.9rem;">
                        PENDING
                    </div>
                    <div style="color:#888; font-size:0.8rem; margin-top:8px;" id="trans_date">Oct 20, 2025 - 10:30 AM
                    </div>
                </div>

                <div
                    style="background: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 12px; padding: 20px; margin-bottom: 20px;">

                    <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                        <span style="color:#6B7280; font-size:0.85rem; font-weight:600;">TRANSACTION ID</span>
                        <span style="color:#111827; font-weight:700; font-family:monospace;" id="trans_id">#1024</span>
                    </div>

                    <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                        <span style="color:#6B7280; font-size:0.85rem; font-weight:600;">REFERENCE</span>
                        <span style="color:#111827; font-weight:700;" id="trans_ref">AMV-REF-123</span>
                    </div>

                    <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                        <span style="color:#6B7280; font-size:0.85rem; font-weight:600;">TYPE</span>
                        <span style="color:#111827; font-weight:600;" id="trans_type">Booking</span>
                    </div>

                    <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                        <span style="color:#6B7280; font-size:0.85rem; font-weight:600;">PAYMENT METHOD</span>
                        <span style="color:#111827; font-weight:600;" id="trans_method">GCash</span>
                    </div>

                    <div style="border-top: 1px dashed #E5E7EB; margin: 15px 0;"></div>

                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="color:#374151; font-size:1rem; font-weight:700;">TOTAL AMOUNT</span>
                        <span style="color:#B88E2F; font-weight:800; font-size:1.3rem;"
                            id="trans_amount">₱1,500.00</span>
                    </div>
                </div>

                <div style="padding: 0 10px;">
                    <div style="color:#6B7280; font-size:0.75rem; font-weight:700; margin-bottom:5px;">PAYER DETAILS
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div
                            style="width: 40px; height: 40px; background: #E0E7FF; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #3730A3; font-weight: 700;">
                            <i class="fas fa-user"></i>
                        </div>
                        <div>
                            <div style="color:#111; font-weight:600;" id="trans_user_name">John Doe</div>
                            <div style="color:#6B7280; font-size:0.85rem;" id="trans_user_email">john@example.com</div>
                        </div>
                    </div>
                </div>

                <div class="ab-grid-footer" style="margin-top: 30px; display: flex; justify-content: flex-end;">
                    <button class="btn-secondary" style="width: auto; padding: 10px 30px;"
                        onclick="document.getElementById('transactionModal').style.display='none'">Close</button>
                </div>

            </div>
        </div>
    </div>

    <div id="composeModal" class="modal" style="z-index: 2600;">
        <div class="ab-modal-content" style="max-width: 500px;">
            <div class="ab-modal-header">
                <h3 class="ab-modal-title">Send Message to Guest</h3>
                <button class="ab-close-btn" onclick="closeComposeModal()">✕</button>
            </div>
            <div class="ab-modal-body" style="padding: 25px;">
                <form id="composeForm" onsubmit="sendGuestEmail(event)">

                    <div class="ab-mb-3">
                        <label class="ab-label">Recipient Email</label>
                        <input type="email" class="ab-input" id="composeEmail" name="email" list="guest_emails_list"
                            placeholder="Start typing email..." required autocomplete="off">
                        <datalist id="guest_emails_list">
                            <?php
                            // Simple PHP to pre-fill suggestions
                            $guest_sql = "SELECT email, first_name, last_name FROM booking_guests GROUP BY email";
                            $g_res = $conn->query($guest_sql);
                            while ($g_row = $g_res->fetch_assoc()) {
                                echo "<option value='{$g_row['email']}'>{$g_row['first_name']} {$g_row['last_name']}</option>";
                            }
                            ?>
                        </datalist>
                    </div>

                    <div class="ab-mb-3">
                        <label class="ab-label">Subject</label>
                        <select class="ab-select" name="subject_type" id="composeSubjectType"
                            onchange="toggleCustomSubject()">
                            <option value="Booking Update">Booking Update</option>
                            <option value="Issue with Order">Issue with Order</option>
                            <option value="Payment Reminder">Payment Reminder</option>
                            <option value="Hotel Announcement">Hotel Announcement</option>
                            <option value="Other">Other (Custom)</option>
                        </select>
                        <input type="text" class="ab-input" name="custom_subject" id="customSubjectInput"
                            placeholder="Enter custom subject..." style="display:none; margin-top:10px;">
                    </div>

                    <div class="ab-mb-4">
                        <label class="ab-label">Message</label>
                        <textarea class="ab-input" name="message" rows="6" required placeholder="Dear Guest..."
                            style="resize:vertical;"></textarea>
                    </div>

                    <div class="ab-grid-footer">
                        <button type="button" class="btn-secondary" onclick="closeComposeModal()">Cancel</button>
                        <button type="submit" class="ab-submit-btn">Send Email <i
                                class="fas fa-paper-plane"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="logoutModal" class="modal" style="z-index: 9999;">
        <div class="ab-modal-content logout-card-premium">

            <div class="logout-visual">
                <img src="../../IMG/6.png" alt="Logout Illustration">
                <div style="font-weight: 700; font-size: 1.1rem;">AMV Hotel</div>
                <p style="font-size: 0.75rem; opacity: 0.8; margin-top: 5px;">Admin Dashboard</p>
            </div>

            <div class="logout-content-area">
                <h3>Ready to leave?</h3>
                <p>Make sure you have saved all your recent changes before signing out.</p>

                <div class="logout-btn-stack">
                    <button id="confirmLogout" class="btn-logout-main">
                        Sign Out <i class="fas fa-sign-out-alt"></i>
                    </button>
                    <button id="cancelLogout" class="btn-logout-secondary">
                        Stay logged in
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- 🟢 NEW: MOST BOOKED DATES MODAL -->
    <div id="dateLeaderboardModal" class="modal" style="z-index: 2100;">
        <div class="ab-modal-content" style="max-width: 600px; height: auto; max-height: 85vh;">
            <div class="ab-modal-header" style="background: #FFF8E1; border-bottom: 1px solid #FFECB3;">
                <h3 class="ab-modal-title" style="color: #B88E2F;">
                    <i class="fas fa-calendar-star"></i> Most Booked Dates
                </h3>
                <button class="ab-close-btn" onclick="closeDateLeaderboardModal()">✕</button>
            </div>

            <div class="ab-modal-body" style="padding: 0; display: flex; flex-direction: column;">
                <!-- Category Buttons (Months) -->
                <div id="dateCategoryContainer" style="padding: 15px 20px; background: #f8fafc; border-bottom: 1px solid #eee; display: flex; gap: 8px; overflow-x: auto; white-space: nowrap; scrollbar-width: none;">
                    <!-- Buttons like [All Time] [Jan 2026] [Feb 2026] injected here -->
                </div>

                <!-- Leaderboard List -->
                <div id="modalDateLeaderboardList" style="padding: 20px; overflow-y: auto; flex: 1;">
                    <div style="text-align:center; padding:40px; color:#999;">
                        <i class="fas fa-spinner fa-spin"></i> Loading rankings...
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Category Button Styling */
        .month-category-btn {
            background: #fff;
            border: 1px solid #e2e8f0;
            color: #64748b;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .month-category-btn:hover {
            border-color: #cbd5e1;
            background: #f1f5f9;
        }

        .month-category-btn.active {
            background: #B88E2F;
            color: white;
            border-color: #B88E2F;
            box-shadow: 0 4px 10px rgba(184, 142, 47, 0.2);
        }
    </style>

    <div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawersSmart()"></div>
    <div class="side-drawer" id="pendingDrawer">
        <div class="drawer-header">
            <div>
                <h3 style="margin:0; font-size:1.1rem; color:#333;">Pending Verifications</h3>
                <small style="color:#666;">Check receipts & confirm bookings</small>
            </div>
            <button onclick="togglePendingDrawer()"
                style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>

        <div class="drawer-body" id="pendingDrawerBody">
            <div style="text-align:center; padding:20px; color:#888;">
                <i class="fas fa-spinner fa-spin"></i> Loading...
            </div>
        </div>
    </div>

    <div id="receiptLightbox" class="lightbox-modal" onclick="this.style.display='none'">
        <span class="lightbox-close">&times;</span>
        <img class="lightbox-img" id="lightboxImage">
    </div>

    <div class="side-drawer" id="orderDrawer">
        <div class="drawer-header">
            <div>
                <h3 style="margin:0; font-size:1.1rem; color:#333;">Kitchen Queue</h3>
                <small style="color:#666;">Verify payments & accept orders</small>
            </div>
            <button onclick="toggleOrderDrawer()"
                style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>

        <div class="drawer-body" id="orderDrawerBody">
            <div style="text-align:center; padding:20px; color:#888;">
                <i class="fas fa-spinner fa-spin"></i> Loading...
            </div>
        </div>
    </div>


    <!-- <script src="../SCRIPT/dashboard-script.js"></script> -->

    <script>

        // --- GLOBAL STORAGE (This fixes the undefined issue) ---
        window.allNotifications = [];
        window.allMessages = [];
        let currentNotifFilter = 'all';
        let currentMsgFilter = 'all';

        // Global Quill Instances
        var newsQuill;
        var eventQuill;

        // --- GLOBAL VARIABLES ---
        let isProcessingBooking = false;
        let currentChartYear = new Date().getFullYear();
        let isDrawerBusy = false;
        let isSendingEmail = false;

        // 🟢 CHART STATE (Revenue vs Leaderboard)
        let currentChartData = { revenue: [], leaderboard: [], food: [], date: [], availableMonths: [] };
        let chartViewMode = 'revenue';
        let leaderboardSearchQuery = ''; // 🔥 Global search query
        let expandedLeaderboardRoom = null; // 🔥 Persistent state for expanded room 
        let expandedLeaderboardFood = null; // 🔥 Persistent state for expanded food 

        // --- 1. CHARTS INITIALIZATION ---
        window.myBookingPieChart = null;

        document.addEventListener("DOMContentLoaded", function () {
            // Initial Pie Chart
            const pieCtx = document.getElementById('pieBookings').getContext('2d');
            const pieDataRaw = <?php echo $js_pieData; ?>;

            window.myBookingPieChart = new Chart(pieCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Complete', 'No-Show', 'Cancelled'],
                    datasets: [{
                        data: pieDataRaw,
                        backgroundColor: ['#10B981', '#F59E0B', '#EF4444'],
                        hoverBackgroundColor: ['#059669', '#D97706', '#DC2626'],
                        borderWidth: 0,
                        cutout: '75%',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: 20 },
                    plugins: { legend: { display: false } }
                }
            });

            // Initial Revenue Line Chart
            const initialRevenue = <?php echo $js_barData; ?>;
            currentChartData.revenue = initialRevenue;
            renderRevenueChart(initialRevenue);

            // Fetch latest data to populate leaderboard background
            fetchRevenueChart(currentChartYear);
        });

        // --- 2. UPDATE CHART YEAR (Button Click) ---
        function updateYearButtons() {
            const actualYear = new Date().getFullYear();
            const nextBtn = document.getElementById('nextYearBtn');
            if (nextBtn) {
                if (currentChartYear >= actualYear) {
                    nextBtn.style.opacity = '0.3';
                    nextBtn.style.cursor = 'not-allowed';
                    nextBtn.style.pointerEvents = 'none';
                } else {
                    nextBtn.style.opacity = '1';
                    nextBtn.style.cursor = 'pointer';
                    nextBtn.style.pointerEvents = 'auto';
                }
            }
        }

        function changeChartYear(offset) {
            const nextYear = currentChartYear + offset;
            const actualYear = new Date().getFullYear();

            // Prevent going into the future beyond current year
            if (nextYear > actualYear) return;

            currentChartYear = nextYear;
            updateYearButtons();
            fetchRevenueChart(currentChartYear);
        }

        // 🟢 HANDLE LEADERBOARD SEARCH
        function handleLeaderboardSearch(query) {
            leaderboardSearchQuery = query.trim().toLowerCase();

            // Re-render the current active view with the filter
            if (chartViewMode === 'leaderboard') {
                renderRoomLeaderboard(currentChartData.leaderboard);
            } else if (chartViewMode === 'food') {
                renderFoodLeaderboard(currentChartData.food);
            } else if (chartViewMode === 'date') {
                renderDateLeaderboard(currentChartData.date);
            }
        }

        // --- 3. SWITCH CHART VIEW (Revenue / Leaderboard / Food / Date) ---
        function setChartViewMode(mode) {
            chartViewMode = mode;
            updateChartToggleButtons();

            // 🔥 Reset Search on Mode Switch
            leaderboardSearchQuery = '';
            const searchInput = document.getElementById('leaderboardSearchInput');
            if (searchInput) searchInput.value = '';

            // 🔥 Show/Hide Year Navigation and Search based on mode
            const prevBtn = document.getElementById('prevYearBtn');
            const nextBtn = document.getElementById('nextYearBtn');
            const searchContainer = document.getElementById('leaderboardSearchContainer');

            const isRevenue = (mode === 'revenue');
            const isLeaderboard = (mode === 'leaderboard' || mode === 'food' || mode === 'date');

            if (prevBtn && nextBtn) {
                // Control presence
                prevBtn.style.visibility = isRevenue ? 'visible' : 'hidden';
                nextBtn.style.visibility = isRevenue ? 'visible' : 'hidden';

                // Control interaction state (Disabled/Enabled)
                if (isRevenue) {
                    updateYearButtons(); // This will correctly set opacity/pointer-events
                } else {
                    prevBtn.style.opacity = '0';
                    nextBtn.style.opacity = '0';
                }
            }

            if (searchContainer) {
                searchContainer.style.display = isLeaderboard ? 'block' : 'none';
                if (searchInput) {
                    if (mode === 'food') searchInput.placeholder = "Search foods...";
                    else if (mode === 'date') searchInput.placeholder = "Search dates...";
                    else searchInput.placeholder = "Search rooms...";
                }
            }

            if (mode === 'revenue') {
                renderRevenueChart(currentChartData.revenue);
            } else if (mode === 'leaderboard') {
                renderRoomLeaderboard(currentChartData.leaderboard);
            } else if (mode === 'food') {
                renderFoodLeaderboard(currentChartData.food);
            } else if (mode === 'date') {
                renderDateLeaderboard(currentChartData.date);
            }
        }

        function updateChartToggleButtons() {
            const btnRev = document.getElementById('btnRevenue');
            const btnLead = document.getElementById('btnLeaderboard');
            const btnFood = document.getElementById('btnFood');
            const btnDate = document.getElementById('btnDate');
            const title = document.getElementById('revenueChartTitle');

            // Reset all
            [btnRev, btnLead, btnFood, btnDate].forEach(btn => {
                if (btn) {
                    btn.style.background = '#f4f4f4';
                    btn.style.color = '#555';
                    btn.style.boxShadow = 'none';
                }
            });

            if (chartViewMode === 'revenue') {
                if (btnRev) {
                    btnRev.style.background = '#B88E2F';
                    btnRev.style.color = 'white';
                    btnRev.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
                }
                if (title) title.innerText = "Revenue " + currentChartYear;
            } else if (chartViewMode === 'leaderboard') {
                if (btnLead) {
                    btnLead.style.background = '#B88E2F';
                    btnLead.style.color = 'white';
                    btnLead.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
                }
                if (title) title.innerText = "Room Leaderboard";
            } else if (chartViewMode === 'food') {
                if (btnFood) {
                    btnFood.style.background = '#B88E2F';
                    btnFood.style.color = 'white';
                    btnFood.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
                }
                if (title) title.innerText = "Food Best Sellers";
            } else if (chartViewMode === 'date') {
                if (btnDate) {
                    btnDate.style.background = '#B88E2F';
                    btnDate.style.color = 'white';
                    btnDate.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
                }
                if (title) title.innerText = "Most Booked Dates";
            }
        }

        // --- 4. FETCH CHART DATA ---
        function fetchRevenueChart(year) {
            fetch(`get_dashboard_stats.php?chart_year=${year}&_t=${new Date().getTime()}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        currentChartData.revenue = data.revenue_data;
                        currentChartData.leaderboard = data.room_leaderboard;
                        currentChartData.food = data.food_leaderboard;
                        currentChartData.date = data.date_leaderboard;
                        currentChartData.availableMonths = data.available_months; // 🟢 Store months
                        setChartViewMode(chartViewMode);
                    }
                })
                .catch(err => console.error("Chart Error:", err));
        }

        // --- 5. RENDER CHART (LINE GRAPH) ---
        function renderRevenueChart(dataValues) {
            const container = document.getElementById('barChartContainer');
            if (!container) return;
            container.innerHTML = '';

            // 🔥 Reset container styles for Chart.js responsiveness
            container.style.height = '';
            container.style.maxHeight = '';
            container.style.overflowY = 'hidden';

            const newCanvas = document.createElement('canvas');
            newCanvas.id = 'barMonthly';
            container.appendChild(newCanvas);

            // Create the chart
            const chart = new Chart(newCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                    datasets: [{
                        label: 'Monthly Revenue',
                        data: dataValues,
                        borderColor: '#B88E2F',
                        backgroundColor: 'rgba(184, 142, 47, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointBackgroundColor: '#B88E2F'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (context) => '₱' + context.parsed.y.toLocaleString()
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: (value) => (value >= 1000) ? '₱' + (value / 1000) + 'k' : '₱' + value
                            }
                        },
                        x: { grid: { display: false } }
                    }
                }
            });

            // 🔥 Store the actual rendered height to use for the Leaderboard
            setTimeout(() => {
                if (newCanvas.offsetHeight > 0) {
                    container.dataset.lastChartHeight = newCanvas.offsetHeight;
                }
            }, 100);
        }

        // --- 6. RENDER LEADERBOARD (LIST) ---
        function renderRoomLeaderboard(data) {
            const container = document.getElementById('barChartContainer');
            if (!container) return;

            // 🔥 Filter data based on search query
            let displayData = data;
            if (leaderboardSearchQuery) {
                displayData = data.filter(room =>
                    room.name.toLowerCase().includes(leaderboardSearchQuery)
                );
            }

            // 🔥 SMART REFRESH: Unique check for ROOMS to prevent blocking when switching from Foods
            const dataString = JSON.stringify(displayData);
            if (container.dataset.currentRoomData === dataString && container.querySelector('.room-row-item')) {
                return;
            }
            container.dataset.currentRoomData = dataString;

            // 🔥 Dynamically detect height
            let targetHeight = container.dataset.lastChartHeight || 350;
            if (targetHeight < 200) targetHeight = 350;

            container.innerHTML = '';
            container.style.height = targetHeight + 'px';
            container.style.maxHeight = targetHeight + 'px';
            container.style.overflowY = 'auto';
            container.style.overflowX = 'hidden';
            container.style.paddingRight = '8px';

            if (!displayData || displayData.length === 0) {
                container.innerHTML = `<div style="text-align:center; padding:40px; color:#999;">${leaderboardSearchQuery ? 'No rooms match your search.' : 'No data found.'}</div>`;
                return;
            }

            // 🔥 Calculate TOTAL bookings across all rooms to determine percentage of the whole
            const totalBookingsForPeriod = data.reduce((sum, r) => sum + r.count, 0) || 1;

            let listHtml = `<div style="display:block; width:100%; padding:5px 0;">`;

            displayData.forEach((room, i) => {
                const pctOfTotal = (room.count / totalBookingsForPeriod) * 100;
                const displayPct = pctOfTotal.toFixed(1);

                const rank = data.indexOf(room) + 1; // Keep original rank
                let rCol = '#6B7280', rBg = '#F3F4F6';
                if (rank === 1) { rCol = '#B88E2F'; rBg = '#FFF8E1'; }
                else if (rank === 2) { rCol = '#4B5563'; rBg = '#F9FAFB'; }
                else if (rank === 3) { rCol = '#92400E'; rBg = '#FFFBEB'; }

                const rowId = `leaderboard-item-${i}`;
                const detailsId = `leaderboard-details-${i}`;
                const isExpanded = (room.name === expandedLeaderboardRoom);

                listHtml += `
                    <div id="${rowId}" class="leaderboard-row room-row-item" onclick="toggleLeaderboardDetails('${detailsId}', '${rowId}', '${room.name}')" 
                         style="display:flex; flex-direction:column; background:${isExpanded ? '#fafafa' : '#fff'}; padding:12px; border-radius:12px; border:1px solid #f0f0f0; margin-bottom:10px; cursor:pointer; transition: all 0.3s ease; overflow:hidden; box-shadow:${isExpanded ? '0 4px 12px rgba(0,0,0,0.05)' : 'none'};">
                        
                        <div style="display:flex; align-items:center; gap:15px;">
                            <div style="width:32px; height:32px; background:${rankBg = (rank <= 3 ? rBg : '#F3F4F6')}; color:${rankCol = (rank <= 3 ? rCol : '#6B7280')}; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:0.85rem; flex-shrink:0;">
                                ${rank}
                            </div>
                            <div style="flex:1; min-width:0;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:6px; align-items:center;">
                                    <span style="font-weight:700; color:#333; font-size:0.9rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${room.name}</span>
                                    <span style="font-weight:800; color:#B88E2F; font-size:0.95rem;">${room.count} <small style="font-size:0.7rem; color:#999;">(${displayPct}%)</small></span>
                                </div>
                                <div style="height:6px; background:#f0f0f0; border-radius:10px; overflow:hidden;" title="${displayPct}% of total ${totalBookingsForPeriod} bookings">
                                    <div class="leaderboard-progress-bar" data-width="${pctOfTotal}%" 
                                         style="height:100%; width:0; background:${rank === 1 ? '#B88E2F' : '#D1D5DB'}; border-radius:10px; transition: width 1.2s cubic-bezier(0.16, 1, 0.3, 1);"></div>
                                </div>
                            </div>
                            <div style="font-size: 0.7rem; color: #ccc; transition: transform 0.3s; transform:${isExpanded ? 'rotate(180deg)' : 'rotate(0deg)'};" class="chevron-icon">
                                <i class="fas fa-chevron-down"></i>
                            </div>
                        </div>

                        <div id="${detailsId}" class="leaderboard-details" style="max-height:${isExpanded ? '150px' : '0'}; opacity:${isExpanded ? '1' : '0'}; transition: all 0.4s ease; padding-top:${isExpanded ? '5px' : '0'}; pointer-events: none;">
                            <div style="display:flex; flex-wrap: wrap; gap:10px; margin-top:15px; padding-top:15px; border-top:1px dashed #eee;">
                                <div style="flex:1; min-width: 90px; background:#EFF6FF; padding:10px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                                    <div style="width:28px; height:28px; background:#3B82F6; color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.75rem; flex-shrink:0;">
                                        <i class="fas fa-mars"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div style="font-size:0.6rem; color:#60A5FA; font-weight:700; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Males</div>
                                        <div style="font-size:0.9rem; font-weight:800; color:#1E40AF;">${room.male}</div>
                                    </div>
                                </div>
                                <div style="flex:1; min-width: 90px; background:#FFF1F2; padding:10px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                                    <div style="width:28px; height:28px; background:#FB7185; color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.75rem; flex-shrink:0;">
                                        <i class="fas fa-venus"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div style="font-size:0.6rem; color:#FDA4AF; font-weight:700; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Females</div>
                                        <div style="font-size:0.9rem; font-weight:800; color:#9F1239;">${room.female}</div>
                                    </div>
                                </div>
                                <div style="flex:1; min-width: 90px; background:#F3F4F6; padding:10px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                                    <div style="width:28px; height:28px; background:#9CA3AF; color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.75rem; flex-shrink:0;">
                                        <i class="fas fa-user-slash"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div style="font-size:0.6rem; color:#9CA3AF; font-weight:700; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="Prefer not to say">Private</div>
                                        <div style="font-size:0.9rem; font-weight:800; color:#374151;">${room.other}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>`;
            });

            listHtml += `</div>`;
            container.innerHTML = listHtml;

            // 🔥 Trigger the animation after a brief delay
            setTimeout(() => {
                const bars = container.querySelectorAll('.leaderboard-progress-bar');
                bars.forEach(bar => {
                    bar.style.width = bar.dataset.width;
                });
            }, 50);
        }

        // --- 7. TOGGLE LEADERBOARD DETAILS ---
        function toggleLeaderboardDetails(detailsId, rowId, roomName) {
            const details = document.getElementById(detailsId);
            const row = document.getElementById(rowId);
            const chevron = row.querySelector('.chevron-icon');

            const isOpen = details.style.maxHeight !== '0px' && details.style.maxHeight !== '';

            // 🔥 Smooth Accordion: Close all other open items first
            if (!isOpen) {
                const allRows = document.querySelectorAll('.leaderboard-row');
                allRows.forEach(r => {
                    const d = r.querySelector('.leaderboard-details');
                    const c = r.querySelector('.chevron-icon');
                    if (d && d.id !== detailsId && (d.style.maxHeight !== '0px' && d.style.maxHeight !== '')) {
                        // Smoothly close this one
                        d.style.maxHeight = '0px';
                        d.style.opacity = '0';
                        d.style.paddingTop = '0px';
                        r.style.background = '#fff';
                        r.style.boxShadow = 'none';
                        if (c) c.style.transform = 'rotate(0deg)';
                    }
                });
            }

            if (isOpen) {
                // Close current
                details.style.maxHeight = '0px';
                details.style.opacity = '0';
                details.style.paddingTop = '0px';
                row.style.background = '#fff';
                row.style.boxShadow = 'none';
                chevron.style.transform = 'rotate(0deg)';
                expandedLeaderboardRoom = null;
            } else {
                // Open current
                details.style.maxHeight = '150px';
                details.style.opacity = '1';
                details.style.paddingTop = '5px';
                row.style.background = '#fafafa';
                row.style.boxShadow = '0 4px 12px rgba(0,0,0,0.05)';
                chevron.style.transform = 'rotate(180deg)';
                expandedLeaderboardRoom = roomName;
            }
        }

        // --- 8. RENDER FOOD LEADERBOARD ---
        function renderFoodLeaderboard(data) {
            const container = document.getElementById('barChartContainer');
            if (!container) return;

            // 🔥 Filter data based on search query
            let displayData = data;
            if (leaderboardSearchQuery) {
                displayData = data.filter(food =>
                    food.name.toLowerCase().includes(leaderboardSearchQuery)
                );
            }

            // 🔥 SMART REFRESH: Unique check for FOOD
            const dataString = JSON.stringify(displayData);
            if (container.dataset.currentFoodData === dataString && container.querySelector('.food-row-item')) {
                return;
            }
            container.dataset.currentFoodData = dataString;

            let targetHeight = container.dataset.lastChartHeight || 350;
            if (targetHeight < 200) targetHeight = 350;

            container.innerHTML = '';
            container.style.height = targetHeight + 'px';
            container.style.maxHeight = targetHeight + 'px';
            container.style.overflowY = 'auto';
            container.style.paddingRight = '8px';

            if (!data || data.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:40px; color:#999;">No food orders recorded for this period.</div>';
                return;
            }

            const totalItems = data.reduce((sum, f) => sum + f.count, 0) || 1;
            let listHtml = `<div style="display:block; width:100%; padding:5px 0;">`;

            displayData.forEach((food, i) => {
                const pct = (food.count / totalItems) * 100;
                const displayPct = pct.toFixed(1);
                const rank = data.indexOf(food) + 1; // Keep original rank from the full list

                const rowId = `food-item-${i}`;
                const detailsId = `food-details-${i}`;
                const isExpanded = (food.name === expandedLeaderboardFood);

                listHtml += `
                    <div id="${rowId}" class="leaderboard-row food-row-item" onclick="toggleFoodLeaderboardDetails('${detailsId}', '${rowId}', '${food.name}')" 
                         style="display:flex; flex-direction:column; background:${isExpanded ? '#fafafa' : '#fff'}; padding:12px; border-radius:12px; border:1px solid #f0f0f0; margin-bottom:10px; cursor:pointer; transition: all 0.3s ease; overflow:hidden; box-shadow:${isExpanded ? '0 4px 12px rgba(0,0,0,0.05)' : 'none'};">
                        
                        <div style="display:flex; align-items:center; gap:15px;">
                            <div style="width:32px; height:32px; background:#F3F4F6; color:#6B7280; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:0.85rem; flex-shrink:0;">
                                ${rank}
                            </div>
                            <div style="flex:1; min-width:0;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:6px; align-items:center;">
                                    <span style="font-weight:700; color:#333; font-size:0.9rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${food.name}</span>
                                    <span style="font-weight:800; color:#B88E2F; font-size:0.95rem;">${food.count} <small style="font-size:0.7rem; color:#999;">(${displayPct}%)</small></span>
                                </div>
                                <div style="height:6px; background:#f0f0f0; border-radius:10px; overflow:hidden;">
                                    <div class="food-progress-bar" data-width="${pct}%" 
                                         style="height:100%; width:0; background:#10B981; border-radius:10px; transition: width 1.2s cubic-bezier(0.16, 1, 0.3, 1);"></div>
                                </div>
                            </div>
                            <div style="font-size: 0.7rem; color: #ccc; transition: transform 0.3s; transform:${isExpanded ? 'rotate(180deg)' : 'rotate(0deg)'};" class="chevron-icon">
                                <i class="fas fa-chevron-down"></i>
                            </div>
                        </div>

                        <div id="${detailsId}" class="leaderboard-details" style="max-height:${isExpanded ? '150px' : '0'}; opacity:${isExpanded ? '1' : '0'}; transition: all 0.4s ease; padding-top:${isExpanded ? '5px' : '0'}; pointer-events: none;">
                            <div style="display:flex; flex-wrap: wrap; gap:10px; margin-top:15px; padding-top:15px; border-top:1px dashed #eee;">
                                <!-- Male -->
                                <div style="flex:1; min-width: 90px; background:#EFF6FF; padding:10px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                                    <div style="width:28px; height:28px; background:#3B82F6; color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.75rem; flex-shrink:0;">
                                        <i class="fas fa-mars"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div style="font-size:0.6rem; color:#60A5FA; font-weight:700; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Males</div>
                                        <div style="font-size:0.9rem; font-weight:800; color:#1E40AF;">${food.male}</div>
                                    </div>
                                </div>
                                <!-- Female -->
                                <div style="flex:1; min-width: 90px; background:#FFF1F2; padding:10px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                                    <div style="width:28px; height:28px; background:#FB7185; color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.75rem; flex-shrink:0;">
                                        <i class="fas fa-venus"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div style="font-size:0.6rem; color:#FDA4AF; font-weight:700; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Females</div>
                                        <div style="font-size:0.9rem; font-weight:800; color:#9F1239;">${food.female}</div>
                                    </div>
                                </div>
                                <!-- Private -->
                                <div style="flex:1; min-width: 90px; background:#F3F4F6; padding:10px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                                    <div style="width:28px; height:28px; background:#9CA3AF; color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.75rem; flex-shrink:0;">
                                        <i class="fas fa-user-slash"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div style="font-size:0.6rem; color:#9CA3AF; font-weight:700; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Private</div>
                                        <div style="font-size:0.9rem; font-weight:800; color:#374151;">${food.other}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>`;
            });

            listHtml += `</div>`;
            container.innerHTML = listHtml;

            setTimeout(() => {
                const bars = container.querySelectorAll('.food-progress-bar');
                bars.forEach(bar => bar.style.width = bar.dataset.width);
            }, 50);
        }

        // --- 8.5 RENDER DATE LEADERBOARD ---
        function renderDateLeaderboard(data) {
            const container = document.getElementById('barChartContainer');
            if (!container) return;

            // 🔥 Safety check for data array
            if (!Array.isArray(data)) {
                container.innerHTML = `<div style="text-align:center; padding:40px; color:#999;">No data available.</div>`;
                return;
            }

            let displayData = data;
            if (leaderboardSearchQuery) {
                displayData = data.filter(item =>
                    item.name && item.name.toLowerCase().includes(leaderboardSearchQuery)
                );
            }

            const dataString = JSON.stringify(displayData);
            if (container.dataset.currentDateData === dataString && container.querySelector('.date-row-item')) {
                return;
            }
            container.dataset.currentDateData = dataString;

            let targetHeight = container.dataset.lastChartHeight || 350;
            if (targetHeight < 200) targetHeight = 350;

            container.innerHTML = '';
            container.style.height = targetHeight + 'px';
            container.style.maxHeight = targetHeight + 'px';
            container.style.overflowY = 'auto';
            container.style.paddingRight = '8px';

            if (!displayData || displayData.length === 0) {
                container.innerHTML = `<div style="text-align:center; padding:40px; color:#999;">${leaderboardSearchQuery ? 'No dates match your search.' : 'No booked dates found.'}</div>`;
                return;
            }

            // Safe calculation of maxCount
            const counts = data.map(d => d.count);
            const maxCount = (counts.length > 0) ? Math.max(...counts) : 1;

            let listHtml = `<div style="display:block; width:100%; padding:5px 0;">`;

            displayData.forEach((item, i) => {
                const pct = (item.count / maxCount) * 100;
                const rank = data.indexOf(item) + 1;

                let rCol = '#6B7280', rBg = '#F3F4F6';
                if (rank === 1) { rCol = '#B88E2F'; rBg = '#FFF8E1'; }
                else if (rank === 2) { rCol = '#4B5563'; rBg = '#F9FAFB'; }
                else if (rank === 3) { rCol = '#92400E'; rBg = '#FFFBEB'; }

                listHtml += `
                    <div class="leaderboard-row date-row-item" 
                         style="display:flex; align-items:center; gap:15px; background:#fff; padding:12px; border-radius:12px; border:1px solid #f0f0f0; margin-bottom:10px; transition: all 0.3s ease;">
                        
                        <div style="width:32px; height:32px; background:${rank <= 3 ? rBg : '#F3F4F6'}; color:${rank <= 3 ? rCol : '#6B7280'}; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:0.85rem; flex-shrink:0;">
                            ${rank}
                        </div>
                        <div style="flex:1; min-width:0;">
                            <div style="display:flex; justify-content:space-between; margin-bottom:6px; align-items:center;">
                                <span style="font-weight:700; color:#333; font-size:0.9rem;">${item.name}</span>
                                <span style="font-weight:800; color:#B88E2F; font-size:0.95rem;">${item.count} <small style="font-size:0.7rem; color:#999;">bookings</small></span>
                            </div>
                            <div style="height:6px; background:#f0f0f0; border-radius:10px; overflow:hidden;">
                                <div class="date-progress-bar" data-width="${pct}%" 
                                     style="height:100%; width:0; background:${rank === 1 ? '#B88E2F' : '#D1D5DB'}; border-radius:10px; transition: width 1.2s cubic-bezier(0.16, 1, 0.3, 1);"></div>
                            </div>
                        </div>
                    </div>`;
            });

            listHtml += `</div>`;
            container.innerHTML = listHtml;

            setTimeout(() => {
                const bars = container.querySelectorAll('.date-progress-bar');
                bars.forEach(bar => bar.style.width = bar.dataset.width);
            }, 50);
        }

        // --- 8.5 TOGGLE FOOD DETAILS ---
        function toggleFoodLeaderboardDetails(detailsId, rowId, foodName) {
            const details = document.getElementById(detailsId);
            const row = document.getElementById(rowId);
            const chevron = row.querySelector('.chevron-icon');

            const isOpen = details.style.maxHeight !== '0px' && details.style.maxHeight !== '';

            // Accordion: Close others
            if (!isOpen) {
                const allRows = document.querySelectorAll('.food-row-item');
                allRows.forEach(r => {
                    const d = r.querySelector('.leaderboard-details');
                    const c = r.querySelector('.chevron-icon');
                    if (d && d.id !== detailsId && (d.style.maxHeight !== '0px' && d.style.maxHeight !== '')) {
                        d.style.maxHeight = '0px';
                        d.style.opacity = '0';
                        d.style.paddingTop = '0px';
                        r.style.background = '#fff';
                        r.style.boxShadow = 'none';
                        if (c) c.style.transform = 'rotate(0deg)';
                    }
                });
            }

            if (isOpen) {
                details.style.maxHeight = '0px';
                details.style.opacity = '0';
                details.style.paddingTop = '0px';
                row.style.background = '#fff';
                row.style.boxShadow = 'none';
                chevron.style.transform = 'rotate(0deg)';
                expandedLeaderboardFood = null;
            } else {
                details.style.maxHeight = '150px';
                details.style.opacity = '1';
                details.style.paddingTop = '5px';
                row.style.background = '#fafafa';
                row.style.boxShadow = '0 4px 12px rgba(0,0,0,0.05)';
                chevron.style.transform = 'rotate(180deg)';
                expandedLeaderboardFood = foodName;
            }
        }

        // --- 5. FETCH CARDS (Updated for Overall/Custom) ---

        function toggleMonthInput(val) {
            const wrapper = document.getElementById('customMonthWrapper');
            if (val === 'custom') {
                wrapper.style.display = 'block';
                // Initialize Flatpickr if not already done
                if (!document.getElementById('customMonthInput')._flatpickr) {
                    flatpickr("#customMonthInput", {
                        plugins: [
                            new monthSelectPlugin({
                                shorthand: true,
                                dateFormat: "Y-m",
                                altFormat: "F Y",
                                theme: "light"
                            })
                        ],
                        defaultDate: new Date().toISOString().slice(0, 7),
                        onChange: function (selectedDates, dateStr) {
                            fetchDashboardCards();
                        },
                        onReady: function (selectedDates, dateStr, instance) {
                            instance.calendarContainer.classList.add("compact-theme");
                        }
                    });
                }
            } else {
                wrapper.style.display = 'none';
            }
        }

        function fetchDashboardCards() {
            const picker = document.getElementById('dashboardMonthPicker');
            let selectedDate = picker ? picker.value : 'overall';

            if (selectedDate === 'custom') {
                const monthInput = document.getElementById('customMonthInput');
                selectedDate = monthInput.value || new Date().toISOString().slice(0, 7);
            }

            fetch(`get_dashboard_stats.php?date=${selectedDate}&_t=${new Date().getTime()}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        // 1. Update Cards
                        document.getElementById('stat_guests').innerHTML = data.guests;
                        document.getElementById('stat_revenue').innerHTML = '₱' + data.revenue;
                        document.getElementById('stat_occupancy').innerHTML = data.occupancy + '%';
                        document.getElementById('stat_orders').innerHTML = data.kitchen_orders;
                        document.getElementById('stat_pending').innerHTML = data.pending;

                        // 2. Update Labels dynamically
                        const labelType = (picker.value === 'overall') ? 'Overall' : 'Monthly';
                        document.getElementById('label_guests').innerText = (picker.value === 'overall') ? 'Total Successful Bookings (Cumulative)' : 'Total Successful Bookings (Monthly)';
                        document.getElementById('label_revenue').innerText = labelType + ' Revenue';
                        document.getElementById('label_occupancy').innerText = labelType + ' Occupancy';

                        // 3. Update Pie Chart
                        if (window.myBookingPieChart) {
                            window.myBookingPieChart.data.datasets[0].data = data.pie_data;
                            window.myBookingPieChart.update();
                        }

                        // 4. Update Leaderboard Data & View
                        currentChartData.leaderboard = data.room_leaderboard;
                        currentChartData.food = data.food_leaderboard;
                        currentChartData.date = data.date_leaderboard; // 🟢 ADDED
                        
                        if (chartViewMode === 'leaderboard') {
                            renderRoomLeaderboard(data.room_leaderboard);
                        } else if (chartViewMode === 'food') {
                            renderFoodLeaderboard(data.food_leaderboard);
                        } else if (chartViewMode === 'date') {
                            renderDateLeaderboard(data.date_leaderboard); // 🟢 ADDED
                        }

                        // 4. Calculate fresh percentages from the NEW data
                        const counts = data.pie_data;
                        const total = counts.reduce((a, b) => a + b, 0);

                        // Calculate percentages
                        const cPct = total > 0 ? Math.round((counts[0] / total) * 100) : 0;
                        const nPct = total > 0 ? Math.round((counts[1] / total) * 100) : 0;
                        const aPct = total > 0 ? Math.round((counts[2] / total) * 100) : 0;

                        // 5. Update Progress Bar Text
                        document.getElementById('prog_text_complete').textContent = cPct + '%';
                        document.getElementById('prog_text_noshow').textContent = nPct + '%';
                        document.getElementById('prog_text_cancelled').textContent = aPct + '%';

                        // 6. Update Progress Bar Widths
                        document.getElementById('prog_bar_complete').style.width = cPct + '%';
                        document.getElementById('prog_bar_noshow').style.width = nPct + '%';
                        document.getElementById('prog_bar_cancelled').style.width = aPct + '%';
                    }
                })
                .catch(err => console.error("Error updating dashboard:", err));
        }

        // Add this to your dashboard.php JavaScript section
        document.addEventListener("DOMContentLoaded", function () {
            const monthPicker = document.getElementById('dashboardMonthPicker');
            if (monthPicker) {
                monthPicker.addEventListener('change', function () {
                    console.log("Month changed to:", this.value);
                    fetchDashboardCards();
                });
            }
        });

        document.addEventListener("DOMContentLoaded", function () {
            const logModal = document.getElementById('logoutModal');
            const logBtn = document.getElementById('logoutBtn');
            const confirm = document.getElementById('confirmLogout');
            const cancel = document.getElementById('cancelLogout');

            // Open with a slight bounce effect
            if (logBtn) {
                logBtn.onclick = (e) => {
                    e.preventDefault();
                    logModal.style.display = 'block';
                };
            }

            // Standard Actions
            if (confirm) confirm.onclick = () => window.location.href = 'logout.php';
            if (cancel) cancel.onclick = () => logModal.style.display = 'none';

            // Global Close
            window.addEventListener('click', (e) => {
                if (e.target === logModal) logModal.style.display = 'none';
            });
        });

        // --- 3. CALENDAR LOGIC ---
        const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
        let viewDate = new Date();
        let bookingsDB = <?php echo $js_calendarData; ?>;

        // --- DYNAMIC ROOMS FROM DB ---
        let allRoomsList = <?php echo $js_allRoomsJSON; ?>; // Data from PHP
        const totalRooms = allRoomsList.length; // Count varies based on DB


        // --- RENDER CALENDAR (Main Function) ---
        function renderRealtimeCalendar() {
            // 1. Get accurate time variables
            const manilaTime = new Date().toLocaleString("en-US", { timeZone: "Asia/Manila" });
            const now = new Date(manilaTime);
            const yearNow = now.getFullYear();
            const monthNow = String(now.getMonth() + 1).padStart(2, '0');
            const dayNow = String(now.getDate()).padStart(2, '0');
            const todayStr = `${yearNow}-${monthNow}-${dayNow}`;

            const year = viewDate.getFullYear();
            const month = viewDate.getMonth();

            document.getElementById('currentMonthYear').innerText = `${monthNames[month]} ${year}`;

            // Disable Prev Button if in current month
            const isCurrentRealtimeMonth = (year === now.getFullYear() && month === now.getMonth());
            const isFutureMonth = (year > now.getFullYear()) || (year === now.getFullYear() && month > now.getMonth());
            document.getElementById('prevMonthBtn').disabled = (!isFutureMonth && isCurrentRealtimeMonth);

            // Get Active Room Count
            const activeTotalRooms = allRoomsList.filter(r => r.is_active == 1).length;

            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const grid = document.getElementById('calendarRealtimeGrid');
            grid.innerHTML = "";

            // Empty cells
            for (let i = 0; i < firstDay; i++) {
                const cell = document.createElement('div');
                cell.className = 'cal-cell other-month';
                grid.appendChild(cell);
            }

            // Days Loop
            for (let i = 1; i <= daysInMonth; i++) {
                const cell = document.createElement('div');
                cell.className = 'cal-cell';

                // Number
                const numSpan = document.createElement('div');
                numSpan.className = 'cal-cell-number';
                numSpan.innerText = i;
                cell.appendChild(numSpan);

                const currentMonthVal = String(month + 1).padStart(2, '0');
                const currentDayVal = String(i).padStart(2, '0');
                const dStr = `${year}-${currentMonthVal}-${currentDayVal}`;

                // 🟢 CRITICAL FIX START: Check for Past Dates 🟢

                if (dStr < todayStr) {
                    // IF PAST: Disable it and DO NOT add onclick
                    cell.classList.add('disabled-date');
                    cell.style.opacity = '0.5';
                    cell.style.cursor = 'not-allowed';
                    // Notice: No cell.onclick here!
                }
                else {
                    // IF TODAY OR FUTURE: Apply logic and clicks

                    // Highlight Today
                    if (dStr === todayStr) {
                        cell.classList.add('is-today');
                    }

                    const dayData = bookingsDB[dStr] || [];
                    // Only count relevant booking types
                    const bookedCount = dayData.filter(b => b.type === 'in_house' || b.type === 'future').length;
                    let labelText = "";

                    // Check Capacity
                    if (bookedCount >= activeTotalRooms && activeTotalRooms > 0) {
                        // FULLY BOOKED (Red Style)
                        cell.classList.add('status-full');
                        labelText = "FULLY BOOKED";
                    }
                    else {
                        // AVAILABLE (Show Stats)
                        let inHouseCount = 0;
                        let reservedCount = 0;

                        dayData.forEach(b => {
                            if (b.type === 'in_house') inHouseCount++;
                            if (b.type === 'future') reservedCount++;
                        });

                        const statsDiv = document.createElement('div');
                        statsDiv.className = 'cal-stats';

                        if (inHouseCount > 0) {
                            statsDiv.innerHTML += `<div class="status-row"><span class="status-dot dot-occupied"></span> ${inHouseCount} In-House</div>`;
                        }
                        if (reservedCount > 0) {
                            statsDiv.innerHTML += `<div class="status-row"><span class="status-dot dot-reserved"></span> ${reservedCount} Reserved</div>`;
                        }
                        cell.appendChild(statsDiv);
                    }

                    // Add Pill if Full
                    if (labelText) {
                        const pill = document.createElement('div');
                        pill.className = 'status-pill';
                        pill.innerText = labelText;
                        cell.appendChild(pill);
                    }

                    // Add Click Handler (Only for active dates)
                    cell.onclick = function () {
                        openRoomModal(dStr, dayData);
                    };
                }
                // 🟢 CRITICAL FIX END 🟢

                grid.appendChild(cell);
            }
        }

        // Helper function to format date like "Tue 29 April"
        function formatModalDate(dateStr) {
            const options = { weekday: 'short', day: 'numeric', month: 'long' };
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-GB', options).replace(',', '');
        }

        function openRoomModal(dateStr, dayBookings) {
            // 1. Format Title neatly
            const dateObj = new Date(dateStr);
            const titleDate = dateObj.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });

            // Update Header Text
            document.getElementById('calendarModalTitle').innerText = titleDate;

            const body = document.getElementById('calendarModalBody');
            body.innerHTML = '';

            // 🟢 ADD FILTER BUTTONS
            const filterContainer = document.createElement('div');
            filterContainer.style.cssText = "display:flex; gap:10px; padding: 15px 20px; background:#f8fafc; border-bottom:1px solid #eee; position:sticky; top:0; z-index:10;";

            filterContainer.innerHTML = `
                <button class="modal-filter-btn active" data-filter="all" onclick="filterModalRooms('all')">All</button>
                <button class="modal-filter-btn" data-filter="available" onclick="filterModalRooms('available')">Available</button>
                <button class="modal-filter-btn" data-filter="occupied" onclick="filterModalRooms('occupied')">Reserved/Occupied</button>
            `;
            body.appendChild(filterContainer);

            // Create a container for the list (removes default padding issues)
            const listContainer = document.createElement('div');
            listContainer.className = 'room-list-container';
            listContainer.id = 'modalRoomList'; // ID for filtering

            // 2. Loop through rooms
            allRoomsList.forEach((room, index) => {
                // Skip hidden rooms
                if (room.is_active == 0) return;

                const roomId = room.id;
                const roomName = room.name; // e.g. "Room 201"
                const cleanName = roomName.replace('Room', '').replace('ROOM', '').trim(); // Extract just number if possible

                // Check status
                const booking = dayBookings.find(b => b.room_id == roomId);

                let themeClass = 'theme-available';
                let statusLabel = 'Available';
                let mainText = 'Ready for Booking'; // Default text for available
                let detailText = 'Vacant';
                let filterType = 'available'; // For filter logic

                if (booking) {
                    mainText = booking.guest; // Show Guest Name
                    filterType = 'occupied';

                    // Format dates
                    const checkOutFormatted = new Date(booking.check_out).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });

                    if (booking.type === 'in_house') {
                        themeClass = 'theme-occupied';
                        statusLabel = 'Occupied';
                        detailText = `Until ${checkOutFormatted}`;
                    } else {
                        themeClass = 'theme-reserved';
                        statusLabel = 'Reserved';
                        detailText = `Arriving`;
                    }
                }

                // 3. Build the Card HTML
                const card = document.createElement('div');
                card.className = `room-status-card ${themeClass}`;
                card.dataset.status = filterType; // Attach filter data

                card.innerHTML = `
                    <div class="room-id-box">
                        <span class="room-id-label">Room</span>
                        ${cleanName}
                    </div>
                    
                    <div class="room-info-area">
                        <div class="room-guest-name">${mainText}</div>
                        <div class="room-status-detail">${detailText}</div>
                    </div>
                    
                    <div class="status-badge-pill">
                        ${statusLabel}
                    </div>
                `;

                listContainer.appendChild(card);

                // Stagger initial load animation
                setTimeout(() => {
                    card.classList.add('show-anim');
                }, index * 30);
            });

            body.appendChild(listContainer);

            // Show Modal
            document.getElementById('calendarModal').style.display = 'block';
        }

        // 🟢 FILTER LOGIC FOR MODAL (FINAL SMOOTH VERSION)
        function filterModalRooms(type) {
            const list = document.getElementById('modalRoomList');
            const modalContent = document.querySelector('.modal-content-calendar');
            if (!list || !modalContent) return;

            const cards = list.querySelectorAll('.room-status-card');
            const buttons = document.querySelectorAll('.modal-filter-btn');

            // 1. Capture and LOCK current height immediately
            const startHeight = modalContent.getBoundingClientRect().height;
            modalContent.style.height = startHeight + 'px';
            modalContent.style.transition = 'none'; // Disable any current transitions
            modalContent.style.overflow = 'hidden';

            // 2. Update Filter Buttons UI
            buttons.forEach(btn => {
                btn.classList.toggle('active', btn.dataset.filter === type);
            });

            // 3. Filter the cards (This happens while height is LOCKED)
            cards.forEach(card => {
                card.classList.remove('show-anim');
                card.style.display = (type === 'all' || card.dataset.status === type) ? 'flex' : 'none';
            });

            // 4. Measure the new "Auto" height
            // We temporarily set it to auto, measure it, and immediately set it back to startHeight
            modalContent.style.height = 'auto';
            const endHeight = modalContent.getBoundingClientRect().height;
            modalContent.style.height = startHeight + 'px';

            // 5. Trigger the Animation
            // We use a small timeout to let the browser "digest" the locked height
            setTimeout(() => {
                modalContent.style.transition = 'height 0.4s cubic-bezier(0.4, 0, 0.2, 1)';
                modalContent.style.height = endHeight + 'px';

                // Stagger card entrance
                let visibleIndex = 0;
                cards.forEach(card => {
                    if (card.style.display === 'flex') {
                        setTimeout(() => card.classList.add('show-anim'), visibleIndex * 40);
                        visibleIndex++;
                    }
                });
            }, 10);

            // 6. Cleanup after animation finishes
            const cleanup = (e) => {
                if (e.propertyName === 'height') {
                    modalContent.style.height = 'auto'; // Release lock
                    modalContent.style.overflow = 'visible';
                    modalContent.removeEventListener('transitionend', cleanup);
                }
            };
            modalContent.addEventListener('transitionend', cleanup);
        }


        // --- REPLACE YOUR CURRENT PREV/NEXT LISTENERS WITH THIS ---

        document.getElementById('prevMonthBtn').addEventListener('click', (e) => {
            e.preventDefault(); // Stop any default link behavior

            // 1. Update the local date object state
            viewDate.setMonth(viewDate.getMonth() - 1);

            // 2. Call your existing AJAX function to fetch new data without reloading
            refreshCalendarData();
        });

        document.getElementById('nextMonthBtn').addEventListener('click', (e) => {
            e.preventDefault(); // Stop any default link behavior

            // 1. Update the local date object state
            viewDate.setMonth(viewDate.getMonth() + 1);

            // 2. Call your existing AJAX function to fetch new data without reloading
            refreshCalendarData();
        });

        document.getElementById('closeCalendarModal').onclick = () => document.getElementById('calendarModal').style.display = 'none';

        renderRealtimeCalendar();

        // --- IMPROVED FILTER & SORT LOGIC ---
        let currentTabStatus = 'today';
        let bookingLimit = 10;
        let bookingOffset = 0;
        let foodLimit = 10;
        let foodOffset = 0;

        function filterTable(filterType) {
            currentTabStatus = filterType;
            bookingOffset = 0; // Reset pagination

            // 1. Update Tab Styling
            const buttons = document.querySelectorAll('.tab-btn');
            buttons.forEach(btn => {
                if (btn.getAttribute('data-target') === filterType) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            // 2. Refresh Table from Server
            refreshBookingTable(true);
        }

        // 3. Initialize on Load
        document.addEventListener("DOMContentLoaded", function () {
            // Set default tab to 'today'
            filterTable('today');

            // Listen for typing
            const searchInput = document.getElementById('bookingSearchInput');
            if (searchInput) {
                searchInput.addEventListener('keyup', function () {
                    bookingOffset = 0; // Reset when searching
                    refreshBookingTable(true);
                });
            }
        });

        // --- 4. ADD BOOKING MODAL LOGIC & SAVING ---

        // A. RESET FUNCTION (Clears everything)
        function resetModal() {
            // 1. Reset standard form fields
            document.getElementById('addBookingForm').reset();

            // 2. Clear Flatpickr Date Inputs (Your existing code)
            const checkinInput = document.getElementById('checkin_picker');
            const checkoutInput = document.getElementById('checkout_picker');
            const birthPicker = document.getElementById('birthdate_picker');
            if (checkinInput._flatpickr) checkinInput._flatpickr.clear();
            if (checkoutInput._flatpickr) checkoutInput._flatpickr.clear();
            if (birthPicker && birthPicker._flatpickr) birthPicker._flatpickr.clear();



            // 3. Reset Wizard Steps (Your existing code)
            currentStep = 1;
            document.querySelectorAll('.ab-step').forEach(step => step.classList.remove('active'));
            document.getElementById('ab-step-1').classList.add('active');
            document.getElementById('abModalTitle').innerText = "Step 1: Select Dates";

            // 4. Clear Selected Rooms (Your existing code)
            selectedRooms = [];
            document.getElementById('roomSelectionContainer').innerHTML = '';

            // 🟢 5. NEW: RESET CUSTOM SELECT VISUALS 🟢
            document.querySelectorAll('.custom-select-wrapper').forEach(wrapper => {
                const triggerSpan = wrapper.querySelector('.custom-select-trigger span');
                const options = wrapper.querySelectorAll('.custom-option');

                // Reset text to default (usually "- Select -" or the first option)
                // We find the corresponding hidden select relative to the wrapper
                const hiddenSelect = wrapper.previousElementSibling;
                if (hiddenSelect && hiddenSelect.tagName === 'SELECT') {
                    // Set trigger to the first option's text
                    triggerSpan.textContent = hiddenSelect.options[0].text;
                }

                // Remove 'selected' class from all options
                options.forEach(opt => opt.classList.remove('selected'));
            });
        }

        // B. VALIDATION FUNCTION
        // Checks required fields before showing Confirmation Modal
        function validateAndReview() {
            const form = document.getElementById('addBookingForm');

            // Manual check for hidden but required fields (Custom Selects)
            const requiredFields = form.querySelectorAll('[required]');
            let firstInvalid = null;

            requiredFields.forEach(field => {
                if (!field.value || field.value.trim() === "") {
                    if (!firstInvalid) firstInvalid = field;
                    field.classList.add('invalid-input'); // Optional: for CSS styling
                } else {
                    field.classList.remove('invalid-input');
                }
            });

            if (firstInvalid) {
                // Get the label or field name for a better message
                const label = firstInvalid.parentElement.querySelector('.ab-label')?.innerText.replace('*', '').trim() || firstInvalid.name;

                // Show standard alert instead of relying on browser tooltip
                alert(`Please complete the required field: ${label}`);

                // Try to focus or scroll to it
                const wrapper = firstInvalid.nextElementSibling;
                if (wrapper && wrapper.classList.contains('custom-select-wrapper')) {
                    wrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    // Flash the custom trigger
                    const trigger = wrapper.querySelector('.custom-select-trigger');
                    if (trigger) {
                        trigger.style.borderColor = '#dc2626';
                        setTimeout(() => trigger.style.borderColor = '', 2000);
                    }
                } else {
                    firstInvalid.focus();
                }
                return;
            }

            // If all manual checks pass
            openConfirmationModal();
        }

        // C. OPEN/CLOSE HANDLERS
        const addBookingModal = document.getElementById('addBookingModal');


        // Close (X Button) -> Triggers Reset
        document.getElementById('closeAddBookingModalX').onclick = () => {
            addBookingModal.style.display = 'none';
            resetModal();
        };

        // Close (Click Outside) -> Triggers Reset
        window.onclick = (e) => {
            if (e.target == addBookingModal) {
                addBookingModal.style.display = 'none';
                resetModal();
            }
            if (e.target == document.getElementById('confirmationModal')) {
                document.getElementById('confirmationModal').style.display = 'none';
            }
        };

        // Confirmation Modal Buttons
        document.getElementById('closeConfirmModalX').onclick = () => document.getElementById('confirmationModal').style.display = 'none';
        document.getElementById('cancelConfirmBtn').onclick = () => document.getElementById('confirmationModal').style.display = 'none';

        // D. SAVE TO DATABASE (AJAX)
        // --- SAVE BOOKING (Seamless Update - No Reload) ---
        document.getElementById('finalConfirmBtn').onclick = function () {

            const btn = document.getElementById('finalConfirmBtn');
            const cancelBtn = document.getElementById('cancelConfirmBtn'); // Get Back Button
            const closeX = document.getElementById('closeConfirmModalX');  // Get X Button
            const originalText = btn.innerHTML;

            // 1. LOCK UI (Disable everything)
            isProcessingBooking = true; // Set flag
            toggleUILock(true, "SAVING NEW BOOKING...");

            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;
            btn.style.opacity = '0.7';

            if (cancelBtn) {
                cancelBtn.disabled = true;
                cancelBtn.style.opacity = '0.5';
                cancelBtn.style.cursor = 'not-allowed';
            }

            if (closeX) {
                closeX.disabled = true;
                closeX.style.opacity = '0.5';
                closeX.style.cursor = 'not-allowed';
            }

            // 2. Prepare Data from Form
            const form = document.getElementById('addBookingForm');
            const formData = new FormData(form);
            const financialData = window.tempBookingPayload;

            // Determine Source & Initial Status
            const sourceElement = document.getElementById('bookingSourceDisplay');
            const sourceValue = sourceElement ? sourceElement.value : 'reservation';

            let initialArrivalStatus = 'awaiting_arrival';
            let finalArrivalTime = formData.get('arrival_time');

            if (sourceValue === 'walk-in') {
                initialArrivalStatus = 'in_house';
                const now = new Date();
                const hours = String(now.getHours()).padStart(2, '0');
                const minutes = String(now.getMinutes()).padStart(2, '0');
                finalArrivalTime = `${hours}:${minutes}`;
            }

            const payload = {
                dates: {
                    checkin: document.getElementById('checkin_picker').value,
                    checkout: document.getElementById('checkout_picker').value
                },
                guest: {
                    salutation: formData.get('salutation'),
                    firstname: formData.get('firstname'),
                    lastname: formData.get('lastname'),
                    gender: formData.get('gender'),
                    birthdate: formData.get('birthdate'),
                    nationality: formData.get('nationality'),
                    email: formData.get('email'),
                    payment_method: formData.get('payment_method'),
                    contact: formData.get('contact'),
                    arrival_time: finalArrivalTime,
                    address: formData.get('address'),
                    adults: formData.get('adults'),
                    children: formData.get('children')
                },
                rooms: selectedRooms,
                totalPrice: financialData.totalPrice,
                amountPaid: financialData.amountPaid,
                paymentStatus: financialData.paymentStatus,
                paymentTerm: financialData.paymentTerm,
                bookingSource: sourceValue,
                arrivalStatus: initialArrivalStatus
            };

            // 3. Send to Server
            fetch('save_booking.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ...payload, csrf_token: csrfToken })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(`Booking Confirmed! Reference: ${data.ref}`);

                        // A. Close Modals
                        document.getElementById('confirmationModal').style.display = 'none';
                        document.getElementById('addBookingModal').style.display = 'none';
                        resetModal();

                        // B. Add row and update stats
                        addBookingRowToTable(data.id || 0, data.ref, payload);
                        fetchDashboardCards();
                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("An unexpected error occurred.");
                })
                .finally(() => {
                    // 4. UNLOCK UI (Re-enable everything whether success or fail)
                    isProcessingBooking = false; // Reset flag
                    toggleUILock(false);

                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    btn.style.opacity = '1';

                    // RE-ENABLE BUTTONS HERE:
                    if (cancelBtn) {
                        cancelBtn.disabled = false;
                        cancelBtn.style.opacity = '1';
                        cancelBtn.style.cursor = 'pointer';
                    }

                    if (closeX) {
                        closeX.disabled = false;
                        closeX.style.opacity = '1';
                        closeX.style.cursor = 'pointer';
                    }
                });
        };


        // --- 5. MULTI-STEP WIZARD LOGIC ---
        let currentStep = 1;
        let selectedRooms = [];

        // Mock Data: Simulated Available Rooms
        const mockAvailableRooms = [
            { id: 101, name: 'Deluxe King 101', price: 1500 },
            { id: 102, name: 'Deluxe King 102', price: 1500 },
            { id: 201, name: 'Twin Suite 201', price: 2000 },
            { id: 204, name: 'Family Room 204', price: 3500 },
            { id: 305, name: 'Standard 305', price: 1200 }
        ];



        function goToStep(step) {
            // Validation for Step 1
            if (currentStep === 1 && step === 2) {

                // --- FIX START: Update IDs to match your new HTML ---
                const cin = document.getElementById('checkin_picker').value;
                const cout = document.getElementById('checkout_picker').value;
                // --- FIX END ---

                if (!cin || !cout) {
                    alert("Please select check-in and check-out dates.");
                    return;
                }

                // Show loading state
                const container = document.getElementById('roomSelectionContainer');
                container.innerHTML = '<p style="padding:20px; text-align:center;">Checking availability...</p>';

                // Call the PHP file
                // Get the booking type from the read-only input
                const type = document.getElementById('bookingSourceDisplay').value;

                // Pass it to the API
                fetch(`get_available_rooms.php?checkin=${cin}&checkout=${cout}&type=${type}`)
                    .then(response => response.json())
                    .then(data => {
                        renderAvailableRooms(data); // Pass real data to render function
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        container.innerHTML = '<p style="color:red; padding:20px;">Error loading rooms.</p>';
                    });
            }
            // Validation for Step 2
            if (currentStep === 2 && step === 3) {
                if (selectedRooms.length === 0) {
                    alert("Please select at least one room.");
                    return;
                }
                updateArrivalTimeOptions();
            }

            // Update UI
            document.querySelectorAll('.ab-step').forEach(el => el.classList.remove('active'));
            document.getElementById(`ab-step-${step}`).classList.add('active');

            // Update Title
            const titles = ["Select Dates", "Select Rooms", "Guest Information"];
            document.getElementById('abModalTitle').innerText = `Step ${step}: ${titles[step - 1]}`;

            currentStep = step;
        }

        // Change the function signature to accept 'rooms'
        // --- UPDATED RENDER FUNCTION FOR VIEW DATA ---
        function renderAvailableRooms(rooms) {
            const container = document.getElementById('roomSelectionContainer');
            container.innerHTML = '';

            if (rooms.length === 0) {
                container.innerHTML = `
        <div style="grid-column: 1/-1; text-align:center; padding:30px; color:#666;">
            <p>No rooms available for the selected dates.</p>
        </div>`;
                return;
            }

            rooms.forEach(room => {
                const card = document.createElement('div');
                card.className = 'room-card';

                // Handle clicking the card
                card.onclick = () => toggleRoomSelection(room.id, card, room.name, room.price);

                // --- 🟢 FIX STARTS HERE 🟢 ---
                // 1. Define the folder where images are stored
                const basePath = '../../room_includes/uploads/images/';
                let imageSrc = '';

                if (room.image && room.image.trim() !== '') {
                    // 2. Handle comma-separated images (take the first one)
                    let cleanName = room.image.split(',')[0].trim();

                    // 3. Check if the database gave us just a filename (e.g. "room.jpg")
                    // If so, add the full path to it.
                    if (cleanName.indexOf('/') === -1) {
                        imageSrc = basePath + cleanName;
                    } else {
                        imageSrc = cleanName;
                    }
                } else {
                    // 4. Fallback if database is empty
                    imageSrc = '../../IMG/default_room.jpg';
                }
                // --- 🟢 FIX ENDS HERE 🟢 ---

                card.innerHTML = `
        <div class="room-card-check"></div>
        
        <img src="${imageSrc}" 
             alt="${room.name}" 
             class="room-card-image" 
             onerror="this.onerror=null; this.src='https://placehold.co/600x400?text=No+Image';">
        
        <div class="room-card-body">
            <div class="room-card-header">${room.name}</div>
            
            <div class="room-card-details">
                <span class="detail-badge">👥 ${room.capacity} Pax</span>
                <span class="detail-badge">🛏️ ${room.bed}</span>
                <span class="detail-badge">📏 ${room.size}</span>
            </div>
            
            <div class="room-card-price">₱${parseFloat(room.price).toLocaleString()}</div>
        </div>
        `;

                // Maintain selection state if re-rendered
                if (selectedRooms.find(r => r.id === room.id)) {
                    card.classList.add('selected');
                }

                container.appendChild(card);
            });
        }


        function toggleRoomSelection(id, cardElement, name, price) {
            if (selectedRooms.find(r => r.id === id)) {
                // Deselect
                selectedRooms = selectedRooms.filter(r => r.id !== id);
                cardElement.classList.remove('selected');
            } else {
                // Select
                selectedRooms.push({ id, name, price });
                cardElement.classList.add('selected');
            }
        }

        function openConfirmationModal() {
            // 1. Gather Data from the Form
            const form = document.getElementById('addBookingForm');
            const formData = new FormData(form);

            const name = `${formData.get('firstname')} ${formData.get('lastname')}`;

            // 🔴 FIX: Define the 'dates' variable here
            const dates = `${formData.get('checkin')} to ${formData.get('checkout')}`;

            const roomNames = selectedRooms.map(r => r.name).join(', ');

            // 2. Calculate Totals (Updated Logic)
            const totalFullPrice = selectedRooms.reduce((sum, r) => sum + r.price, 0);

            const termElement = document.getElementById('payment_term_select');
            const paymentTerm = termElement ? termElement.value : 'full';

            let amountToPayNow = totalFullPrice;
            let balanceDue = 0;
            let paymentStatus = 'paid';

            // --- Payment Logic ---
            if (paymentTerm === 'partial') {
                // 50% Downpayment
                amountToPayNow = totalFullPrice / 2;
                balanceDue = totalFullPrice / 2;
                paymentStatus = 'partial';
            }
            else {
                // Full Payment
                amountToPayNow = totalFullPrice;
                balanceDue = 0;
                paymentStatus = 'paid';
            }

            // 3. Display Data in Modal
            document.getElementById('confirmName').innerText = name;
            document.getElementById('confirmDates').innerText = dates; // This line caused the error
            document.getElementById('confirmRooms').innerText = roomNames;

            // 4. Custom Total Display
            const totalEl = document.getElementById('confirmTotal');

            if (balanceDue > 0) {
                if (amountToPayNow === 0) {
                    // Formatting for "Pay at Checkout"
                    totalEl.innerHTML = `
                    <div style="font-size:1.1rem; color:#333; font-weight:700;">Total: ₱${totalFullPrice.toLocaleString()}</div>
                    <div style="color:#EF4444; font-size:0.9rem; margin-top:5px;">
                        <i class="fas fa-exclamation-circle"></i> No payment collected yet.
                    </div>
                    <div style="color:#555; font-size:0.8rem;">Guest pays full amount upon checkout.</div>
                `;
                } else {
                    // Formatting for "50% Partial"
                    totalEl.innerHTML = `
                    <div style="font-size:0.9rem; color:#555; text-decoration: line-through;">Total: ₱${totalFullPrice.toLocaleString()}</div>
                    <div style="color:#FFA000; font-size:1.2rem;">Pay Now (50%): ₱${amountToPayNow.toLocaleString()}</div>
                    <div style="color:#DC2626; font-size:0.8rem;">Balance upon arrival: ₱${balanceDue.toLocaleString()}</div>
                `;
                }
            } else {
                // Formatting for "Full Payment"
                totalEl.innerText = `₱${totalFullPrice.toLocaleString()} (Full Payment)`;
            }

            // 5. Save data globally so finalConfirmBtn can use it
            window.tempBookingPayload = {
                totalPrice: totalFullPrice,
                amountPaid: amountToPayNow,
                paymentStatus: paymentStatus,
                paymentTerm: paymentTerm
            };

            // 6. Show Modal
            document.getElementById('confirmationModal').style.display = 'block';
        }

        document.addEventListener("DOMContentLoaded", function () {

            flatpickr("#checkin_picker", {
                mode: "range",
                minDate: "today",
                showMonths: 2,
                dateFormat: "Y-m-d",
                plugins: [new rangePlugin({ input: "#checkout_picker" })],
                locale: { firstDayOfWeek: 1 },

                // --- UPDATE THIS SECTION ---
                onOpen: function (selectedDates, dateStr, instance) {
                    document.getElementById('checkin_picker').classList.add('active');
                    document.getElementById('checkout_picker').classList.add('active');

                    // Add the WIDE class when opening
                    instance.calendarContainer.classList.add("double-month-theme");
                },
                // ---------------------------

                onClose: function (selectedDates, dateStr, instance) {
                    document.getElementById('checkin_picker').classList.remove('active');
                    document.getElementById('checkout_picker').classList.remove('active');
                }
            });

        });


        // --- GUEST PROFILE LOGIC ---
        const guestModal = document.getElementById('guestProfileModal');
        const guestLoader = document.getElementById('guestProfileLoader');
        const guestContent = document.getElementById('guestProfileContent');

        function openGuestProfile(email) {
            const guestModal = document.getElementById('guestProfileModal');
            const guestLoader = document.getElementById('guestProfileLoader');
            const guestContent = document.getElementById('guestProfileContent');

            guestModal.style.display = 'block';
            guestLoader.style.display = 'block';
            guestContent.style.display = 'none';

            // Reset view to "Read Only" mode every time we open it
            if (typeof toggleGuestEdit === 'function') toggleGuestEdit(false);

            // Fetch Data
            fetch(`get_guest_details.php?email=${encodeURIComponent(email)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        guestModal.style.display = 'none';
                        return;
                    }

                    // Store global data for editing
                    window.currentGuestData = data;

                    // --- 1. POPULATE PERSONAL INFO ---
                    const info = data.info;
                    document.getElementById('gp_name').innerText = `${info.salutation || ''} ${info.first_name} ${info.last_name}`;
                    document.getElementById('gp_email').innerText = info.email;
                    document.getElementById('gp_phone').innerText = info.phone;
                    document.getElementById('gp_nation').innerText = info.nationality || 'N/A';
                    document.getElementById('gp_gender').innerText = info.gender || 'N/A';
                    document.getElementById('gp_dob').innerText = info.birthdate || 'N/A';
                    document.getElementById('gp_address').innerText = info.address || 'N/A';

                    // --- 2. POPULATE BOOKING HISTORY ---
                    const tbody = document.getElementById('gp_history_body');
                    tbody.innerHTML = '';

                    if (data.history && data.history.length > 0) {
                        const today = new Date();
                        today.setHours(0, 0, 0, 0);

                        data.history.forEach(h => {
                            const checkinObj = new Date(h.check_in);
                            checkinObj.setHours(0, 0, 0, 0);

                            const checkin = checkinObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                            const checkout = new Date(h.check_out).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });

                            let displayStatus = h.status.toUpperCase();
                            let badgeClass = 'badge-pending';

                            if (h.status === 'cancelled') {
                                displayStatus = 'CANCELLED';
                                badgeClass = 'badge-cancelled';
                            } else if (h.arrival_status === 'checked_out') {
                                displayStatus = 'COMPLETE';
                                badgeClass = 'arrival-checkedout';
                            } else if (h.arrival_status === 'in_house') {
                                displayStatus = 'IN HOUSE';
                                badgeClass = 'arrival-inhouse';
                            } else if (h.status === 'confirmed') {
                                if (checkinObj.getTime() < today.getTime()) {
                                    displayStatus = 'NO-SHOW';
                                    badgeClass = 'arrival-overdue';
                                } else if (checkinObj.getTime() === today.getTime()) {
                                    displayStatus = 'ARRIVING TODAY';
                                    badgeClass = 'arrival-today';
                                } else {
                                    displayStatus = 'UPCOMING';
                                    badgeClass = 'badge-confirmed';
                                }
                            }

                            const row = `
                            <tr>
                                <td style="font-weight:bold;">${h.booking_reference}</td>
                                <td>${checkin} - ${checkout}</td>
                                <td>${h.room_names || 'Unknown'}</td>
                                <td>₱${parseFloat(h.total_price).toLocaleString()}</td>
                                <td><span class="badge ${badgeClass}">${displayStatus}</span></td>
                            </tr>`;
                            tbody.innerHTML += row;
                        });
                    } else {
                        tbody.innerHTML = `<tr><td colspan="5" class="text-center">No booking history found.</td></tr>`;
                    }

                    // --- 🟢 3. POPULATE ORDER HISTORY (NEW) ---
                    const orderBody = document.getElementById('gp_orders_body');
                    orderBody.innerHTML = '';

                    if (data.orders && data.orders.length > 0) {
                        data.orders.forEach(o => {
                            // Parse JSON Items (e.g., {"Burger": 2} -> "2x Burger")
                            let itemsStr = '';
                            try {
                                const items = JSON.parse(o.items);
                                itemsStr = Object.keys(items).map(k => `<span style="white-space:nowrap;"><b>${items[k]}x</b> ${k}</span>`).join(', ');
                            } catch (e) { itemsStr = 'Items unavailable'; }

                            // Format Date
                            const dateStr = new Date(o.order_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

                            // Badge Logic for Orders
                            let ordBadgeClass = 'badge-pending';
                            if (o.status === 'Delivered') ordBadgeClass = 'badge-confirmed';
                            if (o.status === 'Cancelled') ordBadgeClass = 'badge-cancelled';
                            if (o.status === 'Preparing') ordBadgeClass = 'arrival-today';

                            const ordRow = `
                            <tr>
                                <td style="font-weight:bold; color:#888;">#${o.id}</td>
                                <td>${dateStr}</td>
                                <td style="font-size:0.85rem; color:#555;">${itemsStr}</td>
                                <td style="font-weight:bold; color:#B88E2F;">₱${parseFloat(o.total_price).toLocaleString()}</td>
                                <td><span class="badge ${ordBadgeClass}">${o.status}</span></td>
                            </tr>`;
                            orderBody.innerHTML += ordRow;
                        });
                    } else {
                        orderBody.innerHTML = `<tr><td colspan="5" class="text-center" style="padding:20px; color:#999;">No food orders found.</td></tr>`;
                    }

                    // --- 🟢 4. RESET TAB TO BOOKINGS ---
                    if (typeof switchGuestHistoryTab === 'function') {
                        switchGuestHistoryTab('bookings');
                    }

                    // Show Content
                    guestLoader.style.display = 'none';
                    guestContent.style.display = 'block';
                })
                .catch(err => {
                    console.error(err);
                    guestLoader.innerHTML = '<p style="color:red;">Error loading profile.</p>';
                });
        }

        // 🟢 NEW FUNCTION: Switch Tabs in Guest Modal
        function switchGuestHistoryTab(tabName) {
            // 1. Get Elements
            const bookingsContainer = document.getElementById('gp_history_container');
            const ordersContainer = document.getElementById('gp_orders_container');
            const btnBookings = document.getElementById('tab-btn-bookings');
            const btnOrders = document.getElementById('tab-btn-orders');

            // 2. Toggle Visibility
            if (tabName === 'bookings') {
                bookingsContainer.style.display = 'block';
                ordersContainer.style.display = 'none';

                // Update Button Styles (Gold for active, Grey for inactive)
                btnBookings.style.color = '#B88E2F';
                btnBookings.style.borderBottomColor = '#B88E2F';
                btnBookings.classList.add('active');

                btnOrders.style.color = '#888';
                btnOrders.style.borderBottomColor = 'transparent';
                btnOrders.classList.remove('active');
            } else {
                bookingsContainer.style.display = 'none';
                ordersContainer.style.display = 'block';

                btnOrders.style.color = '#B88E2F';
                btnOrders.style.borderBottomColor = '#B88E2F';
                btnOrders.classList.add('active');

                btnBookings.style.color = '#888';
                btnBookings.style.borderBottomColor = 'transparent';
                btnBookings.classList.remove('active');
            }
        }

        function closeGuestModal() {
            guestModal.style.display = 'none';
        }

        // Close on click outside
        window.addEventListener('click', (e) => {
            if (e.target == guestModal) {
                closeGuestModal();
            }
        });


        // --- BOOKING ACTION MODAL LOGIC ---
        const actionModal = document.getElementById('bookingActionModal');
        let currentDailyPrice = 0; // 🟢 Global store for current room price

        // Updated function: Cancellation valid only within 3 days of BOOKING DATE
        // Updated signature to accept 'bookingSource' and 'specialRequests'
        function openBookingAction(id, name, ref, rooms, checkin, checkout, price, arrivalStatus, amountPaid, currentLabel, createdAt, bookingSource, dailyPrice = 0, specialRequests = '') {
            console.log("Booking Source:", bookingSource);
            console.log("Daily Price from DB:", dailyPrice);
            currentDailyPrice = parseFloat(dailyPrice) || 0;
            console.log("Captured Global Daily Price:", currentDailyPrice);

            // 1. Sanitize Inputs (Crucial for status checks)
            // --- 🟢 FIX: Get the LATEST status from the HTML row (handles real-time updates) ---
            const row = document.getElementById('row-' + id);
            let realTimeStatus = 'confirmed'; // Default fallback

            if (row) {
                // If we just cancelled it via JS, this attribute will be 'cancelled'
                realTimeStatus = row.getAttribute('data-status');
            }

            // 1. Sanitize Inputs
            const safeStatus = arrivalStatus ? arrivalStatus.trim().toLowerCase() : '';

            // Check both the passed status AND the real-time status from the DOM
            const isCancelled = (realTimeStatus === 'cancelled') || ['cancelled', 'no_show'].includes(safeStatus) || ['Cancelled', 'No-Show'].includes(currentLabel);

            if (isCancelled) {
                let statusText = "Booking is Cancelled";
                if (safeStatus === 'no_show' || currentLabel === 'No-Show') statusText = "Booking marked as No-Show";

                // Reset container and show message
                const container = document.getElementById('ba_action_container');
                container.innerHTML = `<div style="text-align:center; font-weight:bold; color:#EF4444; padding: 10px; background:#FEF2F2; border-radius:6px;">${statusText}</div>`;

                // Fill basic details so the modal isn't empty
                document.getElementById('ba_guest').innerText = name;
                document.getElementById('ba_ref').innerText = ref;
                document.getElementById('ba_room').innerText = rooms;
                document.getElementById('ba_dates').innerText = `${checkin} to ${checkout}`;
                document.getElementById('ba_price').innerHTML = `<div>₱${parseFloat(price).toLocaleString()}</div>`; // Simplified price view

                // Handle Special Request
                const srBox = document.getElementById('ba_special_request');
                if (srBox) {
                    srBox.innerText = (specialRequests && specialRequests.trim() !== "") ? specialRequests : "No special request";
                }

                document.getElementById('ba_warning').style.display = 'none';

                document.getElementById('bookingActionModal').style.display = 'block';
                return; // ⛔ STOP HERE so no buttons are added
            }
            const isWalkIn = (bookingSource && bookingSource.toLowerCase() === 'walk-in');

            // 2. Populate Text Details
            document.getElementById('ba_guest').innerText = name;
            document.getElementById('ba_ref').innerText = ref;
            document.getElementById('ba_room').innerText = rooms;
            document.getElementById('ba_dates').innerText = `${checkin} to ${checkout}`;

            // Handle Special Request
            const srBox = document.getElementById('ba_special_request');
            if (srBox) {
                srBox.innerText = (specialRequests && specialRequests.trim() !== "") ? specialRequests : "No special request";
            }

            // 3. Calculate Balance
            const total = parseFloat(price);
            const paid = amountPaid ? parseFloat(amountPaid) : 0;
            const balance = Math.round((total - paid) * 100) / 100;

            const priceEl = document.getElementById('ba_price');
            if (balance > 0) {
                priceEl.innerHTML = `<div>₱${total.toLocaleString()}</div><div style="font-size:0.8rem; color:#EF4444; font-weight:700;">(Bal: ₱${balance.toLocaleString()})</div>`;
            } else {
                priceEl.innerHTML = `<div>₱${total.toLocaleString()}</div><div style="font-size:0.8rem; color:#10B981; font-weight:700;">(Fully Paid)</div>`;
            }

            // 4. Prepare Container & Reset
            const container = document.getElementById('ba_action_container');
            const warning = document.getElementById('ba_warning');
            container.innerHTML = '';
            warning.style.display = 'none'; // Hide warning by default

            // 5. Handle Cancelled / No-Show
            const isTerminated = ['cancelled', 'no_show'].includes(safeStatus) || ['Cancelled', 'No-Show'].includes(currentLabel);
            if (isTerminated) {
                let statusText = "Booking is Cancelled";
                if (safeStatus === 'no_show' || currentLabel === 'No-Show') statusText = "Booking marked as No-Show";
                container.innerHTML = `<div style="text-align:center; font-weight:bold; color:#EF4444; padding: 10px;">${statusText}</div>`;
                document.getElementById('bookingActionModal').style.display = 'block';
                return;
            }

            // --- BUTTON 1: SETTLE BALANCE (Always show if debt exists) ---
            if (balance > 0) {
                const payBtn = document.createElement('button');
                payBtn.className = 'ab-submit-btn';
                payBtn.style.backgroundColor = '#10B981';
                payBtn.style.marginBottom = '15px';
                payBtn.innerHTML = `💰 Settle Balance ($${balance.toLocaleString()})`;
                payBtn.onclick = function () {
                    settleBalance(id, balance);
                };
                container.appendChild(payBtn);
            }

            // --- BUTTON 2: MAIN ACTIONS (Check In / Check Out) ---

            // Scenario A: Active Guest (In House OR Walk-in not checked out)
            if (safeStatus === 'in_house' || (isWalkIn && safeStatus !== 'checked_out')) {

                // Rule: Cannot check out if balance > 0
                if (balance > 0) {
                    const btnDisabled = document.createElement('button');
                    btnDisabled.className = 'ab-submit-btn';
                    btnDisabled.style.backgroundColor = '#9CA3AF'; // Grey
                    btnDisabled.disabled = true;
                    btnDisabled.style.cursor = 'not-allowed';
                    btnDisabled.innerHTML = '<i class="fas fa-lock"></i> Settle Balance to Check Out';
                    container.appendChild(btnDisabled);
                } else {
                    // ✅ PASTE THIS INSTEAD
                    // Always show the Check Out button if they are In House (Manual Control)
                    const btn = document.createElement('button');
                    btn.className = 'ab-submit-btn';
                    btn.style.backgroundColor = '#7E22CE';
                    btn.innerText = 'Check Out Guest';
                    btn.onclick = function () {
                        updateStatus(id, 'checkout', false, this);
                    };
                    container.appendChild(btn);
                }
            }
            // Scenario B: Checked Out
            else if (safeStatus === 'checked_out') {
                container.innerHTML += `<div style="text-align:center; font-weight:bold; color:#666;">Guest has checked out.</div>`;
            }
            // Scenario C: Pending Reservation
            else {
                // 🔴 NEW LOGIC: CHECK IF DATE IS IN FUTURE
                const today = new Date();
                const year = today.getFullYear();
                const month = String(today.getMonth() + 1).padStart(2, '0');
                const day = String(today.getDate()).padStart(2, '0');
                const todayStr = `${year}-${month}-${day}`;

                // --- NEW RESCHEDULE BUTTON (Available for Upcoming AND Arriving Today) ---
                // 1. Parse the 'Created At' date (passed as 'createdAt' argument)
                const createdDateObj = new Date(createdAt.replace(' ', 'T')); // Fix for Safari/Firefox
                const now = new Date();

                // 2. Calculate the difference in hours
                const diffTime = Math.abs(now - createdDateObj);
                const diffHours = Math.ceil(diffTime / (1000 * 60 * 60));

                // 3. Only show button if within 72 hours (3 days)
                if (diffHours <= 72) {
                    const btnResched = document.createElement('button');
                    btnResched.className = 'btn-secondary';
                    btnResched.style.width = '100%';
                    btnResched.style.marginBottom = '10px';
                    btnResched.style.border = '1px solid #F59E0B'; // Orange border
                    btnResched.style.color = '#B45309'; // Dark Orange text
                    btnResched.innerHTML = '<i class="fas fa-calendar-alt"></i> Reschedule Dates';

                    btnResched.onclick = function () {
                        document.getElementById('bookingActionModal').style.display = 'none';
                        openRescheduleModal(ref);
                    };
                    container.appendChild(btnResched);
                } else {
                    // Optional: Show a disabled/greyed out message explaining why
                    const expiredMsg = document.createElement('div');
                    expiredMsg.style.fontSize = '0.75rem';
                    expiredMsg.style.color = '#999';
                    expiredMsg.style.textAlign = 'center';
                    expiredMsg.style.marginBottom = '10px';
                    expiredMsg.style.fontStyle = 'italic';
                    expiredMsg.innerHTML = '<i class="fas fa-clock"></i> Reschedule period expired (72h limit)';
                    container.appendChild(expiredMsg);
                }

                // If Booking is in the Future (Greater than Today)
                if (checkin > todayStr) {
                    // 1. Show Warning
                    warning.style.display = 'block';
                    warning.innerHTML = `<i class="fas fa-clock"></i> Cannot confirm arrival yet.<br>Check-in date is <b>${checkin}</b>.`;

                    // 2. Only Show Cancel Button (No Confirm Button)
                    const btnCancel = document.createElement('button');
                    btnCancel.className = 'ab-submit-btn';
                    btnCancel.style.backgroundColor = '#EF4444';
                    btnCancel.innerText = 'Cancel Booking';
                    btnCancel.onclick = function () {
                        if (confirm("Are you sure you want to cancel this booking?")) updateStatus(id, 'cancel', false, this);
                    };
                    container.appendChild(btnCancel);
                }
                // If Booking is Today or Past
                else {
                    // 1. Calculate specific 8 PM cutoff for THIS booking
                    // Using the checkin date passed to the function
                    const cutoffDate = new Date(checkin + 'T20:00:00'); // 8:00 PM on check-in day
                    const now = new Date();

                    // 🔴 NEW: Add "Mark No-Show" Button if it is past 8 PM
                    if (now > cutoffDate) {
                        const btnNoShow = document.createElement('button');
                        btnNoShow.className = 'ab-submit-btn';
                        btnNoShow.style.backgroundColor = '#F59E0B'; // Orange
                        btnNoShow.style.marginBottom = '10px';
                        btnNoShow.innerText = 'Mark as No-Show';
                        btnNoShow.onclick = function () {
                            // We reuse your updateStatus function
                            updateStatus(id, 'no_show', false, this);
                        };
                        container.appendChild(btnNoShow);
                    }

                    // --- Standard Confirm Arrival Button ---
                    const btnConfirm = document.createElement('button');
                    btnConfirm.className = 'ab-submit-btn';
                    btnConfirm.style.marginBottom = '10px';

                    if (balance > 0) {
                        btnConfirm.style.backgroundColor = '#9CA3AF';
                        btnConfirm.disabled = true;
                        btnConfirm.innerText = '⚠ Settle Balance to Check In';
                    } else {
                        btnConfirm.style.backgroundColor = '#2563EB';
                        btnConfirm.innerText = 'Confirm Arrival';
                        btnConfirm.onclick = function () {
                            updateStatus(id, 'arrive', false, this);
                        };
                    }
                    container.appendChild(btnConfirm);

                    // --- Cancel Button ---
                    const btnCancel = document.createElement('button');
                    btnCancel.className = 'ab-submit-btn';
                    btnCancel.style.backgroundColor = '#EF4444';
                    btnCancel.innerText = 'Cancel Booking';
                    btnCancel.onclick = function () {
                        if (confirm("Are you sure you want to cancel this booking?")) updateStatus(id, 'cancel', false, this);
                    };
                    container.appendChild(btnCancel);
                }
            }

            // --- BUTTON 3: EXTEND STAY ---
            // Allow extend only if NOT checked out AND (In House OR Walk-in OR Upcoming)
            // We use safeStatus to ensure we are checking the sanitized string
            if (safeStatus !== 'checked_out' && (safeStatus === 'in_house' || isWalkIn || safeStatus === 'upcoming' || currentLabel === 'Upcoming')) {
                const btnExtend = document.createElement('button');
                btnExtend.className = 'btn-secondary';
                btnExtend.style.width = '100%';
                btnExtend.style.marginTop = '10px';
                btnExtend.style.border = '1px solid #2563EB';
                btnExtend.style.color = '#2563EB';
                btnExtend.innerHTML = '<i class="fas fa-calendar-plus"></i> Extend Stay';
                btnExtend.onclick = function () {
                    openExtendModal(id, checkout);
                };
                container.appendChild(btnExtend);
            }

            document.getElementById('bookingActionModal').style.display = 'block';
        }

        // --- SETTLE BALANCE (Seamless Update - Keeps Modal Open) ---
        function settleBalance(id, amount) {
            if (!confirm(`Confirm receipt of remaining $${amount.toLocaleString()}?`)) return;

            const formData = new FormData();
            formData.append('id', id);
            formData.append('action', 'settle_payment');
            formData.append('csrf_token', csrfToken);

            // Show loading state on the button
            const container = document.getElementById('ba_action_container');
            const payBtn = Array.from(container.querySelectorAll('button')).find(b => b.innerText.includes("Settle Balance"));

            if (payBtn) {
                payBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                payBtn.disabled = true;
            }

            fetch('update_arrival.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert("Payment Complete!");

                        // 1. ❌ REMOVED: closeActionModal(); 
                        // We keep the modal OPEN.

                        // 2. 🟢 UPDATE MODAL VISUALS (So it looks paid)

                        // A. Update Price Text to Green (Fully Paid)
                        const priceEl = document.getElementById('ba_price');
                        // Keep the dollar amount, just change the red text to green
                        let currentTotal = priceEl.firstElementChild ? priceEl.firstElementChild.innerText : '';
                        priceEl.innerHTML = `<div>${currentTotal}</div><div style="font-size:0.8rem; color:#10B981; font-weight:700;">(Fully Paid)</div>`;

                        // B. Remove the "Settle Balance" Button (since it's paid)
                        if (payBtn) payBtn.remove();

                        // C. Unlock the "Check In" or "Check Out" button
                        // We look for the grey disabled button and re-enable it
                        const lockedBtn = container.querySelector('button:disabled');
                        if (lockedBtn) {
                            lockedBtn.disabled = false;
                            lockedBtn.style.cursor = 'pointer';

                            // Check the text to decide if it's Check-In or Check-Out
                            const btnText = lockedBtn.innerText.toLowerCase();

                            if (btnText.includes('check out')) {
                                // Convert to Check Out Button
                                lockedBtn.style.backgroundColor = '#7E22CE'; // Purple
                                lockedBtn.innerHTML = 'Check Out Guest';
                                lockedBtn.onclick = function () { updateStatus(id, 'checkout', false, this); };
                            } else {
                                // Convert to Confirm Arrival Button
                                lockedBtn.style.backgroundColor = '#2563EB'; // Blue
                                lockedBtn.innerHTML = 'Confirm Arrival';
                                lockedBtn.onclick = function () { updateStatus(id, 'arrive', false, this); };
                            }
                        }

                        // 3. Update the Background Table Row (So if you close, it's correct)
                        const row = document.getElementById('row-' + id);
                        if (row) {
                            // Update Paid Column
                            const paidCell = row.cells[8];
                            if (paidCell) paidCell.innerHTML = '<span style="color:#10B981; font-weight:700; font-size:0.8rem;">Fully Paid</span>';

                            // Update the "View" button's onclick data so it remembers it's paid
                            const actionCell = row.cells[9];
                            const viewBtn = actionCell.querySelector('button');
                            if (viewBtn) {
                                // We replace the balance argument (index 8 approx) with 0 and status 'paid'
                                // Simplest way: just reload dashboard stats, the row is mostly visual
                            }
                        }

                        // 4. Update Header Stats
                        // CORRECT
                        fetchDashboardCards();

                    } else {
                        alert("Error: " + (data.message || "Unknown error"));
                        if (payBtn) {
                            payBtn.innerHTML = `💰 Settle Balance ($${amount.toLocaleString()})`;
                            payBtn.disabled = false;
                        }
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                    if (payBtn) {
                        payBtn.innerHTML = `💰 Settle Balance ($${amount.toLocaleString()})`;
                        payBtn.disabled = false;
                    }
                });
        }

        function closeActionModal() {
            if (isProcessingBooking) return; // 🔴 Prevent closing via "X" button if processing
            document.getElementById('bookingActionModal').style.display = 'none';
        }

        // --- UPDATE STATUS (With Loading & Safety Lock) ---
        function updateStatus(id, action, isAuto = false, btnElement = null) {

            // 1. Confirmation (Only for manual clicks)
            if (!isAuto) {
                let confirmMsg = "Are you sure you want to update this status?";
                if (action === 'cancel') confirmMsg = "Are you sure you want to CANCEL this booking? This cannot be undone.";
                if (action === 'checkout') confirmMsg = "Confirm guest check-out?";
                if (action === 'no_show') confirmMsg = "Mark this guest as No-Show?";

                if (!confirm(confirmMsg)) return;
            }

            // 2. LOCK UI (Active Busy Mode)
            isProcessingBooking = true;

            // Map actions to labels for the overlay
            const actionLabels = {
                'arrive': 'CHECKING IN GUEST...',
                'checkout': 'CHECKING OUT GUEST...',
                'no_show': 'MARKING AS NO-SHOW...',
                'cancel': 'CANCELLING BOOKING...'
            };
            toggleUILock(true, actionLabels[action] || "UPDATING STATUS...");

            let originalText = "";
            if (btnElement) {
                originalText = btnElement.innerHTML;
                btnElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                btnElement.disabled = true;
                btnElement.style.opacity = '0.7';
                btnElement.style.cursor = 'not-allowed';

                // Disable sibling buttons
                if (btnElement.parentNode) {
                    const siblings = btnElement.parentNode.querySelectorAll('button');
                    siblings.forEach(b => {
                        b.disabled = true;
                        b.style.opacity = '0.5';
                    });
                }
            }

            const formData = new FormData();
            formData.append('id', id);
            formData.append('action', action);
            formData.append('csrf_token', csrfToken);

            fetch('update_arrival.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {

                        // Success Logic
                        if (!isAuto) {
                            // Short delay to show processing state
                            setTimeout(() => {
                                alert("Status Updated Successfully!");
                                document.getElementById('bookingActionModal').style.display = 'none';
                            }, 100);
                        }

                        // Update Background Row
                        const row = document.getElementById('row-' + id);
                        if (row) {
                            const statusCell = row.cells[3];
                            if (action === 'arrive') {
                                statusCell.innerHTML = '<div class="arrival-badge arrival-inhouse">In House</div>';
                                row.setAttribute('data-arrival', 'in_house');
                            } else if (action === 'checkout') {
                                statusCell.innerHTML = '<div class="arrival-badge arrival-checkedout">Checked Out</div>';
                                row.setAttribute('data-arrival', 'checked_out');
                                row.style.backgroundColor = "#fff3cd";
                            } else if (action === 'no_show' || action === 'cancel') {
                                let badgeClass = action === 'cancel' ? 'badge-cancelled' : 'arrival-overdue';
                                let label = action === 'cancel' ? 'Cancelled' : 'No-Show';
                                statusCell.innerHTML = `<div class="arrival-badge ${badgeClass}">${label}</div>`;
                                row.setAttribute('data-arrival', action);
                                row.setAttribute('data-status', 'cancelled');
                                row.style.backgroundColor = "#FEE2E2";
                            }
                        }
                        // Update Dashboard
                        if (typeof fetchDashboardCards === 'function') fetchDashboardCards();

                    } else {
                        throw new Error(data.message || "Unknown error");
                    }
                })
                .catch(err => {
                    console.error(err);
                    if (!isAuto) alert("Error: " + err.message);
                })
                .finally(() => {
                    // 🔴 UNLOCK UI
                    isProcessingBooking = false;
                    toggleUILock(false);

                    // Restore Buttons
                    if (btnElement) {
                        btnElement.innerHTML = originalText;
                        btnElement.disabled = false;
                        btnElement.style.opacity = '1';
                        btnElement.style.cursor = 'pointer';

                        if (btnElement.parentNode) {
                            const siblings = btnElement.parentNode.querySelectorAll('button');
                            siblings.forEach(b => {
                                b.disabled = false;
                                b.style.opacity = '1';
                            });
                        }
                    }
                });
        }

        document.addEventListener("DOMContentLoaded", function () {

            // --- SHARED BIRTHDATE LOGIC (Add Booking & Edit Profile) ---
            const today = new Date();
            const legalAgeDate = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate());

            // Configuration Object
            const dobConfig = {
                dateFormat: "Y-m-d",
                allowInput: true,
                maxDate: legalAgeDate, // Enforce 18+
                yearSelectorType: 'static',
                onReady: function (selectedDates, dateStr, instance) {
                    instance.calendarContainer.classList.add("single-month");
                },
                onClose: function (selectedDates, dateStr, instance) {
                    if (selectedDates.length === 0 && dateStr !== "") {
                        alert("Invalid date format. Please use YYYY-MM-DD.");
                        instance.clear();
                    }
                    if (selectedDates.length > 0) {
                        if (selectedDates[0] > legalAgeDate) {
                            alert("Guest must be at least 18 years old.");
                            instance.clear();
                        }
                    }
                }
            };

            // 1. Apply to Add Booking Modal
            flatpickr("#birthdate_picker", dobConfig);

            // 2. Apply to Edit Profile Modal (NEW)
            flatpickr("#edit_dob", dobConfig);

            // --- NATIONALITY VALIDATION FOR EDIT PROFILE (NEW) ---
            const editNationInput = document.getElementById('edit_nation');
            // We use the same array 'nationalities' defined earlier in your script
            if (editNationInput && typeof nationalities !== 'undefined') {
                editNationInput.addEventListener('change', function () {
                    if (!nationalities.includes(this.value)) {
                        alert("Please select a valid nationality from the list.");
                        this.value = ''; // Clear invalid input
                    }
                });
            }
        });


        document.addEventListener("DOMContentLoaded", function () {

            // 1. The Master List of Nationalities
            const nationalities = [
                "Afghan", "Albanian", "Algerian", "American", "Andorran", "Angolan", "Antiguans", "Argentinean", "Armenian", "Australian", "Austrian", "Azerbaijani",
                "Bahamian", "Bahraini", "Bangladeshi", "Barbadian", "Barbudans", "Batswana", "Belarusian", "Belgian", "Belizean", "Beninese", "Bhutanese", "Bolivian",
                "Bosnian", "Brazilian", "British", "Bruneian", "Bulgarian", "Burkinabe", "Burmese", "Burundian", "Cambodian", "Cameroonian", "Canadian", "Cape Verdean",
                "Central African", "Chadian", "Chilean", "Chinese", "Colombian", "Comoran", "Congolese", "Costa Rican", "Croatian", "Cuban", "Cypriot", "Czech",
                "Danish", "Djibouti", "Dominican", "Dutch", "East Timorese", "Ecuadorean", "Egyptian", "Emirian", "Equatorial Guinean", "Eritrean", "Estonian",
                "Ethiopian", "Fijian", "Filipino", "Finnish", "French", "Gabonese", "Gambian", "Georgian", "German", "Ghanaian", "Greek", "Grenadian", "Guatemalan",
                "Guinea-Bissauan", "Guinean", "Guyanese", "Haitian", "Herzegovinian", "Honduran", "Hungarian", "Icelander", "Indian", "Indonesian", "Iranian", "Iraqi",
                "Irish", "Israeli", "Italian", "Ivorian", "Jamaican", "Japanese", "Jordanian", "Kazakhstani", "Kenyan", "Kittian and Nevisian", "Kuwaiti", "Kyrgyz",
                "Laotian", "Latvian", "Lebanese", "Liberian", "Libyan", "Liechtensteiner", "Lithuanian", "Luxembourger", "Macedonian", "Malagasy", "Malawian",
                "Malaysian", "Maldivan", "Malian", "Maltese", "Marshallese", "Mauritanian", "Mauritian", "Mexican", "Micronesian", "Moldovan", "Monacan", "Mongolian",
                "Moroccan", "Mosotho", "Motswana", "Mozambican", "Namibian", "Nauruan", "Nepalese", "New Zealander", "Ni-Vanuatu", "Nicaraguan", "Nigerien",
                "North Korean", "Northern Irish", "Norwegian", "Omani", "Pakistani", "Palauan", "Panamanian", "Papua New Guinean", "Paraguayan", "Peruvian", "Polish",
                "Portuguese", "Qatari", "Romanian", "Russian", "Rwandan", "Saint Lucian", "Salvadoran", "Samoan", "San Marinese", "Sao Tomean", "Saudi", "Scottish",
                "Senegalese", "Serbian", "Seychellois", "Sierra Leonean", "Singaporean", "Slovakian", "Slovenian", "Solomon Islander", "Somali", "South African",
                "South Korean", "Spanish", "Sri Lankan", "Sudanese", "Surinamer", "Swazi", "Swedish", "Swiss", "Syrian", "Taiwanese", "Tajik", "Tanzanian", "Thai",
                "Togolese", "Tongan", "Trinidadian or Tobagonian", "Tunisian", "Turkish", "Tuvaluan", "Ugandan", "Ukrainian", "Uruguayan", "Uzbekistani", "Venezuelan",
                "Vietnamese", "Welsh", "Yemenite", "Zambian", "Zimbabwean"
            ];

            // 2. Populate the datalist
            const dataList = document.getElementById('nationality_options');
            if (dataList) {
                let optionsHTML = '';
                nationalities.forEach(nation => {
                    optionsHTML += `<option value="${nation}">`;
                });
                dataList.innerHTML = optionsHTML;
            }

            // 3. (Optional) Validation: Force user to pick from list
            const input = document.getElementById('nationalityInput');
            if (input) {
                input.addEventListener('change', function () {
                    // Check if the typed value exists in the array
                    if (!nationalities.includes(this.value)) {
                        // Ideally, show a red border or small error text
                        this.setCustomValidity("Please select a valid nationality from the list.");
                    } else {
                        this.setCustomValidity("");
                    }
                });
            }
        });

        // --- 2. ADDRESS AUTOCOMPLETE (Nominatim) ---
        const addrInput = document.getElementById('adminAddressInput');
        const addrHidden = document.getElementById('adminAddressHidden');
        const addrLoader = document.getElementById('adminAddrLoader');
        const addrResults = document.getElementById('adminAddrResults');
        let debounceTimer;

        if (addrInput && addrHidden) {
            addrInput.addEventListener('input', function () {
                // Automatically copy whatever the user types into the hidden field
                addrHidden.value = this.value;
            });
        }

        if (addrInput) {
            addrInput.addEventListener('input', function () {
                const query = this.value.trim();
                clearTimeout(debounceTimer);

                if (query.length < 3) {
                    addrResults.style.display = 'none';
                    return;
                }

                // Wait 600ms after user stops typing
                debounceTimer = setTimeout(() => {
                    fetchAdminAddress(query);
                }, 600);
            });
        }

        function fetchAdminAddress(query) {
            addrLoader.style.display = 'block';

            // Global Search (No country restriction)
            const url = `search_address.php?q=${encodeURIComponent(query)}`;

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    addrLoader.style.display = 'none';
                    renderAdminAddressResults(data);
                })
                .catch(err => {
                    console.error(err);
                    addrLoader.style.display = 'none';
                });
        }

        function renderAdminAddressResults(data) {
            addrResults.innerHTML = '';

            if (data.length === 0) {
                addrResults.style.display = 'none';
                return;
            }

            data.forEach(place => {
                const item = document.createElement('div');
                item.className = 'address-result-item';
                item.innerText = place.display_name;

                item.onclick = () => {
                    // 1. Fill Visible Input
                    addrInput.value = place.display_name;
                    // 2. Fill Hidden Input (This goes to DB)
                    addrHidden.value = place.display_name;
                    // 3. Hide List
                    addrResults.style.display = 'none';
                };

                addrResults.appendChild(item);
            });

            addrResults.style.display = 'block';
        }

        // Close dropdown on click outside
        document.addEventListener('click', function (e) {
            if (addrInput && e.target !== addrInput && e.target !== addrResults) {
                addrResults.style.display = 'none';
            }
        });

        function editEmailAddress() {
            // 1. Get current email from the display span
            const currentEmail = document.getElementById('gp_email').innerText;

            // 2. Ask Admin for new email
            const newEmail = prompt("Enter the correct email address:", currentEmail);

            if (newEmail && newEmail !== currentEmail) {
                // 3. Confirm action
                if (!confirm(`Change email from "${currentEmail}" to "${newEmail}"? This will update their entire booking history.`)) return;

                // 4. Send to Server
                const formData = new FormData();
                formData.append('old_email', currentEmail);
                formData.append('new_email', newEmail);
                // Reuse your existing CSRF token variable
                formData.append('csrf_token', csrfToken);

                fetch('update_guest_email.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            alert("Email updated successfully!");

                            // 1. Update the email on the profile card immediately
                            document.getElementById('gp_email').innerText = newEmail;

                            // 2. Refresh the main table in the background (Seamless)
                            if (typeof fetchGuestList === 'function') {
                                fetchGuestList();
                            }
                        } else {
                            alert("Error: " + data.message);
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        alert("Request failed.");
                    });
            }
        }


        // --- ROOM MANAGEMENT JS ---

        function openAddRoomModal() {
            document.getElementById('roomForm').reset();
            document.getElementById('roomModalTitle').innerText = "Add New Room";
            document.getElementById('roomAction').value = "add";
            document.getElementById('roomModal').style.display = 'block';
        }

        // --- SUBMIT FORM (PREVENTS DUPLICATES) ---
        // We use .onsubmit instead of .addEventListener to ensure only ONE listener exists
        // --- SUBMIT FORM (PREVENTS DUPLICATES) ---
        // --- ROOM MANAGEMENT (Seamless Update) ---
        document.getElementById('roomForm').onsubmit = function (e) {
            e.preventDefault();

            // 1. Validation
            const bedTypeInput = document.getElementById('roomBedTypeInput');
            if (!bedTypeInput || bedTypeInput.value.trim() === "") {
                alert("Please select a Room Type."); // <--- Updated Message
                if (bedTypeInput) bedTypeInput.focus();
                return;
            }

            // 2. UI Loading State
            const btn = this.querySelector('button[type="submit"]');
            if (btn.disabled) return;
            const originalText = btn.innerText;
            btn.innerText = "Uploading...";
            btn.disabled = true;

            const formData = new FormData(this);
            const actionType = document.getElementById('roomAction').value; // 'add' or 'edit'

            // 3. Send Request
            fetch('manage_rooms.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    // --- PASTE THIS NEW CODE INSTEAD ---
                    if (data.status === 'success') {
                        alert(data.message);
                        // Force reload to sync database, images, and visual table
                        window.location.reload();
                    } else {
                        alert("Error: " + data.message);
                        // Only reset the button if there was an error so user can try again
                        btn.innerText = originalText;
                        btn.disabled = false;
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error: Check console for details.");
                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        };

        // --- AMENITY MANAGEMENT LOGIC ---

        function previewAmenityIcon(iconClass) {
            const preview = document.getElementById('amenityIconPreview');
            if (preview) {
                preview.innerHTML = `<i class="${iconClass}"></i>`;
            }
        }

        function openAddAmenityModal() {
            document.getElementById('amenityForm').reset();
            document.getElementById('amenityModalTitle').innerText = "Add New Amenity";
            document.getElementById('amenityAction').value = "add";
            document.getElementById('amenityId').value = "";
            previewAmenityIcon('fas fa-question-circle');
            document.getElementById('amenityModal').style.display = 'block';
        }

        function openEditAmenityModal(id, title, icon, desc) {
            document.getElementById('amenityModalTitle').innerText = "Edit Amenity";
            document.getElementById('amenityAction').value = "edit";
            document.getElementById('amenityId').value = id;
            document.getElementById('amenityTitleInput').value = title;
            document.getElementById('amenityIconInput').value = icon;
            document.getElementById('amenityDescInput').value = desc;
            previewAmenityIcon(icon);
            document.getElementById('amenityModal').style.display = 'block';
        }

        document.getElementById('amenityForm').onsubmit = function (e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerText;
            btn.innerText = "Saving...";
            btn.disabled = true;

            const formData = new FormData(this);

            fetch('manage_amenities.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert("Error: " + data.message);
                        btn.innerText = originalText;
                        btn.disabled = false;
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        };

        function deleteAmenity(id) {
            if (!confirm("Are you sure you want to delete this amenity? This will remove it from all assigned rooms.")) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('amenity_id', id);

            fetch('manage_amenities.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(err => console.error(err));
        }

        // --- RESTORE ROOM (Seamless) ---
        function restoreRoom(id) {
            if (!confirm("Do you want to restore this room to the active list?")) return;

            const formData = new FormData();
            formData.append('action', 'restore');
            formData.append('room_id', id);

            fetch('manage_rooms.php', { // Make sure this matches your filename (manage_rooms.php or manage_rooms_.php)
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);

                        // Find row and un-hide it or remove "Archived" styling
                        // Since "Archived" usually hides the row in standard view, we might just remove the "ARCHIVED" badge text
                        // or reload if it's too complex to move it between lists visually.
                        // For seamless:
                        location.reload(); // Restore is rare, reloading here is acceptable to resort the list correctly.
                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(err => console.error(err));
        }

        // --- NEWS ARCHIVE LOGIC ---

        // --- TOGGLE ARCHIVED NEWS ---
        function toggleArchivedNews() {
            // 1. Find all rows with the specific class
            const rows = document.querySelectorAll('.archived-news-row');
            const btn = document.getElementById('toggleArchivedNewsBtn');
            let isHidden = false;

            // 2. Debugging: Check if we actually found any rows
            if (rows.length === 0) {
                console.warn("No archived news rows found in the DOM.");
                // Optional: Alert user if list is empty
                // alert("No archived items to show.");
                return;
            }

            // 3. Loop and Toggle
            rows.forEach(row => {
                if (row.style.display === 'none') {
                    row.style.display = 'table-row';
                    isHidden = false; // We just showed them
                } else {
                    row.style.display = 'none';
                    isHidden = true; // We just hidden them
                }
            });

            // 4. Update Button Text
            if (btn) {
                btn.innerText = isHidden ? "Show Archived" : "Hide Archived";
            }
        }


        // 3. New Restore Function
        function restoreNews(id) {
            if (!confirm("Restore this news item to the active list?")) return;

            const formData = new FormData();
            formData.append('action', 'restore');
            formData.append('news_id', id);

            fetch('manage_news.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert("Error: " + data.message);
                    }
                });
        }

        // --- ROOM IMAGE PREVIEW ---
        // function previewRoomImage(input) {
        //     if (input.files && input.files[0]) {
        //         var reader = new FileReader();
        //         reader.onload = function (e) {
        //             document.getElementById('roomImagePreview').src = e.target.result;
        //             document.getElementById('roomImagePreview').style.display = 'block';
        //             document.getElementById('roomImagePlaceholder').style.display = 'none';
        //         };
        //         reader.readAsDataURL(input.files[0]);
        //     }
        // }

        // --- UPDATED ROOM MODAL JS ---


        // --- 🔴 START OF NEW GALLERY LOGIC (Paste this here) ---

        // 1. Preview Image Logic (Updated with Safety Check)
        function previewGalleryImage(input, index) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    // Safe selection
                    const preview = document.getElementById('preview_' + index);
                    const placeholder = document.getElementById('placeholder_' + index);

                    // Only try to set src if the element actually exists
                    if (preview) {
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                    }
                    if (placeholder) {
                        placeholder.style.display = 'none';
                    }
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // 3. Updated Add Room Modal (Resets all 4 boxes)
        function openAddRoomModal() {
            // Reset form text fields
            document.getElementById('roomForm').reset();
            document.getElementById('roomModalTitle').innerText = "Add New Room";
            document.getElementById('roomAction').value = "add";
            document.getElementById('roomId').value = "";

            // Reset all 4 Image Boxes to "Empty" state
            for (let i = 0; i < 4; i++) {
                const preview = document.getElementById('preview_' + i);
                const placeholder = document.getElementById('placeholder_' + i);
                const input = document.getElementById('file_' + i);

                if (preview) {
                    preview.src = "";
                    preview.style.display = 'none';
                }
                if (placeholder) placeholder.style.display = 'block';
                if (input) input.value = ""; // Clear the actual file input
            }

            // Unlock UI if it was locked by 'Edit' mode previously
            const uploaderBoxes = document.querySelectorAll('.gallery-box');
            uploaderBoxes.forEach(box => {
                box.style.pointerEvents = 'auto';
                box.style.opacity = '1';
                box.style.backgroundColor = '#F3F4F6';
            });

            // 🟢 RESET AMENITIES
            document.querySelectorAll('.am-checkbox').forEach(cb => {
                cb.checked = false;
                cb.disabled = false;
                cb.parentElement.style.opacity = '1';
                cb.parentElement.style.cursor = 'pointer';
            });

            document.getElementById('roomModal').style.display = 'block';
        }

        // 2. Edit Room Modal (Fixed: Now locks "Type" dropdown correctly)
        function openEditRoomModal(id, name, price, bedType, capacity, size, description, filePath, isBooked, amenities) {
            // 1. Set IDs
            document.getElementById('roomId').value = id;
            document.getElementById('roomAction').value = "edit";
            document.getElementById('roomModalTitle').innerText = isBooked ? "Edit Room (Locked)" : "Edit Room";

            // 2. Populate Text Fields safely
            if (document.getElementById('roomNameInput')) document.getElementById('roomNameInput').value = name;
            if (document.getElementById('roomPriceInput')) document.getElementById('roomPriceInput').value = price;
            if (document.getElementById('roomBedTypeInput')) document.getElementById('roomBedTypeInput').value = bedType;
            if (document.getElementById('roomCapacityInput')) document.getElementById('roomCapacityInput').value = capacity;
            if (document.getElementById('roomSizeInput')) document.getElementById('roomSizeInput').value = size;
            if (document.getElementById('roomDescInput')) document.getElementById('roomDescInput').value = description;

            // 🟢 POPULATE AMENITIES
            document.querySelectorAll('.am-checkbox').forEach(cb => {
                cb.checked = false; // Reset first
                if (isBooked) {
                    cb.disabled = true;
                    cb.parentElement.style.opacity = '0.6';
                    cb.parentElement.style.cursor = 'not-allowed';
                } else {
                    cb.disabled = false;
                    cb.parentElement.style.opacity = '1';
                    cb.parentElement.style.cursor = 'pointer';
                }
            });

            if (amenities) {
                const amArray = amenities.split(',').map(s => s.trim());
                amArray.forEach(amId => {
                    const checkbox = document.querySelector(`.am-checkbox[value="${amId}"]`);
                    if (checkbox) checkbox.checked = true;
                });
            }

            // 🟢 FORCE REFRESH the Custom Select so it shows the new value (bedType)
            refreshCustomSelect('roomBedTypeInput');

            // 3. Populate Images (Reset & Fill)
            for (let i = 0; i < 4; i++) {
                const preview = document.getElementById('preview_' + i);
                const placeholder = document.getElementById('placeholder_' + i);
                if (preview) { preview.src = ""; preview.style.display = 'none'; }
                if (placeholder) placeholder.style.display = 'block';
            }

            if (filePath && filePath.trim() !== "") {
                const images = filePath.split(',');
                images.forEach((imgName, index) => {
                    if (index < 4) {
                        const cleanName = imgName.trim();
                        if (cleanName !== "") {
                            const preview = document.getElementById('preview_' + index);
                            const placeholder = document.getElementById('placeholder_' + index);
                            if (preview) {
                                preview.src = '../../room_includes/uploads/images/' + cleanName;
                                preview.style.display = 'block';
                                if (placeholder) placeholder.style.display = 'none';
                            }
                        }
                    }
                });
            }

            // 🟢 LOCK LOGIC (Standard Inputs)
            const inputsToLock = ['roomNameInput', 'roomCapacityInput', 'roomSizeInput', 'roomDescInput'];
            inputsToLock.forEach(inputId => {
                const el = document.getElementById(inputId);
                if (el) {
                    el.readOnly = isBooked;
                    el.style.backgroundColor = isBooked ? "#e9ecef" : "#F5F5F5";
                    el.style.cursor = isBooked ? "not-allowed" : "text";
                }
            });

            // 🟢 LOCK THE CUSTOM "TYPE" SELECT MANUALLY
            const typeSelect = document.getElementById('roomBedTypeInput');
            if (typeSelect) {
                // Disable the hidden select (logic)
                typeSelect.disabled = isBooked;

                // Disable the visual wrapper (UI)
                const wrapper = typeSelect.nextElementSibling;
                if (wrapper && wrapper.classList.contains('custom-select-wrapper')) {
                    const trigger = wrapper.querySelector('.custom-select-trigger');
                    if (isBooked) {
                        // Locked State
                        wrapper.style.pointerEvents = 'none'; // Stop clicks
                        wrapper.style.opacity = '0.6'; // Visual dim
                        if (trigger) {
                            trigger.style.backgroundColor = '#e9ecef';
                            trigger.style.cursor = 'not-allowed';
                        }
                    } else {
                        // Active State (Unlocked)
                        wrapper.style.pointerEvents = 'auto';
                        wrapper.style.opacity = '1';
                        if (trigger) {
                            trigger.style.backgroundColor = '#fff';
                            trigger.style.cursor = 'pointer';
                        }
                    }
                }
            }

            // 5. LOCK LOGIC (Images)
            const galleryBoxes = document.querySelectorAll('#roomForm .gallery-box');
            galleryBoxes.forEach(box => {
                if (isBooked) {
                    box.style.pointerEvents = 'none';
                    box.style.opacity = '0.5';
                    box.style.backgroundColor = '#e9ecef';
                    box.style.border = '1px solid #ccc';
                } else {
                    box.style.pointerEvents = 'auto';
                    box.style.opacity = '1';
                    box.style.backgroundColor = '#F3F4F6';
                    box.style.border = '2px dashed #E5E7EB';
                }
            });

            document.getElementById('roomModal').style.display = 'block';
        }

        // 1. Toggle Visibility of Archived Rows
        function toggleArchivedRooms() {
            const rows = document.querySelectorAll('.archived-room-row');
            const btn = document.getElementById('toggleArchivedBtn');

            let isHidden = false;

            rows.forEach(row => {
                if (row.style.display === 'none') {
                    row.style.display = 'table-row';
                    isHidden = false;
                } else {
                    row.style.display = 'none';
                    isHidden = true;
                }
            });

            // Update Button Text
            btn.innerText = isHidden ? "Show Archived" : "Hide Archived";
        }

        // 2. Restore Room Function
        function restoreRoom(id) {
            if (!confirm("Do you want to restore this room to the active list?")) return;

            const formData = new FormData();
            formData.append('action', 'restore');
            formData.append('room_id', id);

            fetch('manage_rooms.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                });
        }

        // --- 6. GUEST SEARCH LOGIC ---
        function filterGuestTable() {
            // 🟢 UPDATED: Reset offset and fetch from server for real pagination
            guestOffset = 0;
            fetchGuestList(true); // true = silent/manual refresh
        }

        // Attach Event Listener when page loads
        document.addEventListener("DOMContentLoaded", function () {
            const guestInput = document.getElementById('guestSearchInput');
            if (guestInput) {
                guestInput.addEventListener('keyup', filterGuestTable);
            }
        });

        // --- HOTEL NEWS LOGIC ---

        // 1. Initialize Flatpickr for News Modal
        document.addEventListener("DOMContentLoaded", function () {
            flatpickr("#news_date_picker", {
                dateFormat: "Y-m-d",
                defaultDate: "today",
                static: true // Important for modal positioning
            });
        });

        // 2. Image Preview
        function previewNewsImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    document.getElementById('newsImagePreview').src = e.target.result;
                    document.getElementById('newsImagePreview').style.display = 'block';
                    document.getElementById('newsImagePlaceholder').style.display = 'none';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // 3. Open Modal (Add)
        function openAddNewsModal() {
            document.getElementById('newsForm').reset();

            // 🔴 REPLACE THIS BLOCK:
            // Old TinyMCE code:
            // if (tinymce.get('newsDescInput')) { ... }

            // 🟢 NEW QUILL CODE:
            // 1. Clear the visual editor
            if (newsQuill) {
                newsQuill.setText('');
            }
            // 2. Clear the hidden input field manually
            document.getElementById('newsDescInput').value = '';


            document.getElementById('newsModalTitle').innerText = "Add News";
            document.getElementById('newsAction').value = "add";
            document.getElementById('newsId').value = "";

            // Reset Image
            document.getElementById('newsImagePreview').src = "";
            document.getElementById('newsImagePreview').style.display = 'none';
            document.getElementById('newsImagePlaceholder').style.display = 'block';

            // Reset Date to today
            const datePicker = document.getElementById('news_date_picker')._flatpickr;
            if (datePicker) {
                datePicker.setDate(new Date());
            }

            document.getElementById('newsModal').style.display = 'block';
        }

        // 4. Open Modal (Edit) - UPDATED WITH BASE64 DECODING
        function openEditNewsModal(id, title, date, encodedDesc, imgPath) {
            document.getElementById('eventId').value = ""; // Clear event ID just in case
            document.getElementById('newsId').value = id;
            document.getElementById('newsAction').value = "edit";
            document.getElementById('newsModalTitle').innerText = "Edit News";

            document.getElementById('newsTitleInput').value = title;

            // 🟢 DECODE BASE64 DESCRIPTION SAFELY
            let decodedDesc = "";
            try {
                // This handles special characters, emojis, and HTML tags correctly
                decodedDesc = decodeURIComponent(escape(window.atob(encodedDesc)));
            } catch (e) {
                console.error("Decoding error", e);
                decodedDesc = "";
            }

            // 1. Update the hidden input
            document.getElementById('newsDescInput').value = decodedDesc;

            // 2. Update the Quill Visual Editor
            if (newsQuill) {
                // Quill uses this method to parse HTML strings back into the editor
                // We use a slight delay to ensure the modal is rendered first
                setTimeout(() => {
                    newsQuill.clipboard.dangerouslyPasteHTML(decodedDesc);
                }, 50);
            }

            // Set Date Picker
            const datePicker = document.getElementById('news_date_picker')._flatpickr;
            if (datePicker) {
                datePicker.setDate(date);
            }

            // Image Preview Logic
            const preview = document.getElementById('newsImagePreview');
            const placeholder = document.getElementById('newsImagePlaceholder');

            if (imgPath && imgPath.trim() !== "") {
                preview.src = '../../room_includes/uploads/news/' + imgPath;
                preview.style.display = 'block';
                placeholder.style.display = 'none';
            } else {
                preview.src = "";
                preview.style.display = 'none';
                placeholder.style.display = 'block';
            }

            document.getElementById('newsModal').style.display = 'block';
        }

        // --- MISSING: Submit Handler for News ---
        document.getElementById('newsForm').onsubmit = function (e) {
            e.preventDefault();

            // 1. SYNC QUILL TO HIDDEN INPUT
            if (newsQuill) {
                document.getElementById('newsDescInput').value = newsQuill.root.innerHTML;
            }

            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerText;
            btn.innerText = "Saving...";
            btn.disabled = true;

            const formData = new FormData(this);

            fetch('manage_news.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert("Error: " + data.message);
                        btn.innerText = originalText;
                        btn.disabled = false;
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        };

        // 5. Submit Form (Add/Edit) - SEAMLESS VERSION
        document.getElementById('foodForm').onsubmit = function (e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerText;
            btn.innerText = "Saving...";
            btn.disabled = true;

            const formData = new FormData(this);
            const action = document.getElementById('foodAction').value; // 'add' or 'edit'

            fetch('manage_food.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        document.getElementById('foodModal').style.display = 'none';

                        // --- 🟢 SEAMLESS UPDATE LOGIC ---
                        if (action === 'add') {
                            // Add new row to table
                            addFoodRowToTable(data.data);
                        } else {
                            // Update existing row
                            updateFoodRowInTable(data.data);
                        }
                        // -------------------------------

                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        };

        // --- HELPER 1: Add New Row ---
        function addFoodRowToTable(item) {
            const tbody = document.getElementById('foodTableBody');
            const row = document.createElement('tr');
            row.id = 'food-row-' + item.id;
            row.innerHTML = generateFoodRowHTML(item);

            // Insert at top
            if (tbody.firstChild) {
                tbody.insertBefore(row, tbody.firstChild);
            } else {
                tbody.appendChild(row);
            }
        }

        // --- HELPER 2: Update Existing Row ---
        function updateFoodRowInTable(item) {
            const row = document.getElementById('food-row-' + item.id);
            if (row) {
                row.innerHTML = generateFoodRowHTML(item);
            }
        }

        // --- HELPER 3: Generate Row HTML (Shared) ---
        function generateFoodRowHTML(food) {
            // 1. Icon Logic
            const cat = food.category.toLowerCase();
            let iconClass = 'fa-concierge-bell';
            let iconColor = '#9CA3AF';

            if (cat.includes('beverage') || cat.includes('drink')) { iconClass = 'fa-glass-martini-alt'; iconColor = '#3B82F6'; }
            else if (cat.includes('dessert')) { iconClass = 'fa-ice-cream'; iconColor = '#EC4899'; }
            else if (cat.includes('snack') || cat.includes('appetizer')) { iconClass = 'fa-cookie-bite'; iconColor = '#F59E0B'; }
            else if (cat.includes('soup')) { iconClass = 'fa-mug-hot'; iconColor = '#EA580C'; }
            else if (cat.includes('breakfast')) { iconClass = 'fa-bacon'; iconColor = '#8B5CF6'; }
            else if (cat.includes('main')) { iconClass = 'fa-utensils'; iconColor = '#10B981'; }

            // 2. Image Logic
            let imgHTML = '';
            if (food.image_path) {
                imgHTML = `<img src="../../room_includes/uploads/food/${food.image_path}" style="width:100%; height:100%; object-fit:cover;">`;
            } else {
                imgHTML = `<i class="fas ${iconClass}" style="color: ${iconColor}; font-size: 1.1rem;"></i>`;
            }

            // 3. Escape Strings
            const safeName = food.item_name.replace(/'/g, "\\'");

            return `
        <td>
            <div style="width: 60px; height: 50px; background:#eee; border-radius:6px; overflow:hidden; border:1px solid #ddd; display:flex; align-items:center; justify-content:center;">
                ${imgHTML}
            </div>
        </td>
        <td style="text-align:center;">
            <i class="fas ${iconClass}" style="color: ${iconColor};"></i>
        </td>
        <td style="font-weight: 700; color: #333; font-size: 1rem;">${food.item_name}</td>
        <td>
            <span class="badge" style="background:#F3F4F6; color:#555; border:1px solid #ddd; text-transform:uppercase; letter-spacing:0.5px;">
                ${food.category}
            </span>
        </td>
        <td style="font-weight: 700; color: #B88E2F;">₱${parseFloat(food.price).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
        <td style="text-align: right;">
            <div style="display: flex; justify-content: flex-end; gap: 5px;">
                <button class="btn-secondary" style="padding:5px 10px;" onclick="openEditFoodModal(
                    '${food.id}', '${safeName}', '${food.category}', '${food.price}', '${food.image_path || ''}'
                )">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <button class="btn-secondary" style="padding:5px 10px; color:#DC2626; border-color: #FECACA; background: #FEF2F2;"
                    onclick="deleteFood('${food.id}')">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </td>
    `;
        }

        // 6. Delete News - SEAMLESS VERSION
        function deleteNews(id) {
            if (!confirm("Are you sure you want to delete this news item?")) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('news_id', id);

            // Visual feedback (optional): Fade out the row immediately
            const row = document.getElementById('news-row-' + id);
            if (row) row.style.opacity = '0.5';

            fetch('manage_news.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);

                        // --- 🟢 SEAMLESS REMOVAL ---
                        // Remove the row from the DOM
                        if (row) {
                            row.remove();
                        }

                        // Optional: If table is empty, show "No news" message
                        const tbody = document.querySelector('#view-news tbody');
                        if (tbody && tbody.children.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="5" class="text-center" style="padding:30px;">No news posted yet.</td></tr>';
                        }
                        // ---------------------------

                    } else {
                        alert("Error: " + data.message);
                        // Revert visual feedback if failed
                        if (row) row.style.opacity = '1';
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                    if (row) row.style.opacity = '1';
                });
        }


        // --- PERMANENT DELETE NEWS ---
        function permanentDeleteNews(id) {
            // 1. Strong Warning
            if (!confirm("⚠️ WARNING: This will PERMANENTLY DELETE this news item from the database.\n\nThis action CANNOT be undone.\n\nAre you sure?")) return;

            const formData = new FormData();
            formData.append('action', 'hard_delete'); // Specific action for permanent removal
            formData.append('news_id', id);

            // Visual feedback
            const row = document.getElementById('news-row-' + id);
            if (row) row.style.opacity = '0.3';

            fetch('manage_news.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert("Item permanently deleted.");
                        // Remove row from DOM immediately
                        if (row) row.remove();

                        // Check if table is empty
                        const tbody = document.querySelector('#view-news tbody');
                        if (tbody && tbody.children.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="5" class="text-center" style="padding:30px;">No news posted yet.</td></tr>';
                        }
                    } else {
                        alert("Error: " + data.message);
                        if (row) row.style.opacity = '1'; // Revert visual if failed
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                    if (row) row.style.opacity = '1';
                });
        }

        // --- HOTEL NEWS LOGIC ---

        // 1. Initialize Flatpickr for News Modal
        document.addEventListener("DOMContentLoaded", function () {

            // 1. KEEP THIS (It handles the Date Picker)
            flatpickr("#news_date_picker", {
                dateFormat: "Y-m-d",
                defaultDate: "today",
                minDate: "today",
                disableMobile: "true",
                showMonths: 1,
                onReady: function (selectedDates, dateStr, instance) {
                    instance.calendarContainer.classList.add("wide-news-calendar");
                    instance.calendarContainer.classList.remove("single-month");
                }
            });

            // 2. REPLACE THE TINYMCE PART WITH THIS (For Quill)
            newsQuill = new Quill('#newsQuillEditor', {
                theme: 'snow',
                placeholder: 'Write news details here...',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline'],
                        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                        ['link', 'clean']
                    ]
                }
            });

        });


        // --- FOOD MENU FUNCTIONS ---

        // --- FOOD MENU FUNCTIONS (SIMPLIFIED) ---

        // 1. Image Preview Function
        function previewFoodImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    document.getElementById('foodImagePreview').src = e.target.result;
                    document.getElementById('foodImagePreview').style.display = 'block';
                    document.getElementById('foodImagePlaceholder').style.display = 'none';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // 2. Open Add Modal
        function openAddFoodModal() {
            document.getElementById('foodForm').reset();
            document.getElementById('foodModalTitle').innerText = "Add Menu Item";
            document.getElementById('foodAction').value = "add";
            document.getElementById('foodId').value = "";

            // Reset Image
            document.getElementById('foodImagePreview').src = "";
            document.getElementById('foodImagePreview').style.display = 'none';
            document.getElementById('foodImagePlaceholder').style.display = 'block';

            document.getElementById('foodModal').style.display = 'block';
        }

        // 3. Open Edit Modal (Accepts imgPath)
        function openEditFoodModal(id, name, category, price, imgPath) {
            document.getElementById('foodId').value = id;
            document.getElementById('foodAction').value = "edit";
            document.getElementById('foodModalTitle').innerText = "Edit Menu Item";

            document.getElementById('foodNameInput').value = name;
            document.getElementById('foodCategoryInput').value = category;
            document.getElementById('foodPriceInput').value = price;

            // Handle Image Preview
            const preview = document.getElementById('foodImagePreview');
            const placeholder = document.getElementById('foodImagePlaceholder');

            if (imgPath) {
                preview.src = '../../room_includes/uploads/food/' + imgPath;
                preview.style.display = 'block';
                placeholder.style.display = 'none';
            } else {
                preview.style.display = 'none';
                placeholder.style.display = 'block';
            }

            document.getElementById('foodModal').style.display = 'block';
        }

        // --- FOOD MENU FUNCTIONS (MANUAL DOM UPDATE) ---

        // 1. Toggle Visibility of Archived Items
        function toggleArchivedFood() {
            const rows = document.querySelectorAll('.archived-food-row');
            const btn = document.getElementById('toggleArchivedFoodBtn');

            if (rows.length === 0) {
                alert("No archived items found.");
                return;
            }

            // Check visibility based on the first row found
            // If it's hidden ('none'), we want to show all.
            let showAll = (rows[0].style.display === 'none');

            rows.forEach(row => {
                row.style.display = showAll ? 'table-row' : 'none';
            });

            if (btn) btn.innerText = showAll ? "Hide Archived" : "Show Archived";
        }

        // 2. Soft Delete (Archive)
        function deleteFood(id) {
            if (!confirm("Archive this item? It will be hidden from the menu.")) return;

            const formData = new FormData();
            formData.append('action', 'delete'); // Matches PHP 'delete' (soft)
            formData.append('food_id', id);

            fetch('manage_food.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        // Reload to re-render the row with "Restore" buttons
                        location.reload();
                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(err => console.error(err));
        }

        // 3. Restore Item
        function restoreFood(id) {
            if (!confirm("Restore this item to the active menu?")) return;

            const formData = new FormData();
            formData.append('action', 'restore');
            formData.append('food_id', id);

            fetch('manage_food.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        location.reload(); // Reload to re-render row as Active
                    }
                });
        }

        // 4. Permanent Delete
        function permanentDeleteFood(id) {
            if (!confirm("⚠️ PERMANENT DELETE: This cannot be undone.\n\nAre you sure?")) return;

            const formData = new FormData();
            formData.append('action', 'hard_delete');
            formData.append('food_id', id);

            fetch('manage_food.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        // Remove row directly from DOM since it's gone forever
                        const row = document.getElementById('food-menu-row-' + id);
                        if (row) row.remove();
                    } else {
                        alert("Error: " + data.message);
                    }
                });
        }

        // 5. Toggle Stock (In Stock / Out of Stock)
        function toggleStock(id, newStatus) {
            const formData = new FormData();
            formData.append('action', 'toggle_stock');
            formData.append('food_id', id);
            formData.append('status', newStatus);

            fetch('manage_food.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        refreshFoodTable(); // Updates the badge color immediately
                    }
                });
        }

        // Handle Submit
        document.getElementById('foodForm').onsubmit = function (e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            btn.innerText = "Saving...";
            btn.disabled = true;

            const formData = new FormData(this);

            fetch('manage_food.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert("Saved successfully!");
                        location.reload();
                    } else {
                        alert("Error: " + data.message);
                        btn.innerText = "Save Item";
                        btn.disabled = false;
                    }
                });
        };

        function updateArrivalTimeOptions() {
            const checkin = document.getElementById('checkin_picker').value;
            const select = document.getElementById('arrival_time_select');

            // 1. Clear existing options
            select.innerHTML = '<option value="" disabled selected>- Select -</option>';

            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const todayStr = `${year}-${month}-${day}`;

            // Standard Check-in is 2 PM (14:00)
            let startHour = 14;
            let endHour = 20; // 8 PM

            // REALTIME CHECK: If booking for TODAY
            if (checkin === todayStr) {
                const currentHour = now.getHours();
                // If it's past 2 PM, start options from the Next Hour
                if (currentHour >= 14) {
                    startHour = currentHour + 1;
                }
            }

            // Loop to generate time options
            for (let h = startHour; h <= endHour; h++) {
                let realHour24 = h;
                let displayHour12 = realHour24;
                let suffix = 'AM';

                if (displayHour12 >= 12) {
                    suffix = 'PM';
                    if (displayHour12 > 12) displayHour12 -= 12;
                }
                if (displayHour12 === 0) displayHour12 = 12;

                // 1. Value to save (24-Hour)
                let valueStr = (realHour24 < 10 ? '0' : '') + realHour24 + ':00';
                // 2. Text to show (12-Hour)
                let textStr = (displayHour12 < 10 ? '0' : '') + displayHour12 + ':00 ' + suffix;

                let opt = document.createElement('option');
                opt.value = valueStr;
                opt.innerText = textStr;
                select.appendChild(opt);

                // Add 30-minute interval
                if (h < endHour) {
                    let halfValue = (realHour24 < 10 ? '0' : '') + realHour24 + ':30';
                    let halfText = (displayHour12 < 10 ? '0' : '') + displayHour12 + ':30 ' + suffix;
                    let halfOpt = document.createElement('option');
                    halfOpt.value = halfValue;
                    halfOpt.innerText = halfText;
                    select.appendChild(halfOpt);
                }
            }

            // 🟢 CRITICAL FIX: Refresh the Custom UI to show these new options
            refreshCustomSelect('arrival_time_select');
        }

        // --- MASTER NAVIGATION & STATE HANDLER (FINAL PERSISTENCE UPDATE) ---
        document.addEventListener("DOMContentLoaded", function () {

            // --- 🟢 INITIALIZE STATE ON RELOAD ---
            const activePage = localStorage.getItem('activePage') || 'dashboard';
            const lastSubView = localStorage.getItem('activeSettingsView');

            // 1. Clear all
            document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.page').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.settings-view').forEach(el => {
                el.classList.remove('active');
                el.style.display = 'none';
            });

            // 2. Activate Main Page
            const targetNav = document.querySelector(`.nav-item[data-page="${activePage}"]`);
            if (targetNav) targetNav.classList.add('active');

            const targetPage = document.getElementById(activePage);
            if (targetPage) targetPage.classList.add('active');

            // 3. Activate Settings Sub-View
            if (activePage === 'settings') {
                const homeGrid = document.getElementById('settings-home');
                if (lastSubView && lastSubView !== 'settings-home') {
                    const sub = document.getElementById(lastSubView);
                    if (sub) {
                        if (homeGrid) homeGrid.style.display = 'none';
                        sub.classList.add('active');
                        sub.style.display = '';
                    } else {
                        if (homeGrid) {
                            homeGrid.classList.add('active');
                            homeGrid.style.display = '';
                        }
                    }
                } else {
                    if (homeGrid) {
                        homeGrid.classList.add('active');
                        homeGrid.style.display = '';
                    }
                }
            }

            function resetSettingsToHome() {
                console.log("Cleaning up Settings views...");
                document.querySelectorAll('.settings-view').forEach(v => {
                    v.classList.remove('active');
                    v.style.display = 'none';
                });

                const home = document.getElementById('settings-home');
                if (home) {
                    home.classList.add('active');
                    home.style.display = ''; // Let CSS flex/block handle it
                }

                // Only clear memory if we are explicitly resetting to home
                localStorage.removeItem('activeSettingsView');
            }

            // --- CLICK HANDLER ---
            document.querySelectorAll('.nav-menu .nav-item').forEach(link => {
                link.onclick = function (e) {
                    const pageId = this.getAttribute('data-page');
                    if (!pageId) return;

                    e.preventDefault();

                    localStorage.setItem('activePage', pageId);

                    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
                    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));

                    // Only reset sub-views if we are moving to a page that ISN'T Settings
                    // This prevents clicking "Settings" in sidebar from wiping your current sub-page
                    if (pageId !== 'settings') {
                        resetSettingsToHome();
                    }

                    this.classList.add('active');
                    const newPage = document.getElementById(pageId);
                    if (newPage) {
                        newPage.classList.add('active');
                        newPage.scrollTop = 0;
                    }

                    if (pageId === 'transactions') {
                        loadTransactions();
                    }
                };
            });
        });

        // --- SETTINGS VIEW OPENER (For the Grid Cards) ---
        function openSettingsView(viewId) {
            console.log("Opening Settings Sub-View:", viewId);

            // 1. Reset scroll position of the settings container
            const settingsPage = document.getElementById('settings');
            if (settingsPage) {
                settingsPage.scrollTop = 0;
            }

            // 2. Clear all views
            document.querySelectorAll('.settings-view').forEach(v => {
                v.classList.remove('active');
                v.style.display = 'none'; // Clear manual overrides
            });

            // 3. Show target view
            const target = document.getElementById(viewId);
            if (target) {
                target.classList.add('active');
                // Remove the manual display: block override to let CSS flex take over
                target.style.display = '';

                // CRITICAL: Save to storage so it survives a reload
                localStorage.setItem('activeSettingsView', viewId);

                const homeGrid = document.getElementById('settings-home');
                if (viewId !== 'settings-home' && homeGrid) {
                    homeGrid.style.display = 'none';
                }
            }
        }


        // --- REAL-TIME BADGE UPDATER (Manual Only Mode) ---
        function updateBadgesRealtime() {
            // 1. Get Current Time
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const todayStr = `${year}-${month}-${day}`;

            // 2. Select all booking rows
            const rows = document.querySelectorAll('.booking-row');

            rows.forEach(row => {
                // Get Data from HTML Attributes
                const status = row.getAttribute('data-status');
                const arrival = row.getAttribute('data-arrival');
                const checkinDate = row.getAttribute('data-checkin');

                // --------------------------------------------------------
                // VISUAL BADGE UPDATES ONLY (No Database Changes)
                // --------------------------------------------------------

                // We only update badges for Confirmed bookings that are NOT In-House, Checked-Out, or No-Show.
                // This ensures manual statuses (like 'no_show' set by admin) are respected and not overwritten.
                if (status === 'confirmed' &&
                    arrival !== 'in_house' &&
                    arrival !== 'checked_out' &&
                    arrival !== 'no_show') {

                    const badge = row.querySelector('.arrival-badge');

                    if (badge) {
                        // 1. Explicit DB Status or Today's Date -> FORCE BLUE
                        // If the DB says 'arriving_today' OR the date matches today:
                        // Show "Arriving Today" (Blue). We ignore the time (8PM rule removed).
                        if (arrival === 'arriving_today' || checkinDate === todayStr) {
                            badge.className = 'arrival-badge arrival-today';
                            badge.innerText = 'Arriving Today';
                        }
                        // 2. Future Date -> Yellow
                        else if (checkinDate > todayStr) {
                            badge.className = 'arrival-badge arrival-upcoming';
                            badge.innerText = 'Upcoming';
                        }
                        // 3. Past Date -> Red (Overdue)
                        // Only turns red if the date is strictly yesterday or older.
                        else if (checkinDate < todayStr) {
                            badge.className = 'arrival-badge arrival-overdue';
                            badge.innerText = 'Late Arrival';
                        }
                    }
                }
            });
        }


        // --- RENDER MESSAGES ---

        // --- 1. TOGGLE DROPDOWN (Smart Reset Version) ---
        function toggleDropdown(dropdownId, event) {
            event.stopPropagation();

            // 1. Close any OTHER open dropdowns first
            document.querySelectorAll('.dropdown-menu').forEach(dd => {
                if (dd.id !== dropdownId) dd.classList.remove('show');
            });

            // 2. Get the target dropdown
            const targetDropdown = document.getElementById(dropdownId);

            // 3. Check if we are about to OPEN it (before we toggle the class)
            const isOpening = !targetDropdown.classList.contains('show');

            // 4. Toggle visibility
            targetDropdown.classList.toggle('show');

            // 5. IF OPENING -> RESET EVERYTHING TO 'ALL'
            if (isOpening) {

                // --- A. Reset Notifications ---
                if (dropdownId === 'notifDropdown') {
                    // 1. Reset the "Memory" variable
                    currentNotifFilter = 'all';

                    // 2. Reset the Visual Filter Menu (Hide popup, set 'All' to active)
                    const filterMenu = document.getElementById('notifFilterMenu');
                    if (filterMenu) {
                        filterMenu.style.display = 'none';
                        filterMenu.querySelectorAll('.filter-option').forEach(el => el.classList.remove('active'));
                        if (filterMenu.firstElementChild) filterMenu.firstElementChild.classList.add('active');
                    }

                    // 3. Force render ALL items immediately
                    if (window.allNotifications) {
                        renderNotificationList(window.allNotifications);
                    }
                }

                // --- B. Reset Messages ---
                if (dropdownId === 'msgDropdown') {
                    // 1. Reset the "Memory" variable
                    currentMsgFilter = 'all';

                    // 2. Reset the Visual Filter Menu
                    const msgFilterMenu = document.getElementById('msgFilterMenu');
                    if (msgFilterMenu) {
                        msgFilterMenu.style.display = 'none';
                        msgFilterMenu.querySelectorAll('.filter-option').forEach(el => el.classList.remove('active'));
                        if (msgFilterMenu.firstElementChild) msgFilterMenu.firstElementChild.classList.add('active');
                    }

                    // 3. Force render ALL items immediately
                    if (window.allMessages) {
                        renderMessageList(window.allMessages);
                    }
                }
            }
        }

        // --- UNIFIED WINDOW CLICK HANDLER ---
        window.onclick = function (event) {

            // 🔴 SAFETY CHECK: Prevent closing ANY modal if a process is running
            if (isProcessingBooking || isSendingEmail) {
                // If the user clicks the background of ANY modal while processing, ignore the click.
                if (event.target.classList.contains('modal')) {
                    console.log("🚫 Click blocked: Operation in progress.");
                    return;
                }
            }

            // 1. Close Modal if background clicked (Normal behavior)
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                if (event.target.id === 'addBookingModal') resetModal();
                if (event.target.id === 'adminEditModal') toggleAdminEdit(false);
            }

            // 2. Close Main Dropdowns (Bell/Message)
            if (!event.target.closest('.action-wrapper') && !event.target.closest('.modal')) {
                document.querySelectorAll('.dropdown-menu').forEach(dd => dd.classList.remove('show'));
            }

            // 3. Close Filter Menus if clicking outside
            if (!event.target.closest('.filter-btn') && !event.target.closest('.filter-menu-container')) {
                const notifMenu = document.getElementById('notifFilterMenu');
                const msgMenu = document.getElementById('msgFilterMenu');

                if (notifMenu) notifMenu.style.display = 'none';
                if (msgMenu) msgMenu.style.display = 'none';
            }
        }

        // --- 2. FETCH DATA FROM API ---
        function fetchHeaderData() {
            fetch('get_header_data.php')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        renderNotifications(data.notifications, data.counts.notifications);
                        renderMessages(data.messages, data.counts.messages);

                        // NEW: Update late arrival alert
                        if (data.counts.late_arrivals !== undefined) {
                            updateLateArrivalAlert(data.counts.late_arrivals);
                        }
                    }
                })
                .catch(err => console.error("Header API Error:", err));
        }

        // --- 1. NEW: Refactored Render Function (Splits Logic) ---
        function renderNotifications(items, count) {
            // A. Store the Master List
            window.allNotifications = items;

            // B. Update the Badge
            const btn = document.querySelector('.btn-notify');
            let badge = btn.querySelector('.icon-badge');
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'icon-badge';
                btn.appendChild(badge);
            }
            badge.style.display = count > 0 ? 'flex' : 'none';
            badge.innerText = count > 9 ? '9+' : count;

            // NEW: Show/hide the floating alert
            updateNotificationAlert(count);

            // C. Render, but respect the active filter!
            filterAndRender();
        }

        // --- 3. NEW: Filter Logic Functions ---

        function toggleNotifFilter(event) {
            event.stopPropagation(); // Stop click from closing the main dropdown
            const menu = document.getElementById('notifFilterMenu');
            menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
        }

        function applyNotifFilter(criteria, element) {
            // 1. Stop click from bubbling (Prevents window.onclick from closing it)
            if (event) event.stopPropagation();

            // 2. Update Visuals (Active Class)
            document.querySelectorAll('.filter-option').forEach(el => el.classList.remove('active'));
            if (element) element.classList.add('active');

            // 3. Save the filter to memory!
            currentNotifFilter = criteria;

            // 4. Apply the filter logic
            filterAndRender();
        }

        // Global variable to store what is currently being shown (for the "View All" button)
        window.currentFilteredList = [];

        // --- NEW VARIABLES FOR DATE FILTERING ---
        let currentNotifDate = null; // Stores the selected date (YYYY-MM-DD)
        let currentMsgDate = null;   // Stores the selected date for messages

        // Initialize Flatpickr for the notification and message filters
        function initFilterPickers() {
            const nInput = document.getElementById("notifDateFilter");
            const mInput = document.getElementById("msgDateFilter");

            if (nInput) {
                flatpickr(nInput, {
                    dateFormat: "Y-m-d",
                    static: true,
                    onChange: function (selectedDates, dateStr, instance) {
                        currentNotifDate = dateStr;
                        filterAndRender();
                    }
                });
            }

            if (mInput) {
                flatpickr(mInput, {
                    dateFormat: "Y-m-d",
                    static: true,
                    onChange: function (selectedDates, dateStr, instance) {
                        currentMsgDate = dateStr;
                        filterAndRenderMessages();
                    }
                });
            }
        }

        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", initFilterPickers);
        } else {
            initFilterPickers();
        }

        // Function to clear the date filter
        function clearNotifDate() {
            currentNotifDate = null;
            const picker = document.querySelector("#notifDateFilter")._flatpickr;
            if (picker) picker.clear();
            filterAndRender();
        }

        function filterAndRender() {
            let filteredList = window.allNotifications;

            // 1. Filter by Type (Existing Logic)
            if (currentNotifFilter !== 'all') {
                if (currentNotifFilter === 'unread') {
                    filteredList = filteredList.filter(n => n.is_read == 0);
                } else {
                    filteredList = filteredList.filter(n => n.type === currentNotifFilter);
                }
            }

            // 2. Filter by Date (New Logic)
            if (currentNotifDate) {
                filteredList = filteredList.filter(n => {
                    // Extract the 'YYYY-MM-DD' part from the notification's 'created_at' string
                    const notifDate = n.created_at.split(' ')[0];
                    return notifDate === currentNotifDate;
                });
            }

            // 3. Render the result
            renderNotificationList(filteredList);
        }

        // --- 2. NEW: Pure Renderer (Just builds HTML) ---
        function renderNotificationList(items) {
            const listContainer = document.querySelector('#notifDropdown .dropdown-list');
            listContainer.innerHTML = '';

            if (items.length === 0) {
                let msg = "No notifications found";
                // Customize message if a date is selected
                if (currentNotifDate) {
                    msg = `No notifications on ${currentNotifDate}`;
                }
                listContainer.innerHTML = `<div style="padding:20px; text-align:center; color:#999; font-size:0.85rem;">${msg}</div>`;
                return;
            }

            // Render items (Showing up to 50 to ensure daily list is visible)
            items.slice(0, 50).forEach(item => {
                let iconClass = 'fa-info-circle', colorClass = 'icon-blue';

                if (item.type === 'booking') { iconClass = 'fa-calendar-check'; colorClass = 'icon-gold'; }
                if (item.type === 'cancel') { iconClass = 'fa-calendar-times'; colorClass = 'icon-red'; }
                if (item.type === 'reminder') { iconClass = 'fa-clock'; colorClass = 'icon-blue'; }

                const bgStyle = item.is_read == 0 ? 'background-color: #f0f9ff;' : '';

                const dateStr = new Date(item.created_at).toLocaleDateString('en-US', {
                    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                });

                const html = `
    <div class="dropdown-item-row" style="${bgStyle}" 
         onclick="openNotificationModal(${item.id})">
        <div class="item-icon-box ${colorClass}"><i class="fas ${iconClass}"></i></div>
        <div class="item-content">
            <div class="item-header">
                <span class="item-title">${item.title}</span>
                <span class="item-time" style="font-size:0.7rem;">${dateStr}</span>
            </div>
            <div class="item-desc">${item.description}</div>
        </div>
    </div>`;

                listContainer.innerHTML += html;
            });
        }

        // --- OPEN NOTIFICATION MODAL (Fixed Logic) ---
        function openNotificationModal(id) {
            // 1. Look up the data from the global list using the ID
            const item = window.allNotifications.find(n => n.id == id);

            if (!item) {
                console.error("Notification data not found for ID:", id);
                return;
            }

            // 2. Populate Modal from the 'item' object we found
            document.getElementById('notifModalTitle').innerText = item.title;
            document.getElementById('notifModalDesc').innerText = item.description;

            // 3. Format Date
            const dateObj = new Date(item.created_at);
            const dateStr = dateObj.toLocaleDateString('en-US', {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });
            document.getElementById('notifModalDate').innerText = dateStr;

            // 4. Show Modal
            document.getElementById('notificationModal').style.display = 'block';

            // 5. Mark as Read
            if (item.is_read == 0) {
                markAsRead(id, 'notification');
                item.is_read = 1; // Update memory so badge doesn't reappear
            }
        }


        // --- 4. RENDER MESSAGES ---
        function renderMessages(items, count) {
            // A. Store Data
            window.allMessages = items;

            // B. Update Badge
            const btn = document.querySelector('.btn-compose');
            let badge = btn.querySelector('.icon-badge');
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'icon-badge';
                btn.appendChild(badge);
            }
            badge.style.display = count > 0 ? 'flex' : 'none';
            badge.innerText = count > 9 ? '9+' : count;

            updateMessageAlert(count);
            // C. Render based on active filter
            filterAndRenderMessages();
        }

        // Pure HTML Builder
        function renderMessageList(items) {
            const list = document.querySelector('#msgDropdown .dropdown-list');
            list.innerHTML = '';

            if (items.length === 0) {
                list.innerHTML = '<div style="padding:20px; text-align:center; color:#999; font-size:0.85rem;">No messages found</div>';
                return;
            }

            items.forEach(item => {
                const bgStyle = item.is_read == 0 ? 'background-color: #f0f9ff;' : '';
                const dateStr = new Date(item.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });

                // Escape special characters for the onclick string
                const safeMsg = item.message.replace(/'/g, "\\'").replace(/"/g, '&quot;');

                const html = `
            <div class="dropdown-item-row" style="${bgStyle}" 
                 onclick="openMessage(${item.id}, '${item.guest_name}', '${item.email}', '${safeMsg}')">
                <div class="item-icon-box icon-blue"><i class="fas fa-envelope"></i></div>
                <div class="item-content">
                    <div class="item-header">
                        <span class="item-title">${item.guest_name}</span>
                        <span class="item-time" style="font-size:0.7rem;">${dateStr}</span>
                    </div>
                    <div class="item-desc">${item.message}</div>
                </div>
            </div>`;

                list.innerHTML += html;
            });
        }

        // --- 5. INTERACTIVITY (Open & Mark Read) ---
        function openMessage(id, name, email, body) {
            document.getElementById('msgModalName').innerText = name;
            document.getElementById('msgModalEmail').innerText = email;
            document.getElementById('msgModalBody').innerText = body;
            document.getElementById('messageModal').style.display = 'block';
            markAsRead(id, 'message');
        }

        // --- MESSAGE FILTER FUNCTIONS ---

        function toggleMsgFilter(event) {
            event.stopPropagation();
            const menu = document.getElementById('msgFilterMenu');
            // Toggle display
            menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';

            // Close the notification filter if it's open (good UX)
            const notifMenu = document.getElementById('notifFilterMenu');
            if (notifMenu) notifMenu.style.display = 'none';
        }

        function applyMsgFilter(criteria, element) {
            if (event) event.stopPropagation();

            // 1. Visuals
            // Find filter options specifically inside the msgFilterMenu
            const container = document.getElementById('msgFilterMenu');
            container.querySelectorAll('.filter-option').forEach(el => el.classList.remove('active'));
            element.classList.add('active');

            // 2. Save State
            currentMsgFilter = criteria;

            // 3. Render
            filterAndRenderMessages();
        }

        function filterAndRenderMessages() {
            let filteredList = window.allMessages || [];

            // 1. Filter by Type
            if (currentMsgFilter === 'unread') {
                filteredList = filteredList.filter(m => m.is_read == 0);
            }

            // 2. Filter by Date (New Logic)
            if (currentMsgDate) {
                filteredList = filteredList.filter(m => {
                    if (!m.created_at) return false;
                    const msgDate = m.created_at.split(' ')[0]; // Assumes "YYYY-MM-DD HH:MM:SS"
                    return msgDate === currentMsgDate;
                });
            }

            renderMessageList(filteredList);
        }

        function clearMsgDate(event) {
            if (event) event.stopPropagation();
            currentMsgDate = null;
            const input = document.getElementById('msgDateFilter');
            if (input && input._flatpickr) {
                input._flatpickr.clear();
            }
            filterAndRenderMessages();
        }

        function markAsRead(id, type) {
            fetch('mark_as_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id, type: type })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') fetchHeaderData(); // Refresh UI
                });
        }

        function markAllMessagesRead(event) {
            if (event) event.stopPropagation();
            if (!confirm('Mark all messages as read?')) return;

            fetch('mark_as_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: 'message_all' })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        fetchHeaderData(); // Refresh UI
                    }
                })
                .catch(err => console.error(err));
        }

        function extendBooking(id, currentCheckout, roomName, currentPrice) {
            // 1. Ask for new date
            // Note: Ideally, use a flatpickr modal here, but 'prompt' is the quickest way to test logic
            const newDate = prompt(`Current Checkout: ${currentCheckout}\n\nEnter new checkout date (YYYY-MM-DD):`, currentCheckout);

            if (!newDate || newDate === currentCheckout) return; // Cancelled or same date

            // 2. Basic Validation
            if (newDate < currentCheckout) {
                alert("New date cannot be earlier than the current checkout date.");
                return;
            }

            if (!confirm(`Are you sure you want to extend this booking to ${newDate}? This will calculate the new price automatically.`)) return;

            // 3. Send to Server
            const formData = new FormData();
            formData.append('id', id);
            formData.append('action', 'extend');
            formData.append('new_checkout', newDate);
            formData.append('csrf_token', csrfToken);

            fetch('update_arrival.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(`Success! Stay extended to ${newDate}.\nNew Total Price: ₱${data.new_total}`);
                        location.reload();
                    } else {
                        alert("Failed: " + data.message);
                    }
                })
                .catch(err => console.error(err));
        }

        // --- EXTEND STAY LOGIC ---
        let extendPickerInstance = null;

        function openExtendModal(id, currentCheckout) {
            // 0. Reset Modal View State (Crucial Fix)
            document.getElementById('ext_main_content').style.display = 'block';
            document.getElementById('ext_conflict_resolution').style.display = 'none';
            document.getElementById('ext_conflict_resolution').innerHTML = '';

            // 1. Set IDs and Text
            document.getElementById('ext_booking_id').value = id;
            document.getElementById('ext_current_date').innerText = currentCheckout;

            // --- 🟢 FETCH ROOMS FOR THIS BOOKING ---
            const roomsList = document.getElementById('ext_rooms_list');
            roomsList.innerHTML = '<span style="font-size:0.8rem; color:#999;">Loading rooms...</span>';

            fetch(`get_booking_rooms.php?id=${id}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        roomsList.innerHTML = '';
                        data.rooms.forEach(room => {
                            const tag = document.createElement('span');
                            tag.style.cssText = "background:#F3F4F6; color:#374151; padding:4px 10px; border-radius:6px; font-size:0.8rem; font-weight:600; border:1px solid #E5E7EB;";
                            tag.innerText = room.room_name;
                            roomsList.appendChild(tag);
                        });
                    }
                })
                .catch(err => {
                    roomsList.innerHTML = '<span style="color:#EF4444; font-size:0.8rem;">Error loading rooms.</span>';
                });

            // Normalize current checkout date
            const currentCheckoutObj = new Date(currentCheckout);
            currentCheckoutObj.setHours(0, 0, 0, 0);

            // Set daily rate
            const dailyRate = parseFloat(currentDailyPrice) || 0;
            document.getElementById('ext_daily_rate').innerText = '₱' + dailyRate.toLocaleString();
            document.getElementById('ext_price_preview').style.display = 'none';

            // 2. Initialize Flatpickr
            const nextDay = new Date(currentCheckoutObj);
            nextDay.setDate(nextDay.getDate() + 1);

            if (extendPickerInstance) {
                extendPickerInstance.destroy();
            }

            const updatePreview = (selectedDate) => {
                if (!selectedDate) return;
                const d2 = new Date(selectedDate);
                d2.setHours(0, 0, 0, 0);

                const diffTime = d2.getTime() - currentCheckoutObj.getTime();
                const diffDays = Math.round(diffTime / (1000 * 60 * 60 * 24));

                if (diffDays > 0) {
                    const totalExtension = diffDays * dailyRate;
                    document.getElementById('ext_extra_nights').innerText = diffDays;
                    document.getElementById('ext_total_cost').innerText = '₱' + totalExtension.toLocaleString();
                    document.getElementById('ext_price_preview').style.display = 'block';
                }
            };

            extendPickerInstance = flatpickr("#extend_date_picker", {
                dateFormat: "Y-m-d",
                minDate: nextDay,
                defaultDate: nextDay,
                static: true,
                appendTo: document.getElementById('extendModal').querySelector('.ab-modal-body'),
                onReady: (sd, ds, inst) => inst.calendarContainer.classList.add("compact-theme"),
                onChange: (selectedDates) => updatePreview(selectedDates[0])
            });

            // Initial Calc
            updatePreview(nextDay);

            // 3. Show Modal
            document.getElementById('extendModal').style.display = 'block';
            document.getElementById('bookingActionModal').style.display = 'none';
        }

        function closeExtendModal() {
            document.getElementById('extendModal').style.display = 'none';
        }

        function toggleUILock(isLocked, message = "PROCESSING...") {
            const overlay = document.getElementById('globalLoadingOverlay');
            if (!overlay) return;

            const text = overlay.querySelector('p');
            if (text) text.innerText = message;

            overlay.style.display = isLocked ? 'flex' : 'none';
        }

        function submitExtension(ignoreConflicts = false) {
            const id = document.getElementById('ext_booking_id').value;
            const newDate = document.getElementById('extend_date_picker').value;
            const paymentChoice = document.getElementById('ext_payment_choice').value;

            if (!newDate) {
                alert("Please select a new checkout date.");
                return;
            }

            if (!ignoreConflicts && !confirm(`Confirm extension until ${newDate}?`)) return;

            // 🟢 UI LOCKING
            toggleUILock(true, "PROCESSING STAY EXTENSION...");

            const formData = new FormData();
            formData.append('id', id);
            formData.append('action', 'extend');
            formData.append('new_checkout', newDate);
            formData.append('extension_payment', paymentChoice);
            if (ignoreConflicts) formData.append('ignore_conflicts', '1');
            formData.append('csrf_token', csrfToken);

            const btn = document.querySelector('#extendModal .ab-submit-btn');
            const oldText = btn.innerText;
            btn.innerText = "Processing...";
            btn.disabled = true;

            fetch('update_arrival.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(`Success! Stay extended.\nNew Total: ₱${data.new_total}`);
                        location.reload();
                    }
                    else if (data.status === 'conflict') {
                        // 🟢 HANDLE CONFLICT: Unlock UI so they can pick alternatives
                        toggleUILock(false);
                        if (confirm(data.message + "\n\nDo you want to proceed and pick alternative rooms for the conflicting ones?")) {
                            renderExtendAlternatives(data.alternatives, data.conflicted_rooms);
                        } else {
                            btn.innerText = oldText;
                            btn.disabled = false;
                        }
                    }
                    else {
                        toggleUILock(false);
                        alert("Failed: " + data.message);
                        btn.innerText = oldText;
                        btn.disabled = false;
                    }
                })
                .catch(err => {
                    toggleUILock(false);
                    console.error(err);
                    alert("System Error.");
                    btn.innerText = oldText;
                    btn.disabled = false;
                });
        }

        let selectedSwaps = {}; // ConflictedRoomID -> SelectedRoomObj

        function renderExtendAlternatives(alternatives, conflictedRooms) {
            const mainContent = document.getElementById('ext_main_content');
            const conflictArea = document.getElementById('ext_conflict_resolution');

            mainContent.style.display = 'none'; // Hide the picker/payment
            conflictArea.style.display = 'block'; // Show the swaps
            selectedSwaps = {};

            let altHtml = `
                <div style="background:#FEF2F2; border:1px solid #FECACA; padding:15px; border-radius:8px; margin-bottom:20px;">
                    <h4 style="margin:0 0 5px 0; color:#DC2626; font-size:0.9rem;">⚠️ Room Conflicts Detected</h4>
                    <p style="font-size:0.8rem; color:#991B1B; margin:0;">
                        The following rooms are unavailable: <b>${conflictedRooms.map(r => r.room_name).join(', ')}</b>.
                    </p>
                </div>
                
                <div id="swap_controls">
                    ${conflictedRooms.map(cr => `
                        <div class="swap-item" style="margin-bottom:20px; padding:15px; border:1px solid #eee; border-radius:8px;">
                            <label class="ab-label" style="font-size:0.85rem; color:#333;">Replace <b>${cr.room_name}</b> with:</label>
                            <div class="room-selection-grid" style="grid-template-columns: 1fr 1fr; gap: 10px; margin-top:10px;">
                                ${alternatives.length > 0 ? alternatives.map(alt => {
                let imgUrl = '../../IMG/default_room.jpg';
                if (alt.image_path) {
                    imgUrl = '../../room_includes/uploads/images/' + alt.image_path.split(',')[0].trim();
                }
                return `
                                    <div class="room-card alt-room-card" 
                                         onclick="selectSwapRoom(${cr.room_id}, ${JSON.stringify(alt).replace(/"/g, '&quot;')}, this)"
                                         style="cursor:pointer; border:1px solid #ddd; position:relative; display: flex; flex-direction: column; overflow: hidden;">
                                        <div class="room-card-check"></div>
                                        <img src="${imgUrl}" class="room-card-image" style="height:80px; width:100%; object-fit:cover;" onerror="this.src='../../IMG/default_room.jpg'">
                                        <div class="room-card-body" style="padding:8px;">
                                            <div style="font-weight:700; font-size:0.8rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${alt.name}</div>
                                            <div style="color:#B88E2F; font-size:0.85rem; font-weight:700;">₱${parseFloat(alt.price).toLocaleString()}</div>
                                        </div>
                                    </div>
                                    `;
            }).join('') : '<div style="color:#999; font-size:0.8rem;">No alternatives found.</div>'}
                            </div>
                        </div>
                    `).join('')}
                </div>

                <div class="ab-grid-footer" style="margin-top: 25px;">
                    <button class="btn-secondary" onclick="location.reload()">Cancel</button>
                    <button class="ab-submit-btn" style="background:#10B981;" onclick="submitExtensionWithSwaps()">Confirm Swaps & Extend</button>
                </div>
            `;

            conflictArea.innerHTML = altHtml;
        }

        function selectSwapRoom(conflictedId, roomObj, element) {
            // Check if this room is ALREADY the selected one for THIS specific conflictedId
            const isAlreadySelected = element.classList.contains('selected');

            // 1. Unselect all rooms in THIS group (this specific conflicted room's grid)
            const parentGrid = element.closest('.room-selection-grid');
            parentGrid.querySelectorAll('.room-card').forEach(c => c.classList.remove('selected'));

            if (isAlreadySelected) {
                // If it was already selected, we now unselect it entirely
                delete selectedSwaps[conflictedId];
            } else {
                // Otherwise, we select it
                element.classList.add('selected');
                selectedSwaps[conflictedId] = roomObj;
            }
        }

        function submitExtensionWithSwaps() {
            const id = document.getElementById('ext_booking_id').value;
            const newDate = document.getElementById('extend_date_picker').value;
            const paymentChoice = document.getElementById('ext_payment_choice').value;

            // 🟢 Visual Loading Feedback
            const btn = document.querySelector('#ext_conflict_resolution .ab-submit-btn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            }

            // 🟢 UI LOCKING
            toggleUILock(true, "PROCESSING ROOM SWAPS & EXTENSION...");

            const formData = new FormData();
            formData.append('id', id);
            formData.append('action', 'extend');
            formData.append('new_checkout', newDate);
            formData.append('extension_payment', paymentChoice);
            formData.append('room_swaps', JSON.stringify(selectedSwaps));
            formData.append('csrf_token', csrfToken);

            fetch('update_arrival.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert("Stay Extended with room changes!");
                        location.reload();
                    } else {
                        toggleUILock(false);
                        alert("Error: " + data.message);
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = 'Confirm Swaps & Extend';
                        }
                    }
                })
                .catch(err => {
                    toggleUILock(false);
                    console.error(err);
                    alert("System Error");
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = 'Confirm Swaps & Extend';
                    }
                });
        }

        // --- 1. TOGGLE THE DROPDOWN ---
        function toggleAddBookingDropdown(event) {
            event.stopPropagation();
            // Close other dropdowns first
            document.querySelectorAll('.dropdown-menu').forEach(dd => dd.classList.remove('show'));

            // Toggle this one
            document.getElementById('addBookingDropdown').classList.toggle('show');
        }

        // --- 2. OPEN MODAL WITH PRE-SELECTED TYPE ---
        function openAddBookingModal(type) {
            // 1. Reset everything first
            resetModal();

            // 🕒 8 PM RULE: For Reservations, disable 'today' if past 8 PM
            const checkinInput = document.getElementById('checkin_picker');
            if (checkinInput && checkinInput._flatpickr) {
                let minDateStr = "today";
                const now = new Date();

                // If it's a Reservation and it's 8:00 PM (20:00) or later
                if (type === 'reservation' && now.getHours() >= 20) {
                    const tomorrow = new Date();
                    tomorrow.setDate(now.getDate() + 1);
                    minDateStr = tomorrow;
                }

                checkinInput._flatpickr.set('minDate', minDateStr);
                checkinInput._flatpickr.clear();
            }

            // 2. SET THE READ-ONLY INPUT
            const sourceInput = document.getElementById('bookingSourceDisplay');
            if (sourceInput) {
                sourceInput.value = type;
            }

            // 3. TOGGLE ARRIVAL TIME FIELD
            const arrivalContainer = document.getElementById('arrivalTimeContainer');
            const arrivalSelect = document.getElementById('arrival_time_select');

            if (type === 'walk-in') {
                // --- WALK-IN: HIDE FIELD ---
                if (arrivalContainer) arrivalContainer.style.display = 'none';
                if (arrivalSelect) arrivalSelect.removeAttribute('required');

                document.getElementById('abModalTitle').innerText = "New Walk-in Booking";

                // Auto-set dates for walk-in (Today -> Tomorrow)
                const today = new Date();
                const tomorrow = new Date();
                tomorrow.setDate(today.getDate() + 1);

                const checkoutFP = document.getElementById('checkout_picker')._flatpickr;

                if (checkinInput._flatpickr) checkinInput._flatpickr.setDate(today);
                if (checkoutFP) checkoutFP.setDate(tomorrow);

            } else {
                // --- RESERVATION: SHOW FIELD ---
                if (arrivalContainer) arrivalContainer.style.display = 'block';
                if (arrivalSelect) arrivalSelect.setAttribute('required', 'true');

                document.getElementById('abModalTitle').innerText = "New Reservation";
            }

            // 4. Show Modal
            document.getElementById('addBookingModal').style.display = 'block';

            // 🔴 CRITICAL FIX IS HERE: Close the correct wrapper ID
            const wrapper = document.getElementById('addBookingWrapper');
            if (wrapper) {
                wrapper.classList.remove('open'); // Rotates the arrow back
                const options = wrapper.querySelector('.custom-options');
                if (options) options.classList.remove('open'); // Hides the menu
            }
        }

        // --- HELPER: Handle Walk-in vs Reservation Logic ---
        function handleBookingTypeChange() {
            const typeSelect = document.getElementById('bookingSourceSelect');

            // Safety check: if the select ID is missing, stop to prevent errors
            if (!typeSelect) return;

            const type = typeSelect.value;
            const checkinInput = document.getElementById('checkin_picker');
            const checkoutInput = document.getElementById('checkout_picker');

            // Get Flatpickr instances
            const checkinFP = checkinInput && checkinInput._flatpickr;
            const checkoutFP = checkoutInput && checkoutInput._flatpickr;

            if (type === 'walk-in') {
                // --- WALK-IN LOGIC ---
                // 1. Set Check-in date to TODAY immediately
                const today = new Date();
                if (checkinFP) {
                    checkinFP.set('minDate', 'today'); // Ensure walk-in can always check-in today
                    checkinFP.setDate(today);
                }

                // 2. Default Check-out to TOMORROW
                const tomorrow = new Date();
                tomorrow.setDate(today.getDate() + 1);
                if (checkoutFP) checkoutFP.setDate(tomorrow);

            } else {
                // --- RESERVATION LOGIC ---
                // 🕒 8 PM RULE: For Reservations, disable 'today' if past 8 PM
                const now = new Date();
                if (now.getHours() >= 20) {
                    const tomorrow = new Date();
                    tomorrow.setDate(now.getDate() + 1);
                    if (checkinFP) {
                        checkinFP.set('minDate', tomorrow);
                        checkinFP.clear();
                    }
                } else {
                    if (checkinFP) checkinFP.set('minDate', 'today');
                }
            }
        }

        // --- HELPER: Inject New Booking Row into Table ---
        function addBookingRowToTable(newId, newRef, payload) {
            // 1. 🔴 ADD THIS MISSING LINE HERE:
            const nowCreated = new Date().toISOString().slice(0, 19).replace('T', ' ');
            const tbody = document.getElementById('bookingTableBody');

            // 1. Remove "No Data" message if visible
            const noDataMsg = document.getElementById('noDataMessage');
            if (noDataMsg) noDataMsg.style.display = 'none';

            // 2. Format Data for Display
            const guestName = `${payload.guest.firstname} ${payload.guest.lastname}`;
            const roomNames = payload.rooms.map(r => r.name).join(', ');

            // Date Formatting
            const fmtDate = (dStr) => new Date(dStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            const dateRange = `${fmtDate(payload.dates.checkin)} - ${fmtDate(payload.dates.checkout)}`;

            // Time Formatting
            let timeDisplay = "14:00";
            if (payload.guest.arrival_time) {
                const [h, m] = payload.guest.arrival_time.split(':');
                const dateObj = new Date();
                dateObj.setHours(h, m);
                timeDisplay = dateObj.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            }

            // Source Icon & Color
            let sourceHtml = '';
            if (payload.bookingSource === 'walk-in') {
                sourceHtml = `<div class="source-tag source-walkin"><span>🚶</span> WALK-IN</div>`;
            } else {
                sourceHtml = `<div class="source-tag source-online"><span>📅</span> RESERVATION</div>`;
            }

            // Arrival Badge
            let badgeHtml = '';
            if (payload.arrivalStatus === 'in_house') {
                badgeHtml = `<div class="arrival-badge arrival-inhouse">In House</div>`;
            } else {
                // Check if arriving today
                const today = new Date().toISOString().split('T')[0];
                if (payload.dates.checkin === today) {
                    badgeHtml = `<div class="arrival-badge arrival-today">Arriving Today</div>`;
                } else {
                    badgeHtml = `<div class="arrival-badge arrival-upcoming">Upcoming</div>`;
                }
            }

            // Payment Status
            let paymentHtml = '';
            if (payload.paymentStatus === 'paid') {
                paymentHtml = `<span style="color:#10B981; font-weight:700; font-size:0.8rem;">Fully Paid</span>`;
            } else if (payload.paymentStatus === 'partial') {
                const bal = payload.totalPrice - payload.amountPaid;
                paymentHtml = `
                <div style="font-size:0.75rem; color:#F59E0B; font-weight:600;">Paid: ₱${payload.amountPaid.toLocaleString()}</div>
                <div style="font-size:0.75rem; color:#EF4444; font-weight:600;">Bal: ₱${bal.toLocaleString()}</div>
            `;
            } else {
                paymentHtml = `<span style="color:#EF4444; font-weight:600; font-size:0.8rem;">Unpaid</span>`;
            }

            // 3. Create the Row HTML
            const row = document.createElement('tr');
            row.className = 'booking-row';
            row.id = 'row-' + newId;
            // Set attributes for filtering logic
            row.setAttribute('data-status', 'confirmed');
            row.setAttribute('data-checkin', payload.dates.checkin);
            row.setAttribute('data-checkout', payload.dates.checkout);
            row.setAttribute('data-arrival', payload.arrivalStatus);
            row.setAttribute('data-created', nowCreated);

            row.innerHTML = `
            <td><strong>${newRef}</strong></td>
            <td>
                <div style="font-weight:600; font-size:0.9rem;">${guestName}</div>
                <div class="fs-xxs" style="color:#888;">ID: ${newId}</div>
            </td>
            <td>${sourceHtml}</td>
            <td>${badgeHtml}</td>
            <td>
                <div style="font-weight:600; color:#555; font-size:0.9rem;">
                    <i class="far fa-clock" style="color:#888; margin-right:4px;"></i> ${timeDisplay}
                </div>
            </td>
            <td>${roomNames}</td>
            <td>${dateRange}</td>
            <td>$${payload.totalPrice.toLocaleString()}</td>
            <td>${paymentHtml}</td>
            <td>
                 <button class="btn-secondary" style="padding:5px 10px; font-size:0.8rem;" 
                    onclick="openBookingAction(
                        '${newId}', 
                        '${guestName.replace(/'/g, "\\'")}', 
                        '${newRef}', 
                        '${roomNames.replace(/'/g, "\\'")}', 
                        '${payload.dates.checkin}', 
                        '${payload.dates.checkout}', 
                        '${payload.totalPrice}', 
                        '${payload.arrivalStatus}', 
                        '${payload.amountPaid}', 
                        '${payload.arrivalStatus === 'in_house' ? 'In House' : 'Upcoming'}',
                        '${new Date().toISOString().split('T')[0]}',
                        '${payload.bookingSource}',
                        '${payload.rooms[0].price}',
                        '${(payload.guest.requests || "").replace(/'/g, "\\'").replace(/\n/g, " ")}'
                    )">View</button>
            </td>
        `;

            // 4. Insert at the TOP of the table
            if (tbody.firstChild) {
                tbody.insertBefore(row, tbody.firstChild);
            } else {
                tbody.appendChild(row);
            }

            // 5. Refresh from server for pagination and sorting
            refreshBookingTable(true);
        }

        // --- HELPER 1: Add New Room Row ---
        function addRoomRowToTable(room) {
            const tbody = document.getElementById('roomTableBody');

            // Remove "No rooms" message if exists
            // (You might not have one, but good practice)

            const row = document.createElement('tr');
            row.id = 'room-row-' + room.id;
            row.style.verticalAlign = 'middle';

            // Default placeholder if image is empty or base64 empty
            let displayImage = room.imageSrc;
            if (!displayImage || displayImage.includes('data:application/octet-stream') || displayImage === window.location.href) {
                displayImage = "data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3QgZmlsbD0iI2RkZCIgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJhcmlhbCIgZm9udC1zaXplPSIxMiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzU1NSI+Tm8gSW1hZ2U8L3RleHQ+PC9zdmc+";
            }

            // Safe strings for onclick
            const safeName = room.name.replace(/'/g, "\\'");
            const safeBed = room.bed.replace(/'/g, "\\'");
            const safeDesc = room.desc.replace(/'/g, "\\'").replace(/\n/g, " ");
            const safeSize = room.size.replace(/'/g, "\\'");

            // Note: For 'file_path', newly added rooms won't have the server filename available immediately 
            // without a reload unless PHP returns it. We pass '' for now to prevent broken image links on next edit.

            row.innerHTML = `
            <td style="font-weight: 600; color: #888;">${room.id}</td>
            <td>
                <div style="width: 120px; height: 80px; background:#eee; border-radius:6px; overflow:hidden; border:1px solid #ddd;">
                    <img src="${displayImage}" style="width:100%; height:100%; object-fit:cover;">
                </div>
            </td>
            <td>
                <div class="room-name" style="font-weight: 600; font-size: 1rem; color: #333;">${room.name}</div>
            </td>
            <td>
                <span class="room-bed" style="background: #fff; padding: 4px 10px; border-radius: 4px; border:1px solid #eee; font-size: 0.85rem; font-weight: 500; color: #555;">
                    ${room.bed}
                </span>
            </td>
            <td class="room-price" style="font-weight: 700; color: #333;">₱${parseFloat(room.price).toLocaleString()}</td>
            <td>
                <button class="btn-secondary" style="padding:6px 12px; margin-right: 5px;" 
                    onclick="openEditRoomModal(
                        '${room.id}', 
                        '${safeName}', 
                        '${room.price}', 
                        '${safeBed}', 
                        '${room.capacity}', 
                        '${safeSize}', 
                        '${safeDesc}', 
                        '', 
                        false
                    )">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <button class="btn-secondary" style="padding:6px 12px; color:#555; border-color: #FECACA; background: #FEF2F2;" 
                    onclick="deleteRoom('${room.id}')">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;

            // Prepend to top
            tbody.insertBefore(row, tbody.firstChild);
        }

        // --- HELPER 2: Update Existing Room Row (Crash-Proof Version) ---
        function updateRoomRowInTable(room) {
            // 1. Find the row by ID
            let row = document.getElementById('room-row-' + room.id);

            // Fallback: If row doesn't have ID, scan the first column
            if (!row) {
                const rows = document.querySelectorAll('#roomTableBody tr');
                rows.forEach(r => {
                    if (r.cells[0] && r.cells[0].innerText.trim() == room.id) {
                        row = r;
                    }
                });
            }

            if (row) {
                // 2. Update Image (Cell 1)
                if (room.imageSrc && !room.imageSrc.includes('placeholder')) {
                    const img = row.cells[1].querySelector('img');
                    if (img) img.src = room.imageSrc;
                }

                // 3. Update Name (Cell 2) - SAFETY CHECK ADDED
                // We look for a div with class 'room-name', or fallback to the cell itself
                const nameDiv = row.querySelector('.room-name') || row.cells[2].querySelector('div');

                if (nameDiv) {
                    // Keep the "ARCHIVED" badge if it exists
                    const badge = nameDiv.querySelector('span'); // Save badge
                    nameDiv.innerText = room.name; // Update text
                    if (badge) nameDiv.appendChild(badge); // Put badge back
                } else {
                    // Last resort: just set the cell text (removes badge if structural mismatch)
                    row.cells[2].innerText = room.name;
                }

                // 4. Update Bed Type (Cell 3)
                const bedSpan = row.querySelector('.room-bed') || row.cells[3].querySelector('span');
                if (bedSpan) {
                    bedSpan.innerText = room.bed;
                } else {
                    row.cells[3].innerText = room.bed;
                }

                // 5. Update Price (Cell 4)
                const priceCell = row.querySelector('.room-price') || row.cells[4];
                if (priceCell) {
                    priceCell.innerText = '₱' + parseFloat(room.price).toLocaleString();
                }

                // 6. Update the "Edit" button onclick data
                const actionCell = row.cells[5];
                const editBtn = actionCell.querySelector('button:first-child');

                if (editBtn) {
                    const safeName = room.name.replace(/'/g, "\\'");
                    const safeBed = room.bed.replace(/'/g, "\\'");
                    // Remove newlines to prevent JS errors
                    const safeDesc = room.desc.replace(/'/g, "\\'").replace(/(\r\n|\n|\r)/gm, " ");
                    const safeSize = room.size.replace(/'/g, "\\'");

                    // We pass '' for filePath because we don't have the new server path yet
                    // passing false for isBooked
                    editBtn.setAttribute('onclick', `openEditRoomModal('${room.id}', '${safeName}', '${room.price}', '${safeBed}', '${room.capacity}', '${safeSize}', '${safeDesc}', '', false)`);
                }
            } else {
                console.warn("Could not find row for room ID: " + room.id);
            }
        }

        // --- GUEST EDIT LOGIC ---

        // 1. Toggle between View and Edit
        function toggleGuestEdit(isEditing) {
            const viewMode = document.getElementById('gp_view_mode');
            const editMode = document.getElementById('gp_edit_mode');

            if (isEditing) {
                // Switch to Edit Mode
                viewMode.style.display = 'none';
                editMode.style.display = 'block';

                // Populate Input Fields from current text
                // Note: We need the raw data. Ideally, we save the data object globally when we fetched it.
                // Let's grab it from the DOM for now, but cleaner is to use 'window.currentGuestData'

                if (window.currentGuestData) {
                    const info = window.currentGuestData.info;
                    document.getElementById('edit_fname').value = info.first_name;
                    document.getElementById('edit_lname').value = info.last_name;
                    document.getElementById('edit_email').value = info.email;
                    document.getElementById('edit_original_email').value = info.email;
                    document.getElementById('edit_phone').value = info.phone;
                    document.getElementById('edit_nation').value = info.nationality;
                    document.getElementById('edit_gender').value = info.gender;
                    document.getElementById('edit_dob').value = info.birthdate;
                    document.getElementById('edit_address').value = info.address;
                }

            } else {
                // Switch back to View Mode
                viewMode.style.display = 'block';
                editMode.style.display = 'none';
            }
        }

        // 2. Save Changes (Seamless - No Reload)
        function saveGuestProfile(event) {
            event.preventDefault(); // Stop the form from submitting normally

            const form = document.getElementById('guestEditForm');
            const formData = new FormData(form);
            formData.append('csrf_token', csrfToken);

            const btn = form.querySelector('button[type="submit"]');
            const originalText = btn.innerText;
            btn.innerText = "Saving...";
            btn.disabled = true;

            fetch('update_guest_profile.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert("Guest profile updated!");

                        // A. Update the Global Data Object so we don't need to re-fetch
                        if (window.currentGuestData) {
                            const info = window.currentGuestData.info;
                            // Update values in memory
                            info.first_name = formData.get('firstname');
                            info.last_name = formData.get('lastname');
                            info.phone = formData.get('phone');
                            info.nationality = formData.get('nationality');
                            info.gender = formData.get('gender');
                            info.birthdate = formData.get('birthdate');
                            info.address = formData.get('address');

                            // B. Update the "View Mode" Text on the screen immediately
                            const salutation = info.salutation ? info.salutation : '';
                            document.getElementById('gp_name').innerText = `${salutation} ${info.first_name} ${info.last_name}`;
                            document.getElementById('gp_phone').innerText = info.phone;
                            document.getElementById('gp_nation').innerText = info.nationality;
                            document.getElementById('gp_gender').innerText = info.gender;
                            document.getElementById('gp_dob').innerText = info.birthdate;
                            document.getElementById('gp_address').innerText = info.address;

                            // Note: Email is read-only in this form, so we don't update gp_email here
                        }

                        // C. Switch back to view mode (Seamlessly)
                        toggleGuestEdit(false);

                        // ❌ DELETED: location.reload(); 

                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        }

        // --- ENABLE ADDRESS SEARCH FOR GUEST EDIT ---
        function setupEditAddressSearch() {
            const input = document.getElementById('edit_address');
            const loader = document.getElementById('editAddrLoader');
            const results = document.getElementById('editAddrResults');
            let debounceTimer;

            if (!input) return;

            input.addEventListener('input', function () {
                const query = this.value.trim();
                clearTimeout(debounceTimer);

                if (query.length < 3) {
                    results.style.display = 'none';
                    return;
                }

                // Wait 600ms before searching
                debounceTimer = setTimeout(() => {
                    loader.style.display = 'block';

                    fetch(`search_address.php?q=${encodeURIComponent(query)}`)
                        .then(res => res.json())
                        .then(data => {
                            loader.style.display = 'none';
                            results.innerHTML = '';

                            if (data.length === 0) {
                                results.style.display = 'none';
                                return;
                            }

                            data.forEach(place => {
                                const div = document.createElement('div');
                                div.className = 'address-result-item';
                                div.innerText = place.display_name;
                                div.onclick = function () {
                                    input.value = place.display_name;
                                    results.style.display = 'none';
                                };
                                results.appendChild(div);
                            });

                            results.style.display = 'block';
                        })
                        .catch(err => {
                            console.error(err);
                            loader.style.display = 'none';
                        });
                }, 600);
            });

            // Hide dropdown if clicked outside
            document.addEventListener('click', function (e) {
                if (e.target !== input && e.target !== results) {
                    results.style.display = 'none';
                }
            });
        }

        // --- ACTIVATE IT ON LOAD ---
        document.addEventListener("DOMContentLoaded", function () {

            const today = new Date().toISOString().split('T')[0];
            if (localStorage.getItem('lateAlertDismissed_' + today)) {
                const alertCard = document.getElementById('lateArrivalAlert');
                if (alertCard) alertCard.style.display = 'none';
            }
            // ... your other init codes ...
            setupEditAddressSearch(); // <--- CALL THE FUNCTION HERE
        });

        // Function to handle the "View Bookings" button on the alert card
        // Function to handle the "View Bookings" button on the alert card
        // Function to handle the "View Bookings" button on the alert card
        function goToBookingsTab(filterType) {
            // 1. Mark as dismissed in Local Storage for TODAY
            const today = new Date().toISOString().split('T')[0];
            localStorage.setItem('lateAlertDismissed_' + today, 'true');

            // 2. Hide visually
            const alertCard = document.getElementById('lateArrivalAlert');
            if (alertCard) alertCard.style.display = 'none';

            // 3. Existing navigation logic
            const bookingNav = document.querySelector('.nav-item[data-page="bookings"]');
            if (bookingNav) bookingNav.click();

            setTimeout(() => {
                filterTable(filterType || 'late');
            }, 300);
        }
        // Function to open the Header Notification Dropdown
        function openNotificationPanel(event) {
            if (event) event.stopPropagation();

            // 1. Mark as read in Database
            fetch('mark_as_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: 'notification_all' })
            });

            // 2. Hide the visual alert card
            const alertBox = document.getElementById('newNotificationAlert');
            if (alertBox) alertBox.style.display = 'none';

            // 3. Open the dropdown correctly
            const notifDropdown = document.getElementById('notifDropdown'); // ✅ Correct ID
            if (notifDropdown) {
                // Close other dropdowns first (like messages)
                document.querySelectorAll('.dropdown-menu').forEach(dd => dd.classList.remove('show'));

                // Show this dropdown
                notifDropdown.classList.add('show'); // ✅ Correct Class
            }
        }

        // --- ADMIN PROFILE LOGIC ---

        // ADMIN/PHP/dashboard.php -> Find the loadAdminProfile function

        // --- UPDATED ADMIN PROFILE LOADER ---
        function loadAdminProfile() {
            console.log("1. Starting Profile Load...");

            // Set loading placeholders
            document.getElementById('disp_username').innerText = "Loading...";
            document.getElementById('disp_wifi_ssid').innerText = "...";
            document.getElementById('disp_wifi_pass').innerText = "...";

            // Add timestamp to prevent caching
            const url = 'get_admin_details.php?t=' + new Date().getTime();

            fetch(url)
                .then(res => {
                    if (!res.ok) { throw new Error("HTTP Status: " + res.status); }
                    return res.json();
                })
                .then(data => {
                    if (data.status === 'success') {
                        const u = data.data;

                        // 1. UPDATE VIEW MODE (Display Cards)
                        document.getElementById('disp_username').innerText = u.name || 'No Name Set';
                        document.getElementById('disp_email').innerText = u.email;
                        document.getElementById('disp_contact').innerText = u.contact_number || 'No contact info';

                        // 🟢 Update Wi-Fi Display
                        document.getElementById('disp_wifi_ssid').innerText = u.ssid || 'Not Set';
                        document.getElementById('disp_wifi_pass').innerText = u.wifi_password || 'Not Set';

                        // 2. PRE-FILL EDIT FORM INPUTS
                        if (document.getElementById('edit_username')) document.getElementById('edit_username').value = u.name;
                        if (document.getElementById('edit_admin_email')) document.getElementById('edit_admin_email').value = u.email;
                        if (document.getElementById('edit_contact')) document.getElementById('edit_contact').value = u.contact_number || '';

                        // 🟢 Update Wi-Fi Inputs
                        if (document.getElementById('edit_wifi_ssid')) document.getElementById('edit_wifi_ssid').value = u.ssid || '';
                        if (document.getElementById('edit_wifi_pass')) document.getElementById('edit_wifi_pass').value = u.wifi_password || '';

                    } else {
                        console.warn("PHP returned error:", data.message);
                        if (data.message && data.message.toLowerCase().includes("session")) {
                            alert("Session expired. Please login again.");
                            window.location.href = "login.php";
                        } else {
                            document.getElementById('disp_username').innerHTML = `<span style="color:red; font-size:0.8rem;">${data.message}</span>`;
                        }
                    }
                })
                .catch(err => {
                    console.error("Critical Fetch Error:", err);
                    document.getElementById('disp_username').innerHTML = `<span style="color:red; font-size:0.8rem;">JS Error. Check Console.</span>`;
                });
        }

        // 2. Toggle Edit Mode (Updated to use Modal)
        function toggleAdminEdit(showEdit) {
            const modal = document.getElementById('adminEditModal');

            if (showEdit) {
                // Show the modal
                modal.style.display = 'block';
            } else {
                // Hide the modal
                modal.style.display = 'none';
                // Optional: Reset form fields if cancelled
                // document.getElementById('adminEditForm').reset(); 
            }
        }

        // 3. Save Admin Profile
        function saveAdminProfile(e) {
            e.preventDefault();

            const form = document.getElementById('adminEditForm');
            const formData = new FormData(form);
            formData.append('csrf_token', csrfToken);

            const btn = form.querySelector('button[type="submit"]');
            const oldText = btn.innerText;
            btn.innerText = "Saving...";
            btn.disabled = true;

            fetch('update_admin_profile.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert("Profile Updated Successfully!");
                        loadAdminProfile(); // Refresh Data
                        toggleAdminEdit(false); // Go back to view
                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                })
                .finally(() => {
                    btn.innerText = oldText;
                    btn.disabled = false;
                });
        }

        // 4. Attach to the sidebar click to ensure data is fresh
        document.addEventListener("DOMContentLoaded", function () {
            const profileCard = document.querySelector('.tree-item-card[onclick*="view-profile"]');
            if (profileCard) {
                profileCard.addEventListener('click', () => {
                    loadAdminProfile();
                    loadPaymentSettings(); // 🟢 ADD THIS LINE
                });
            }
            // Load once on startup
            loadAdminProfile();
            loadPaymentSettings(); // 🟢 ADD THIS LINE
        });

        // --- REAL-TIME GUEST LIST ---
        let isGuestSearchActive = false; // Flag to pause updates if user is typing
        let guestOffset = 0;
        const guestLimit = 10;

        function fetchGuestList(isSilent = false) {
            const searchInput = document.getElementById('guestSearchInput');
            const search = searchInput ? searchInput.value.trim() : '';

            // 🟢 AUTO-REFRESH: Only run if NOT searching OR if explicitly told to (silent)
            if (!isSilent && search !== "") {
                return;
            }

            const url = `get_all_guests.php?limit=${guestLimit}&offset=${guestOffset}&search=${encodeURIComponent(search)}`;

            fetch(url)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        renderGuestTable(data.data);
                        updateGuestPaginationUI(data.total, data.limit, data.offset);
                    }
                })
                .catch(err => console.error("Guest Fetch Error:", err));
        }

        function updateGuestPaginationUI(total, limit, offset) {
            const start = total === 0 ? 0 : offset + 1;
            const end = Math.min(offset + limit, total);
            const currentPage = Math.floor(offset / limit) + 1;
            const totalPages = Math.ceil(total / limit);

            document.getElementById('guestPageStart').innerText = start;
            document.getElementById('guestPageEnd').innerText = end;
            document.getElementById('guestTotalCount').innerText = total;

            const btnContainer = document.getElementById('guestPageButtons');
            if (!btnContainer) return;
            btnContainer.innerHTML = '';

            const addDots = () => {
                const span = document.createElement('span');
                span.className = 'pg-dots';
                span.innerText = '...';
                btnContainer.appendChild(span);
            };

            // Prev
            const prevBtn = document.createElement('button');
            prevBtn.className = 'pg-btn pg-btn-nav';
            prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i> Prev';
            prevBtn.disabled = (currentPage === 1);
            prevBtn.onclick = (e) => {
                e.preventDefault();
                guestOffset = Math.max(0, guestOffset - limit);
                fetchGuestList(true);
            };
            btnContainer.appendChild(prevBtn);

            // Pages
            let startPage = Math.max(1, currentPage - 1);
            let endPage = Math.min(totalPages, startPage + 2);
            if (endPage - startPage < 2) startPage = Math.max(1, endPage - 2);

            if (startPage > 1) {
                btnContainer.appendChild(createGuestPageBtn(1, limit, 1 === currentPage));
                if (startPage > 2) addDots();
            }

            for (let i = startPage; i <= endPage; i++) {
                if (i > 0) btnContainer.appendChild(createGuestPageBtn(i, limit, i === currentPage));
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) addDots();
                btnContainer.appendChild(createGuestPageBtn(totalPages, limit, totalPages === currentPage));
            }

            // Next
            const nextBtn = document.createElement('button');
            nextBtn.className = 'pg-btn pg-btn-nav';
            nextBtn.innerHTML = 'Next <i class="fas fa-chevron-right"></i>';
            nextBtn.disabled = (currentPage === totalPages || total === 0);
            nextBtn.onclick = (e) => {
                e.preventDefault();
                guestOffset += limit;
                fetchGuestList(true);
            };
            btnContainer.appendChild(nextBtn);
        }

        function createGuestPageBtn(page, limit, isActive) {
            const btn = document.createElement('button');
            btn.className = 'pg-btn' + (isActive ? ' active' : '');
            btn.innerText = page;
            btn.onclick = (e) => {
                e.preventDefault();
                guestOffset = (page - 1) * limit;
                fetchGuestList(true);
            };
            return btn;
        }

        function renderGuestTable(guests) {
            const tbody = document.getElementById('guestTableBody');
            if (!tbody) return;

            // Save current scroll position (optional polish)
            // const scrollPos = tbody.parentElement.scrollTop;

            let html = '';

            if (guests.length === 0) {
                html = `<tr><td colspan="6" class="text-center" style="padding: 30px; color: #888;">No guests found yet.</td></tr>`;
            } else {
                guests.forEach(g => {
                    const fullName = `${g.first_name || ''} ${g.last_name || ''}`.trim();
                    const email = g.email || '';
                    // Escape strings for safety in onclick
                    const safeEmail = email.replace(/'/g, "\\'");

                    html += `
    <tr class="guest-row">
        <td><div style="font-weight:600; color:#333;">${fullName}</div></td>
        <td>${email}</td>
        <td>${g.phone || ''}</td>
        <td>${g.nationality || ''}</td>
       <td style="text-align: center;">
            <span class="badge" style="background:#e0f2fe; color:#0284c7; font-size:0.9rem; font-weight:700; padding: 6px 15px; min-width: 40px; border-radius: 6px;">
                ${g.total_stays}
            </span>
        </td>
        <td style="text-align: center;">
            <span class="badge" style="background:#FFF7ED; color:#C2410C; font-size:0.9rem; font-weight:700; padding: 6px 15px; min-width: 40px; border-radius: 6px;">
                ${g.total_orders}
            </span>
        </td>
        <td>
            <button class="btn-secondary" style="padding: 5px 12px; font-size: 0.8rem;" onclick="openGuestProfile('${safeEmail}')">
                View Profile
            </button>
        </td>
    </tr>`;
                });
            }

            tbody.innerHTML = html;
        }

        document.addEventListener("DOMContentLoaded", function () {

            // Check if the element exists first to avoid errors
            if (document.getElementById('termsQuillEditor')) {

                // Initialize Quill for Terms & Conditions
                var termsQuill = new Quill('#termsQuillEditor', {
                    theme: 'snow',
                    placeholder: 'Enter terms and conditions here...',
                    modules: {
                        toolbar: [
                            [{ 'header': [1, 2, 3, false] }], // Custom headers
                            ['bold', 'italic', 'underline'],
                            [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                            ['link', 'clean']
                        ]
                    }
                });

                // OPTIONAL: Auto-sync to hidden input if you have a form submission for this
                // termsQuill.on('text-change', function() {
                //     document.getElementById('termsHiddenInput').value = termsQuill.root.innerHTML;
                // });
            }

        });

        // --- POLICY BUILDER ENGINE (JSON VERSION) ---

        // 1. Initialize on Load
        document.addEventListener("DOMContentLoaded", function () {
            const rawData = document.getElementById('rawDatabaseContent').value.trim();
            const container = document.getElementById('policy-builder-container');

            // Clear container just in case
            container.innerHTML = '';

            try {
                // A. Attempt to parse the data as JSON (The New Way)
                const policies = JSON.parse(rawData);

                if (Array.isArray(policies) && policies.length > 0) {
                    // Success! We have structured data. Create a card for each policy.
                    policies.forEach(policy => {
                        addPolicySection(policy.title, policy.content);
                    });
                } else {
                    // JSON is valid but empty
                    addPolicySection();
                }

            } catch (e) {
                // B. Fallback: Data is not JSON (It's the Old HTML Format)
                // We load it into one card so you don't lose your data.
                // You can split it up manually one last time.
                if (rawData === "") {
                    addPolicySection();
                } else {
                    addPolicySection("General Policies", rawData);
                }
            }
        });

        // 2. Function to Add a New Section (Card) - QUILL VERSION
        function addPolicySection(initialTitle = "", initialContent = "") {
            const container = document.getElementById('policy-builder-container');

            // Generate a unique ID for this specific editor instance
            const uniqueId = "policy_quill_" + new Date().getTime() + Math.floor(Math.random() * 1000);

            // Create the HTML for the card
            const card = document.createElement('div');
            card.className = 'policy-card';
            card.innerHTML = `
        <div class="policy-header">
            <input type="text" class="policy-title-input" placeholder="Enter Policy Title (e.g. Booking Rules)" value="${initialTitle.replace(/"/g, '&quot;')}">
            <button class="btn-delete-policy" onclick="deletePolicySection(this)">
                <i class="fas fa-trash-alt"></i> Delete
            </button>
        </div>
        
        <input type="hidden" class="policy-hidden-content" value='${initialContent.replace(/'/g, "&#39;")}'>

        <div id="${uniqueId}" style="height: 300px; background: white;"></div>
    `;

            container.appendChild(card);

            // Initialize Quill on the unique ID we just created
            var quill = new Quill('#' + uniqueId, {
                theme: 'snow',
                placeholder: 'Enter policy details here...',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline'],
                        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                        ['link', 'clean']
                    ]
                }
            });

            // If there is existing content (from database), load it into Quill
            if (initialContent) {
                quill.clipboard.dangerouslyPasteHTML(initialContent);
            }

            // AUTO-SYNC: Whenever text changes in Quill, update the hidden input
            quill.on('text-change', function () {
                const html = quill.root.innerHTML;
                card.querySelector('.policy-hidden-content').value = html;
            });

            // Enter Key Logic (Jump from Title to Editor)
            const titleInput = card.querySelector('.policy-title-input');
            titleInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    quill.focus(); // Focus the Quill editor
                }
            });
        }

        // 3. Function to Delete a Section - QUILL VERSION
        function deletePolicySection(btn) {
            if (!confirm("Are you sure you want to delete this entire policy section?")) return;

            const card = btn.closest('.policy-card');

            // With Quill, removing the element from the DOM is sufficient.
            if (card) {
                card.remove();
                if (typeof showSuccess === 'function') {
                    showSuccess("Policy section deleted.");
                } else {
                    alert("Policy section deleted.");
                }
            }
        }

        // 4. Master Save Function (Saves as JSON)
        function saveTerms() {
            const btn = document.querySelector('#view-terms .ab-submit-btn');
            const originalText = btn.innerText;

            btn.innerText = "Processing...";
            btn.disabled = true;

            let policies = [];

            // 🟢 FIX: Select ONLY cards inside the Terms & Conditions container
            const cards = document.querySelectorAll('#policy-builder-container .policy-card');

            cards.forEach(card => {
                const title = card.querySelector('.policy-title-input').value.trim();
                const content = card.querySelector('.policy-hidden-content').value;

                if (title || content) {
                    policies.push({
                        title: title,
                        content: content
                    });
                }
            });

            const jsonString = JSON.stringify(policies);
            const formData = new FormData();
            formData.append('terms_content', jsonString);
            if (typeof csrfToken !== 'undefined') formData.append('csrf_token', csrfToken);

            fetch('update_terms.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert("Terms & Conditions updated successfully!");
                        document.getElementById('rawDatabaseContent').value = jsonString;
                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        }

        // --- AUTOMATED DAILY REMINDER TRIGGER (SMART CATCH-UP) ---

        function checkAutoReminders() {
            const now = new Date();
            const hours = now.getHours(); // 0-23

            // 1. Set your Target Hour (9 = 9:00 AM)
            const targetHour = 9;

            // 2. Create a unique key for TODAY (e.g., "reminder_sent_2026-01-05")
            // We use local time string to ensure it matches your computer's date correctly
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const todayStr = `${year}-${month}-${day}`;

            const storageKey = 'reminder_sent_' + todayStr;

            // 3. THE LOGIC CHANGE:
            // IF the current hour is 9 or later...
            // AND we haven't marked it as "done" for today...
            if (hours >= targetHour && !localStorage.getItem(storageKey)) {

                console.log(`⏰ It's after ${targetHour}:00 AM and reminders haven't run. Triggering now...`);

                // Lock it immediately to prevent double-firing if multiple tabs open at once
                localStorage.setItem(storageKey, 'true');

                // 4. Call the PHP Script
                fetch('send_reminders.php')
                    .then(res => res.text())
                    .then(data => {
                        console.log("✅ Auto-Process Result:", data);
                        // Optional: alert("Missed task: Daily Reminders have been sent!"); 
                    })
                    .catch(err => {
                        console.error("❌ Auto-Process Failed:", err);
                        // If it fails, unlock it so it tries again next check
                        localStorage.removeItem(storageKey);
                    });
            }
        }

        // --- GALLERY UPLOAD FUNCTIONS (Must be global) ---

        // 1. Triggers the hidden file input when you click the box
        function triggerGalleryUpload(index) {
            const fileInput = document.getElementById('file_' + index);
            if (fileInput) {
                fileInput.click();
            } else {
                console.error("File input not found for index: " + index);
            }
        }

        // 2. Shows the image immediately after selecting it
        function previewGalleryImage(input, index) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    // Safe selection with checks
                    const preview = document.getElementById('preview_' + index);
                    const placeholder = document.getElementById('placeholder_' + index);

                    if (preview) {
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                    }
                    if (placeholder) {
                        placeholder.style.display = 'none';
                    }
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Update this function in your JS
        function refreshCalendarData() {
            const calendarPage = document.getElementById('calendar');
            if (!calendarPage.classList.contains('active')) return;

            // Get current view params
            const m = viewDate.getMonth() + 1;
            const y = viewDate.getFullYear();

            // Pass them to the AJAX call
            fetch(`get_calendar_data.php?month=${m}&year=${y}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        bookingsDB = data.bookings;
                        if (data.rooms) allRoomsList = data.rooms;
                        renderRealtimeCalendar();
                        console.log("Calendar synced.");
                    }
                })
                .catch(err => console.error("Calendar Sync Error:", err));
        }

        // --- DELETE ROOM (Soft Delete) ---
        function deleteRoom(id) {
            if (!confirm("Are you sure you want to archive this room? It will be hidden from the active list.")) return;

            const formData = new FormData();
            formData.append('action', 'delete'); // This triggers the soft-delete in your PHP
            formData.append('room_id', id);

            fetch('manage_rooms.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        location.reload(); // Reload to update the lists
                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error: Could not connect to server.");
                });
        }

        // --- PERMANENT DELETE ROOM ---
        function permanentDeleteRoom(id) {
            // 1. Strong Warning
            if (!confirm("⚠️ CRITICAL WARNING: This will PERMANENTLY DELETE this room and its images.\n\nAny booking history associated with this room might be affected.\n\nAre you absolutely sure?")) return;

            const formData = new FormData();
            formData.append('action', 'hard_delete');
            formData.append('room_id', id);

            // Visual feedback
            const row = document.getElementById('room-row-' + id);
            if (row) row.style.opacity = '0.3';

            fetch('manage_rooms.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert("Room permanently deleted.");
                        // Remove row from DOM immediately
                        if (row) row.remove();
                    } else {
                        alert("Error: " + data.message);
                        // Revert visual if failed (likely due to foreign key constraint with existing bookings)
                        if (row) row.style.opacity = '1';
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                    if (row) row.style.opacity = '1';
                });
        }


        // --- RESCHEDULE LOGIC ---
        function rescheduleGuestBooking(reference, newStart, newEnd) {

            if (!confirm("Attempting to reschedule. The 3-day rule will be checked.")) return;

            fetch('guest_reschedule.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    booking_reference: reference, // e.g., "REF12345"
                    new_check_in: newStart,       // e.g., "2025-12-01"
                    new_check_out: newEnd         // e.g., "2025-12-05"
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'error') {
                        // This shows the 3-Day Rule Error
                        alert("❌ " + data.message);
                    } else {
                        alert("✅ " + data.message + "\nNew Price: ₱" + data.new_total);
                        location.reload();
                    }
                })
                .catch(err => console.error(err));
        }

        // --- RESCHEDULE LOGIC ---
        let reschedCheckinFP = null;
        let reschedCheckoutFP = null;
        let rescheduleInitialBody = null; // 🟢 To store original HTML

        function openRescheduleModal(reference) {
            const modal = document.getElementById('rescheduleModal');
            const body = modal.querySelector('.ab-modal-body');

            // 1. Save initial body only once
            if (!rescheduleInitialBody) {
                rescheduleInitialBody = body.innerHTML;
            } else {
                // 2. RESTORE initial body (Fixes the bug where selection screen persists)
                body.innerHTML = rescheduleInitialBody;
            }

            document.getElementById('resched_ref_id').value = reference;

            if (reschedCheckinFP) reschedCheckinFP.destroy();

            reschedCheckinFP = flatpickr("#resched_checkin", {
                mode: "range",
                minDate: "today",
                showMonths: 1,
                dateFormat: "Y-m-d",
                plugins: [new rangePlugin({ input: "#resched_checkout" })],
                static: false,
                appendTo: document.body,
                onReady: function (selectedDates, dateStr, instance) {
                    instance.calendarContainer.classList.add("compact-theme");
                    instance.calendarContainer.classList.remove("double-month-theme");
                }
            });

            modal.style.display = 'block';
        }

        function closeRescheduleModal() {
            document.getElementById('rescheduleModal').style.display = 'none';
            // Re-open main modal (Optional)
            // document.getElementById('bookingActionModal').style.display = 'block';
        }

        function submitReschedule(overrideRoomId = null) {
            const ref = document.getElementById('resched_ref_id').value;
            const newIn = document.getElementById('resched_checkin').value;
            const newOut = document.getElementById('resched_checkout').value;

            // Select the button based on context (Main modal vs Alternative view)
            let btn = document.querySelector('#rescheduleModal .ab-submit-btn');
            if (overrideRoomId) {
                // If we are confirming a new room, find the specific button inside the alternative view
                btn = document.getElementById('btn-confirm-alt');
            }

            if (!newIn || !newOut) {
                alert("Please select the new dates.");
                return;
            }

            if (!overrideRoomId && !confirm("Are you sure you want to reschedule?")) return;

            // 🟢 UI LOCKING (Locker System)
            toggleUILock(true, "PROCESSING RESCHEDULE...");

            // UI Loading State
            const originalText = btn.innerText;
            btn.innerText = "Checking...";
            btn.disabled = true;

            // Prepare Payload
            const payload = {
                booking_reference: ref,
                new_check_in: newIn,
                new_check_out: newOut
            };

            // Add Room ID if user selected an alternative
            if (overrideRoomId) {
                payload.new_room_id = overrideRoomId;
            }

            fetch('guest_reschedule.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert("✅ " + data.message + "\n\nNew Total Price: ₱" + parseFloat(data.new_total).toLocaleString());
                        location.reload();
                    }
                    else if (data.status === 'selection_required' || data.status === 'conflict') {
                        // --- UI MAGIC: SWITCH TO ROOM SELECTION VIEW ---
                        toggleUILock(false);
                        renderRescheduleAlternatives(data.message, data.next_date, data.alternatives, data.current_room_id);
                    }
                    else {
                        toggleUILock(false);
                        alert("❌ " + data.message);
                        btn.innerText = originalText;
                        btn.disabled = false;
                    }
                })
                .catch(err => {
                    toggleUILock(false);
                    console.error(err);
                    alert("System Error");
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        }

        let selectedAltRoomId = null;

        function renderRescheduleAlternatives(msg, nextDate, rooms, currentRoomId = null) {
            const modalBody = document.querySelector('#rescheduleModal .ab-modal-body');

            // Capture existing values before clearing the DOM
            const ref = document.getElementById('resched_ref_id').value;
            const newIn = document.getElementById('resched_checkin').value;
            const newOut = document.getElementById('resched_checkout').value;

            // 1. Build the HTML for the room list (Using your existing CSS classes)
            let roomsHtml = '';

            if (rooms.length > 0) {
                rooms.forEach(room => {
                    // Fix Image Path Logic (Same as your main table)
                    let imgUrl = '../../IMG/default_room.jpg';
                    if (room.image_path) {
                        const parts = room.image_path.split(',');
                        imgUrl = '../../room_includes/uploads/images/' + parts[0].trim();
                    }

                    const isCurrent = (room.id == currentRoomId);

                    roomsHtml += `
            <div class="room-card ${isCurrent ? 'selected' : ''}" onclick="selectAltRoom(this, ${room.id})" style="border:1px solid #ddd; margin-bottom:0; position:relative;">
                ${isCurrent ? '<div style="position:absolute; top:5px; left:5px; background:#10B981; color:#fff; font-size:10px; padding:2px 5px; border-radius:4px; z-index:5;">Current</div>' : ''}
                <div class="room-card-check"></div>
                <img src="${imgUrl}" class="room-card-image" style="height:120px;" onerror="this.src='../../IMG/default_room.jpg'">
                <div class="room-card-body" style="padding:10px;">
                    <div class="room-card-header" style="font-size:0.9rem;">${room.name}</div>
                    <div class="room-card-details">
                        <span class="detail-badge">👥 ${room.capacity}</span>
                        <span class="detail-badge">🛏️ ${room.bed_type}</span>
                    </div>
                    <div class="room-card-price" style="font-size:1rem;">₱${parseFloat(room.price).toLocaleString()}</div>
                </div>
            </div>`;
                });
            } else {
                roomsHtml = `<div style="grid-column:1/-1; text-align:center; padding:20px; background:#f9f9f9; border-radius:8px;">No other rooms available on these dates.</div>`;
            }

            // If current room was automatically selected, update global var
            if (currentRoomId) selectedAltRoomId = currentRoomId;

            // 2. Inject the New UI into the Modal
            modalBody.innerHTML = `
        <div style="background-color: #EFF6FF; color: #1E40AF; padding: 12px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 15px; border: 1px solid #DBEAFE;">
            <i class="fas fa-info-circle"></i> <strong>Room Availability</strong><br>
            ${msg}
            ${nextDate ? `<br><small>Busy until: <strong>${nextDate}</strong></small>` : ''}
        </div>

        <h4 style="margin:0 0 10px 0; font-size:0.9rem; color:#333;">Select a Room to Finalize:</h4>
        
        <div class="room-selection-grid" style="grid-template-columns: 1fr 1fr; gap: 10px; max-height: 250px; overflow-y: auto; padding: 2px;">
            ${roomsHtml}
        </div>

        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <span class="footer-heading" style="margin:0; font-size: 0.7rem; color: #999; text-transform: uppercase;">Dates Selection</span>
                <button type="button" onclick="openRescheduleModal('${ref}')" style="background: none; border: none; color: #B88E2F; font-size: 0.7rem; font-weight: 700; cursor: pointer; text-transform: uppercase;">
                    <i class="fas fa-edit"></i> Change
                </button>
            </div>
            <div class="ab-grid-2" style="gap: 10px;">
                <div class="ab-input-wrapper">
                    <input type="text" class="ab-input" value="${newIn}" readonly style="background-color: #F9FAFB; cursor: default; font-size: 0.8rem; padding: 10px 12px;">
                    <i class="far fa-calendar-alt ab-calendar-icon" style="color: #9CA3AF; font-size: 0.8rem;"></i>
                </div>
                <div class="ab-input-wrapper">
                    <input type="text" class="ab-input" value="${newOut}" readonly style="background-color: #F9FAFB; cursor: default; font-size: 0.8rem; padding: 10px 12px;">
                    <i class="far fa-calendar-alt ab-calendar-icon" style="color: #9CA3AF; font-size: 0.8rem;"></i>
                </div>
            </div>
        </div>

        <!-- Hidden inputs to persist state -->
        <input type="hidden" id="resched_ref_id" value="${ref}">
        <input type="hidden" id="resched_checkin" value="${newIn}">
        <input type="hidden" id="resched_checkout" value="${newOut}">

        <div class="ab-grid-footer" style="margin-top: 15px;">
            <button class="btn-secondary" onclick="location.reload()">Cancel</button>
            <button class="ab-submit-btn" id="btn-confirm-alt" onclick="confirmAltRoom()" ${!currentRoomId ? 'disabled style="opacity:0.5;"' : ''}>
                Confirm Reschedule
            </button>
        </div>
    `;
        }

        function selectAltRoom(card, id) {
            // Visual Select
            document.querySelectorAll('.room-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');

            // Logic Select
            selectedAltRoomId = id;

            // Enable Button
            const btn = document.getElementById('btn-confirm-alt');
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.innerText = "Confirm Room Change";
        }

        function confirmAltRoom() {
            if (!selectedAltRoomId) return;
            // Re-call the submit function, but pass the new room ID
            submitReschedule(selectedAltRoomId);
        }


        // --- REAL-TIME BOOKING TABLE ---
        let isTableUpdating = false;

        function refreshBookingTable(isSilent = false) {
            const searchInput = document.getElementById('bookingSearchInput');
            const search = searchInput ? searchInput.value.trim() : '';

            // 🟢 AUTO-REFRESH: Only run if NOT searching OR if explicitly told to (silent)
            if (!isSilent && search !== "") {
                return;
            }

            const url = `fetch_booking_table.php?limit=${bookingLimit}&offset=${bookingOffset}&search=${encodeURIComponent(search)}&filter=${currentTabStatus}&t=${new Date().getTime()}`;

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        const tbody = document.getElementById('bookingTableBody');
                        if (!tbody) return;

                        // 1. Update Table Body
                        tbody.innerHTML = data.html;

                        // 2. Update Pagination UI
                        updateBookingPaginationUI(data.total, data.limit, data.offset);
                    }
                })
                .catch(err => console.error("Table refresh error:", err));
        }

        function updateBookingPaginationUI(total, limit, offset) {
            const container = document.getElementById('bookingPagination');
            if (container) {
                container.style.display = (total > limit) ? 'flex' : 'none';
            }

            const start = total === 0 ? 0 : offset + 1;
            const end = Math.min(offset + limit, total);
            const currentPage = Math.floor(offset / limit) + 1;
            const totalPages = Math.ceil(total / limit);

            document.getElementById('bookingPageStart').innerText = start;
            document.getElementById('bookingPageEnd').innerText = end;
            document.getElementById('bookingTotalCount').innerText = total;
            const btnContainer = document.getElementById('bookingPageButtons');
            if (!btnContainer) return;
            btnContainer.innerHTML = '';

            const addDots = () => {
                const span = document.createElement('span');
                span.className = 'pg-dots';
                span.innerText = '...';
                btnContainer.appendChild(span);
            };

            // Prev
            const prevBtn = document.createElement('button');
            prevBtn.className = 'pg-btn pg-btn-nav';
            prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i> Prev';
            prevBtn.disabled = (currentPage === 1);
            prevBtn.onclick = (e) => {
                e.preventDefault();
                bookingOffset = Math.max(0, bookingOffset - limit);
                refreshBookingTable(true);
            };
            btnContainer.appendChild(prevBtn);

            // Pages
            let startPage = Math.max(1, currentPage - 1);
            let endPage = Math.min(totalPages, startPage + 2);
            if (endPage - startPage < 2) startPage = Math.max(1, endPage - 2);

            if (startPage > 1) {
                btnContainer.appendChild(createBookingPageBtn(1, limit, 1 === currentPage));
                if (startPage > 2) addDots();
            }

            for (let i = startPage; i <= endPage; i++) {
                if (i > 0) btnContainer.appendChild(createBookingPageBtn(i, limit, i === currentPage));
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) addDots();
                btnContainer.appendChild(createBookingPageBtn(totalPages, limit, totalPages === currentPage));
            }

            // Next
            const nextBtn = document.createElement('button');
            nextBtn.className = 'pg-btn pg-btn-nav';
            nextBtn.innerHTML = 'Next <i class="fas fa-chevron-right"></i>';
            nextBtn.disabled = (currentPage === totalPages || total === 0);
            nextBtn.onclick = (e) => {
                e.preventDefault();
                bookingOffset += limit;
                refreshBookingTable(true);
            };
            btnContainer.appendChild(nextBtn);
        }

        function createBookingPageBtn(page, limit, isActive) {
            const btn = document.createElement('button');
            btn.className = 'pg-btn' + (isActive ? ' active' : '');
            btn.innerText = page;
            btn.onclick = (e) => {
                e.preventDefault();
                bookingOffset = (page - 1) * limit;
                refreshBookingTable(true);
            };
            return btn;
        }

        // --- 🟢 NEW: SEAMLESS FOOD TABLE REFRESH ---
        function refreshFoodTable() {
            const tbody = document.getElementById('foodTableBody');
            if (!tbody) return;

            const url = `fetch_food_table.php?limit=${foodLimit}&offset=${foodOffset}&t=${new Date().getTime()}`;

            fetch(url)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        // 1. Update Table Body
                        tbody.innerHTML = data.html;

                        // 2. Update Pagination UI
                        updateFoodPaginationUI(data.total, data.limit, data.offset);
                    }
                })
                .catch(err => console.error("Food table sync error:", err));
        }

        function updateFoodPaginationUI(total, limit, offset) {
            const container = document.getElementById('foodPagination');
            if (container) {
                container.style.display = (total > limit) ? 'flex' : 'none';
            }

            const start = total === 0 ? 0 : offset + 1;
            const end = Math.min(offset + limit, total);
            const currentPage = Math.floor(offset / limit) + 1;
            const totalPages = Math.ceil(total / limit);

            if (document.getElementById('foodPageStart')) document.getElementById('foodPageStart').innerText = start;
            if (document.getElementById('foodPageEnd')) document.getElementById('foodPageEnd').innerText = end;
            if (document.getElementById('foodTotalCount')) document.getElementById('foodTotalCount').innerText = total;

            const btnContainer = document.getElementById('foodPageButtons');
            if (!btnContainer) return;
            btnContainer.innerHTML = '';

            const addDots = () => {
                const span = document.createElement('span');
                span.className = 'pg-dots';
                span.innerText = '...';
                btnContainer.appendChild(span);
            };

            // Prev
            const prevBtn = document.createElement('button');
            prevBtn.className = 'pg-btn pg-btn-nav';
            prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i> Prev';
            prevBtn.disabled = (currentPage === 1);
            prevBtn.onclick = (e) => {
                e.preventDefault();
                foodOffset = Math.max(0, foodOffset - limit);
                refreshFoodTable();
            };
            btnContainer.appendChild(prevBtn);

            // Pages
            let startPage = Math.max(1, currentPage - 1);
            let endPage = Math.min(totalPages, startPage + 2);
            if (endPage - startPage < 2) startPage = Math.max(1, endPage - 2);

            if (startPage > 1) {
                btnContainer.appendChild(createFoodPageBtn(1, limit, 1 === currentPage));
                if (startPage > 2) addDots();
            }

            for (let i = startPage; i <= endPage; i++) {
                if (i > 0) btnContainer.appendChild(createFoodPageBtn(i, limit, i === currentPage));
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) addDots();
                btnContainer.appendChild(createFoodPageBtn(totalPages, limit, totalPages === currentPage));
            }

            // Next
            const nextBtn = document.createElement('button');
            nextBtn.className = 'pg-btn pg-btn-nav';
            nextBtn.innerHTML = 'Next <i class="fas fa-chevron-right"></i>';
            nextBtn.disabled = (currentPage === totalPages || total === 0);
            nextBtn.onclick = (e) => {
                e.preventDefault();
                foodOffset += limit;
                refreshFoodTable();
            };
            btnContainer.appendChild(nextBtn);
        }

        function createFoodPageBtn(page, limit, isActive) {
            const btn = document.createElement('button');
            btn.className = 'pg-btn' + (isActive ? ' active' : '');
            btn.innerText = page;
            btn.onclick = (e) => {
                e.preventDefault();
                foodOffset = (page - 1) * limit;
                refreshFoodTable();
            };
            return btn;
        }

        // --- BACKGROUND AUTO-UPDATER ---
        function triggerAutoUpdates() {
            fetch('auto_update_status.php')
                .then(res => res.json())
                .then(data => {
                    if (data.updates > 0) {
                        console.log("System Auto-Update: " + data.updates + " bookings marked as No-Show.");
                        // If an update happened, refresh the table so the user sees it immediately
                        refreshBookingTable();
                        fetchDashboardCards();
                    }
                })
                .catch(err => console.error("Auto-update check failed:", err));
        }


        // --- QR SCANNER LOGIC ---
        let html5QrcodeScanner = null;

        function openScannerModal() {
            document.getElementById('qrScannerModal').style.display = 'block';

            // Initialize Scanner only if not already running
            if (!html5QrcodeScanner) {
                html5QrcodeScanner = new Html5Qrcode("qr-reader");
            }

            const config = { fps: 10, qrbox: { width: 250, height: 250 } };

            // Start Camera (Rear camera preferred)
            html5QrcodeScanner.start({ facingMode: "environment" }, config, onScanSuccess, onScanFailure)
                .catch(err => {
                    console.error("Camera start failed", err);
                    document.getElementById('qr-result').innerText = "Error starting camera. Please allow permissions.";
                });
        }

        function closeScannerModal() {
            document.getElementById('qrScannerModal').style.display = 'none';

            // Stop Camera to save battery
            if (html5QrcodeScanner) {
                html5QrcodeScanner.stop().then(() => {
                    console.log("Scanner stopped");
                }).catch(err => {
                    console.warn("Failed to stop scanner", err);
                });
            }
        }

        // SUCCESS CALLBACK
        function onScanSuccess(decodedText, decodedResult) {
            // 1. Pause scanning so we don't spam the server
            html5QrcodeScanner.pause();

            document.getElementById('qr-result').innerHTML = `<span style="color:blue;">Processing: ${decodedText}...</span>`;

            // 2. Send to Backend (confirm_qr.php)
            fetch('confirm_qr.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reference: decodedText })
            })
                .then(res => res.json())
                .then(data => {
                    // Clear the "Processing..." text once we get a response
                    document.getElementById('qr-result').innerHTML = "";

                    // Standard configuration for all modals to ensure they are on top
                    const swalConfig = {
                        didOpen: () => {
                            const container = Swal.getContainer();
                            if (container) container.style.zIndex = '9999';
                        }
                    };

                    if (data.status === 'success') {
                        // ✅ SUCCESS
                        Swal.fire({
                            ...swalConfig,
                            icon: 'success',
                            title: 'Check-in Confirmed!',
                            text: data.message,
                            timer: 3000,
                            showConfirmButton: false
                        }).then(() => {
                            refreshBookingTable();
                            fetchDashboardCards();
                            closeScannerModal();
                        });

                    } else if (data.status === 'warning') {
                        // ⚠️ WARNING
                        Swal.fire({
                            ...swalConfig,
                            icon: 'warning',
                            title: 'Note',
                            text: data.message,
                            confirmButtonColor: '#f8bb86'
                        }).then(() => {
                            html5QrcodeScanner.resume();
                        });

                    } else {
                        // ❌ ERROR
                        Swal.fire({
                            ...swalConfig,
                            icon: 'error',
                            title: 'Access Denied',
                            text: data.message,
                            confirmButtonColor: '#d33'
                        }).then(() => {
                            html5QrcodeScanner.resume();
                        });
                    }
                })
                .catch(err => {
                    console.error(err);
                    Swal.fire({
                        icon: 'error',
                        title: 'System Error',
                        text: 'Check connection to the server.',
                        didOpen: () => {
                            const container = Swal.getContainer();
                            if (container) container.style.zIndex = '9999';
                        }
                    });
                    html5QrcodeScanner.resume();
                });
        }

        function onScanFailure(error) {
            // Handle scan failure, usually better to ignore as it fires constantly while looking for a code
            // console.warn(`Code scan error = ${error}`);
        }

        // 🟢 NEW: HELPER TO HANDLE TOAST EXIT ANIMATION
        function triggerToastExit(alertCard) {
            if (!alertCard || alertCard.style.display === 'none') return;

            // Add exit class
            alertCard.classList.add('toast-out');

            // Wait for animation to finish then hide
            setTimeout(() => {
                alertCard.style.display = 'none';
                alertCard.classList.remove('toast-out');
                repositionToasts(); // Recalculate positions
            }, 400); // Matches CSS animation duration
        }

        // 🟢 NEW: HELPER TO STACK TOASTS DYNAMICALLY
        function repositionToasts() {
            const toastIds = ['newNotificationAlert', 'newMessageAlert', 'lateArrivalAlert', 'newOrderAlert'];
            let currentTop = 90;
            const gap = 95;

            toastIds.forEach(id => {
                const el = document.getElementById(id);
                // Check if element is visible and not animating out
                if (el && getComputedStyle(el).display === 'flex' && !el.classList.contains('toast-out')) {
                    el.style.top = currentTop + 'px';
                    currentTop += gap;
                }
            });
        }

        function updateNotificationAlert(unreadCount) {
            const alertCard = document.getElementById('newNotificationAlert');
            const countDisplay = document.getElementById('nb_count_display');
            const progressBar = alertCard ? alertCard.querySelector('.toast-progress-bar') : null;

            if (!alertCard || !countDisplay) return;

            const lastCount = parseInt(localStorage.getItem('lastNotificationCount') || '0');

            if (unreadCount > 0 && unreadCount > lastCount) {
                // 🟢 TRIGGER: New notification arrived
                countDisplay.innerText = unreadCount;

                if (getComputedStyle(alertCard).display === 'none' || alertCard.classList.contains('toast-out')) {
                    alertCard.style.display = 'flex';
                    alertCard.classList.remove('toast-out');
                    repositionToasts();
                }

                // Animate Progress Bar (Force reflow for stability)
                if (progressBar) {
                    progressBar.style.transition = 'none';
                    progressBar.style.transform = 'scaleX(1)';
                    void progressBar.offsetWidth; // Force Reflow
                    progressBar.style.transition = 'transform 10s linear';
                    progressBar.style.transform = 'scaleX(0)';
                }

                // Auto-hide after 10 seconds with animation
                clearTimeout(window.alertHideTimeout);
                window.alertHideTimeout = setTimeout(() => {
                    triggerToastExit(alertCard);
                }, 10000);
            } else if (unreadCount === 0) {
                if (getComputedStyle(alertCard).display === 'flex' && !alertCard.classList.contains('toast-out')) {
                    triggerToastExit(alertCard);
                }
            }

            localStorage.setItem('lastNotificationCount', unreadCount);
        }

        function updateMessageAlert(unreadCount) {
            const alertCard = document.getElementById('newMessageAlert');
            const countDisplay = document.getElementById('msg_count_display');
            const progressBar = alertCard ? alertCard.querySelector('.toast-progress-bar') : null;

            if (!alertCard || !countDisplay) return;

            const lastCount = parseInt(localStorage.getItem('lastMessageCount') || '0');

            if (unreadCount > 0 && unreadCount > lastCount) {
                // 🟢 TRIGGER: New message arrived
                countDisplay.innerText = unreadCount;

                if (getComputedStyle(alertCard).display === 'none' || alertCard.classList.contains('toast-out')) {
                    alertCard.style.display = 'flex';
                    alertCard.classList.remove('toast-out');
                    repositionToasts();
                }

                // Animate Progress Bar (Force reflow)
                if (progressBar) {
                    progressBar.style.transition = 'none';
                    progressBar.style.transform = 'scaleX(1)';
                    void progressBar.offsetWidth; // Force Reflow
                    progressBar.style.transition = 'transform 10s linear';
                    progressBar.style.transform = 'scaleX(0)';
                }

                // Auto-hide after 10 seconds
                clearTimeout(window.msgAlertHideTimeout);
                window.msgAlertHideTimeout = setTimeout(() => {
                    triggerToastExit(alertCard);
                }, 10000);
            } else if (unreadCount === 0) {
                if (getComputedStyle(alertCard).display === 'flex' && !alertCard.classList.contains('toast-out')) {
                    triggerToastExit(alertCard);
                }
            }

            localStorage.setItem('lastMessageCount', unreadCount);
        }

        function updateLateArrivalAlert(lateCount) {
            const alertCard = document.getElementById('lateArrivalAlert');
            const countDisplay = document.getElementById('late_count_display');
            const progressBar = alertCard ? alertCard.querySelector('.toast-progress-bar') : null;

            if (!alertCard || !countDisplay) return;

            const today = new Date().toISOString().split('T')[0];
            const isDismissed = localStorage.getItem('lateAlertDismissed_' + today);
            const lastCount = parseInt(localStorage.getItem('lastLateArrivalCount') || '0');

            if (lateCount > 0 && lateCount > lastCount && !isDismissed) {
                // 🟢 TRIGGER: New late arrival detected
                countDisplay.innerText = lateCount;

                if (getComputedStyle(alertCard).display === 'none' || alertCard.classList.contains('toast-out')) {
                    alertCard.style.display = 'flex';
                    alertCard.classList.remove('toast-out');
                    repositionToasts();
                }

                // Animate Progress Bar (Force reflow)
                if (progressBar) {
                    progressBar.style.transition = 'none';
                    progressBar.style.transform = 'scaleX(1)';
                    void progressBar.offsetWidth; // Force Reflow
                    progressBar.style.transition = 'transform 15s linear';
                    progressBar.style.transform = 'scaleX(0)';
                }

                // Auto-hide after 15 seconds
                clearTimeout(window.lateAlertHideTimeout);
                window.lateAlertHideTimeout = setTimeout(() => {
                    triggerToastExit(alertCard);
                }, 15000);
            } else if (lateCount === 0 || isDismissed) {
                if (getComputedStyle(alertCard).display === 'flex' && !alertCard.classList.contains('toast-out')) {
                    triggerToastExit(alertCard);
                }
            }

            localStorage.setItem('lastLateArrivalCount', lateCount);
        }

        function updateOrderAlert(unreadCount) {
            const alertCard = document.getElementById('newOrderAlert');
            const countDisplay = document.getElementById('order_count_display');
            const progressBar = alertCard ? alertCard.querySelector('.toast-progress-bar') : null;

            if (!alertCard || !countDisplay) return;

            const lastCount = parseInt(localStorage.getItem('lastOrderCount') || '0');

            if (unreadCount > 0 && unreadCount > lastCount) {
                // 🟢 TRIGGER: New order arrived
                countDisplay.innerText = unreadCount;

                if (getComputedStyle(alertCard).display === 'none' || alertCard.classList.contains('toast-out')) {
                    alertCard.style.display = 'flex';
                    alertCard.classList.remove('toast-out');
                    repositionToasts();
                }

                // Animate Progress Bar (Force reflow)
                if (progressBar) {
                    progressBar.style.transition = 'none';
                    progressBar.style.transform = 'scaleX(1)';
                    void progressBar.offsetWidth;
                    progressBar.style.transition = 'transform 10s linear';
                    progressBar.style.transform = 'scaleX(0)';
                }

                // Auto-hide after 10 seconds
                clearTimeout(window.orderAlertHideTimeout);
                window.orderAlertHideTimeout = setTimeout(() => {
                    triggerToastExit(alertCard);
                }, 10000);
            } else if (unreadCount === 0) {
                if (getComputedStyle(alertCard).display === 'flex' && !alertCard.classList.contains('toast-out')) {
                    triggerToastExit(alertCard);
                }
            }

            localStorage.setItem('lastOrderCount', unreadCount);
        } function openUnreadMessages(event) {
            if (event) event.stopPropagation();

            // 1. Force Open the Dropdown
            const dropdown = document.getElementById('msgDropdown');
            dropdown.classList.add('show');

            // 2. Hide other dropdowns (like notifications) to prevent overlap
            document.querySelectorAll('.dropdown-menu').forEach(dd => {
                if (dd.id !== 'msgDropdown') dd.classList.remove('show');
            });

            // 3. Find the "Unread" filter option button in the DOM
            // We look inside the filter menu for the element that has the 'unread' onclick
            const filterMenu = document.getElementById('msgFilterMenu');
            const unreadOption = filterMenu.querySelector('.filter-option:nth-child(2)'); // Usually the 2nd option is 'Unread'

            // 4. Apply the existing filter logic
            // This reuses your existing code to filter the list and highlight the "Unread" button
            if (unreadOption) {
                applyMsgFilter('unread', unreadOption);
            }

            // 5. Hide the Alert Card immediately
            const alertCard = document.getElementById('newMessageAlert');
            if (alertCard) alertCard.style.display = 'none';
        }

        // --- 🟢 EVENT MANAGEMENT LOGIC ---

        // 1. Initialize Date Picker & TinyMCE for Events
        document.addEventListener("DOMContentLoaded", function () {

            // 1. KEEP THIS (Date Picker for Events)
            flatpickr("#event_date_picker", {
                dateFormat: "Y-m-d",
                minDate: "today",
                static: true
            });

            // 2. REPLACE TINYMCE WITH QUILL (For Events)
            eventQuill = new Quill('#eventQuillEditor', {
                theme: 'snow',
                placeholder: 'Write event details here...',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline'],
                        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                        ['link', 'clean']
                    ]
                }
            });

        });

        // 2. Image Preview
        function previewEventImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    document.getElementById('eventImagePreview').src = e.target.result;
                    document.getElementById('eventImagePreview').style.display = 'block';
                    document.getElementById('eventImagePlaceholder').style.display = 'none';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // 3. Open Modal (Add)
        function openAddEventModal() {
            document.getElementById('eventForm').reset();

            // 🟢 NEW: Clear Quill Editor
            if (eventQuill) {
                eventQuill.setText('');
            }
            // Clear hidden input
            document.getElementById('eventDescInput').value = '';

            document.getElementById('eventModalTitle').innerText = "Add New Event";
            document.getElementById('eventAction').value = "add";
            document.getElementById('eventId').value = "";

            // Reset Image
            const preview = document.getElementById('eventImagePreview');
            const placeholder = document.getElementById('eventImagePlaceholder');

            preview.src = "";
            preview.style.display = 'none';
            placeholder.style.display = 'block';

            document.getElementById('eventModal').style.display = 'block';
        }

        // 4. Open Modal (Edit)
        function openEditEventModal(id, title, date, time, desc, imgPath) {
            document.getElementById('eventId').value = id;
            document.getElementById('eventAction').value = "edit";
            document.getElementById('eventModalTitle').innerText = "Edit Event";

            document.getElementById('eventTitleInput').value = title;

            // Set Date Picker
            const datePicker = document.getElementById('event_date_picker')._flatpickr;
            if (datePicker) {
                datePicker.setDate(date);
            }

            document.getElementById('eventTimeInput').value = time;

            // 🟢 NEW: Load content into Quill Editor
            if (eventQuill) {
                eventQuill.clipboard.dangerouslyPasteHTML(desc);
            }
            // Update hidden input immediately
            document.getElementById('eventDescInput').value = desc;

            // Image Logic
            const preview = document.getElementById('eventImagePreview');
            const placeholder = document.getElementById('eventImagePlaceholder');

            if (imgPath) {
                preview.src = '../../room_includes/uploads/events/' + imgPath;
                preview.style.display = 'block';
                placeholder.style.display = 'none';
            } else {
                preview.src = "";
                preview.style.display = 'none';
                placeholder.style.display = 'block';
            }

            document.getElementById('eventModal').style.display = 'block';
        }

        // 5. Submit Form
        document.getElementById('eventForm').onsubmit = function (e) {
            e.preventDefault();

            // 🟢 NEW: Sync Quill to Hidden Input manually
            if (eventQuill) {
                document.getElementById('eventDescInput').value = eventQuill.root.innerHTML;
            }

            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerText;
            btn.innerText = "Saving...";
            btn.disabled = true;

            const formData = new FormData(this);

            fetch('manage_events.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert("Error: " + data.message);
                        btn.innerText = originalText;
                        btn.disabled = false;
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        };

        // --- 1. TOGGLE ARCHIVED EVENTS ---
        function toggleArchivedEvents() {
            const rows = document.querySelectorAll('.archived-event-row');
            const btn = document.getElementById('toggleArchivedEventsBtn');
            let isHidden = false;

            rows.forEach(row => {
                if (row.style.display === 'none') {
                    row.style.display = 'table-row';
                    isHidden = false;
                } else {
                    row.style.display = 'none';
                    isHidden = true;
                }
            });

            btn.innerText = isHidden ? "Show Archived" : "Hide Archived";
        }

        // --- 2. SOFT DELETE EVENT (Archive) ---
        function deleteEvent(id) {
            if (!confirm("Are you sure you want to archive this event? It will be hidden from the website.")) return;

            const formData = new FormData();
            formData.append('action', 'delete'); // 'delete' usually means soft-delete in your system
            formData.append('event_id', id);

            fetch('manage_events.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert("Error: " + data.message);
                    }
                });
        }

        // --- 3. RESTORE EVENT ---
        function restoreEvent(id) {
            if (!confirm("Restore this event to the active list?")) return;

            const formData = new FormData();
            formData.append('action', 'restore');
            formData.append('event_id', id);

            fetch('manage_events.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert("Error: " + data.message);
                    }
                });
        }

        // --- 4. PERMANENT DELETE EVENT ---
        function permanentDeleteEvent(id) {
            if (!confirm("⚠️ WARNING: This will PERMANENTLY DELETE this event and its image.\n\nThis cannot be undone.\n\nAre you sure?")) return;

            const formData = new FormData();
            formData.append('action', 'hard_delete');
            formData.append('event_id', id);

            // Visual feedback
            const row = document.getElementById('event-row-' + id);
            if (row) row.style.opacity = '0.3';

            fetch('manage_events.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert("Event permanently deleted.");
                        if (row) row.remove();
                    } else {
                        alert("Error: " + data.message);
                        if (row) row.style.opacity = '1';
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                    if (row) row.style.opacity = '1';
                });
        }

        // --- PRIVACY POLICY BUILDER LOGIC ---

        // 1. Initialize Privacy Data on Load
        document.addEventListener("DOMContentLoaded", function () {
            const rawData = document.getElementById('rawPrivacyContent').value.trim();
            const container = document.getElementById('privacy-builder-container');

            try {
                const policies = JSON.parse(rawData);
                if (Array.isArray(policies) && policies.length > 0) {
                    policies.forEach(policy => {
                        addPrivacySection(policy.title, policy.content);
                    });
                } else {
                    addPrivacySection(); // Add empty card if none exist
                }
            } catch (e) {
                addPrivacySection();
            }
        });

        // 2. Function to Add a Privacy Section Card
        function addPrivacySection(initialTitle = "", initialContent = "") {
            const container = document.getElementById('privacy-builder-container');
            const uniqueId = "privacy_quill_" + new Date().getTime() + Math.floor(Math.random() * 1000);

            const card = document.createElement('div');
            card.className = 'policy-card'; // Reusing your existing CSS class for consistent styling
            card.innerHTML = `
        <div class="policy-header">
            <input type="text" class="policy-title-input" placeholder="Section Title (e.g. Information We Collect)" value="${initialTitle.replace(/"/g, '&quot;')}">
            <button class="btn-delete-policy" onclick="deletePolicySection(this)">
                <i class="fas fa-trash-alt"></i> Delete
            </button>
        </div>
        <input type="hidden" class="policy-hidden-content" value='${initialContent.replace(/'/g, "&#39;")}'>
        <div id="${uniqueId}" style="height: 250px; background: white;"></div>
    `;

            container.appendChild(card);

            // Initialize Quill
            var quill = new Quill('#' + uniqueId, {
                theme: 'snow',
                placeholder: 'Enter privacy details...',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline'],
                        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                        ['link', 'clean']
                    ]
                }
            });

            if (initialContent) {
                quill.clipboard.dangerouslyPasteHTML(initialContent);
            }

            // Sync to hidden input
            quill.on('text-change', function () {
                card.querySelector('.policy-hidden-content').value = quill.root.innerHTML;
            });

            // Enter Key Logic (Jump from Title to Editor)
            const titleInput = card.querySelector('.policy-title-input');
            titleInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    quill.focus(); // Focus the Quill editor
                }
            });

            // 🟢 NEW: Scroll the added section into view and focus title
            if (initialTitle === "" && initialContent === "") {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                titleInput.focus();
            }
        }

        // 3. Save Function
        function savePrivacy() {
            const btn = document.querySelector('#view-privacy .ab-submit-btn');
            const originalText = btn.innerText;

            btn.innerText = "Processing...";
            btn.disabled = true;

            let policies = [];
            // Select cards specifically inside the privacy container
            const cards = document.querySelectorAll('#privacy-builder-container .policy-card');

            cards.forEach(card => {
                const title = card.querySelector('.policy-title-input').value.trim();
                const content = card.querySelector('.policy-hidden-content').value;

                if (title || content) {
                    policies.push({
                        title: title,
                        content: content
                    });
                }
            });

            const jsonString = JSON.stringify(policies);
            const formData = new FormData();
            formData.append('privacy_content', jsonString);
            formData.append('csrf_token', csrfToken); // Using your existing global token

            // Point to a new PHP file for saving
            fetch('update_privacy.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert("Privacy Policy updated successfully!");
                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        }

        // --- 🟢 CUSTOM ADMIN SELECT INITIALIZATION ---
        document.addEventListener("DOMContentLoaded", function () {
            initAdminCustomSelects();
        });

        // --- 🟢 CUSTOM ADMIN SELECT (PORTAL VERSION) ---
        function initAdminCustomSelects() {
            const targets = ['.ab-select', '.rm-input'];
            const selector = targets.join(', select');
            const selects = document.querySelectorAll(selector);

            selects.forEach(originalSelect => {
                if (originalSelect.tagName !== 'SELECT') return;
                // Avoid duplicates
                if (originalSelect.nextElementSibling && originalSelect.nextElementSibling.classList.contains('custom-select-wrapper')) return;

                // Create Unique ID for linking
                const uniqueId = 'custom-opt-' + Math.random().toString(36).substr(2, 9);

                // 1. Wrapper
                const wrapper = document.createElement('div');
                wrapper.classList.add('custom-select-wrapper');
                wrapper.dataset.targetId = uniqueId; // Link to options

                // 2. Trigger
                const trigger = document.createElement('div');
                trigger.classList.add('custom-select-trigger');
                const selectedOption = originalSelect.options[originalSelect.selectedIndex];
                const initialText = selectedOption ? selectedOption.text : "- Select -";
                trigger.innerHTML = `<span>${initialText}</span> <i class="fas fa-chevron-down custom-arrow"></i>`;

                // 3. Options Container (APPEND TO BODY, NOT WRAPPER)
                const optionsDiv = document.createElement('div');
                optionsDiv.classList.add('custom-options');
                optionsDiv.id = uniqueId; // ID for linking
                document.body.appendChild(optionsDiv); // 🔴 Key Fix: Move to Body

                // 4. Build Options
                populateCustomOptions(originalSelect, optionsDiv, trigger, wrapper);

                // 5. Insert Wrapper
                wrapper.appendChild(trigger);
                originalSelect.parentNode.insertBefore(wrapper, originalSelect.nextSibling);

                // 6. Click Handler
                trigger.addEventListener('click', function (e) {
                    e.stopPropagation();

                    // 🟢 FIX: Do not open if original select is disabled
                    if (originalSelect.disabled) return;

                    const isOpen = wrapper.classList.contains('open');

                    // Close ALL other dropdowns first
                    closeAllCustomSelects();

                    if (!isOpen) {
                        // Open THIS one
                        wrapper.classList.add('open');
                        optionsDiv.classList.add('open');

                        // 🔴 Calculate Position Dynamically
                        const rect = wrapper.getBoundingClientRect();
                        optionsDiv.style.width = rect.width + 'px';
                        optionsDiv.style.top = (rect.bottom + window.scrollY + 5) + 'px';
                        optionsDiv.style.left = (rect.left + window.scrollX) + 'px';
                    }
                });
            });

            // 7. Global Listeners
            document.addEventListener('click', closeAllCustomSelects);
            window.addEventListener('resize', closeAllCustomSelects);

            // Close on scroll (in any scrollable container) to prevent floating ghosts
            document.addEventListener('scroll', closeAllCustomSelects, true);
        }

        // Helper to populate options
        function populateCustomOptions(originalSelect, optionsDiv, trigger, wrapper) {
            optionsDiv.innerHTML = '';
            Array.from(originalSelect.options).forEach(option => {
                const divOption = document.createElement('div');
                divOption.classList.add('custom-option');
                divOption.textContent = option.text;
                divOption.dataset.value = option.value;

                if (option.selected) divOption.classList.add('selected');

                divOption.addEventListener('click', function (e) {
                    e.stopPropagation();
                    trigger.querySelector('span').textContent = this.textContent;

                    // Visual Update
                    optionsDiv.querySelectorAll('.custom-option').forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');

                    // Logic Update
                    originalSelect.value = this.dataset.value;
                    originalSelect.dispatchEvent(new Event('change'));

                    closeAllCustomSelects();
                });
                optionsDiv.appendChild(divOption);
            });
        }

        function closeAllCustomSelects() {
            document.querySelectorAll('.custom-select-wrapper').forEach(ws => ws.classList.remove('open'));
            document.querySelectorAll('.custom-options').forEach(opt => opt.classList.remove('open'));
        }

        // 🟢 Updated Refresh Function
        function refreshCustomSelect(selectId) {
            const originalSelect = document.getElementById(selectId);
            if (!originalSelect) return;

            const wrapper = originalSelect.nextElementSibling;
            if (!wrapper || !wrapper.classList.contains('custom-select-wrapper')) return;

            const targetId = wrapper.dataset.targetId;
            const optionsDiv = document.getElementById(targetId);
            const trigger = wrapper.querySelector('.custom-select-trigger');

            if (optionsDiv && trigger) {
                populateCustomOptions(originalSelect, optionsDiv, trigger, wrapper);

                // Update Trigger Text if needed
                if (originalSelect.value !== "") {
                    const selected = originalSelect.options[originalSelect.selectedIndex];
                    if (selected) trigger.querySelector('span').textContent = selected.text;
                } else {
                    trigger.querySelector('span').textContent = "- Select -";
                }
            }
        }

        // 🟢 SEAMLESS UPDATE: Handles Food Order Status changes without reload
        function updateOrderStatus(id, action) {
            const btnText = action === 'prepare' ? "Start Preparing" : "Mark as Served";
            if (!confirm(`Are you sure you want to ${btnText} this order?`)) return;

            const formData = new FormData();
            formData.append('id', id);
            formData.append('action', action);

            // UI Feedback: Find the button and show loading state
            const row = document.getElementById('order-row-' + id);
            const btn = row ? row.querySelector('button') : null;
            let originalBtnContent = "";

            if (btn) {
                originalBtnContent = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ...';
                btn.disabled = true;
            }

            fetch('update_order.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {

                        // --- DOM MANIPULATION (Seamless Update) ---
                        if (row) {
                            const statusBadge = row.querySelector('.badge');
                            const actionCell = row.lastElementChild; // The last <td> contains the button

                            if (action === 'prepare') {
                                // 1. Update Status Badge to "Preparing" (Blue style)
                                if (statusBadge) {
                                    statusBadge.className = 'badge arrival-today';
                                    statusBadge.innerText = 'Preparing';
                                }

                                // 2. Change Button to "Serve"
                                actionCell.innerHTML = `
                                <button class="btn-secondary" style="background:#DCFCE7; color:#166534; border:1px solid #BBF7D0;" 
                                    onclick="updateOrderStatus(${id}, 'deliver')">
                                    <i class="fas fa-check"></i> Serve
                                </button>
                            `;

                            } else if (action === 'deliver') {
                                // 1. Update Status Badge to "Delivered" (Green style)
                                if (statusBadge) {
                                    statusBadge.className = 'badge badge-confirmed';
                                    statusBadge.innerText = 'Delivered';
                                }

                                // 2. Remove Button, show "Completed" text
                                actionCell.innerHTML = `<span style="font-size:0.8rem; color:#aaa;">Completed</span>`;
                            }
                        }

                    } else {
                        alert("Error: " + data.message);
                        // Revert button if failed
                        if (btn) {
                            btn.innerHTML = originalBtnContent;
                            btn.disabled = false;
                        }
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                    if (btn) {
                        btn.innerHTML = originalBtnContent;
                        btn.disabled = false;
                    }
                });
        }

        // --- PAYMENT SETTINGS LOGIC ---

        // 1. Fetch Data & Populate Card + Modal
        function loadPaymentSettings() {
            document.getElementById('disp_pay_method').innerText = "Loading...";

            fetch('get_payment_settings.php')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        const d = data.data;

                        // A. Populate VIEW Card
                        document.getElementById('disp_pay_method').innerText = d.payment_method;
                        document.getElementById('disp_acc_name').innerText = d.account_name;
                        document.getElementById('disp_acc_num').innerText = d.account_number;

                        const qrView = document.getElementById('disp_qr');
                        const qrFallback = document.getElementById('qrFallback');

                        if (d.qr_image && d.qr_image.trim() !== "") {
                            // 1. Show Image
                            qrView.src = '../../room_includes/uploads/payment/' + d.qr_image + '?t=' + new Date().getTime();
                            qrView.style.display = 'block';

                            // 2. Hide Fallback
                            if (qrFallback) qrFallback.style.display = 'none';
                        } else {
                            // 1. Hide Image
                            qrView.style.display = 'none';
                            qrView.src = ""; // Clear src to prevent broken link icon

                            // 2. Show Fallback (Flex to keep it centered)
                            if (qrFallback) qrFallback.style.display = 'flex';
                        }

                        // B. Populate EDIT Modal fields (Pre-fill)
                        document.getElementById('edit_pay_method').value = d.payment_method;
                        document.getElementById('edit_acc_name').value = d.account_name;
                        document.getElementById('edit_acc_num').value = d.account_number;

                        // Handle QR Preview in Edit Modal
                        const editQrPreview = document.getElementById('editQrPreview');
                        const editQrPlace = document.getElementById('editQrPlaceholder');

                        if (d.qr_image && d.qr_image.trim() !== "") {
                            editQrPreview.src = '../../room_includes/uploads/payment/' + d.qr_image + '?t=' + new Date().getTime();
                            editQrPreview.style.display = 'block';
                            if (editQrPlace) editQrPlace.style.display = 'none';
                        } else {
                            editQrPreview.src = "";
                            editQrPreview.style.display = 'none';
                            if (editQrPlace) editQrPlace.style.display = 'block';
                        }

                    } else {
                        document.getElementById('disp_pay_method').innerText = "Not Configured";
                    }
                })
                .catch(err => console.error(err));
        }

        // 2. Toggle Payment Edit Modal
        function togglePaymentEdit(show) {
            const modal = document.getElementById('paymentEditModal');
            modal.style.display = show ? 'block' : 'none';
        }

        // 3. QR Preview Function
        function previewPaymentQR(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    document.getElementById('editQrPreview').src = e.target.result;
                    document.getElementById('editQrPreview').style.display = 'block';
                    document.getElementById('editQrPlaceholder').style.display = 'none';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // 4. Save Payment Settings
        function savePaymentSettings(e) {
            e.preventDefault();

            const form = document.getElementById('paymentEditForm');
            const btn = form.querySelector('button[type="submit"]');
            const originalText = btn.innerText;

            btn.innerText = "Saving...";
            btn.disabled = true;

            const formData = new FormData(form);
            formData.append('csrf_token', csrfToken);

            fetch('update_payment_settings.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert("Payment details updated successfully!");
                        togglePaymentEdit(false);
                        loadPaymentSettings(); // Refresh the view card immediately
                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        }

        // --- PENDING BOOKINGS DRAWER LOGIC ---

        // 1. Toggle Drawer Visibility
        function togglePendingDrawer() {
            // 🟢 SAFETY CHECK: If busy, stop here.
            if (isDrawerBusy) {
                return;
            }

            const drawer = document.getElementById('pendingDrawer');
            const overlay = document.getElementById('drawerOverlay');
            const isOpen = drawer.classList.contains('open');

            if (isOpen) {
                drawer.classList.remove('open');
                overlay.classList.remove('show');
            } else {
                drawer.classList.add('open');
                overlay.classList.add('show');
                fetchPendingBookings();
            }
        }

        // 2. Fetch Data from API
        function fetchPendingBookings() {
            fetch('get_pending_bookings.php')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        renderPendingList(data.data);
                        updatePendingPulse(data.data.length); // Update the red dot on header icon
                    }
                })
                .catch(err => console.error("Error fetching pending:", err));
        }

        // 3. Render the List inside the Drawer (Updated with Room Name)
        function renderPendingList(bookings) {
            const container = document.getElementById('pendingDrawerBody');
            container.innerHTML = '';

            if (bookings.length === 0) {
                container.innerHTML = `
            <div style="text-align:center; padding:40px; color:#9ca3af;">
                <i class="fas fa-check-circle" style="font-size:3rem; margin-bottom:15px; color:#D1D5DB;"></i>
                <p>All clear! No pending bookings.</p>
            </div>`;
                return;
            }

            bookings.forEach(b => {
                // Image Path Logic
                let receiptHtml = '';
                if (b.payment_proof) {
                    const imgSrc = '../../room_includes/uploads/receipts/' + b.payment_proof;
                    receiptHtml = `
                <div class="receipt-preview-box" onclick="viewReceipt('${imgSrc}')">
                    <img src="${imgSrc}" class="receipt-img" onerror="this.src='../../IMG/default_image.svg'">
                    <div style="position:absolute; bottom:5px; right:5px; background:rgba(0,0,0,0.6); color:white; padding:2px 6px; border-radius:4px; font-size:0.7rem;">
                        <i class="fas fa-search-plus"></i> Zoom
                    </div>
                </div>`;
                } else {
                    receiptHtml = `
                <div class="receipt-preview-box" style="background:#fee2e2; border-color:#fecaca; cursor:default;">
                    <div style="text-align:center; color:#dc2626;">
                        <i class="fas fa-exclamation-triangle"></i><br>
                        <small>No Receipt Uploaded</small>
                    </div>
                </div>`;
                }

                const card = document.createElement('div');
                card.className = 'pending-card';
                card.innerHTML = `
            <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                <strong style="color:#333;">${b.booking_reference}</strong>
                <span style="font-size:0.8rem; color:#666;">${b.created_at.substring(0, 10)}</span>
            </div>
            
            <div style="font-size:0.9rem; color:#555; margin-bottom:5px;">
                <i class="fas fa-user"></i> ${b.first_name} ${b.last_name}
            </div>

            <div style="font-size:0.85rem; color:#3B82F6; background:#EFF6FF; padding:5px 10px; border-radius:4px; margin-bottom:10px; font-weight:600;">
                <i class="fas fa-bed"></i> ${b.room_names || 'Unknown Room'}
            </div>

            ${receiptHtml}

            <div style="display:flex; justify-content:space-between; font-size:0.9rem; margin-bottom:10px;">
                <span>Total: <strong>₱${parseFloat(b.total_price).toLocaleString()}</strong></span>
                <span style="color:#d97706;">Claimed: ₱${parseFloat(b.amount_paid).toLocaleString()}</span>
            </div>

            <div class="drawer-actions">
                <button class="ab-submit-btn" style="background:#EF4444; padding:8px;" onclick="rejectBooking(${b.id}, this)">
                    Reject
                </button>
                
                <button class="ab-submit-btn" style="background:#FFA500; padding:8px;" onclick="verifyBooking(${b.id}, this)">
                    Verify & Confirm
                </button>
            </div>
        `;
                container.appendChild(card);
            });
        }

        // 4. Update Header Icon Pulse
        function updatePendingPulse(count) {
            const dot = document.getElementById('pendingPulse');
            if (count > 0) {
                dot.style.display = 'block';
            } else {
                dot.style.display = 'none';
            }
        }

        // 5. Lightbox for Receipts
        function viewReceipt(src) {
            const modal = document.getElementById('receiptLightbox');
            const img = document.getElementById('lightboxImage');
            img.src = src;
            modal.style.display = 'flex';
        }



        // --- UPDATED VERIFY FUNCTION (With Rotating Spinner) ---
        function verifyBooking(id, btnElement) {
            if (!confirm("Are you sure the receipt matches? Confirm Booking?")) return;

            // 1. LOCK UI GLOBALLY
            isDrawerBusy = true;
            toggleUILock(true, "CONFIRMING BOOKING...");

            // 2. Visual Loading State (Save original content, show spinner)
            const originalContent = btnElement.innerHTML; // Save the old text/icon
            btnElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
            btnElement.disabled = true;
            btnElement.style.opacity = '0.7';

            // 3. Lock the container visuals
            const drawerBody = document.getElementById('pendingDrawerBody');
            drawerBody.style.pointerEvents = 'none';
            drawerBody.style.opacity = '0.8';

            const formData = new FormData();
            formData.append('id', id);

            fetch('approve_booking.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        // Success! Keep spinner for a split second before refreshing
                        alert(data.message);
                        fetchPendingBookings(); // Reload sidebar list
                        refreshBookingTable();  // Reload main table
                        fetchDashboardCards();  // Reload stats
                    } else {
                        // Error: Revert button
                        alert("Error: " + data.message);
                        btnElement.innerHTML = originalContent;
                        btnElement.disabled = false;
                        btnElement.style.opacity = '1';
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error: Check console.");
                    // Revert button on crash
                    btnElement.innerHTML = originalContent;
                    btnElement.disabled = false;
                    btnElement.style.opacity = '1';
                })
                .finally(() => {
                    // 4. UNLOCK UI
                    drawerBody.style.opacity = '1';
                    drawerBody.style.pointerEvents = 'auto';
                    isDrawerBusy = false;
                    toggleUILock(false);
                });
        }

        // --- UPDATED REJECT FUNCTION (With Rotating Spinner) ---
        function rejectBooking(id, btnElement) {
            if (!confirm("Are you sure you want to REJECT this booking?")) return;

            // 1. LOCK UI GLOBALLY
            isDrawerBusy = true;
            toggleUILock(true, "REJECTING BOOKING...");

            // 2. Visual Loading State
            const originalContent = btnElement.innerHTML;
            btnElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Rejecting...';
            btnElement.disabled = true;
            btnElement.style.opacity = '0.7';

            // Lock the container visuals
            const drawerBody = document.getElementById('pendingDrawerBody');
            drawerBody.style.pointerEvents = 'none';
            drawerBody.style.opacity = '0.8';

            const formData = new FormData();
            formData.append('id', id);
            formData.append('action', 'cancel');

            fetch('update_arrival.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert("Booking Rejected.");
                        fetchPendingBookings();
                        refreshBookingTable();
                        fetchDashboardCards();
                    } else {
                        alert("Error: " + data.message);
                        // Revert button
                        btnElement.innerHTML = originalContent;
                        btnElement.disabled = false;
                        btnElement.style.opacity = '1';
                    }
                })
                .catch(err => {
                    alert("System Error");
                    // Revert button
                    btnElement.innerHTML = originalContent;
                    btnElement.disabled = false;
                    btnElement.style.opacity = '1';
                })
                .finally(() => {
                    // 2. UNLOCK UI
                    drawerBody.style.pointerEvents = 'auto';
                    drawerBody.style.opacity = '1';
                    isDrawerBusy = false;
                    toggleUILock(false);
                });
        }

        /* --- 🟢 FOOD ORDER DRAWER LOGIC --- */

        // 1. Toggle Drawer
        function toggleOrderDrawer() {
            if (isDrawerBusy) return; // UI Lock check

            const drawer = document.getElementById('orderDrawer');
            const overlay = document.getElementById('drawerOverlay'); // Reuse existing overlay
            const isOpen = drawer.classList.contains('open');

            if (isOpen) {
                drawer.classList.remove('open');
                overlay.classList.remove('show');
            } else {
                drawer.classList.add('open');
                overlay.classList.add('show');
                fetchPendingOrders(); // Load data
            }
        }

        // 2. Fetch Data
        function fetchPendingOrders() {
            fetch('get_pending_orders.php')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        renderOrderList(data.data);
                        updateOrderPulse(data.data.length);
                        updateOrderAlert(data.data.length); // 🟢 Show toast notification
                    }
                })
                .catch(err => console.error("Error fetching orders:", err));
        }

        // 🟢 REPLACEMENT: renderOrderList
        // Prioritizes Payment Method over Image to prevent "Charge to Room" text errors
        function renderOrderList(orders) {
            const container = document.getElementById('orderDrawerBody');
            container.innerHTML = '';

            if (orders.length === 0) {
                container.innerHTML = `
            <div style="text-align:center; padding:40px; color:#9ca3af;">
                <i class="fas fa-check-circle" style="font-size:3rem; margin-bottom:15px; color:#D1D5DB;"></i>
                <p>No pending food orders.</p>
            </div>`;
                return;
            }

            orders.forEach(o => {
                // Parse Items
                let itemsHtml = '';
                if (o.items_decoded) {
                    for (const [item, qty] of Object.entries(o.items_decoded)) {
                        itemsHtml += `<div style="font-size:0.85rem; color:#555;"><b>${qty}x</b> ${item}</div>`;
                    }
                }

                // 🟢 SMART LOGIC FIX: Check Method FIRST, then Image
                let receiptHtml = '';

                if (o.payment_method === 'Charge to Room') {
                    // Case 1: Room Charge (Always Blue Badge, ignore payment_proof content)
                    receiptHtml = `
                <div class="receipt-preview-box" style="background:#E0E7FF; border-color:#C7D2FE; cursor:default; height:100px; flex-direction:column;">
                    <i class="fas fa-door-open" style="font-size:2rem; color:#4338CA; margin-bottom:5px;"></i>
                    <div style="font-size:0.8rem; font-weight:700; color:#4338CA;">Charged to Room</div>
                </div>`;
                }
                else if (o.payment_method === 'Cash') {
                    // Case 2: Cash (Always Green Badge)
                    receiptHtml = `
                <div class="receipt-preview-box" style="background:#DCFCE7; border-color:#86EFAC; cursor:default; height:100px; flex-direction:column;">
                    <i class="fas fa-money-bill-wave" style="font-size:2rem; color:#15803D; margin-bottom:5px;"></i>
                    <div style="font-size:0.8rem; font-weight:700; color:#15803D;">Pay Cash</div>
                </div>`;
                }
                else if (o.payment_proof && o.payment_proof.trim() !== '' && o.payment_proof !== 'Charge to Room') {
                    // Case 3: Has Valid Image (GCash/Online) AND filename is not "Charge to Room"
                    const imgSrc = '../../room_includes/uploads/receipts/' + o.payment_proof;
                    receiptHtml = `
                <div class="receipt-preview-box" onclick="viewReceipt('${imgSrc}')">
                    <img src="${imgSrc}" class="receipt-img" onerror="this.style.display='none'; this.nextElementSibling.innerText='Image Error';">
                    <div style="position:absolute; bottom:5px; right:5px; background:rgba(0,0,0,0.6); color:white; padding:2px 6px; border-radius:4px; font-size:0.7rem;">
                        <i class="fas fa-search-plus"></i> Zoom
                    </div>
                </div>`;
                }
                else {
                    // Case 4: No Receipt (GCash but missing image)
                    receiptHtml = `
                <div class="receipt-preview-box" style="background:#FEE2E2; border-color:#FECACA; cursor:default; height:100px; flex-direction:column;">
                    <i class="fas fa-exclamation-triangle" style="font-size:2rem; color:#B91C1C; margin-bottom:5px;"></i>
                    <div style="font-size:0.8rem; font-weight:700; color:#B91C1C;">No Receipt Uploaded</div>
                </div>`;
                }

                // Method Badge Color
                let methodColor = '#6B7280';
                if (o.payment_method === 'GCash') methodColor = '#3B82F6';
                if (o.payment_method === 'Maya') methodColor = '#10B981';
                if (o.payment_method === 'Charge to Room') methodColor = '#F59E0B';
                if (o.payment_method === 'Cash') methodColor = '#10B981';

                const card = document.createElement('div');
                card.className = 'pending-card';
                card.innerHTML = `
            <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                <strong style="color:#333;">Order #${o.id}</strong>
                <span style="font-size:0.8rem; color:#666;">${o.order_date.substring(11, 16)}</span>
            </div>
            <div style="font-size:0.9rem; color:#555; margin-bottom:10px;">
                <i class="fas fa-door-open"></i> ${o.room_number} <span style="color:#ccc;">|</span> ${o.guest_name || 'Guest'}
            </div>

            <div style="background:#f9f9f9; padding:10px; border-radius:6px; margin-bottom:10px;">
                ${itemsHtml}
            </div>

            ${receiptHtml}

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; margin-top:10px;">
                <span style="font-size:0.8rem; font-weight:700; color:${methodColor}; border:1px solid ${methodColor}; padding:2px 6px; border-radius:4px;">
                    ${o.payment_method}
                </span>
                <strong style="font-size:1.1rem; color:#333;">₱${parseFloat(o.total_price).toLocaleString()}</strong>
            </div>

            <div class="drawer-actions">
                <button class="ab-submit-btn" style="background:#EF4444; padding:8px;" onclick="processOrder(${o.id}, 'reject', this)">
                    Reject
                </button>
                <button class="ab-submit-btn" style="background:#FFA500; padding:8px;" onclick="processOrder(${o.id}, 'approve', this)">
                    Accept & Prepare
                </button>
            </div>
        `;
                container.appendChild(card);
            });
        }

        // --- 🟢 SMART OVERLAY HANDLER (Handles Both Drawers) ---
        function closeDrawersSmart() {
            // 1. SAFETY LOCK: If system is busy (Loading/Verifying), IGNORE click
            if (isDrawerBusy) {
                console.log("🔒 Overlay click blocked: System is busy.");
                return;
            }

            // 2. Check Booking Drawer
            const pendingDrawer = document.getElementById('pendingDrawer');
            if (pendingDrawer && pendingDrawer.classList.contains('open')) {
                togglePendingDrawer(); // Uses existing toggle logic
            }

            // 3. Check Food Order Drawer
            const orderDrawer = document.getElementById('orderDrawer');
            if (orderDrawer && orderDrawer.classList.contains('open')) {
                toggleOrderDrawer(); // Uses existing toggle logic
            }
        }

        // --- UPDATED PROCESS ORDER FUNCTION (With Rotating Spinner & Text) ---
        function processOrder(id, action, btnElement) {
            const actionText = action === 'approve' ? "Accept" : "Reject";

            if (!confirm(`Are you sure you want to ${actionText} this order?`)) return;

            // 1. LOCK UI GLOBALLY
            isDrawerBusy = true;
            toggleUILock(true, action === 'approve' ? "ACCEPTING ORDER..." : "REJECTING ORDER...");

            // 2. Visual Loading State (Save original, set new state)
            const originalContent = btnElement.innerHTML;
            const loadingLabel = action === 'approve' ? 'Accepting...' : 'Rejecting...';

            // 🟢 The Magic Line: Spinner + Specific Action Text
            btnElement.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${loadingLabel}`;
            btnElement.disabled = true;
            btnElement.style.opacity = '0.7';

            // 3. Lock the container visuals (Gray out list)
            const drawerBody = document.getElementById('orderDrawerBody');
            drawerBody.style.pointerEvents = 'none';
            drawerBody.style.opacity = '0.8';

            const formData = new FormData();
            formData.append('id', id);
            formData.append('action', action);

            fetch('approve_order.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        // 4. Success Animation (Slide Out)
                        const card = btnElement.closest('.pending-card');
                        if (card) {
                            card.style.transition = "all 0.5s ease";
                            card.style.opacity = "0";
                            // Slide Right for Approve, Left for Reject
                            card.style.transform = action === 'approve' ? "translateX(50px)" : "translateX(-50px)";

                            setTimeout(() => {
                                card.remove(); // Remove from DOM

                                // Check if list is empty
                                if (document.querySelectorAll('#orderDrawerBody .pending-card').length === 0) {
                                    document.getElementById('orderDrawerBody').innerHTML = `
                                    <div style="text-align:center; padding:40px; color:#9ca3af;">
                                        <i class="fas fa-check-circle" style="font-size:3rem; margin-bottom:15px; color:#D1D5DB;"></i>
                                        <p>No pending food orders.</p>
                                    </div>`;
                                }

                                // Refresh Tables & Stats
                                refreshFoodTable();
                                fetchDashboardCards();
                                alert(data.message);

                                // 5. Unlock UI (After animation finishes)
                                isDrawerBusy = false;
                                drawerBody.style.pointerEvents = 'auto';
                                drawerBody.style.opacity = '1';
                                toggleUILock(false);
                            }, 500); // Wait for CSS transition
                        }
                    } else {
                        // Error: Revert Button
                        alert("Error: " + data.message);
                        btnElement.innerHTML = originalContent;
                        btnElement.disabled = false;
                        btnElement.style.opacity = '1';

                        // Unlock UI
                        isDrawerBusy = false;
                        drawerBody.style.pointerEvents = 'auto';
                        drawerBody.style.opacity = '1';
                        toggleUILock(false);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                    // Revert Button
                    btnElement.innerHTML = originalContent;
                    btnElement.disabled = false;
                    btnElement.style.opacity = '1';

                    // Unlock UI
                    isDrawerBusy = false;
                    drawerBody.style.pointerEvents = 'auto';
                    drawerBody.style.opacity = '1';
                    toggleUILock(false);
                });
        }

        // 5. Update Red Dot
        function updateOrderPulse(count) {
            const dot = document.getElementById('orderPulse');
            if (dot) dot.style.display = count > 0 ? 'block' : 'none';
        }

        // --- RECEIPT ARCHIVE LOGIC ---

        // 1. Load Receipts based on Filter
        function loadReceipts() {
            const filterVal = document.getElementById('receiptFilterDate').value; // YYYY-MM
            const [year, month] = filterVal.split('-');

            const container = document.getElementById('receiptGrid');
            container.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:50px; color:#888;"><i class="fas fa-spinner fa-spin"></i> Loading receipts...</div>';

            fetch(`get_all_receipts.php?month=${month}&year=${year}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        renderReceiptGrid(data.data);
                    } else {
                        container.innerHTML = `<div style="grid-column:1/-1; text-align:center; color:red;">Error loading data.</div>`;
                    }
                })
                .catch(err => {
                    console.error(err);
                    container.innerHTML = `<div style="grid-column:1/-1; text-align:center; color:red;">System Error.</div>`;
                });
        }

        // ==========================================
        // 🟢 RECEIPT ARCHIVE LOGIC (SHOW ALL + FILTER)
        // ==========================================

        document.addEventListener("DOMContentLoaded", function () {
            if (document.getElementById("receiptFilterDate")) {
                flatpickr("#receiptFilterDate", {
                    dateFormat: "Y-m-d",
                    altInput: true,
                    altFormat: "F j, Y",
                    disableMobile: "true",
                    // Note: removed defaultDate: "today" so it defaults to "Show All" or empty on first load if you prefer
                    // or keep defaultDate: "today" if you want today to be default.

                    onChange: function (selectedDates, dateStr, instance) {
                        loadReceipts();
                    }
                });

                // Load initially (Will load 'All' if input is empty, or 'Today' if defaultDate is set)
                loadReceipts();
            }

            // Menu click listener
            const receiptMenuBtn = document.querySelector('.tree-item-card[onclick*="view-receipts"]');
            if (receiptMenuBtn) {
                receiptMenuBtn.addEventListener('click', () => {
                    // Optional: Auto-clear filter when opening the page fresh
                    // clearReceiptFilter(); 
                    loadReceipts();
                });
            }
        });

        // 🟢 NEW: Clear Filter Function
        function clearReceiptFilter() {
            const picker = document.querySelector("#receiptFilterDate")._flatpickr;
            if (picker) {
                picker.clear(); // Clears the visual input
            }
            loadReceipts(); // Reloads data with empty date (Show All)
        }

        // 🟢 UPDATED: Fetch Receipts
        function loadReceipts() {
            const input = document.getElementById('receiptFilterDate');
            if (!input) return;

            const dateVal = input.value; // Can be "2026-02-02" or "" (empty)

            const container = document.getElementById('receiptGrid');
            container.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:50px; color:#888;"><i class="fas fa-spinner fa-spin"></i> Loading receipts...</div>';

            // Send date (empty or specific)
            fetch(`get_all_receipts.php?date=${dateVal}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        renderReceiptGrid(data.data);
                    } else {
                        container.innerHTML = `<div style="grid-column:1/-1; text-align:center; color:red;">Error loading data.</div>`;
                    }
                })
                .catch(err => {
                    console.error(err);
                    container.innerHTML = `<div style="grid-column:1/-1; text-align:center; color:red;">System Error.</div>`;
                });
        }

        // --- UPDATED RENDER FUNCTION (Filters out Admin Bookings) ---
        function renderReceiptGrid(receipts) {
            const container = document.getElementById('receiptGrid');
            container.innerHTML = '';

            // 🟢 1. FILTER: Only keep bookings that have a REAL receipt image
            // This removes Admin/Walk-in bookings (empty image) or Room Charges
            const validReceipts = receipts.filter(r =>
                r.image &&
                r.image.trim() !== '' &&
                r.image !== 'Charge to Room'
            );

            // 2. Check if valid receipts exist
            if (validReceipts.length === 0) {
                container.innerHTML = `
        <div style="grid-column:1/-1; text-align:center; padding:50px; color:#999;">
            <i class="fas fa-file-invoice-dollar" style="font-size:3rem; margin-bottom:15px; opacity:0.3;"></i>
            <p>No online payment receipts found.</p>
        </div>`;
                return;
            }

            // 3. Render only the valid ones
            validReceipts.forEach(r => {
                const imgPath = '../../room_includes/uploads/receipts/' + r.image;

                const dateStr = new Date(r.date_time).toLocaleDateString('en-US', {
                    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                });

                const card = document.createElement('div');
                card.className = 'receipt-card';

                // Prevent click if clicking the delete button
                card.onclick = (e) => {
                    if (!e.target.closest('.delete-btn')) {
                        viewReceipt(imgPath);
                    }
                };

                card.innerHTML = `
            <div style="position:relative;">
                <button class="delete-btn" 
                    onclick="deleteReceipt(event, ${r.id}, '${r.source_table}', '${r.image}')"
                    style="position:absolute; top:10px; right:10px; background:rgba(239, 68, 68, 0.9); color:white; border:none; width:30px; height:30px; border-radius:50%; cursor:pointer; z-index:10; display:flex; align-items:center; justify-content:center; transition:0.2s;">
                    <i class="fas fa-trash-alt" style="font-size:0.8rem;"></i>
                </button>
                
                <img src="${imgPath}" class="receipt-thumb" loading="lazy" 
                     onerror="this.src='https://placehold.co/200x300?text=Image+Error'">
            </div>
            <div class="receipt-info">
                <span class="r-type">${r.type}</span>
                <div class="r-ref">${r.ref}</div>
                <div class="r-date"><i class="far fa-clock"></i> ${dateStr}</div>
            </div>
        `;
                container.appendChild(card);
            });
        }

        // --- NEW DELETE RECEIPT FUNCTION ---
        function deleteReceipt(event, id, table, filename) {
            event.stopPropagation(); // Prevent opening the lightbox

            if (!confirm("Are you sure you want to PERMANENTLY delete this receipt image? This cannot be undone.")) return;

            // Visual feedback: change icon to spinner
            const btn = event.currentTarget;
            const originalContent = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btn.disabled = true;

            const formData = new FormData();
            formData.append('id', id);
            formData.append('table', table);
            formData.append('filename', filename);
            // formData.append('csrf_token', csrfToken); // Uncomment if you enforce CSRF on this file

            fetch('delete_receipt.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        // Remove the card from UI immediately with a fade out
                        const card = btn.closest('.receipt-card');
                        card.style.opacity = '0';
                        setTimeout(() => card.remove(), 300);
                    } else {
                        alert("Error: " + data.message);
                        btn.innerHTML = originalContent;
                        btn.disabled = false;
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System Error");
                    btn.innerHTML = originalContent;
                    btn.disabled = false;
                });
        }

        // ==========================================
        // 🟢 UPDATED TRANSACTION PAGE LOGIC (INTERACTIVE PAGINATION)
        // ==========================================

        let transOffset = 0;
        const transLimit = 10;

        // 1. Main Load Function
        function loadTransactions(isSilent = false) {
            const tbody = document.getElementById('transactions_body');
            const filterType = document.getElementById('transFilterType').value;

            if (!isSilent) {
                tbody.style.opacity = '0.5';
                tbody.style.pointerEvents = 'none';
            }

            const url = `get_transactions.php?limit=${transLimit}&offset=${transOffset}&type=${filterType}`;

            fetch(url)
                .then(res => res.json())
                .then(response => {
                    tbody.style.opacity = '1';
                    tbody.style.pointerEvents = 'auto';

                    if (response.status === 'success') {
                        renderTransactionTable(response.data, filterType);
                        updateTransPaginationUI(response.total, response.limit, response.offset);
                    } else {
                        if (!isSilent) tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; color:red; padding:20px;">Error: ${response.message}</td></tr>`;
                    }
                })
                .catch(err => {
                    tbody.style.opacity = '1';
                    tbody.style.pointerEvents = 'auto';
                    console.error("Load Error:", err);
                    if (!isSilent) tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; color:red; padding:20px;">System Error</td></tr>`;
                });
        }

        // 2. Interactive Pagination UI Generator
        function updateTransPaginationUI(total, limit, offset) {
            const start = total === 0 ? 0 : offset + 1;
            const end = Math.min(offset + limit, total);
            const currentPage = Math.floor(offset / limit) + 1;
            const totalPages = Math.ceil(total / limit);

            document.getElementById('transPageStart').innerText = start;
            document.getElementById('transPageEnd').innerText = end;
            document.getElementById('transTotalCount').innerText = total;

            const btnContainer = document.getElementById('transPageButtons');
            btnContainer.innerHTML = ''; // Clear existing

            // Helper to add dots
            const addDots = () => {
                const span = document.createElement('span');
                span.className = 'pg-dots';
                span.innerText = '...';
                btnContainer.appendChild(span);
            };

            // Previous Button
            const prevBtn = document.createElement('button');
            prevBtn.className = 'pg-btn pg-btn-nav';
            prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i> Prev';
            prevBtn.disabled = (currentPage === 1);
            prevBtn.onclick = (e) => {
                e.preventDefault();
                transOffset = Math.max(0, transOffset - limit);
                loadTransactions();
            };
            btnContainer.appendChild(prevBtn);

            // Page Numbers Logic (Smart Sliding Window)
            let startPage = Math.max(1, currentPage - 1);
            let endPage = Math.min(totalPages, startPage + 2);

            if (endPage - startPage < 2) {
                startPage = Math.max(1, endPage - 2);
            }

            // First Page + Dots
            if (startPage > 1) {
                btnContainer.appendChild(createPageBtn(1, limit, 1 === currentPage));
                if (startPage > 2) addDots();
            }

            // Middle Pages
            for (let i = startPage; i <= endPage; i++) {
                if (i > 0) btnContainer.appendChild(createPageBtn(i, limit, i === currentPage));
            }

            // Last Page + Dots
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) addDots();
                btnContainer.appendChild(createPageBtn(totalPages, limit, totalPages === currentPage));
            }

            // Next Button
            const nextBtn = document.createElement('button');
            nextBtn.className = 'pg-btn pg-btn-nav';
            nextBtn.innerHTML = 'Next <i class="fas fa-chevron-right"></i>';
            nextBtn.disabled = (currentPage === totalPages || total === 0);
            nextBtn.onclick = (e) => {
                e.preventDefault();
                transOffset += limit;
                loadTransactions();
            };
            btnContainer.appendChild(nextBtn);
        }

        function createPageBtn(page, limit, isActive = false) {
            const btn = document.createElement('button');
            btn.className = 'pg-btn' + (isActive ? ' active' : '');
            btn.innerText = page;
            btn.onclick = () => {
                transOffset = (page - 1) * limit;
                loadTransactions();
            };
            return btn;
        }

        // 🟢 DEPRECATED (Moved Logic to loadTransactions)
        function changeTransPage(direction) { }

        // 4. Render Function (Keep your existing table builder)
        function renderTransactionTable(data, filter) {
            const tbody = document.getElementById('transactions_body');
            tbody.innerHTML = '';

            if (!data || data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:40px; color:#999;">No transactions recorded yet.</td></tr>';
                return;
            }

            data.forEach(t => {
                let typeBadge = '';
                if (t.transaction_type === 'Booking') {
                    typeBadge = '<span class="badge" style="background:#E0E7FF; color:#3730A3; border:1px solid #C7D2FE;">Booking</span>';
                } else {
                    typeBadge = '<span class="badge" style="background:#FFF7ED; color:#9A3412; border:1px solid #FED7AA;">Food Order</span>';
                }

                let statusClass = 'badge-pending';
                if (t.status === 'Paid') statusClass = 'badge-confirmed';
                if (t.status === 'Failed' || t.status === 'Cancelled') statusClass = 'badge-cancelled';

                let methodIcon = '<i class="fas fa-money-bill-wave" style="color:#10B981;"></i>';
                if (t.payment_method === 'GCash' || t.payment_method === 'Maya') {
                    methodIcon = '<i class="fas fa-mobile-alt" style="color:#3B82F6;"></i>';
                }

                const dateObj = new Date(t.created_at);
                const dateStr = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                const timeStr = dateObj.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

                const tr = document.createElement('tr');
                tr.style.cursor = 'pointer';
                tr.className = 'transaction-row';
                tr.onclick = function () { openTransactionDetails(t); };

                tr.innerHTML = `
                    <td style="color:#888;">#${t.id}</td>
                    <td>
                        <div style="font-weight:600; color:#333;">${t.user_name || 'Guest User'}</div>
                        <div style="font-size:0.75rem; color:#888;">${t.email || 'No email'}</div>
                    </td>
                    <td style="font-family:monospace; font-size:0.9rem; color:#555; font-weight:700;">${t.reference_id}</td>
                    <td>${typeBadge}</td>
                    <td style="font-weight:700; color:#333;">₱${parseFloat(t.amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                    <td style="color:#555;">${methodIcon} ${t.payment_method}</td>
                    <td><span class="badge ${statusClass}">${t.status}</span></td>
                    <td style="text-align:right;">
                        <div style="font-size:0.85rem; color:#333;">${dateStr}</div>
                        <div style="font-size:0.75rem; color:#999;">${timeStr}</div>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        // 🟢 NEW: Open Modal Function
        function openTransactionDetails(t) {
            // 1. Populate Text Fields
            document.getElementById('trans_id').innerText = '#' + t.id;
            document.getElementById('trans_ref').innerText = t.reference_id;
            document.getElementById('trans_type').innerText = t.transaction_type;
            document.getElementById('trans_method').innerText = t.payment_method;
            document.getElementById('trans_amount').innerText = '₱' + parseFloat(t.amount).toLocaleString(undefined, { minimumFractionDigits: 2 });

            document.getElementById('trans_user_name').innerText = t.user_name || 'Guest User';
            document.getElementById('trans_user_email').innerText = t.email || 'No Email Provided';

            // 2. Format Date
            const d = new Date(t.created_at);
            const dateFormatted = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' - ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            document.getElementById('trans_date').innerText = dateFormatted;

            // 3. Style the Status Badge
            const badge = document.getElementById('trans_status_badge');
            badge.innerText = t.status.toUpperCase();

            // Reset classes
            badge.style.backgroundColor = '#eee';
            badge.style.color = '#333';
            badge.style.border = '1px solid #ddd';

            if (t.status === 'Paid') {
                badge.style.backgroundColor = '#DCFCE7'; // Green
                badge.style.color = '#166534';
                badge.style.borderColor = '#BBF7D0';
            } else if (t.status === 'Pending') {
                badge.style.backgroundColor = '#FFF7ED'; // Orange
                badge.style.color = '#9A3412';
                badge.style.borderColor = '#FED7AA';
            } else if (t.status === 'Failed' || t.status === 'Cancelled') {
                badge.style.backgroundColor = '#FEE2E2'; // Red
                badge.style.color = '#991B1B';
                badge.style.borderColor = '#FECACA';
            }

            // 4. Show Modal
            document.getElementById('transactionModal').style.display = 'block';
        }

        // --- TOGGLE ADD BOOKING SELECT ---
        function toggleAddBookingSelect(e) {
            e.stopPropagation(); // Prevent immediate closing

            const wrapper = document.getElementById('addBookingWrapper');
            const options = wrapper.querySelector('.custom-options');

            // 1. Close all other custom selects first (to keep UI clean)
            document.querySelectorAll('.custom-select-wrapper').forEach(ws => {
                if (ws !== wrapper) {
                    ws.classList.remove('open');
                    const opt = ws.querySelector('.custom-options');
                    if (opt) opt.classList.remove('open');
                }
            });

            // 2. Toggle 'open' class on Wrapper (Rotates the Arrow)
            wrapper.classList.toggle('open');

            // 3. Toggle 'open' class on Options (Shows the Menu with Animation)
            if (wrapper.classList.contains('open')) {
                options.classList.add('open');
            } else {
                options.classList.remove('open');
            }
        }

        // Ensure clicking outside closes it (Reuse your existing global listener or add this)
        window.addEventListener('click', function (e) {
            const wrapper = document.getElementById('addBookingWrapper');
            if (wrapper && !wrapper.contains(e.target)) {
                wrapper.classList.remove('open');
                const options = wrapper.querySelector('.custom-options');
                if (options) options.classList.remove('open');
            }
        });


        // --- COMPOSE MESSAGE LOGIC ---

        function openComposeModal(prefillEmail = '') {
            // 1. Reset form
            document.getElementById('composeForm').reset();
            document.getElementById('customSubjectInput').style.display = 'none';

            // 2. Prefill if email is passed (e.g. from table action)
            if (prefillEmail) {
                document.getElementById('composeEmail').value = prefillEmail;
            }

            // 3. Show Modal
            document.getElementById('composeModal').style.display = 'block';

            // 4. Close the dropdown menu if open
            document.getElementById('msgDropdown').classList.remove('show');
        }

        function closeComposeModal() {
            // 🔴 LOCK CHECK: If sending, do nothing
            if (isSendingEmail) {
                return;
            }
            document.getElementById('composeModal').style.display = 'none';
        }

        function toggleCustomSubject() {
            const select = document.getElementById('composeSubjectType');
            const input = document.getElementById('customSubjectInput');
            if (select.value === 'Other') {
                input.style.display = 'block';
                input.setAttribute('required', 'true');
            } else {
                input.style.display = 'none';
                input.removeAttribute('required');
            }
        }

        function sendGuestEmail(e) {
            e.preventDefault();

            // Ask for confirmation before sending
            if (!confirm("Are you sure you want to send this email to the guest?")) {
                return;
            }

            // Prevent double clicks
            if (isSendingEmail) return;

            const form = document.getElementById('composeForm');
            const submitBtn = form.querySelector('button[type="submit"]');
            const cancelBtn = form.querySelector('button[type="button"]'); // The Cancel button
            const closeXBtn = document.querySelector('#composeModal .ab-close-btn'); // The X button in header

            const originalText = submitBtn.innerHTML;

            // 1. LOCK UI (Active Busy Mode)
            isSendingEmail = true;
            toggleUILock(true, "SENDING EMAIL TO GUEST...");

            // Change Send Button State
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            submitBtn.style.opacity = '0.7';

            // Disable Cancel & Close Buttons (Visual Feedback)
            if (cancelBtn) {
                cancelBtn.disabled = true;
                cancelBtn.style.opacity = '0.5';
                cancelBtn.style.cursor = 'not-allowed';
            }
            if (closeXBtn) {
                closeXBtn.disabled = true;
                closeXBtn.style.opacity = '0.5';
                closeXBtn.style.cursor = 'not-allowed';
            }

            const formData = new FormData(form);
            formData.append('csrf_token', csrfToken);

            fetch('send_guest_email.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert("✅ Email sent successfully!");
                        // Important: Reset flag BEFORE closing so the close function works
                        isSendingEmail = false;
                        closeComposeModal();
                    } else {
                        alert("❌ Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert("System error sending email. Check console.");
                })
                .finally(() => {
                    // 2. UNLOCK UI
                    isSendingEmail = false;
                    toggleUILock(false);

                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                    submitBtn.style.opacity = '1';

                    if (cancelBtn) {
                        cancelBtn.disabled = false;
                        cancelBtn.style.opacity = '1';
                        cancelBtn.style.cursor = 'pointer';
                    }
                    if (closeXBtn) {
                        closeXBtn.disabled = false;
                        closeXBtn.style.opacity = '1';
                        closeXBtn.style.cursor = 'pointer';
                    }
                });
        }
        // ---------------------------------------------------------------
        // 🟢 FINAL SMART REAL-TIME LOGIC (Consolidated & Corrected)
        // ---------------------------------------------------------------

        // 🟢 SINGLE SESSION SECURITY CHECK
        setInterval(function () {
            fetch('check_session.php')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'logout') {
                        alert("⚠️ Session Terminated\n\nYou have been logged out because your account was accessed from another device or browser tab.");
                        window.location.href = 'login.php'; // Redirect to login
                    }
                })
                .catch(err => console.error("Security check failed", err));
        }, 5000); // Checks every 5 seconds

        // 1. Transactions (Every 1 second)
        setInterval(() => {
            const page = document.getElementById('transactions');
            if (page && page.classList.contains('active')) {
                loadTransactions(true);
            }
        }, 1000);

        // 2. Food Orders Table (Every 1 second)
        setInterval(() => {
            const page = document.getElementById('food-ordered');
            const drawerOpen = document.getElementById('orderDrawer').classList.contains('open');

            // Only refresh table if active AND the side drawer is CLOSED
            if (page && page.classList.contains('active') && !drawerOpen) {
                refreshFoodTable();
            }
        }, 1000);

        // 3. Bookings Table (Every 1 second)
        setInterval(() => {
            const page = document.getElementById('bookings');
            const searchInput = document.getElementById('bookingSearchInput');
            const isTyping = searchInput && searchInput.value.trim() !== "";

            // Only refresh if active AND user is NOT typing
            if (page && page.classList.contains('active') && !isTyping) {
                refreshBookingTable();
            }
        }, 1000);

        // 4. Calendar (Every 2 seconds)
        setInterval(() => {
            const page = document.getElementById('calendar');
            if (page && page.classList.contains('active')) {
                refreshCalendarData();
            }
        }, 2000);

        // 5. Dashboard Overview (Every 2 seconds)
        setInterval(() => {
            const page = document.getElementById('dashboard');
            if (page && page.classList.contains('active')) {
                fetchDashboardCards();
            }
        }, 2000);

        // 6. Guests Database (Every 2 seconds)
        setInterval(() => {
            const page = document.getElementById('guests');
            const searchInput = document.getElementById('guestSearchInput');
            const isTyping = searchInput && searchInput.value.trim() !== "";

            // Only refresh if active AND user is NOT typing
            if (page && page.classList.contains('active') && !isTyping) {
                fetchGuestList();
            }
        }, 2000);

        // Checks every 60 seconds if it is past 12:00 PM and triggers alerts
        setInterval(function () {
            // Optional: Only fetch if it's actually past 11 AM to save bandwidth
            const currentHour = new Date().getHours();
            if (currentHour >= 12) {
                fetch('trigger_checkout_alerts.php')
                    .then(res => res.json())
                    .then(data => {
                        if (data.sent > 0) {
                            console.log("🔔 Auto-Alert: Sent " + data.sent + " checkout notifications.");
                            fetchHeaderData(); // Refresh the bell icon immediately
                        }
                    })
                    .catch(err => console.error("Auto-Alert Error:", err));
            }
        }, 60000); // Runs every 1 minute

        // 7. Global Background Tasks (Runs on ALL pages for Header Icons)
        // This replaces the 15s timers you deleted. Now they run every 5s.
        setInterval(() => {
            fetchHeaderData();        // Updates Bell & Message Badges
            fetchPendingBookings();   // Updates Clipboard Red Dot
            fetchPendingOrders();     // Updates Food Tray Red Dot
        }, 5000);

        setInterval(checkAutoReminders, 60000); // Check emails every 1 min
        setInterval(triggerAutoUpdates, 60000);   // Check no-shows every 1 min

        // 🟢 EXCLUSIVE ACCESS HEARTBEAT: Renew the lock every 1 minute
        setInterval(function () {
            fetch('heartbeat.php').catch(err => console.error("Heartbeat failed", err));
        }, 60000);

        // 🟢 MOST BOOKED DATES MODAL LOGIC
        function openDateLeaderboardModal() {
            const modal = document.getElementById('dateLeaderboardModal');
            modal.style.display = 'block';

            // 1. Render Category Buttons (Months)
            renderDateCategoryButtons(currentChartData.availableMonths);

            // 2. Initial Render: All Time (using current data from state)
            filterDateLeaderboardByMonth('all');
        }

        function closeDateLeaderboardModal() {
            document.getElementById('dateLeaderboardModal').style.display = 'none';
        }

        function renderDateCategoryButtons(months) {
            const container = document.getElementById('dateCategoryContainer');
            if (!container) return;

            // Start with "All Time" button
            let html = `<button class="month-category-btn active" data-month="all" onclick="filterDateLeaderboardByMonth('all', this)">All Time</button>`;

            if (Array.isArray(months)) {
                months.forEach(m => {
                    html += `<button class="month-category-btn" data-month="${m.value}" onclick="filterDateLeaderboardByMonth('${m.value}', this)">${m.label}</button>`;
                });
            }

            container.innerHTML = html;
        }

        function filterDateLeaderboardByMonth(monthVal, btn = null) {
            // 1. UI Update (Button Active State)
            if (btn) {
                document.querySelectorAll('.month-category-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            }

            const listContainer = document.getElementById('modalDateLeaderboardList');
            listContainer.innerHTML = '<div style="text-align:center; padding:40px; color:#999;"><i class="fas fa-spinner fa-spin"></i> Filtering...</div>';

            // 2. Fetch specific data for this month if not 'all'
            // If 'all', we use currentChartData.date
            if (monthVal === 'all') {
                renderDateLeaderboardInModal(currentChartData.date);
            } else {
                fetch(`get_dashboard_stats.php?date=${monthVal}&_t=${new Date().getTime()}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            renderDateLeaderboardInModal(data.date_leaderboard);
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        listContainer.innerHTML = '<div style="text-align:center; padding:40px; color:red;">Error loading data.</div>';
                    });
            }
        }

        function renderDateLeaderboardInModal(data) {
            const container = document.getElementById('modalDateLeaderboardList');
            if (!container) return;

            if (!Array.isArray(data) || data.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:40px; color:#999;">No booked dates found for this selection.</div>';
                return;
            }

            const maxCount = Math.max(...data.map(d => d.count)) || 1;
            let listHtml = '';

            data.forEach((item, i) => {
                const pct = (item.count / maxCount) * 100;
                const rank = i + 1;

                let rCol = '#6B7280', rBg = '#F3F4F6';
                if (rank === 1) { rCol = '#B88E2F'; rBg = '#FFF8E1'; }
                else if (rank === 2) { rCol = '#4B5563'; rBg = '#F9FAFB'; }
                else if (rank === 3) { rCol = '#92400E'; rBg = '#FFFBEB'; }

                listHtml += `
                    <div class="leaderboard-row" 
                         style="display:flex; align-items:center; gap:15px; background:#fff; padding:15px; border-radius:12px; border:1px solid #f0f0f0; margin-bottom:12px; transition: all 0.3s ease; animation: cardFadeIn 0.3s ease forwards; animation-delay: ${i * 40}ms; opacity: 0;">
                        
                        <div style="width:36px; height:36px; background:${rBg}; color:${rCol}; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:0.9rem; flex-shrink:0; border: 1px solid ${rank <= 3 ? rCol + '44' : 'transparent'};">
                            ${rank}
                        </div>
                        <div style="flex:1; min-width:0;">
                            <div style="display:flex; justify-content:space-between; margin-bottom:8px; align-items:center;">
                                <span style="font-weight:700; color:#333; font-size:1rem;">${item.name}</span>
                                <span style="font-weight:800; color:#B88E2F; font-size:1.1rem;">${item.count} <small style="font-size:0.75rem; color:#999; font-weight:600;">bookings</small></span>
                            </div>
                            <div style="height:8px; background:#f1f5f9; border-radius:10px; overflow:hidden;">
                                <div class="modal-date-progress-bar" style="height:100%; width:${pct}%; background:${rank === 1 ? '#B88E2F' : '#cbd5e1'}; border-radius:10px; transition: width 1s ease-out;"></div>
                            </div>
                        </div>
                    </div>`;
            });

            container.innerHTML = listHtml;
        }

        // 🟢 INITIAL LOAD (Runs once immediately when page opens)
        // ---------------------------------------------------------------
        document.addEventListener("DOMContentLoaded", function () {
            // Load header counts immediately
            fetchHeaderData();
            fetchPendingBookings();
            fetchPendingOrders();

            // Load content for whatever page is currently active
            fetchDashboardCards();
            refreshBookingTable();
            filterTable('today'); // 🟢 Default selected tab for bookings
            fetchGuestList();
            checkAutoReminders();
            renderRealtimeCalendar();
            updateYearButtons();
            fetchRevenueChart(currentChartYear);
        });

        /**
 * 🔒 MULTI-TAB PREVENTION SYSTEM
 * This uses the BroadcastChannel API to communicate between tabs.
 */
        (function () {
            const channel = new BroadcastChannel('amv_admin_session');

            // 1. When a new tab opens, it "pings" other tabs to see if they exist
            channel.postMessage({ type: 'NEW_TAB_OPENED' });

            // 2. Listen for messages from other tabs
            channel.onmessage = (event) => {
                if (event.data.type === 'NEW_TAB_OPENED') {
                    // An existing tab heard a new tab opening. 
                    // It sends back an "I'M ALREADY HERE" message.
                    channel.postMessage({ type: 'SESSION_ALREADY_ACTIVE' });
                }

                if (event.data.type === 'SESSION_ALREADY_ACTIVE') {
                    // This tab just opened, but received word that another tab is active.
                    // We block this tab immediately.
                    alert("⚠️ Access Denied: You already have an active Admin session open in another tab.");
                    window.location.href = "login.php?error=multiple_tabs";
                }
            };
        })();
    </script>

</body>

</html>
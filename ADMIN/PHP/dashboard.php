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

// 🟢 CHECK IF WE SHOULD SHOW THE INITIAL LOADER
// Only show if redirected from loading.php (contains ?login=success)
$show_loader = isset($_GET['login']) && $_GET['login'] === 'success';

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
$sql_list_base = "SELECT
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

if ($search) {
    $sql_list_base .= " AND (
        b.booking_reference LIKE ? OR 
        u.name LIKE ? OR 
        bg.first_name LIKE ? OR 
        bg.last_name LIKE ? OR
        CONCAT(bg.first_name, ' ', bg.last_name) LIKE ?
    )";
}

$sql_list_base .= " GROUP BY b.id";

// 🟢 GET TOTAL COUNT FOR PAGINATION
$total_bookings_sql = "SELECT COUNT(*) as total FROM ( $sql_list_base ) as sub";
$total_stmt = $conn->prepare($total_bookings_sql);
if ($search) {
    $searchTerm = "%" . $search . "%";
    $total_stmt->bind_param("sssss", $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}
$total_stmt->execute();
$total_bookings_count = $total_stmt->get_result()->fetch_assoc()['total'];

// 🟢 FETCH DATA WITH LIMIT
$sql_list = $sql_list_base . " ORDER BY FIELD(b.status, 'confirmed', 'cancelled'), b.check_in ASC LIMIT 100";
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
    ORDER BY MAX(last_name) ASC LIMIT 100";$result_guests = $conn->query($sql_guests);

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
               o.order_date ASC LIMIT 100";

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
    <script>
        const csrfToken = "<?php echo $_SESSION['csrf_token']; ?>";
        const pieDataRaw = <?php echo $js_pieData; ?>;
        const initialRevenue = <?php echo $js_barData; ?>;
        let bookingsDB = <?php echo $js_calendarData; ?>;
        let allRoomsList = <?php echo $js_allRoomsJSON; ?>;
    </script>
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
    <script type="module" src="https://cdn.jsdelivr.net/npm/ldrs/dist/auto/jelly.js"></script>
    <link rel="stylesheet" href="dashboard_styles.css">
</head>

<body>

    <!-- 🟢 INITIAL DASHBOARD LOADER (Seamless from loading.php) -->
    <div id="initialDashboardLoader" style="<?php echo $show_loader ? '' : 'display: none;'; ?>">
        <div class="loader-container">
            <l-jelly size="60" speed="0.9" color="#B88E2F"></l-jelly>
            <h3>INITIALIZING DASHBOARD</h3>
            <p>Please wait while we prepare your data...</p>
        </div>
    </div>

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
                                            placeholder="Select Date..." readonly>
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
                            <div class="dropdown-header"
                                style="position: relative; display:flex; flex-direction:column; gap:10px;">
                                <div
                                    style="display:flex; justify-content:space-between; width:100%; align-items:center;">
                                    <h4 class="dropdown-title">Notifications</h4>
                                    <div style="display:flex; gap: 8px;">
                                        <button class="filter-btn" style="color: #2563EB; font-size: 0.7rem;"
                                            onclick="markAllNotificationsRead(event)">
                                            <i class="fas fa-check-double"></i> Mark All
                                        </button>
                                        <button class="filter-btn" onclick="toggleNotifFilter(event)">
                                            <i class="fas fa-sliders-h"></i> Filter
                                        </button>
                                    </div>
                                </div>

                                <div id="notifFilterMenu" class="filter-menu-container" style="top: 50px;">
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
                                            placeholder="Select Date..." readonly>
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

                        <div style="display: flex; align-items: center; gap: 15px;">
                            <!-- 🟢 Container 1: Most Booked Dates -->
                            <div style="display: flex; align-items: center;">
                                <button class="most-booked-btn" onclick="openDateLeaderboardModal()">
                                    <i class="fas fa-calendar-star"></i> Most Booked Dates
                                </button>
                            </div>

                            <!-- 🟢 Container 2: View Analytics -->
                            <div class="analytics-filter-container">
                                <label style="font-size: 0.85rem; font-weight: 700; color: #4B5563; text-transform: uppercase; letter-spacing: 0.5px;">View Analytics:</label>

                                <div style="width: 200px;">
                                    <select id="dashboardMonthPicker" class="ab-select"
                                        onchange="toggleMonthInput(this.value); fetchDashboardCards()">
                                        <option value="overall" selected>Overall Analytics</option>
                                        <option value="custom">Specific Month</option>
                                    </select>
                                </div>

                                <div id="customMonthWrapper" style="display: none; width: 220px; position: relative; opacity: 0; transform: translateX(-10px); transition: all 0.3s ease;">
                                    <input type="text" id="customMonthInput" class="custom-date-input-enhanced"
                                        placeholder="Select Month..." readonly>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="stats-grid">
                        <div class="stat-card" id="card_guests">
                            <div class="skeleton-circle skeleton" style="display: none;"></div>
                            <div class="skeleton-title skeleton" style="display: none;"></div>
                            <h3 class="stat-value" id="stat_guests"><?php echo $activeBookings; ?></h3>
                            <p class="stat-label" id="label_guests">Total Successful Bookings (Cumulative)</p>
                        </div>

                        <div class="stat-card" id="card_revenue">
                            <div class="skeleton-circle skeleton" style="display: none;"></div>
                            <div class="skeleton-title skeleton" style="display: none;"></div>
                            <h3 class="stat-value"><span
                                    id="stat_revenue">₱<?php echo number_format($overallRevenue, 0); ?></span></h3>
                            <p class="stat-label" id="label_revenue">Overall Revenue</p>
                        </div>

                        <div class="stat-card" id="card_occupancy">
                            <div class="skeleton-circle skeleton" style="display: none;"></div>
                            <div class="skeleton-title skeleton" style="display: none;"></div>
                            <h3 class="stat-value"><span id="stat_occupancy"><?php echo $occupancyRate; ?>%</span></h3>
                            <p class="stat-label" id="label_occupancy">Overall Occupancy</p>
                        </div>

                        <div class="stat-card" id="card_pending">
                            <div class="skeleton-circle skeleton" style="display: none;"></div>
                            <div class="skeleton-title skeleton" style="display: none;"></div>
                            <h3 class="stat-value" id="stat_pending"><?php echo $pendingRequests; ?></h3>
                            <p class="stat-label">Arriving Today</p>
                        </div>

                        <div class="stat-card" id="card_orders">
                            <div class="skeleton-circle skeleton" style="display: none;"></div>
                            <div class="skeleton-title skeleton" style="display: none;"></div>
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
                                    <div id="roomStatsSkeleton" class="skeleton skeleton-chart"
                                        style="display: none; position: absolute; inset: 0; z-index: 5;"></div>
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
                                <div id="revenueChartSkeleton" class="skeleton skeleton-chart"
                                    style="display: none; position: absolute; inset: 0 20px 20px 20px; z-index: 5;">
                                </div>
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
                        <div>
                            <h2 class="fs-md" style="margin: 0;">Guest Database</h2>
                            <p style="font-size:0.85rem; color:#666; margin:0;">Manage and view detailed history for all registered guests.</p>
                        </div>

                            <div style="position:relative;">
                                <input type="text" id="guestSearchInput" class="search-input"
                                    placeholder="Search Name or Email...">
                                <i class="fa-solid fa-magnifying-glass"
                                    style="position:absolute; right:15px; top:50%; transform:translateY(-50%); color:#999; font-size: 0.9rem; pointer-events:none;"></i>
                            </div>
                        </div>

                        <!-- guest table -->
                        <div class="booking-table-container">
                            <table class="booking-table" id="guestMainTable">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Nationality</th>
                                        <th style="text-align: center;">Total Bookings</th>
                                        <th style="text-align: center;">Total Orders</th>
                                        <th style="text-align: center;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="guestTableBody">
                                    <tr>
                                        <td colspan="7" style="padding: 100px 0; text-align: center;">
                                            <div class="amv-loader-container">
                                                <div class="amv-loader"></div>
                                                <div style="font-weight: 600; font-size: 1.1rem; letter-spacing: 0.5px; color: #B88E2F;">Loading Guest Data...</div>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot id="guestPaginationFoot" style="display: <?php echo ($result_guests->num_rows > 100) ? 'table-footer-group' : 'none'; ?>;">
                                   <tr>
                                       <td colspan="7" style="padding: 0;">
                                           <!-- 🟢 PAGINATION CONTROLS FOR GUESTS -->
                                           <div id="guestPagination" class="pagination-container"
                                               style="display: <?php echo ($result_guests->num_rows > 100) ? 'flex' : 'none'; ?>; border-top: 1px solid #f3f4f6; border-radius: 0 0 12px 12px;">                                                <div class="pagination-info">
                                                    Showing <span><span
                                                                id="guestPageStart"><?php echo ($result_guests->num_rows > 0) ? 1 : 0; ?></span>
                                                            - <span
                                                                id="guestPageEnd"><?php echo $result_guests->num_rows; ?></span></span> of
                                                    <span id="guestTotalCount"><?php echo $result_guests->num_rows; ?></span> records
                                                </div>
                                                <div class="pagination-buttons" id="guestPageButtons">
                                                    <!-- Page numbers generated here -->
                                                    <?php if ($result_guests->num_rows > 0): ?>
                                                        <button class="pg-btn pg-btn-nav" disabled><i class="fas fa-chevron-left"></i> Prev</button>
                                                        <button class="pg-btn active">1</button>
                                                        <button class="pg-btn pg-btn-nav" disabled>Next <i
                                                                class="fas fa-chevron-right"></i></button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>

                            <div id="noGuestDataMessage"
                                style="display:none; text-align:center; padding:20px; color:#888;">
                                No guests found matching your search.
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
                            <div id="guestProfileLoader" style="padding: 100px 0; text-align: center;">
                                <div class="amv-loader-container">
                                    <div class="amv-loader"></div>
                                    <div style="font-weight: 600; font-size: 1.2rem; letter-spacing: 0.5px; color: #B88E2F;">Loading Profile...</div>
                                    <p style="color: #888; font-size: 0.9rem; margin-top: 10px;">Retrieving guest history and details</p>
                                </div>
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
                                        <div class="booking-table-container" style="height:auto; max-height:400px; overflow-y:auto; border: 1px solid #eee; border-radius: 8px;">
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
                                                <tfoot id="gp_history_pagination_foot" style="display:none;">
                                                    <tr>
                                                        <td colspan="5" style="padding:0;">
                                                            <div id="gp_history_pagination" class="pagination-container" style="border-top: 1px solid #eee; background: #fafafa;">
                                                                <!-- Pagination content injected by JS -->
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>

                                    <div id="gp_orders_container" class="history-view" style="display: none;">
                                        <div class="booking-table-container" style="height:auto; max-height:400px; overflow-y:auto; border: 1px solid #eee; border-radius: 8px;">
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
                                                <tfoot id="gp_orders_pagination_foot" style="display:none;">
                                                    <tr>
                                                        <td colspan="5" style="padding:0;">
                                                            <div id="gp_orders_pagination" class="pagination-container" style="border-top: 1px solid #eee; background: #fafafa;">
                                                                <!-- Pagination content injected by JS -->
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </tfoot>
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
                                                    <input type="text" class="ab-input custom-date-input-enhanced" id="edit_dob"
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
                                        style="background-color: black; color:white; border:none; padding:10px 20px; border-radius:6px; cursor:pointer; font-weight:600; white-space:nowrap; display:flex; align-items:center; gap:8px; height: 42px;">
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
                            <table class="booking-table" id="bookingMainTable">
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

                                            <tr class="booking-row clickable-row" 
                                                data-status="<?php echo $row['status']; ?>"
                                                data-checkin="<?php echo $row['check_in']; ?>"
                                                data-cutoff="<?php echo $row['check_in'] . ' 20:00:00'; ?>"
                                                data-checkout="<?php echo $row['check_out']; ?>"
                                                data-arrival="<?php echo $row['arrival_status']; ?>"
                                                data-created="<?php echo $createdDate; ?>" 
                                                id="row-<?php echo $row['id']; ?>"
                                                style="cursor: pointer;"
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

                                                <td style="text-align: center;">
                                                    <span style="color:#B88E2F; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">
                                                        Tap to View <i class="fas fa-chevron-right" style="font-size:0.65rem; margin-left:3px;"></i>
                                                    </span>
                                                </td>
                                            </tr>

                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" style="text-align:center; padding:60px 20px; color:#94a3b8;">
                                                <div
                                                    style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;">
                                                    <div
                                                        style="width:64px; height:64px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                                                        <i class="fas fa-calendar-times"
                                                            style="font-size:1.8rem; color:#cbd5e1;"></i>
                                                    </div>
                                                    <div style="font-weight:600; font-size:1.1rem; color:#64748b;">No Bookings Found</div>
                                                    <p style="margin:0; font-size:0.9rem; max-width:300px; line-height:1.5;">There are no active bookings found matching your criteria.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot id="bookingPaginationFoot" style="display: <?php echo ($result_list->num_rows > 100) ? 'table-footer-group' : 'none'; ?>;">
                                    <tr>
                                        <td colspan="10" style="padding: 0;">
                                            <!-- 🟢 PAGINATION CONTROLS FOR BOOKINGS (ATTACHED) -->
                                            <div id="bookingPagination" class="pagination-container"
                                                style="display: <?php echo ($result_list->num_rows > 100) ? 'flex' : 'none'; ?>; border-top: 1px solid #f3f4f6; border-radius: 0 0 12px 12px;">
                                                <div class="pagination-info">
                                                    Showing <span><span
                                                                id="bookingPageStart"><?php echo ($result_list->num_rows > 0) ? 1 : 0; ?></span>
                                                            - <span
                                                                id="bookingPageEnd"><?php echo $result_list->num_rows; ?></span></span> of
                                                    <span id="bookingTotalCount"><?php echo $result_list->num_rows; ?></span>
                                                    records
                                                </div>
                                                <div class="pagination-buttons" id="bookingPageButtons">
                                                    <!-- Page numbers generated here -->
                                                    <?php if ($result_list->num_rows > 0): ?>
                                                        <button class="pg-btn pg-btn-nav" disabled><i class="fas fa-chevron-left"></i> Prev</button>
                                                        <button class="pg-btn active">1</button>
                                                        <button class="pg-btn pg-btn-nav" disabled>Next <i
                                                                class="fas fa-chevron-right"></i></button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                            <div id="noDataMessage" style="display:none; text-align:center; padding:20px; color:#888;">
                                No bookings found in this category.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="page" id="food-ordered" style="overflow-y: auto;">
                    <div class="p-3" style="display: flex; flex-direction: column; height: 100%;">

                        <div class="bookings-toolbar">
                            <h2 class="fs-md">Incoming Food Orders</h2>
                            <button class="btn-primary" onclick="refreshFoodTable()"
                                style="color:white; border:none; padding:8px 15px; border-radius:6px; cursor:pointer;">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>

                        <div class="booking-table-container">
                            <table class="booking-table" id="foodMainTable">
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
                                                </td>

                                                <td>
                                                    <?php if (!empty($order['notes'])): ?>
                                                        <div
                                                            style="font-size:0.85rem; color:#d97706; font-style:italic;">
                                                            <i class="fas fa-sticky-note"></i>
                                                            <?php echo htmlspecialchars($order['notes']); ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span style="font-size:0.8rem; color:#aaa; font-style:italic;">no special instructions</span>
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
                                            <td colspan="9" style="text-align:center; padding:60px 20px; color:#94a3b8;">
                                                <div
                                                    style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;">
                                                    <div
                                                        style="width:64px; height:64px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                                                        <i class="fas fa-utensils" style="font-size:1.8rem; color:#cbd5e1;"></i>
                                                    </div>
                                                    <div style="font-weight:600; font-size:1.1rem; color:#64748b;">No Active
                                                        Orders</div>
                                                    <p style="margin:0; font-size:0.9rem; max-width:250px; line-height:1.5;">
                                                        There are no food orders being prepared or delivered for today yet.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot id="foodPaginationFoot" style="display: <?php echo ($result_orders->num_rows > 100) ? 'table-footer-group' : 'none'; ?>;">
                                    <tr>
                                        <td colspan="9" style="padding: 0;">
                                            <!-- 🟢 PAGINATION CONTROLS FOR FOOD ORDERS (ATTACHED) -->
                                            <div id="foodPagination" class="pagination-container"
                                                style="display: <?php echo ($result_orders->num_rows > 100) ? 'flex' : 'none'; ?>; border-top: 1px solid #f3f4f6; border-radius: 0 0 12px 12px;">
                                                <div class="pagination-info">
                                                    Showing <span><span
                                                                id="foodPageStart"><?php echo ($result_orders->num_rows > 0) ? 1 : 0; ?></span>
                                                            - <span
                                                                id="foodPageEnd"><?php echo $result_orders->num_rows; ?></span></span> of
                                                    <span id="foodTotalCount"><?php echo $result_orders->num_rows; ?></span>
                                                    records
                                                </div>
                                                <div class="pagination-buttons" id="foodPageButtons">
                                                    <!-- Page numbers generated here -->
                                                    <?php if ($result_orders->num_rows > 0): ?>
                                                        <button class="pg-btn pg-btn-nav" disabled><i class="fas fa-chevron-left"></i> Prev</button>
                                                        <button class="pg-btn active">1</button>
                                                        <button class="pg-btn pg-btn-nav" disabled>Next <i
                                                                class="fas fa-chevron-right"></i></button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
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
                            <table class="booking-table" id="transactionsMainTable">
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
                                        <th style="text-align: center;">Action</th>
                                        </tr>

                                </thead>
                                <tbody id="transactions_body">
                                    <tr>
                                        <td colspan="9" style="padding: 100px 0; text-align: center;">
                                            <div class="amv-loader-container">
                                                <div class="amv-loader"></div>
                                                <div style="font-weight: 600; font-size: 1.1rem; letter-spacing: 0.5px; color: #B88E2F;">Loading Transactions...</div>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot id="transPaginationFoot" style="display: none;">
                                    <tr>
                                        <td colspan="9" style="padding: 0;">
                                            <!-- 🟢 PAGINATION CONTROLS -->
                                            <div id="transPagination" class="pagination-container" style="border-top: 1px solid #f3f4f6; border-radius: 0 0 12px 12px;">
                                                <div class="pagination-info">
                                                    Showing <span><span id="transPageStart">0</span> - <span
                                                            id="transPageEnd">0</span></span> of <span id="transTotalCount">0</span> records
                                                </div>
                                                <div class="pagination-buttons" id="transPageButtons">
                                                    <!-- Page numbers will be generated here -->
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
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
                                    <?php if (!empty($allRoomsDB)): ?>
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
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align:center; padding:60px 20px; color:#94a3b8;">
                                            <div
                                                style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;">
                                                <div
                                                    style="width:64px; height:64px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                                                    <i class="fas fa-door-closed" style="font-size:1.8rem; color:#cbd5e1;"></i>
                                                </div>
                                                <div style="font-weight:600; font-size:1.1rem; color:#64748b;">No Rooms
                                                    Available</div>
                                                <p style="margin:0; font-size:0.9rem; max-width:250px; line-height:1.5;">
                                                    You haven't added any rooms to your inventory yet.
                                                </p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
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
                                        <th style="text-align: right;">Actions</th>
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
                                                <td style="text-align: right;">
                                                    <div style="display: flex; justify-content: flex-end; gap: 5px;">
                                                        <button class="btn-secondary" style="padding:6px 12px;"
                                                            onclick="openEditNewsModal(
                        '<?php echo $news['id']; ?>',
                        '<?php echo addslashes($news['title']); ?>',
                        '<?php echo $news['news_date']; ?>',
                        '<?php echo base64_encode($news['description']); ?>', 
                        '<?php echo $news['image_path']; ?>')">
                                                            <i class="fas fa-edit"></i> Edit
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
                                                                <i class="fas fa-trash-restore"></i> Restore
                                                            </button>

                                                            <button class="btn-secondary"
                                                                style="padding:6px 12px; color:white; border-color: #B91C1C; background: #DC2626; margin-left: 5px;"
                                                                onclick="permanentDeleteNews('<?php echo $news['id']; ?>')">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
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
                                            <td colspan="5" style="text-align:center; padding:60px 20px; color:#94a3b8;">
                                                <div
                                                    style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;">
                                                    <div
                                                        style="width:64px; height:64px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                                                        <i class="far fa-star" style="font-size:1.8rem; color:#cbd5e1;"></i>
                                                    </div>
                                                    <div style="font-weight:600; font-size:1.1rem; color:#64748b;">No Reviews
                                                        Yet</div>
                                                    <p style="margin:0; font-size:0.9rem; max-width:250px; line-height:1.5;">
                                                        You haven't received any guest feedback or ratings yet.
                                                    </p>
                                                </div>
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
                                                        '<?php echo base64_encode($evt['description']); ?>',
                                                        '<?php echo $evt['image_path']; ?>')">

                                                        <i class="fas fa-edit"></i> Edit
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
                                            <td colspan="5" style="text-align:center; padding:60px 20px; color:#94a3b8;">
                                                <div
                                                    style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;">
                                                    <div
                                                        style="width:64px; height:64px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                                                        <i class="fas fa-calendar-times"
                                                            style="font-size:1.8rem; color:#cbd5e1;"></i>
                                                    </div>
                                                    <div style="font-weight:600; font-size:1.1rem; color:#64748b;">No Events
                                                        Scheduled</div>
                                                    <p style="margin:0; font-size:0.9rem; max-width:250px; line-height:1.5;">
                                                        There are no hotel events currently scheduled.
                                                    </p>
                                                </div>
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
                                    <input type="text" id="receiptFilterDate" class="custom-date-input-enhanced"
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
                            
                            <!-- 🟢 NEW: PAGINATION CONTROLS -->
                            <div id="receiptPagination" style="display: flex; justify-content: center; align-items: center; gap: 15px; margin-top: 25px; padding: 15px; border-top: 1px solid #eee;">
                                <button class="btn-secondary" id="receiptPrevBtn" onclick="changeReceiptPage(-1)" style="padding: 8px 20px; font-weight: 600;">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </button>
                                <div id="receiptPageInfo" style="font-weight: 600; color: #555;">Page 1 of 1</div>
                                <button class="btn-secondary" id="receiptNextBtn" onclick="changeReceiptPage(1)" style="padding: 8px 20px; font-weight: 600;">
                                    Next <i class="fas fa-chevron-right"></i>
                                </button>
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
                                    <?php if (!empty($all_amenities)): ?>
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
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" style="text-align:center; padding:60px 20px; color:#94a3b8;">
                                                <div
                                                    style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;">
                                                    <div
                                                        style="width:64px; height:64px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                                                        <i class="fas fa-concierge-bell"
                                                            style="font-size:1.8rem; color:#cbd5e1;"></i>
                                                    </div>
                                                    <div style="font-weight:600; font-size:1.1rem; color:#64748b;">No Amenities
                                                        Found</div>
                                                    <p style="margin:0; font-size:0.9rem; max-width:250px; line-height:1.5;">
                                                        You haven't defined any room amenities yet.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
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
                                <input type="text" class="custom-date-input-enhanced" id="checkin_picker" name="checkin"
                                    placeholder="Select Date" required readonly>
                            </div>

                            <div class="input-col">
                                <label class="input-label">Check-Out</label>
                                <input type="text" class="custom-date-input-enhanced" id="checkout_picker" name="checkout"
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
                                <input type="text" class="custom-date-input-enhanced" id="birthdate_picker" name="birthdate"
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
                                    <input type="text" class="news-clean-input" name="time_start" id="eventTimeInput"
                                        required placeholder="Select Time">
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
                            <input type="text" id="extend_date_picker" class="custom-date-input-enhanced"
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
        <div class="ab-modal-content" style="max-width: 900px; height: auto; max-height: 85vh;">
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
                    <div style="text-align:center; padding:100px 0;">
                        <div class="amv-loader-container">
                            <div class="amv-loader"></div>
                            <div style="font-weight: 600; font-size: 1.1rem; letter-spacing: 0.5px; color: #B88E2F;">Loading Rankings...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


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
            <div style="text-align:center; padding:100px 0;">
                <div class="amv-loader-container">
                    <div class="amv-loader"></div>
                    <div style="font-weight: 600; font-size: 1.1rem; letter-spacing: 0.5px; color: #B88E2F;">Loading Bookings...</div>
                </div>
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
            <div style="text-align:center; padding:100px 0;">
                <div class="amv-loader-container">
                    <div class="amv-loader"></div>
                    <div style="font-weight: 600; font-size: 1.1rem; letter-spacing: 0.5px; color: #B88E2F;">Loading Orders...</div>
                </div>
            </div>
        </div>
    </div>


    <script src="dashboard_scripts.js?v=<?php echo time(); ?>"></script>
    </body>

</html>

<?php
// ADMIN/PHP/get_dashboard_stats.php
require 'db_connect.php';
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

session_start();

// Security Check
if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$selectedDate = isset($_GET['date']) ? $_GET['date'] : 'overall';
$isOverall = ($selectedDate === 'overall');

if ($isOverall) {
    $year = date('Y');
    $month = date('m');
    
    // Calculate total days since the first booking for occupancy divisor
    $res_min_date = $conn->query("SELECT MIN(check_in) as first_date FROM bookings WHERE status = 'confirmed'");
    $first_date = $res_min_date->fetch_assoc()['first_date'] ?? date('Y-m-d');
    $daysInMonth = (int)date_diff(date_create($first_date), date_create('now'))->format('%a') + 1;
    if ($daysInMonth < 1) $daysInMonth = 1;

    $bookingDateFilter = "1=1";
    $orderDateFilter = "1=1";
    $occupancyDateFilter = "check_in <= CURDATE() AND check_out > '$first_date'";
    $occupancyRangeEnd = "CURDATE()";
    $occupancyRangeStart = "'$first_date'";
} else {
    $year = date('Y', strtotime($selectedDate));
    $month = date('m', strtotime($selectedDate));
    $daysInMonth = date('t', strtotime($selectedDate));
    
    $bookingDateFilter = "MONTH(check_in) = '$month' AND YEAR(check_in) = '$year'";
    $orderDateFilter = "MONTH(order_date) = '$month' AND YEAR(order_date) = '$year'";
    $occupancyDateFilter = "check_in < '$year-$month-$daysInMonth' AND check_out > '$year-$month-01'";
    $occupancyRangeEnd = "'$year-$month-$daysInMonth'";
    $occupancyRangeStart = "'$year-$month-01'";
}

// =========================================================
// A. Guests & Revenue (Confirmed Bookings + Food Orders)
// =========================================================
$sql_bookings = "SELECT 
                COUNT(*) as guest_count, 
                SUM(total_price) as rev_total 
             FROM bookings 
             WHERE status = 'confirmed' 
             AND arrival_status != 'cancelled'
             AND $bookingDateFilter";
             
$res_bookings = $conn->query($sql_bookings)->fetch_assoc();
$guests = $res_bookings['guest_count'] ?? 0;
$bookingRevenue = $res_bookings['rev_total'] ?? 0;

$sql_orders_rev = "SELECT SUM(total_price) as total FROM orders 
                   WHERE status IN ('Preparing', 'Delivered', 'PAID', 'Completed') 
                   AND $orderDateFilter";
$res_orders_rev = $conn->query($sql_orders_rev)->fetch_assoc();
$orderRevenue = $res_orders_rev['total'] ?? 0;

$revenue = $bookingRevenue + $orderRevenue;

// =========================================================
// B. Occupancy Logic
// =========================================================
$totalRoomsRes = $conn->query("SELECT COUNT(*) as c FROM rooms WHERE is_active=1");
$totalRooms = $totalRoomsRes->fetch_assoc()['c'] ?? 1;
$totalAvailableNights = $totalRooms * $daysInMonth;

$sql_nights = "SELECT SUM(DATEDIFF(LEAST(check_out, $occupancyRangeEnd), GREATEST(check_in, $occupancyRangeStart))) as nights 
               FROM bookings WHERE status = 'confirmed' 
               AND arrival_status NOT IN ('no_show', 'cancelled')
               AND $occupancyDateFilter";
               
$nightsSold = $conn->query($sql_nights)->fetch_assoc()['nights'] ?? 0;
$occupancy = ($totalAvailableNights > 0) ? round(($nightsSold / $totalAvailableNights) * 100) : 0;

// =========================================================
// C. Pending Arrivals
// =========================================================
$todayDate = date('Y-m-d');
$sql_pending = "SELECT COUNT(*) as total FROM bookings 
                WHERE status = 'confirmed' 
                AND check_in = '$todayDate'
                AND (arrival_status IS NULL OR arrival_status = '' OR arrival_status = 'awaiting_arrival' OR arrival_status = 'upcoming')";

$res_pending = $conn->query($sql_pending);
$pendingCount = $res_pending->fetch_assoc()['total'] ?? 0;

// =========================================================
// D. Outcome Stats (Pie Chart)
// =========================================================
$pieStats = ['complete' => 0, 'noshow' => 0, 'cancelled' => 0];
$sql_pie = "SELECT status, arrival_status, check_in FROM bookings 
            WHERE $bookingDateFilter";
            
$result_pie = $conn->query($sql_pie);
$todayObj = new DateTime();
$todayObj->setTime(0,0,0);

while ($row = $result_pie->fetch_assoc()) {
    if ($row['status'] === 'cancelled' || $row['arrival_status'] === 'cancelled') {
        $pieStats['cancelled']++;
    } 
    elseif ($row['arrival_status'] === 'no_show') {
        $pieStats['noshow']++;
    } 
    elseif ($row['arrival_status'] === 'checked_out') {
        $pieStats['complete']++;
    }
    elseif ($row['status'] === 'confirmed' && ($row['arrival_status'] == 'upcoming' || empty($row['arrival_status']))) {
        $checkInDate = new DateTime($row['check_in']);
        if ($checkInDate < $todayObj) {
            $pieStats['noshow']++;
        }
    }
}

// =========================================================
// E. Chart Data (Revenue Bar Chart)
// =========================================================
$chartYear = isset($_GET['chart_year']) ? intval($_GET['chart_year']) : date('Y');
$barData = array_fill(1, 12, 0);

$sql_bar = "SELECT MONTH(check_in) as month, SUM(total_price) as total 
            FROM bookings 
            WHERE status = 'confirmed' 
            AND YEAR(check_in) = $chartYear
            AND arrival_status != 'cancelled'
            GROUP BY MONTH(check_in)";

$res_bar = $conn->query($sql_bar);
while ($row = $res_bar->fetch_assoc()) {
    $barData[intval($row['month'])] = floatval($row['total']);
}

// 🟢 NEW: ADD FOOD ORDERS TO CHART DATA
$sql_bar_orders = "SELECT MONTH(order_date) as month, SUM(total_price) as total 
                   FROM orders 
                   WHERE status IN ('Preparing', 'Delivered', 'PAID', 'Completed') 
                   AND YEAR(order_date) = $chartYear
                   GROUP BY MONTH(order_date)";
$res_bar_orders = $conn->query($sql_bar_orders);
while ($row = $res_bar_orders->fetch_assoc()) {
    $barData[intval($row['month'])] += floatval($row['total']);
}

// 🟢 NEW: ROOM LEADERBOARD DATA (Truly Cumulative OR filtered by Month)
$roomLeaderboard = [];
$leaderboardFilter = $isOverall 
    ? "1=1" // 🔥 Truly cumulative (no date filter)
    : "MONTH(b.check_in) = '$month' AND YEAR(b.check_in) = '$year'";

$sql_leaderboard = "SELECT r.name as room_name, 
                           COUNT(b.id) as booking_count,
                           SUM(CASE WHEN bg.gender = 'Male' THEN 1 ELSE 0 END) as male_count,
                           SUM(CASE WHEN bg.gender = 'Female' THEN 1 ELSE 0 END) as female_count,
                           SUM(CASE WHEN bg.gender = 'Other' THEN 1 ELSE 0 END) as other_count
                    FROM rooms r
                    LEFT JOIN booking_rooms br ON r.id = br.room_id 
                    LEFT JOIN bookings b ON br.booking_id = b.id
                        AND b.status IN ('confirmed', 'pending', 'cancelled') 
                        AND $leaderboardFilter
                    LEFT JOIN booking_guests bg ON b.id = bg.booking_id
                    GROUP BY r.id
                    ORDER BY booking_count DESC";
$res_leaderboard = $conn->query($sql_leaderboard);
while ($row = $res_leaderboard->fetch_assoc()) {
    $roomLeaderboard[] = [
        'name' => $row['room_name'],
        'count' => (int)$row['booking_count'],
        'male' => (int)$row['male_count'],
        'female' => (int)$row['female_count'],
        'other' => (int)$row['other_count']
    ];
}

// =========================================================
// F. 🟢 ACTIVE KITCHEN ORDERS (NEW SECTION)
// =========================================================
// Counts any order that is Pending or Preparing (Needs attention)
$sql_orders = "SELECT COUNT(*) as total FROM orders WHERE status IN ('Pending', 'Preparing')";
$res_orders = $conn->query($sql_orders);
$activeOrders = $res_orders->fetch_assoc()['total'] ?? 0;

// 🟢 NEW: FOOD LEADERBOARD DATA
$foodLeaderboard = [];
$foodOrderFilter = $isOverall ? "1=1" : "MONTH(o.order_date) = '$month' AND YEAR(o.order_date) = '$year'";

// Initialize stats with ALL items from the menu
$foodStats = [];
$sql_menu = "SELECT item_name FROM food_menu WHERE is_active = 1";
$res_menu = $conn->query($sql_menu);
while ($mRow = $res_menu->fetch_assoc()) {
    $foodStats[$mRow['item_name']] = ['total' => 0, 'male' => 0, 'female' => 0, 'other' => 0];
}

// Use a subquery for gender to prevent row multiplication (fixes the 1000+ count bug)
$sql_food_orders = "SELECT o.items, 
                    (SELECT gender FROM booking_guests WHERE email = u.email LIMIT 1) as gender
                    FROM orders o 
                    LEFT JOIN users u ON o.user_id = u.id
                    WHERE o.status IN ('Preparing', 'Delivered', 'PAID', 'Completed', 'Cancelled') AND $foodOrderFilter";
$res_food_orders = $conn->query($sql_food_orders);

while ($row = $res_food_orders->fetch_assoc()) {
    $items = json_decode($row['items'], true);
    $gender = $row['gender'] ?? 'Other';
    if (!in_array($gender, ['Male', 'Female'])) $gender = 'Other';

    if (is_array($items)) {
        foreach ($items as $name => $qty) {
            // Only add if it exists in our initialized menu list (active items)
            if (isset($foodStats[$name])) {
                $foodStats[$name]['total'] += (int)$qty;
                if ($gender === 'Male') $foodStats[$name]['male'] += (int)$qty;
                elseif ($gender === 'Female') $foodStats[$name]['female'] += (int)$qty;
                else $foodStats[$name]['other'] += (int)$qty;
            }
        }
    }
}

// Sort by total count descending
uasort($foodStats, function($a, $b) {
    return $b['total'] - $a['total'];
});

foreach ($foodStats as $name => $stats) {
    $foodLeaderboard[] = [
        'name' => $name,
        'count' => $stats['total'],
        'male' => $stats['male'],
        'female' => $stats['female'],
        'other' => $stats['other']
    ];
}

// 🟢 NEW: DATE LEADERBOARD DATA (Most Booked Dates - Including Cancelled)
$dateLeaderboard = [];
$dateFilter = $isOverall ? "1=1" : "MONTH(check_in) = '$month' AND YEAR(check_in) = '$year'";

$sql_date_leaderboard = "SELECT check_in, COUNT(*) as booking_count 
                         FROM bookings 
                         WHERE status IN ('confirmed', 'pending', 'complete', 'cancelled') 
                         AND $dateFilter
                         GROUP BY check_in 
                         ORDER BY booking_count DESC 
                         LIMIT 10";

$res_date_leaderboard = $conn->query($sql_date_leaderboard);
while ($row = $res_date_leaderboard->fetch_assoc()) {
    $dateLeaderboard[] = [
        'name' => date('M d, Y', strtotime($row['check_in'])),
        'count' => (int)$row['booking_count']
    ];
}

// 🟢 NEW: AVAILABLE BOOKING MONTHS (Including Cancelled)
$availableMonths = [];
$sql_months = "SELECT DISTINCT DATE_FORMAT(check_in, '%Y-%m') as month_val, 
                              DATE_FORMAT(check_in, '%M %Y') as month_label 
               FROM bookings 
               WHERE status IN ('confirmed', 'pending', 'complete', 'cancelled') 
               ORDER BY check_in DESC";
$res_months = $conn->query($sql_months);
while ($m_row = $res_months->fetch_assoc()) {
    $availableMonths[] = [
        'value' => $m_row['month_val'],
        'label' => $m_row['month_label']
    ];
}

// 🟢 NEW: TOTAL COUNT FOR THE PERIOD (to calculate % correctly in modal)
$sql_total_date_count = "SELECT COUNT(*) as total FROM bookings WHERE status IN ('confirmed', 'pending', 'complete', 'cancelled') AND $dateFilter";
$res_total_date_count = $conn->query($sql_total_date_count);
$totalDateCount = $res_total_date_count->fetch_assoc()['total'] ?? 0;

// Final JSON Output
echo json_encode([
    'status' => 'success',
    'guests' => (int)$guests,
    'revenue' => number_format($revenue, 0),
    'occupancy' => (int)$occupancy,
    'pending' => (int)$pendingCount,
    'kitchen_orders' => (int)$activeOrders, 
    'pie_data' => [(int)$pieStats['complete'], (int)$pieStats['noshow'], (int)$pieStats['cancelled']],
    'revenue_data' => array_values($barData),
    'room_leaderboard' => $roomLeaderboard,
    'food_leaderboard' => $foodLeaderboard,
    'date_leaderboard' => $dateLeaderboard,
    'total_date_count' => (int)$totalDateCount, // 🟢 Added
    'available_months' => $availableMonths, 
    'data' => array_values($barData) 
]);
?>
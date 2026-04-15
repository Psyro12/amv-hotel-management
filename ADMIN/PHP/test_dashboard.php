<?php
session_start();

// 1. DATABASE CONNECTION
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "amv_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}
$user = $_SESSION['user'];

// 2. FETCH DASHBOARD STATS
$sql_bookings = "SELECT COUNT(*) as total FROM bookings";
$result_bookings = $conn->query($sql_bookings);
$totalBookings = $result_bookings->fetch_assoc()['total'];

$sql_revenue = "SELECT SUM(total_price) as total FROM bookings WHERE status = 'confirmed'";
$result_revenue = $conn->query($sql_revenue);
$totalRevenue = $result_revenue->fetch_assoc()['total'] ?? 0;

$totalOrders = 0; // Placeholder

// 3. FETCH CHART DATA
// Pie Chart
$sql_pie = "SELECT status, COUNT(*) as count FROM bookings GROUP BY status";
$result_pie = $conn->query($sql_pie);
$pieData = ['confirmed' => 0, 'pending' => 0, 'cancelled' => 0];
while ($row = $result_pie->fetch_assoc()) {
    $pieData[$row['status']] = $row['count'];
}
$totalPie = array_sum($pieData);
$pctConfirmed = $totalPie > 0 ? round(($pieData['confirmed'] / $totalPie) * 100) : 0;
$pctPending = $totalPie > 0 ? round(($pieData['pending'] / $totalPie) * 100) : 0;
$pctCancelled = $totalPie > 0 ? round(($pieData['cancelled'] / $totalPie) * 100) : 0;

// Bar Chart
$currentYear = date('Y');
$sql_bar = "SELECT MONTH(check_in) as month, SUM(total_price) as total 
            FROM bookings 
            WHERE status='confirmed' AND YEAR(check_in) = '$currentYear' 
            GROUP BY MONTH(check_in)";
$result_bar = $conn->query($sql_bar);
$barData = array_fill(1, 12, 0);
while ($row = $result_bar->fetch_assoc()) {
    $barData[$row['month']] = $row['total'];
}

// 4. CALENDAR DATA LOGIC
$calendarData = [];

// A. Fetch All Rooms
$allRoomsDB = [];
$sql_rooms_fetch = "SELECT room_id, image_name FROM room_details.room_image_details ORDER BY room_id ASC";
$result_rooms_fetch = $conn->query($sql_rooms_fetch);
if ($result_rooms_fetch) {
    while ($row_r = $result_rooms_fetch->fetch_assoc()) {
        $allRoomsDB[] = [
            'id' => $row_r['room_id'],
            'name' => $row_r['image_name']
        ];
    }
}
$js_allRoomsJSON = json_encode($allRoomsDB);

// B. Fetch Active Bookings for Calendar
$sql_cal = "SELECT 
                b.id, b.check_in, b.check_out, b.status, 
                br.room_id, br.room_name, 
                CONCAT(bg.first_name, ' ', bg.last_name) as guest_name,
                bg.email, bg.phone
            FROM bookings b 
            JOIN booking_rooms br ON b.id = br.booking_id 
            JOIN booking_guests bg ON b.id = bg.booking_id
            WHERE b.status IN ('confirmed', 'pending')";

$result_cal = $conn->query($sql_cal);

while ($row = $result_cal->fetch_assoc()) {
    $start = new DateTime($row['check_in']);
    $end = new DateTime($row['check_out']);

    for ($date = clone $start; $date < $end; $date->modify('+1 day')) {
        $dateStr = $date->format('Y-m-d');
        if (!isset($calendarData[$dateStr])) {
            $calendarData[$dateStr] = [];
        }
        
        $todayStr = date('Y-m-d');
        $colorType = 'future';
        if ($dateStr <= $todayStr && $row['status'] == 'confirmed') {
            $colorType = 'in_house';
        }

        $calendarData[$dateStr][] = [
            'room_id' => $row['room_id'],
            'room_name' => $row['room_name'],
            'guest' => $row['guest_name'],
            'status' => $row['status'],
            'type' => $colorType,
            'check_in' => $row['check_in'],
            'check_out' => $row['check_out']
        ];
    }
}

// 5. FETCH BOOKING LIST (Bookings Page)
$sql_list = "SELECT 
        b.id, b.booking_reference, b.check_in, b.check_out, b.status, b.total_price, b.booking_source, b.arrival_status,
        u.name as user_name, bg.first_name, bg.last_name,
        GROUP_CONCAT(br.room_name SEPARATOR ', ') as room_names
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    LEFT JOIN booking_guests bg ON b.id = bg.booking_id
    LEFT JOIN booking_rooms br ON b.id = br.booking_id
    WHERE b.check_out >= CURDATE() 
    GROUP BY b.id
    ORDER BY FIELD(b.status, 'pending', 'confirmed', 'cancelled'), b.check_in ASC";

$result_list = $conn->query($sql_list);

// 6. FETCH GUESTS LIST (Guests Page - Robust Query)
$sql_guests = "SELECT 
        MAX(first_name) as first_name, 
        MAX(last_name) as last_name, 
        email, 
        MAX(phone) as phone, 
        MAX(nationality) as nationality, 
        COUNT(id) as booking_count 
    FROM booking_guests 
    GROUP BY email 
    ORDER BY MAX(last_name) ASC";
$result_guests = $conn->query($sql_guests);

// Pass Data to JS
$js_pieData = json_encode([$pieData['confirmed'], $pieData['pending'], $pieData['cancelled']]);
$js_barData = json_encode(array_values($barData));
$js_calendarData = json_encode($calendarData);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMV - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../STYLE/dashboard-styles.css">
    <link rel="stylesheet" href="../STYLE/utilities.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/rangePlugin.js"></script>

    <style>
        /* --- GLOBAL LAYOUT --- */
        html, body {
            height: 100%; margin: 0; overflow: hidden; /* Disables scroll on main body */
            font-family: 'Montserrat', sans-serif; background-color: #F3F4F6;
        }
        .dashboard-container { height: 100vh; display: flex; overflow: hidden; }
        .sidebar { flex-shrink: 0; }
        .main-content {
            flex: 1; display: flex; flex-direction: column; height: 100%; overflow: hidden; background-color: #F3F4F6;
        }
        .top-header {
            background: #fff; padding: 15px 20px; border-bottom: 1px solid #e0e0e0; flex-shrink: 0;
        }
        .page-content {
            flex: 1; padding: 20px; overflow: hidden; display: flex; flex-direction: column; position: relative;
        }

        /* --- PAGE VISIBILITY (Fixes Overlapping) --- */
        .page { display: none; flex-direction: column; width: 100%; height: 100%; }
        .page.active { display: flex; }

        /* --- DASHBOARD PAGE --- */
        #dashboard.page.active { gap: 1rem; overflow-y: auto; }
        .stats-grid { flex: 0 0 auto; }
        .charts-grid-dashboard { flex: 1; min-height: 0; display: flex; gap: 1rem; align-items: stretch; }
        .chart-card {
            height: 100%; display: flex; flex-direction: column; background: #fff; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .chart-container { flex: 1; min-height: 0; position: relative; width: 100%; }

        /* --- CALENDAR PAGE --- */
        #calendar.page.active { overflow: hidden; }
        .calendar-wrapper {
            background: #fff; border-radius: 4px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden; width: 100%; height: 100%; display: flex; flex-direction: column; border: 1px solid #e0e0e0;
        }
        .calendar-header-styled {
            background-color: #545454; color: #fff; padding: 15px 25px; display: flex; justify-content: space-between; align-items: center;
        }
        .calendar-header-styled h3 { margin: 0; font-size: 1.2rem; font-weight: 600; }
        .cal-nav-btn { background: none; border: none; color: white; cursor: pointer; font-size: 1.2rem; display: flex; align-items: center; }
        .calendar-days-styled { display: grid; grid-template-columns: repeat(7, 1fr); padding: 15px 0; border-bottom: 1px solid #eee; }
        .calendar-days-styled span { text-align: center; color: #B88E2F; font-weight: 700; font-size: 0.8rem; text-transform: uppercase; }
        .calendar-grid-styled { display: grid; grid-template-columns: repeat(7, 1fr); flex: 1; grid-auto-rows: 1fr; overflow-y: auto; }
        .cal-cell {
            border-bottom: 1px solid #f0f0f0; border-right: 1px solid #f0f0f0; display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: #444; font-weight: 500; position: relative; min-height: 80px;
        }
        .cal-cell.status-booked { background-color: #F7EC73; color: #333; }
        .cal-cell.status-inhouse { background-color: #D3C855; color: #fff; }
        .cal-cell.status-full { background-color: #FE8578; color: #fff; }

        /* --- BOOKINGS & GUESTS PAGE (SCROLL FIX) --- */
        #bookings.page.active, #guests.page.active { overflow: hidden; }
        .p-3 { display: flex; flex-direction: column; height: 100%; overflow: hidden; padding-bottom: 0 !important; }

        .booking-table-container {
            flex: 1; min-height: 0; overflow: auto; margin-top: 10px;
            background: #fff; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .booking-table { width: 100%; border-collapse: collapse; min-width: 800px; }
        .booking-table thead th {
            position: sticky; top: 0; background-color: #f4f4f4; z-index: 10;
            box-shadow: 0 2px 2px -1px rgba(0,0,0,0.1); color: #555; font-weight: 600; text-align: left; padding: 15px; font-size: 0.85rem;
        }
        .booking-table td { padding: 15px; border-bottom: 1px solid #eee; color: #333; font-size: 0.9rem; vertical-align: middle; }
        .booking-table tr:hover { background-color: #fafafa; }

        /* --- MODAL STYLES (Consolidated & Fixed) --- */
        .modal {
            display: none; position: fixed; z-index: 2000; left: 0; top: 0;
            width: 100%; height: 100%; overflow: hidden; /* Prevent background scroll */
            background-color: rgba(0,0,0,0.4);
        }

        /* Generic Modal Content (Used for Add Booking & Guest Profile) */
        .ab-modal-content {
            background-color: #ECEFF1; border-radius: 12px; padding: 0;
            width: 90%; max-width: 800px; max-height: 85vh;
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); margin: 0;
            display: flex; flex-direction: column;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2); font-family: 'Montserrat', sans-serif; overflow: hidden;
        }

        .ab-modal-header {
            flex-shrink: 0; display: flex; justify-content: center; align-items: center; position: relative;
            padding: 25px 30px 15px 30px; background-color: #ECEFF1; z-index: 10;
        }
        .ab-modal-title { font-size: 1.25rem; color: #374151; font-weight: 700; margin: 0; }
        .ab-close-btn { position: absolute; right: 30px; top: 25px; background: none; border: none; font-size: 1.2rem; color: #666; cursor: pointer; }

        .ab-modal-body { overflow-y: auto; flex-grow: 1; padding: 0 30px 30px 30px; }

        /* Small Modal for Room Status */
        .modal-content-calendar {
             background-color: #fff; top: 50%; left: 50%; transform: translate(-50%, -50%);
             position: absolute; padding: 20px; border-radius: 8px; width: 400px;
        }
        .modal-close { position: absolute; right: 15px; top: 10px; background: none; border: none; font-size: 1.2rem; cursor: pointer; }

        /* --- FORM ELEMENTS --- */
        .ab-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .ab-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        .ab-input, .ab-select {
            width: 100%; padding: 12px 15px; background-color: #FFFFFF; border: 1px solid #E5E7EB; border-radius: 8px;
            font-size: 0.9rem; color: #555; outline: none; box-sizing: border-box;
        }
        .ab-label { display: block; font-size: 0.85rem; font-weight: 700; color: #4b5563; margin-bottom: 8px; }
        .ab-mb-3 { margin-bottom: 15px; }
        .ab-submit-btn { width: 100%; padding: 12px; background-color: #FFA000; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .btn-secondary { background: #eee; color: #555; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; }
        
        /* --- UTILITIES --- */
        .d-flex { display: flex; } .justify-end { justify-content: flex-end; } .align-center { align-items: center; }
        .badge { padding: 5px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .badge-confirmed { background-color: #D4EDDA; color: #155724; border: 1px solid #C3E6CB; }
        .badge-pending { background-color: #FFF3CD; color: #856404; border: 1px solid #FFEEBA; }
        
        /* Room Selection */
        .room-selection-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px; margin-bottom: 20px; max-height: 500px; overflow-y: auto; padding: 5px; }
        .room-card { background: #fff; border: 1px solid #E5E7EB; border-radius: 12px; overflow: hidden; cursor: pointer; position: relative; display: flex; flex-direction: column; }
        .room-card.selected { border: 2px solid #FFA000; background-color: #FFFDF5; }
        .room-card-image { width: 100%; height: 140px; background-color: #eee; object-fit: cover; }
        .room-card-body { padding: 15px; display: flex; flex-direction: column; gap: 5px; }

        /* Logout Modal */
        #logoutModal .modal-content {
            background-color: #fff; margin: 15% auto; padding: 20px; border-radius: 8px; width: 300px; text-align: center;
        }
        .modal-buttons { margin-top: 20px; display: flex; justify-content: center; gap: 10px; }

        @media (max-width: 600px) {
            .ab-grid-2, .ab-grid-3 { grid-template-columns: 1fr; }
            .flatpickr-calendar { width: 300px !important; }
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <nav class="sidebar" style="font-size:0.85rem;">
            <div class="sidebar-header">
                <div class="logo">
                    <div class="logo-icon">
                        <img src="../../IMG/4.png" alt="AMV Logo" style="height: 64px; width: auto; display: block; margin: 0 auto;">
                    </div>
                    <span class="brand-text">AMV</span>
                </div>
            </div>
            <ul class="nav-menu">
                <li class="nav-item active" data-page="dashboard"><a href="#" class="nav-link"><span>Dashboard</span></a></li>
                <li class="nav-item" data-page="calendar"><a href="#" class="nav-link"><span>Calendar</span></a></li>
                <li class="nav-item" data-page="guests"><a href="#" class="nav-link"><span>Guests</span></a></li>
                <li class="nav-item" data-page="bookings"><a href="#" class="nav-link"><span>Bookings</span></a></li>
                <li class="nav-item" data-page="food-ordered"><a href="#" class="nav-link"><span>Food Ordered</span></a></li>
                <li class="nav-item" data-page="transactions"><a href="#" class="nav-link"><span>Transactions</span></a></li>
                <li class="nav-item" data-page="settings"><a href="#" class="nav-link"><span>Settings</span></a></li>
            </ul>
            <div class="sidebar-footer">
                <a href="#" class="logout-btn" id="logoutBtn"><span>Logout</span></a>
            </div>
        </nav>

        <main class="main-content">
            <header class="top-header">
                <div class="header-left">
                    <h1 class="page-title ml-2 fs-md">Dashboard</h1>
                </div>
            </header>

            <div class="page-content">
                <div class="page active" id="dashboard">
                    <div class="stats-grid g-3">
                        <div class="stat-card d-flex">
                            <div class="stat-icon orders-icon">📦</div>
                            <div class="stat-content">
                                <h3 class="stat-number p-1 fs-md"><?php echo $totalBookings; ?></h3>
                                <p class="stat-label fs-xs">Total Bookings</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon revenue-icon">💰</div>
                            <div class="stat-content">
                                <h3 class="stat-number p-1 fs-md">$<?php echo number_format($totalRevenue, 2); ?></h3>
                                <p class="stat-label fs-xs">Total Revenue</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon users-icon">🧾</div>
                            <div class="stat-content">
                                <h3 class="stat-number p-1 fs-md"><?php echo $totalOrders; ?></h3>
                                <p class="stat-label fs-xs">Total Orders</p>
                            </div>
                        </div>
                    </div>

                    <div class="charts-grid charts-grid-dashboard">
                        <div style="display: flex; flex-direction: column; gap: 1rem; flex: 0 1 320px; min-width: 300px;">
                            <div class="chart-card" style="flex: 1; padding: 20px; justify-content: center;">
                                <h3 class="chart-title fs-sm mb-2 text-center">Monthly Bookings</h3>
                                <div class="chart-container" style="flex: 1; position: relative; max-height: 250px;">
                                    <canvas id="pieBookings"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="chart-card" style="flex: 1 1 600px; min-width: 400px;">
                            <div style="padding: 20px; text-align: center;">
                                <h3 class="chart-title">Revenue (Current Year)</h3>
                            </div>
                            <div class="chart-container" style="flex: 1; padding: 0 20px 20px 20px;">
                                <canvas id="barMonthly"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="page" id="calendar">
                    <div class="calendar-wrapper">
                        <div class="calendar-header-styled">
                            <h3 id="currentMonthYear">Month YYYY</h3>
                            <div class="d-flex g-2">
                                <button class="cal-nav-btn" id="prevMonthBtn">◀</button>
                                <button class="cal-nav-btn" id="nextMonthBtn">▶</button>
                            </div>
                        </div>
                        <div class="calendar-days-styled">
                            <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                        </div>
                        <div class="calendar-grid-styled" id="calendarRealtimeGrid"></div>
                    </div>

                    <div class="modal" id="calendarModal">
                        <div class="modal-content-calendar">
                            <button class="modal-close" id="closeCalendarModal">✕</button>
                            <h3 class="fs-md" id="calendarModalTitle" style="margin-bottom: 15px;">Room Status</h3>
                            <div id="calendarModalBody"></div>
                        </div>
                    </div>
                </div>

                <div class="page" id="guests">
                    <div class="p-3">
                        <h2 class="fs-md mb-3">Guest Database</h2>
                        <div class="booking-table-container">
                            <table class="booking-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Nationality</th>
                                        <th style="text-align: center;">Total Bookings</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($result_guests && $result_guests->num_rows > 0): ?>
                                        <?php while ($guest = $result_guests->fetch_assoc()): ?>
                                            <tr>
                                                <td><div style="font-weight:600;"><?php echo htmlspecialchars($guest['first_name'] . ' ' . $guest['last_name']); ?></div></td>
                                                <td><?php echo htmlspecialchars($guest['email']); ?></td>
                                                <td><?php echo htmlspecialchars($guest['phone']); ?></td>
                                                <td><?php echo htmlspecialchars($guest['nationality']); ?></td>
                                                <td style="text-align: center;">
                                                    <span class="badge" style="background:#e0f2fe; color:#0284c7;">
                                                        <?php echo $guest['booking_count']; ?> Stay(s)
                                                    </span>
                                                </td>
                                                <td>
                                                    <button class="btn-secondary" style="padding: 5px 12px; font-size: 0.8rem;"
                                                        onclick="openGuestProfile('<?php echo $guest['email']; ?>')">
                                                        View Profile
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" style="text-align:center; padding: 20px;">No guests found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div id="guestProfileModal" class="modal">
                    <div class="ab-modal-content">
                        <div class="ab-modal-header">
                            <h3 class="ab-modal-title">Guest Profile</h3>
                            <button class="ab-close-btn" onclick="closeGuestModal()">✕</button>
                        </div>
                        <div class="ab-modal-body">
                            <div id="guestProfileLoader" class="text-center" style="padding:40px;">Loading...</div>
                            <div id="guestProfileContent" style="display:none;">
                                <div style="background:#f9fafb; padding:20px; border-radius:8px; margin-bottom:25px; border:1px solid #eee;">
                                    <div class="ab-grid-3">
                                        <div><span class="fs-xxs">Full Name</span><div style="font-weight:700;" id="gp_name"></div></div>
                                        <div><span class="fs-xxs">Email</span><div style="font-weight:600;" id="gp_email"></div></div>
                                        <div><span class="fs-xxs">Phone</span><div style="font-weight:600;" id="gp_phone"></div></div>
                                        <div><span class="fs-xxs">Nationality</span><div style="font-weight:600;" id="gp_nation"></div></div>
                                        <div><span class="fs-xxs">Gender</span><div style="font-weight:600;" id="gp_gender"></div></div>
                                        <div><span class="fs-xxs">Birthdate</span><div style="font-weight:600;" id="gp_dob"></div></div>
                                    </div>
                                    <div style="margin-top:15px;">
                                        <span class="fs-xxs">Address</span><div style="font-weight:600;" id="gp_address"></div>
                                    </div>
                                </div>
                                <h4 style="margin-bottom:15px;">Booking History</h4>
                                <div class="booking-table-container" style="height:auto; max-height:300px;">
                                    <table class="booking-table">
                                        <thead>
                                            <tr><th>Ref</th><th>Dates</th><th>Rooms</th><th>Total</th><th>Status</th></tr>
                                        </thead>
                                        <tbody id="gp_history_body"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="page" id="bookings">
                    <div class="p-3">
                        <header class="d-flex justify-end align-center mb-3">
                            <button id="openAddBookingModalBtn" class="btn-primary" 
                                style="background:#2563EB; color:white; border:none; padding:8px 16px; border-radius:6px; cursor:pointer;">
                                + Add Booking
                            </button>
                        </header>
                        <div class="tabs-container">
                            <button class="tab-btn active" onclick="filterTable('pending')" data-target="pending">Unconfirmed</button>
                            <button class="tab-btn" onclick="filterTable('confirmed')" data-target="confirmed">Confirmed</button>
                        </div>
                        <div class="booking-table-container">
                            <table class="booking-table">
                                <thead>
                                    <tr>
                                        <th>Reference</th><th>Guest Name</th><th>Source</th><th>Arrival Status</th>
                                        <th>Rooms</th><th>Dates</th><th>Price</th><th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="bookingTableBody">
                                    <?php if ($result_list->num_rows > 0): ?>
                                        <?php while ($row = $result_list->fetch_assoc()): ?>
                                            <?php
                                            $guestName = !empty($row['first_name']) ? $row['first_name'] . ' ' . $row['last_name'] : $row['user_name'];
                                            $checkin = date('M d', strtotime($row['check_in']));
                                            $checkout = date('M d', strtotime($row['check_out']));
                                            $statusClass = 'badge-' . $row['status'];
                                            $sourceClass = ($row['booking_source'] === 'walk-in') ? 'source-walkin' : 'source-online';
                                            $sourceIcon = ($row['booking_source'] === 'walk-in') ? '🚶' : '🌐';
                                            
                                            $arrivalLabel = ''; $arrivalClass = '';
                                            if ($row['arrival_status'] == 'in_house') { $arrivalLabel = 'In House'; $arrivalClass = 'arrival-inhouse'; }
                                            elseif ($row['arrival_status'] == 'checked_out') { $arrivalLabel = 'Checked Out'; $arrivalClass = 'arrival-checkedout'; }
                                            else { $arrivalLabel = 'Awaiting Arrival'; $arrivalClass = 'arrival-awaiting'; }
                                            ?>
                                            <tr class="booking-row" data-status="<?php echo $row['status']; ?>">
                                                <td><strong><?php echo $row['booking_reference']; ?></strong></td>
                                                <td>
                                                    <div style="font-weight:500;"><?php echo $guestName; ?></div>
                                                    <div class="fs-xxs">ID: <?php echo $row['id']; ?></div>
                                                </td>
                                                <td><div class="source-tag <?php echo $sourceClass; ?>"><span><?php echo $sourceIcon; ?></span><?php echo $row['booking_source']; ?></div></td>
                                                <td>
                                                    <div class="badge <?php echo $statusClass; ?>"><?php echo $row['status']; ?></div>
                                                    <?php if ($row['status'] == 'confirmed'): ?><div class="arrival-badge <?php echo $arrivalClass; ?>"><?php echo $arrivalLabel; ?></div><?php endif; ?>
                                                </td>
                                                <td><?php echo $row['room_names']; ?></td>
                                                <td><?php echo $checkin . ' - ' . $checkout; ?></td>
                                                <td>$<?php echo number_format($row['total_price'], 2); ?></td>
                                                <td><button class="btn-secondary" style="padding:5px 10px;">View</button></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="8" style="text-align:center;">No bookings found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <div id="noDataMessage" style="display:none; text-align:center; padding:20px; color:#888;">No bookings found in this category.</div>
                        </div>
                    </div>
                </div>

                <div class="page" id="food-ordered"><h2 class="p-3">Food Ordered Page</h2></div>
                <div class="page" id="transactions"><h2 class="p-3">Transactions Page</h2></div>
                <div class="page" id="settings"><h2 class="p-3">Settings Page</h2></div>
            </div>
        </main>
    </div>

    <div id="addBookingModal" class="modal">
        <div class="ab-modal-content">
            <div class="ab-modal-header">
                <h3 class="ab-modal-title" id="abModalTitle">Step 1: Select Dates</h3>
                <button class="ab-close-btn" id="closeAddBookingModalX">✕</button>
            </div>
            <div class="ab-modal-body">
                <form id="addBookingForm">
                    <div id="ab-step-1" class="ab-step active">
                        <div class="input-row-flex">
                            <div class="input-col"><label class="ab-label">Check-In</label><input type="text" class="custom-date-input" id="checkin_picker" name="checkin" placeholder="Select Date" required readonly></div>
                            <div class="input-col"><label class="ab-label">Check-Out</label><input type="text" class="custom-date-input" id="checkout_picker" name="checkout" placeholder="Select Date" required readonly></div>
                        </div>
                        <div class="ab-grid-footer"><div></div><button type="button" class="ab-submit-btn" onclick="goToStep(2)">Search Rooms</button></div>
                    </div>
                    <div id="ab-step-2" class="ab-step">
                        <p style="margin-bottom:15px; color:#666;">Select one or more rooms:</p>
                        <div class="room-selection-grid" id="roomSelectionContainer"></div>
                        <div class="step-nav-buttons">
                            <button type="button" class="btn-secondary" onclick="goToStep(1)">Back</button>
                            <button type="button" class="ab-submit-btn" style="width:auto; padding: 10px 30px;" onclick="goToStep(3)">Next: Guest Info</button>
                        </div>
                    </div>
                    <div id="ab-step-3" class="ab-step">
                        <div class="step-header-row"><h3 class="step-header-title">Personal Information</h3></div>
                        <div class="ab-grid-3 ab-mb-3">
                            <div><label class="ab-label">Salutation</label><select class="ab-select" name="salutation" required><option value="Mr.">Mr.</option><option value="Ms.">Ms.</option><option value="Mrs.">Mrs.</option></select></div>
                            <div><label class="ab-label">First Name</label><input type="text" class="ab-input" name="firstname" required></div>
                            <div><label class="ab-label">Last Name</label><input type="text" class="ab-input" name="lastname" required></div>
                        </div>
                        <div class="ab-grid-3 ab-mb-3">
                            <div><label class="ab-label">Gender</label><select class="ab-select" name="gender" required><option value="Male">Male</option><option value="Female">Female</option></select></div>
                            <div><label class="ab-label">Birthdate</label><input type="date" class="ab-input" name="birthdate" required></div>
                            <div><label class="ab-label">Nationality</label><select class="ab-select" name="nationality" required><option value="Filipino">Filipino</option><option value="American">American</option></select></div>
                        </div>
                        <div class="ab-grid-2 ab-mb-3">
                            <div><label class="ab-label">Email</label><input type="email" class="ab-input" name="email" required></div>
                            <div><label class="ab-label">Payment Method</label><select class="ab-select" name="payment_method" required><option value="Cash">Cash</option><option value="GCash">GCash</option></select></div>
                        </div>
                        <div class="ab-grid-2 ab-mb-3">
                            <div><label class="ab-label">Contact</label><input type="text" class="ab-input" name="contact" required></div>
                            <div><label class="ab-label">Arrival Time</label><select class="ab-select" name="arrival_time"><option value="02:00 PM">02:00 PM</option></select></div>
                        </div>
                        <div class="ab-mb-4"><label class="ab-label">Address</label><input type="text" class="ab-input ab-full-width" name="address" required></div>
                        <input type="hidden" name="adults" value="1"><input type="hidden" name="children" value="0">
                        <div class="step-nav-buttons">
                            <button type="button" class="btn-secondary" onclick="goToStep(2)">Back</button>
                            <button type="button" class="ab-submit-btn" style="width:auto;" onclick="validateAndReview()">Review & Confirm</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="confirmationModal" class="modal" style="z-index: 2100;">
        <div class="ab-modal-content" style="max-width: 500px; height: auto; max-height: none; top: 40%;">
            <div class="ab-modal-header">
                <h3 class="ab-modal-title">Confirm Booking</h3>
                <button class="ab-close-btn" id="closeConfirmModalX">✕</button>
            </div>
            <div class="ab-modal-body" style="text-align:center; padding-bottom: 30px;">
                <div style="background:#f9f9f9; padding:20px; border-radius:8px; text-align:left; margin-bottom:20px;">
                    <p><strong>Guest:</strong> <span id="confirmName"></span></p>
                    <p><strong>Dates:</strong> <span id="confirmDates"></span></p>
                    <p><strong>Rooms:</strong> <span id="confirmRooms"></span></p>
                    <p><strong>Total:</strong> <span id="confirmTotal" style="color:#FFA000; font-weight:bold;"></span></p>
                </div>
                <button type="button" class="ab-submit-btn" id="finalConfirmBtn">Confirm & Save</button>
                <button type="button" class="btn-secondary" style="margin-top:10px; width:100%; background:none;" id="cancelConfirmBtn">Back to Edit</button>
            </div>
        </div>
    </div>

    <div id="logoutModal" class="modal" style="z-index: 2200;">
        <div id="logoutContent" style="background:#fff; width:300px; margin:20% auto; padding:20px; border-radius:8px; text-align:center; position:relative;">
            <button class="modal-close-x" id="closeLogoutModal" style="position:absolute; right:15px; top:10px; border:none; background:none; cursor:pointer;">✕</button>
            <h3 style="margin-top:0;">Confirm Logout</h3>
            <div class="modal-buttons">
                <button id="confirmLogout" class="ab-submit-btn" style="width:100px;">Yes</button>
                <button id="cancelLogout" class="btn-secondary" style="width:100px;">Cancel</button>
            </div>
        </div>
    </div>

    <script src="../SCRIPT/dashboard-script.js"></script>
    <script>
        // --- 1. CHARTS ---
        const pieCtx = document.getElementById('pieBookings').getContext('2d');
        new Chart(pieCtx, {
            type: 'doughnut',
            data: {
                labels: ['Check-ins', 'No-show', 'Cancelled'],
                datasets: [{
                    data: <?php echo $js_pieData; ?>,
                    backgroundColor: ['#10B981', '#F59E0B', '#EF4444'],
                    borderWidth: 0, cutout: '75%',
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, layout: { padding: 20 }, plugins: { legend: { display: false } } }
        });

        const barCtx = document.getElementById('barMonthly').getContext('2d');
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{ label: 'Revenue ($)', data: <?php echo $js_barData; ?>, backgroundColor: '#B88E2F' }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });

        // --- 2. LOGOUT LOGIC ---
        const logoutModal = document.getElementById('logoutModal');
        document.getElementById('logoutBtn').onclick = (e) => { e.preventDefault(); logoutModal.style.display = 'block'; };
        document.getElementById('cancelLogout').onclick = () => logoutModal.style.display = 'none';
        document.getElementById('closeLogoutModal').onclick = () => logoutModal.style.display = 'none';
        document.getElementById('confirmLogout').onclick = () => window.location.href = 'logout.php';

        // --- 3. CALENDAR LOGIC ---
        const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
        let viewDate = new Date();
        const bookingsDB = <?php echo $js_calendarData; ?>;
        const allRoomsList = <?php echo $js_allRoomsJSON; ?>;

        function renderRealtimeCalendar() {
            const today = new Date(); today.setHours(0,0,0,0);
            const year = viewDate.getFullYear(); const month = viewDate.getMonth();
            document.getElementById('currentMonthYear').innerText = `${monthNames[month]} ${year}`;
            
            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const grid = document.getElementById('calendarRealtimeGrid');
            grid.innerHTML = "";

            for(let i=0; i<firstDay; i++) { grid.innerHTML += '<div class="cal-cell other-month"></div>'; }

            for(let i=1; i<=daysInMonth; i++) {
                const cell = document.createElement('div');
                cell.className = 'cal-cell'; cell.innerText = i;
                const d = new Date(year, month, i);
                const dStr = new Date(d.getTime() - (d.getTimezoneOffset() * 60000)).toISOString().split('T')[0];
                
                // Logic to disable past dates
                if ((year < today.getFullYear()) || (year === today.getFullYear() && month < today.getMonth()) || (year === today.getFullYear() && month === today.getMonth() && i < today.getDate())) {
                    cell.classList.add('disabled-date');
                } else {
                    const dayData = bookingsDB[dStr] || [];
                    if (dayData.length > 0) {
                        const inHouse = dayData.some(b => b.type === 'in_house');
                        const booked = dayData.some(b => b.type === 'future');
                        if (dayData.length >= allRoomsList.length) cell.classList.add('status-full');
                        else if (inHouse) cell.classList.add('status-inhouse');
                        else if (booked) cell.classList.add('status-booked');
                        
                        cell.onclick = () => openRoomModal(dStr, dayData);
                    }
                }
                grid.appendChild(cell);
            }
        }
        
        function formatModalDate(dateStr) {
             const d = new Date(dateStr);
             return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'long' }).replace(',', '');
        }

        function openRoomModal(dateStr, dayBookings) {
            const dateObj = new Date(dateStr);
            document.getElementById('calendarModalTitle').innerText = `${dateObj.toLocaleDateString('en-US', { month: 'long', day: 'numeric' })} - Rooms`;
            const body = document.getElementById('calendarModalBody');
            body.innerHTML = '';

            allRoomsList.forEach(room => {
                const booking = dayBookings.find(b => b.room_id == room.id || (b.room_name && b.room_name.includes(room.id)));
                let boxClass = 'box-available', statusHtml = 'Available';
                
                if (booking) {
                    if (booking.type === 'in_house') { boxClass = 'box-occupied'; statusHtml = `Occupied until <b>${formatModalDate(booking.check_out)}</b>`; }
                    else { boxClass = 'box-reserved'; statusHtml = `Reserved: <b>${formatModalDate(booking.check_in)}</b> - <b>${formatModalDate(booking.check_out)}</b>`; }
                }
                
                const row = document.createElement('div'); row.className = 'room-status-row';
                row.innerHTML = `<div class="room-number-box ${boxClass}">${room.id}</div><div class="room-details-text"><div style="font-size:0.75rem; color:#888;">${room.name}</div>${statusHtml}</div>`;
                body.appendChild(row);
            });
            document.getElementById('calendarModal').style.display = 'block';
        }

        document.getElementById('prevMonthBtn').onclick = () => { viewDate.setMonth(viewDate.getMonth()-1); renderRealtimeCalendar(); };
        document.getElementById('nextMonthBtn').onclick = () => { viewDate.setMonth(viewDate.getMonth()+1); renderRealtimeCalendar(); };
        document.getElementById('closeCalendarModal').onclick = () => document.getElementById('calendarModal').style.display = 'none';
        
        renderRealtimeCalendar();

        // --- 4. BOOKINGS TABLE LOGIC ---
        function filterTable(status) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelector(`.tab-btn[data-target="${status}"]`).classList.add('active');
            
            let visible = 0;
            document.querySelectorAll('.booking-row').forEach(row => {
                if(row.getAttribute('data-status') === status) { row.style.display = 'table-row'; visible++; }
                else { row.style.display = 'none'; }
            });
            document.getElementById('noDataMessage').style.display = visible ? 'none' : 'block';
        }
        document.addEventListener("DOMContentLoaded", () => filterTable('pending'));

        // --- 5. GUEST PROFILE LOGIC ---
        const guestModal = document.getElementById('guestProfileModal');
        const guestLoader = document.getElementById('guestProfileLoader');
        const guestContent = document.getElementById('guestProfileContent');

        function openGuestProfile(email) {
            guestModal.style.display = 'block'; guestLoader.style.display = 'block'; guestContent.style.display = 'none';
            fetch(`get_guest_details.php?email=${encodeURIComponent(email)}`)
                .then(res => res.json())
                .then(data => {
                    if(data.error) { alert(data.error); guestModal.style.display = 'none'; return; }
                    const info = data.info;
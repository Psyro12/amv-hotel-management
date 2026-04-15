<?php
// ADMIN/PHP/fetch_booking_table.php

session_start();
require 'db_connect.php'; 
date_default_timezone_set('Asia/Manila');

// 1. GET PARAMETERS
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'today';

// 2. BUILD BASE WHERE CLAUSE
$where = " WHERE (b.status != 'pending' AND b.status != 'Pending') ";

// 3. APPLY TAB FILTERS
if ($filter === 'today') {
    $where .= " AND DATE(b.check_in) = CURDATE() ";
} elseif ($filter === 'recent') {
    $where .= " AND b.created_at >= NOW() - INTERVAL 72 HOUR ";
} elseif ($filter === 'late') {
    $where .= " AND DATE(b.check_in) = CURDATE() AND b.status = 'confirmed' 
                AND (b.arrival_status IS NULL OR b.arrival_status NOT IN ('in_house', 'checked_out', 'no_show', 'cancelled')) ";
} else {
    // 'all' - Display all present and future bookings
    $where .= " AND (b.check_out >= CURDATE() OR b.arrival_status = 'in_house') ";
}

// 4. APPLY SEARCH
if ($search !== "") {
    $where .= " AND (b.booking_reference LIKE '%$search%' 
                OR u.name LIKE '%$search%' 
                OR bg.first_name LIKE '%$search%' 
                OR bg.last_name LIKE '%$search%') ";
}

// 5. FETCH TOTAL COUNT
$countSql = "SELECT COUNT(DISTINCT b.id) as total 
             FROM bookings b
             LEFT JOIN users u ON b.user_id = u.id
             LEFT JOIN booking_guests bg ON b.id = bg.booking_id
             $where";
$countRes = $conn->query($countSql);
$totalCount = $countRes ? $countRes->fetch_assoc()['total'] : 0;

// 6. FETCH DATA
$orderBy = " FIELD(b.status, 'pending', 'confirmed', 'cancelled'), b.check_in ASC ";
if ($filter === 'recent') {
    $orderBy = " b.created_at DESC ";
}

$sql_list = "SELECT 
        b.id, b.booking_reference, b.check_in, b.check_out, 
        b.status, b.total_price, b.booking_source, b.arrival_status,
        b.amount_paid, b.payment_status, b.payment_term,
        b.created_at, 
        u.name as user_name, bg.first_name, bg.last_name,
        bg.arrival_time, bg.special_requests,
        GROUP_CONCAT(DISTINCT br.room_name SEPARATOR ', ') as room_names,
        MAX(br.price_per_night) as daily_price
    FROM bookings b
    LEFT JOIN users u ON b.user_id = u.id
    LEFT JOIN booking_guests bg ON b.id = bg.booking_id
    LEFT JOIN booking_rooms br ON b.id = br.booking_id
    $where
    GROUP BY b.id 
    ORDER BY $orderBy
    LIMIT $limit OFFSET $offset";

$result = $conn->query($sql_list);

$html = "";
$todayDateOnly = date('Y-m-d');

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $guestName = !empty($row['first_name']) ? $row['first_name'] . ' ' . $row['last_name'] : (!empty($row['user_name']) ? $row['user_name'] : 'Guest');
        $checkinDisplay = date('M d', strtotime($row['check_in']));
        $checkoutDisplay = date('M d', strtotime($row['check_out']));
        $paymentStatus = $row['payment_status'];
        $amountPaid = $row['amount_paid'] ?? 0;
        $balance = $row['total_price'] - $amountPaid;
        $createdDate = !empty($row['created_at']) ? $row['created_at'] : $row['check_in'];
        $guestTimeStr = !empty($row['arrival_time']) ? $row['arrival_time'] : '14:00';
        $checkInDateOnly = date('Y-m-d', strtotime($row['check_in']));

        // Source Logic
        $sourceText = !empty($row['booking_source']) ? $row['booking_source'] : 'Reservation';
        $sourceClass = ''; $sourceIcon = '';
        if (strcasecmp($sourceText, 'walk-in') === 0) {
            $sourceClass = 'source-walkin'; $sourceIcon = '<i class="fas fa-walking"></i>'; 
        } elseif (strcasecmp($sourceText, 'reservation') === 0) {
            $sourceClass = 'source-online'; $sourceIcon = '<i class="far fa-calendar-alt"></i>'; 
        } elseif (strcasecmp($sourceText, 'mobile_app') === 0) {
            $sourceClass = 'source-walkin'; $sourceIcon = '<i class="fas fa-mobile-alt"></i>'; 
        } else {
            $sourceClass = 'source-online'; $sourceIcon = '<i class="fas fa-globe"></i>';
        }

        // Arrival Status Logic
        $arrivalLabel = ''; $arrivalClass = '';
        if ($row['arrival_status'] == 'no_show') {
            $arrivalLabel = 'No-Show'; $arrivalClass = 'arrival-overdue';
        } elseif ($row['arrival_status'] == 'in_house') {
            $arrivalLabel = 'In House'; $arrivalClass = 'arrival-inhouse';
        } elseif ($row['arrival_status'] == 'checked_out') {
            $arrivalLabel = 'Checked Out'; $arrivalClass = 'arrival-checkedout';
        } elseif ($row['status'] == 'confirmed') {
            if ($checkInDateOnly === $todayDateOnly) {
                $arrivalLabel = 'Arriving Today'; $arrivalClass = 'arrival-today';
            } elseif ($checkInDateOnly > $todayDateOnly) {
                $arrivalLabel = 'Upcoming'; $arrivalClass = 'arrival-upcoming';
            } else {
                $arrivalLabel = 'Late Arrival'; $arrivalClass = 'arrival-overdue';
            }
        } else {
            $arrivalLabel = 'Cancelled'; $arrivalClass = 'badge-cancelled';
        }

        $rowHtml = '<tr class="booking-row clickable-row" 
            style="cursor: pointer;"
            data-status="'. $row['status'] .'"
            data-checkin="'. $row['check_in'] .'"
            data-checkout="'. $row['check_out'] .'"
            data-arrival="'. $row['arrival_status'] .'"
            data-created="'. $createdDate .'" 
            id="row-'. $row['id'] .'"
            onclick="openBookingAction(
                \''. $row['id'] .'\', 
                \''. addslashes($guestName) .'\', 
                \''. $row['booking_reference'] .'\', 
                \''. addslashes($row['room_names']) .'\', 
                \''. $row['check_in'] .'\', 
                \''. $row['check_out'] .'\', 
                \''. $row['total_price'] .'\', 
                \''. $row['arrival_status'] .'\', 
                \''. $amountPaid .'\', 
                \''. $arrivalLabel .'\', 
                \''. $createdDate .'\', 
                \''. $row['booking_source'] .'\',
                \''. $row['daily_price'] .'\',
                \''. addslashes(str_replace(["\r", "\n"], [" ", " "], $row['special_requests'] ?? '')) .'\'
            )">

            <td><strong>'. $row['booking_reference'] .'</strong></td>
            <td>
                <div style="font-weight:600; font-size:0.9rem;">'. htmlspecialchars($guestName) .'</div>
                <div class="fs-xxs" style="color:#888;">ID: '. $row['id'] .'</div>
            </td>
            <td>
                <div class="source-tag '. $sourceClass .'">
                    <span>'. $sourceIcon .'</span> '. strtoupper($sourceText) .'
                </div>
            </td>
            <td>
                <div class="arrival-badge '. $arrivalClass .'">'. $arrivalLabel .'</div>
            </td>
            <td>
                <div style="font-weight:600; color:#555; font-size:0.9rem;">
                    <i class="far fa-clock" style="color:#888; margin-right:4px;"></i>
                    '. date('h:i A', strtotime($guestTimeStr)) .'
                </div>
            </td>
            <td>'. $row['room_names'] .'</td>
            <td>'. $checkinDisplay . ' - ' . $checkoutDisplay .'</td>
            <td>₱'. number_format($row['total_price'], 2) .'</td>
            <td>';
        
        if ($paymentStatus == 'paid') {
            $rowHtml .= '<span style="color:#10B981; font-weight:700; font-size:0.8rem;">Fully Paid</span>';
        } elseif ($paymentStatus == 'partial') {
            $rowHtml .= '<div style="font-size:0.75rem; color:#F59E0B; font-weight:600;">Paid: ₱'. number_format($amountPaid, 0) .'</div>
                         <div style="font-size:0.75rem; color:#EF4444; font-weight:600;">Bal: ₱'. number_format($balance, 0) .'</div>';
        } else {
            $rowHtml .= '<span style="color:#EF4444; font-weight:600; font-size:0.8rem;">Unpaid</span>';
        }

        $rowHtml .= '</td>
            <td style="text-align: center;">
                <span style="color:#B88E2F; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">
                    Tap to View <i class="fas fa-chevron-right" style="font-size:0.65rem; margin-left:3px;"></i>
                </span>
            </td>
        </tr>';
        $html .= $rowHtml;
    }
} else {
    // Determine messaging and button visibility based on filter
    $emptyTitle = "No Bookings Found";
    $emptySub = "We couldn't find any bookings matching your current criteria.";
    $showAddBtn = false;
    $showClearBtn = ($search !== ""); // Always show clear if searching

    if ($filter === 'today' && $search === "") {
        $emptyTitle = "No Bookings for Today";
        $emptySub = "There are no guest check-ins or check-outs scheduled for today.";
    } elseif ($filter === 'recent' && $search === "") {
        $emptyTitle = "No Recent Activity";
        $emptySub = "No new bookings have been made within the last 72 hours (3 days).";
    } elseif ($filter === 'all') {
        $emptyTitle = "No Bookings Found";
        $emptySub = "Your booking database is currently empty.";
        if ($search === "") $showAddBtn = true;
    }

    $html = '<tr>
        <td colspan="10" style="padding: 60px 20px; color:#94a3b8;">
            <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 12px;">
                <div style="width:64px; height:64px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                    <i class="fas fa-calendar-times" style="font-size:1.8rem; color:#cbd5e1;"></i>
                </div>
                <div style="font-weight:600; font-size:1.1rem; color:#64748b;">'. $emptyTitle .'</div>
                <p style="margin:0; font-size:0.9rem; max-width:300px; line-height:1.5;">'. $emptySub .'</p>
                <div style="display: flex; gap: 10px; margin-top: 8px;">
                    '.($showClearBtn ? '<button onclick="clearSearch()" class="tab-btn" style="background:#fff; color:#B88E2F; border:1px solid #B88E2F; padding: 8px 20px; font-size: 0.85rem; font-weight:600; border-radius:8px;">Clear Search</button>' : '').'
                    '.($showAddBtn ? '<button onclick="openAddBookingModal()" class="tab-btn" style="background:#B88E2F; color:#fff; border:1px solid #B88E2F; padding: 8px 20px; font-size: 0.85rem; font-weight:600; border-radius:8px;">Add Booking</button>' : '').'
                </div>
            </div>
        </td>
    </tr>';
}

header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'html' => $html,
    'total' => $totalCount,
    'limit' => $limit,
    'offset' => $offset,
    'filter' => $filter
]);
exit;
?>
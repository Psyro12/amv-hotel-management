<?php
// ADMIN/PHP/get_guest_details.php
session_start(); 
header('Content-Type: application/json');

// 1. Security Check: Validate CSRF Token (Only if POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $_POST['csrf_token'] ?? $input['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die(json_encode(['status' => 'error', 'message' => 'Security token invalid. Refresh the page.']));
    }
}

require 'db_connect.php';

// 2. Validate Input
if (!isset($_GET['email'])) {
    echo json_encode(['error' => 'No email provided']);
    exit;
}

$email = $conn->real_escape_string($_GET['email']);
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$offset_h = isset($_GET['offset_history']) ? (int)$_GET['offset_history'] : 0;
$offset_o = isset($_GET['offset_orders']) ? (int)$_GET['offset_orders'] : 0;

// 3. Fetch User Personal Details
$sql_guest = "SELECT 
                salutation, 
                first_name, 
                last_name, 
                email, 
                phone, 
                nationality, 
                gender, 
                birthdate, 
                address
            FROM booking_guests 
            WHERE email = '$email' 
            ORDER BY id DESC LIMIT 1";

$result_guest = $conn->query($sql_guest);

if ($result_guest->num_rows > 0) {
    $guestData = $result_guest->fetch_assoc();
} else {
    echo json_encode(['error' => 'Guest not found']);
    exit;
}

// 4. Fetch Booking History
$count_history = $conn->query("SELECT COUNT(DISTINCT b.id) as total FROM bookings b JOIN booking_guests bg ON b.id = bg.booking_id WHERE bg.email = '$email'")->fetch_assoc()['total'];

$sql_history = "SELECT 
                b.booking_reference, 
                b.check_in, 
                b.check_out, 
                b.status, 
                b.arrival_status,
                b.total_price,
                GROUP_CONCAT(br.room_name SEPARATOR ', ') as room_names
            FROM bookings b
            JOIN booking_guests bg ON b.id = bg.booking_id
            LEFT JOIN booking_rooms br ON b.id = br.booking_id
            WHERE bg.email = '$email'
            GROUP BY b.id
            ORDER BY b.check_in DESC
            LIMIT $limit OFFSET $offset_h";

$result_history = $conn->query($sql_history);
$historyData = [];

while ($row = $result_history->fetch_assoc()) {
    $historyData[] = $row;
}

// 🟢 5. NEW: Fetch Order History
$count_orders = $conn->query("SELECT COUNT(*) as total FROM orders o JOIN users u ON o.user_id = u.id WHERE u.email = '$email'")->fetch_assoc()['total'];

$sql_orders = "SELECT 
                o.id, 
                o.items, 
                o.total_price, 
                o.status, 
                o.payment_method, 
                o.order_date
              FROM orders o
              JOIN users u ON o.user_id = u.id
              WHERE u.email = '$email'
              ORDER BY o.order_date DESC
              LIMIT $limit OFFSET $offset_o";

$result_orders = $conn->query($sql_orders);
$orderData = [];

if ($result_orders) {
    while ($row = $result_orders->fetch_assoc()) {
        $orderData[] = $row;
    }
}

// 6. Return JSON
echo json_encode([
    'info' => $guestData,    
    'history' => $historyData,
    'history_total' => (int)$count_history,
    'history_offset' => $offset_h,
    'orders' => $orderData,
    'orders_total' => (int)$count_orders,
    'orders_offset' => $offset_o,
    'limit' => $limit
]);

$conn->close();
?>
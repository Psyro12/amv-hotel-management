<?php
// ADMIN/PHP/get_transactions.php

// 1. Start buffering
ob_start();

// 2. Hide errors from output (logs only)
error_reporting(E_ALL);
ini_set('display_errors', 0); 

session_start();
header('Content-Type: application/json');

$response = [];

try {
    // 3. Database Connection
    if (!file_exists('db_connect.php')) {
        throw new Exception("Database configuration file missing.");
    }
    require 'db_connect.php';

    // 4. Security Check
    if (!isset($_SESSION['user'])) {
        throw new Exception("Unauthorized access.");
    }

    // 🟢 5. FETCH LIMIT & OFFSET
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $typeFilter = isset($_GET['type']) ? $_GET['type'] : 'all';

    // 🟢 5.1 FETCH TOTAL COUNT (Optimized)
    $countSql = "SELECT COUNT(*) as total FROM transactions t WHERE (t.transaction_type = ? OR ? = 'all')";
    $c_stmt = $conn->prepare($countSql);
    $c_stmt->bind_param("ss", $typeFilter, $typeFilter);
    $c_stmt->execute();
    $totalCount = $c_stmt->get_result()->fetch_assoc()['total'];

    // 5. Fetch transactions (Optimized Query)
    $sql = "SELECT 
                t.id, 
                t.user_id, 
                t.transaction_type, 
                t.reference_id, 
                t.amount, 
                t.payment_method, 
                
                -- 🟢 DYNAMIC STATUS MAPPING
                CASE 
                    WHEN t.status = 'Pending' THEN 'Pending'
                    WHEN t.transaction_type = 'Booking' AND (b.payment_status = 'partial' OR t.status IN ('Partial', 'partial')) THEN 'Partially Paid'
                    WHEN t.transaction_type = 'Food Order' AND o.status IN ('Cancelled', 'Rejected') THEN 'Cancelled'
                    ELSE t.status 
                END as status,

                t.created_at,
                
                -- 🟢 SMART NAME LOOKUP
                COALESCE(u.name, CONCAT(bg.first_name, ' ', bg.last_name), CONCAT('Room ', o.room_number), 'Guest User') as user_name,
                COALESCE(u.email, bg.email, 'No Email') as email

            FROM transactions t 
            LEFT JOIN users u ON t.user_id = u.id 
            LEFT JOIN bookings b ON t.reference_id = b.booking_reference
            LEFT JOIN booking_guests bg ON b.id = bg.booking_id
            LEFT JOIN orders o ON (t.transaction_type = 'Food Order' AND t.reference_id LIKE CONCAT('Order #', o.id))
            
            WHERE (t.transaction_type = ? OR ? = 'all')
            ORDER BY t.created_at DESC 
            LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $typeFilter, $typeFilter, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result) {
        throw new Exception("Database Error: " . $conn->error);
    }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        if (trim($row['user_name']) === '') {
            $row['user_name'] = 'Guest User';
        }
        $data[] = $row;
    }

    $response = [
        'status' => 'success', 
        'data' => $data, 
        'total' => $totalCount,
        'limit' => $limit,
        'offset' => $offset
    ];

} catch (Exception $e) {
    $response = ['status' => 'error', 'message' => $e->getMessage()];
}

// 6. Output JSON
ob_end_clean(); 
echo json_encode($response);
exit;
?>
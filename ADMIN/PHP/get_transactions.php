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

    // 🟢 5.1 FETCH TOTAL COUNT (for pagination calculation)
    $countSql = "SELECT COUNT(*) as total FROM transactions";
    if ($typeFilter !== 'all') {
        $countSql .= " WHERE transaction_type = '" . $conn->real_escape_string($typeFilter) . "'";
    }
    $countResult = $conn->query($countSql);
    $totalCount = $countResult->fetch_assoc()['total'];

    // 5. Fetch transactions with DYNAMIC STATUS UPDATE
    $sql = "SELECT 
                t.id, 
                t.user_id, 
                t.transaction_type, 
                t.reference_id, 
                t.amount, 
                t.payment_method, 
                
                -- 🟢 FIX: Check the REAL status from the Orders table
                -- If the linked Order is 'Cancelled', show 'Cancelled' here too.
                -- Otherwise, show the original transaction status.
                CASE 
                    WHEN t.transaction_type = 'Food Order' AND o.status = 'Cancelled' THEN 'Cancelled'
                    WHEN t.transaction_type = 'Food Order' AND o.status = 'Rejected' THEN 'Cancelled'
                    ELSE t.status 
                END as status,

                t.created_at,
                
                -- 🟢 SMART NAME LOOKUP
                COALESCE(
                    u.name, 
                    CONCAT(bg.first_name, ' ', bg.last_name),
                    CONCAT('Room ', o.room_number),
                    'Guest User'
                ) as user_name,

                -- 🟢 SMART EMAIL LOOKUP
                COALESCE(u.email, bg.email, 'No Email') as email

            FROM transactions t 
            
            -- Join Users
            LEFT JOIN users u ON t.user_id = u.id 

            -- Join Bookings
            LEFT JOIN bookings b ON t.reference_id = b.booking_reference
            LEFT JOIN booking_guests bg ON b.id = bg.booking_id

            -- Join Orders (Link Transaction Ref 'Order #123' to Order ID 123)
            LEFT JOIN orders o ON (
                t.transaction_type = 'Food Order' AND 
                o.id = REPLACE(t.reference_id, 'Order #', '')
            )";

    if ($typeFilter !== 'all') {
        $sql .= " WHERE t.transaction_type = '" . $conn->real_escape_string($typeFilter) . "'";
    }

    $sql .= " ORDER BY t.created_at DESC LIMIT $limit OFFSET $offset";

    $result = $conn->query($sql);

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
<?php
// ADMIN/PHP/get_all_guests.php

// 1. Prevent unwanted output
ob_start();
require 'db_connect.php';
session_start();
ob_clean(); 

header('Content-Type: application/json');

// 2. Security Check
if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// 🟢 3. FETCH LIMIT, OFFSET, AND SEARCH
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

// Build Where Clause for Search
$where = "";
if ($search !== "") {
    $where = " WHERE bg.email LIKE '%$search%' OR bg.first_name LIKE '%$search%' OR bg.last_name LIKE '%$search%' ";
}

// 🟢 3.1 FETCH TOTAL COUNT (Unique by email)
$countSql = "SELECT COUNT(DISTINCT bg.email) as total FROM booking_guests bg $where";
$countRes = $conn->query($countSql);
$totalCount = $countRes->fetch_assoc()['total'];

// 3. The Query (UPDATED with Pagination and Search)
$sql = "SELECT 
            MAX(bg.first_name) as first_name, 
            MAX(bg.last_name) as last_name, 
            bg.email, 
            MAX(bg.phone) as phone, 
            MAX(bg.nationality) as nationality, 
            COUNT(bg.id) as total_stays,
            (
                SELECT COUNT(*) 
                FROM orders o 
                LEFT JOIN users u ON o.user_id = u.id 
                WHERE u.email = bg.email
            ) as total_orders
        FROM booking_guests bg 
        $where
        GROUP BY bg.email 
        ORDER BY MAX(bg.last_name) ASC
        LIMIT $limit OFFSET $offset";

$result = $conn->query($sql);
$guests = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $guests[] = $row;
    }
    echo json_encode([
        'status' => 'success', 
        'data' => $guests,
        'total' => $totalCount,
        'limit' => $limit,
        'offset' => $offset
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => $conn->error]);
}

exit;
?>
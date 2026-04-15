<?php
// FILE: API/api_get_my_transactions.php

// 🟢 SECURITY: Prevent warnings/errors from breaking JSON
ob_start(); 
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 🟢 SECURITY: Restrict to POST requests only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

require 'connection.php'; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");

// 🟢 1. Capture Input (Handle both JSON & POST)
$input = json_decode(file_get_contents("php://input"), true);
$uid = isset($input['uid']) ? trim($input['uid']) : (isset($_POST['uid']) ? trim($_POST['uid']) : '');

if (empty($uid)) {
    ob_clean(); // Clear any previous output
    echo json_encode(["success" => false, "message" => "UID required"]);
    exit;
}

$debug = []; // Optional: Can disable this array in production for cleaner output

try {
    // 2. Resolve User ID (Firebase UID -> MySQL ID)
    $stmt = $conn->prepare("SELECT id FROM users WHERE firebase_uid = ? LIMIT 1");
    if (!$stmt) { throw new Exception("Prepare failed: " . $conn->error); }
    
    $stmt->bind_param("s", $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    // Fallback: Check if the input is already the numeric MySQL ID
    if (!$user) {
        $stmt_alt = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        if ($stmt_alt) {
            $stmt_alt->bind_param("i", $uid);
            $stmt_alt->execute();
            $res_alt = $stmt_alt->get_result();
            $user = $res_alt->fetch_assoc();
            $stmt_alt->close();
        }
    }

    if (!$user) {
        ob_clean();
        echo json_encode([
            "success" => false, 
            "message" => "User not found in Database"
        ]);
        exit;
    }

    $mysql_id = $user['id'];

    // 3. Combined Fetch (Transactions + Orders)
    // Using UNION ALL to merge Booking Payments and Food Orders into one list
    $sql = "
        (SELECT 
            id, 
            CAST(reference_id AS CHAR) as ref_id, 
            CAST(amount AS DECIMAL(10,2)) as amount, 
            payment_method, 
            status, 
            created_at as date,
            'Booking' as category
        FROM transactions 
        WHERE user_id = ?)
        
        UNION ALL
        
        (SELECT 
            id, 
            CAST(CONCAT('FOOD-', id) AS CHAR) as ref_id, 
            CAST(total_price AS DECIMAL(10,2)) as amount, 
            payment_method, 
            status, 
            order_date as date,
            'Food Order' as category
        FROM orders 
        WHERE user_id = ?)
        
        ORDER BY date DESC";

    $stmt2 = $conn->prepare($sql);
    if (!$stmt2) { throw new Exception("Prepare failed (Union): " . $conn->error); }
    
    $stmt2->bind_param("ii", $mysql_id, $mysql_id);
    $stmt2->execute();
    $result = $stmt2->get_result();

    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        // Ensure amount is a number (safeguard for Flutter)
        $row['amount'] = (float)$row['amount'];
        $transactions[] = $row;
    }

    // Final Output
    ob_clean(); 
    echo json_encode([
        "success" => true, 
        "data" => $transactions
    ]);

} catch (Exception $e) {
    ob_clean();
    // 🟢 SECURITY: Log the real error internally, show generic message to user
    error_log("Transaction API Error: " . $e->getMessage());
    echo json_encode([
        "success" => false, 
        "message" => "Server error while fetching transactions."
    ]);
}

$conn->close();
?>
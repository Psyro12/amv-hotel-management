<?php
// ADMIN/PHP/approve_order.php
session_start();
header('Content-Type: application/json');
require 'db_connect.php';

// Enable error logging for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 1. Auth Check
if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// 2. Validate Input
if (!isset($_POST['id']) || !isset($_POST['action'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
    exit;
}

$id = intval($_POST['id']);
$action = $_POST['action']; // 'approve' or 'reject'

// 3. Fetch Order Details
$sql = "SELECT o.id, o.room_number, u.email, u.account_source, o.payment_method 
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        WHERE o.id = ? LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$order = $result->fetch_assoc();
$stmt->close();

if (!$order) {
    echo json_encode(['status' => 'error', 'message' => 'Order not found']);
    exit;
}

// 4. Determine Statuses
$transactionStatus = '';
$orderStatus = '';
$notifTitle = '';
$notifMsg = '';
$successMsg = '';

if ($action === 'approve') {
    // 🟢 ACCEPT LOGIC
    $orderStatus = 'Preparing';
    
    // 🟢 FORCE STATUS TO 'Paid'
    // Since the kitchen accepted it, we consider the transaction valid/billable.
    $transactionStatus = 'Paid'; 

    $notifTitle = "Order Accepted";
    $notifMsg = "Your food order for Room " . $order['room_number'] . " is now being prepared.";
    $successMsg = "Order accepted and sent to kitchen!";

} else {
    // 🔴 REJECT LOGIC
    $orderStatus = 'Cancelled';
    
    // 🔴 FORCE STATUS TO 'Cancelled'
    $transactionStatus = 'Cancelled'; 

    $notifTitle = "Order Rejected";
    $notifMsg = "Your food order for Room " . $order['room_number'] . " has been cancelled. Please contact front desk.";
    $successMsg = "Order rejected.";
}

// 5. Update TRANSACTION Table
// We check "ORD-83", "Order #83", and "83" to cover all bases.
$ref1 = "ORD-" . $id;         // Matches your DB format
$ref2 = "Order #" . $id;      
$ref3 = (string)$id;          

$transSql = "UPDATE transactions SET status = ? 
             WHERE transaction_type = 'Food Order' 
             AND (reference_id = ? OR reference_id = ? OR reference_id = ?)";

$transStmt = $conn->prepare($transSql);
$transStmt->bind_param("ssss", $transactionStatus, $ref1, $ref2, $ref3);
$transStmt->execute();
$transStmt->close();

// 6. Update ORDER Table
$updateStmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
$updateStmt->bind_param("si", $orderStatus, $id);

if ($updateStmt->execute()) {
    
    // 7. Send Notification
    if (!empty($order['email'])) {
        $source = $order['account_source'] ?? 'email'; 
        
        $notifStmt = $conn->prepare("INSERT INTO guest_notifications (email, account_source, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, 'order', 0, NOW())");
        $notifStmt->bind_param("ssss", $order['email'], $source, $notifTitle, $notifMsg);
        $notifStmt->execute();
        $notifStmt->close();
    }

    // 🟢 TRIGGER REAL-TIME UPDATE FOR DASHBOARD
    $conn->query("UPDATE system_updates SET last_updated = CURRENT_TIMESTAMP WHERE category IN ('food_orders', 'transactions', 'notifications')");

    echo json_encode(['status' => 'success', 'message' => $successMsg]);

} else {
    echo json_encode(['status' => 'error', 'message' => 'Database update failed']);
}

$updateStmt->close();
$conn->close();
?>
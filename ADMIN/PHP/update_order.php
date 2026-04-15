<?php
// ADMIN/PHP/update_order.php

// 1. Start Buffering
ob_start();

session_start();
header('Content-Type: application/json');

// 2. Disable Error Display
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 3. Connect to Database
if (file_exists('db_connect.php')) {
    require 'db_connect.php';
} elseif (file_exists('../DB-CONNECTIONS/db_connect.php')) {
    require '../DB-CONNECTIONS/db_connect.php';
} else {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Database connection file not found.']);
    exit;
}

// 4. Clear Buffer
ob_clean(); 

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$id = intval($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($id > 0 && !empty($action)) {
    $status = '';
    
    if ($action === 'prepare') $status = 'Preparing';
    elseif ($action === 'deliver') $status = 'Delivered';
    elseif ($action === 'cancel') $status = 'Cancelled';

    if ($status) {
        // A. Update the Order Status
        $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $id);
        
        if ($stmt->execute()) {
            
            // 🟢 B. NEW: SEND NOTIFICATION TO GUEST
            // 1. Get the User ID and Email associated with this Order
            $sqlGuest = "SELECT o.user_id, u.email, u.account_source 
                         FROM orders o 
                         JOIN users u ON o.user_id = u.id 
                         WHERE o.id = ?";
            
            $stmtGuest = $conn->prepare($sqlGuest);
            $stmtGuest->bind_param("i", $id);
            $stmtGuest->execute();
            $resGuest = $stmtGuest->get_result();

            if ($rowGuest = $resGuest->fetch_assoc()) {
                $email = $rowGuest['email'];
                $source = $rowGuest['account_source'] ?? 'email'; // Default fallback
                
                // 2. Define Message based on Action
                $notifTitle = "Order Update";
                $notifMsg = "";
                
                if ($action === 'prepare') {
                    $notifTitle = "Kitchen Update";
                    $notifMsg = "Your food order #{$id} is now being prepared by the kitchen.";
                } elseif ($action === 'deliver') {
                    $notifTitle = "Order Served";
                    $notifMsg = "Your food order #{$id} has been served. Enjoy your meal!";
                } elseif ($action === 'cancel') {
                    $notifTitle = "Order Cancelled";
                    $notifMsg = "Your food order #{$id} has been cancelled. Please contact staff for details.";
                }

                // 3. Insert Notification if message exists
                if ($notifMsg) {
                    $stmtNotif = $conn->prepare("INSERT INTO guest_notifications (email, account_source, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, 'order', 0, NOW())");
                    $stmtNotif->bind_param("ssss", $email, $source, $notifTitle, $notifMsg);
                    $stmtNotif->execute();
                    $stmtNotif->close();
                }
            }
            $stmtGuest->close();
            // 🟢 END NOTIFICATION LOGIC

            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database update failed: ' . $conn->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action type']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Missing ID or Action']);
}
?>
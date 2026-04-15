<?php
// FILE: API/api_place_order.php

error_reporting(E_ALL);
ini_set("display_errors", 0);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method Not Allowed"]);
    exit();
}

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

function sendError($message) {
    echo json_encode(["success" => false, "message" => $message]);
    exit();
}

try {
    include "connection.php";

    $data = $_POST;
    if (empty($data)) {
        $json = file_get_contents("php://input");
        $data = json_decode($json, true);
    }

    if (empty($data)) sendError("No data received.");

    $incomingUid   = isset($data['user_id']) ? trim($data['user_id']) : (isset($data['uid']) ? trim($data['uid']) : '');        
    $totalPrice    = $data['total_price'] ?? 0;
    $roomNumber    = $data['room_number'] ?? 'Unknown';
    $paymentMethod = $data['payment_method'] ?? '';
    $notes         = $data['notes'] ?? '';
    $paymentRef    = $data['payment_reference'] ?? null;
    $cartItemsRaw  = $data['cart_items'] ?? '[]';
    $itemsJsonString = is_array($cartItemsRaw) ? json_encode($cartItemsRaw) : $cartItemsRaw;
    $ocrText       = $data['ocr_text'] ?? ''; // 🟢 Received from mobile OCR

    // 🟢 RECIPIENT VALIDATION (BACKEND)
    if ($paymentMethod === 'GCash' && !empty($accNum)) {
        $last4 = substr($accNum, -4);
        $cleanOcr = str_replace([' ', '-', ':'], '', $ocrText);
        $cleanAcc = str_replace([' ', '-', ':'], '', $accNum);

        // Check if full account or last 4 digits exist in the OCR text
        if (!empty($ocrText) && !str_contains($cleanOcr, $cleanAcc) && !str_contains($cleanOcr, $last4)) {
            sendError("RECIPIENT_MISMATCH: The receipt does not match the hotel's GCash number.");
        }
    }

    if (empty($incomingUid)) sendError("User ID is required."); 

    // Get User Details
    $stmt = $conn->prepare("SELECT id, email, account_source, is_blocked FROM users WHERE firebase_uid = ? LIMIT 1");
    $stmt->bind_param("s", $incomingUid);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $realUserId = $row['id'];
        $userEmail  = $row['email'];
        $userSource = $row['account_source'];
        $isPermanentlyBlocked = $row['is_blocked'];
    } else {
        // Fallback for direct ID
        $stmt2 = $conn->prepare("SELECT id, email, account_source, is_blocked FROM users WHERE id = ? LIMIT 1");
        $stmt2->bind_param("i", $incomingUid);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        if ($row2 = $res2->fetch_assoc()) {
            $realUserId = $row2['id'];
            $userEmail  = $row2['email'];
            $userSource = $row2['account_source'];
            $isPermanentlyBlocked = $row2['is_blocked'];
        }
        $stmt2->close();
    }
    $stmt->close();

    if (empty($realUserId)) sendError("User not found.");

    // 🟢 BLOCK CHECK 1: Permanent Block
    if ($isPermanentlyBlocked) {
        sendError("Your account is permanently blocked from ordering. Please contact front desk.");
    }

    // 🟢 BLOCK CHECK 2: Pending Limit (4 Orders)
    $checkStmt = $conn->prepare("SELECT COUNT(*) as pending_count FROM orders WHERE user_id = ? AND status = 'Pending'");
    $checkStmt->bind_param("i", $realUserId);
    $checkStmt->execute();
    $pendingCount = $checkStmt->get_result()->fetch_assoc()['pending_count'] ?? 0;
    $checkStmt->close();

    if ($pendingCount >= 4) {
        // Option: We can also set the permanent flag here if you want to 'ban' them 
        // for spamming, but for now we just block the order.
        sendError("You have 4 pending orders. Please wait for them to be delivered before ordering more.");
    }

    // 🟢 FETCH HOTEL GCASH CREDENTIALS
    $accNum = "";
    $stmt_pay = $conn->prepare("SELECT account_number FROM payment_settings WHERE method_name = 'GCash' LIMIT 1");
    $stmt_pay->execute();
    $res_pay = $stmt_pay->get_result();
    if ($row_p = $res_pay->fetch_assoc()) {
        $accNum = $row_p['account_number'];
    }
    $stmt_pay->close();

    // Handle Receipt
    $receiptPath = null;
    $orderStatus = 'Pending';
    if ($paymentMethod === 'Charge to Room') {
        $receiptPath = "Charge to Room";
    } else if (($paymentMethod === 'GCash' || $paymentMethod === 'Maya') && isset($_FILES['receipt'])) {
        $file = $_FILES['receipt'];
        if ($file['error'] === 0) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $uploadDir = "../room_includes/uploads/receipts/";
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
            $filename = "FOOD_" . time() . "_" . uniqid() . "." . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                $receiptPath = $filename;
            }
        }
    }

    $conn->begin_transaction();

    // Insert Order
    $sql = "INSERT INTO orders (user_id, items, total_price, room_number, payment_method, payment_proof, notes, payment_reference, order_date, status, is_read_by_user)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 0)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isdssssss", $realUserId, $itemsJsonString, $totalPrice, $roomNumber, $paymentMethod, $receiptPath, $notes, $paymentRef, $orderStatus);

    if ($stmt->execute()) {
        $newOrderId = $stmt->insert_id;
        $stmt->close();

        // Notification
        $notifSql = "INSERT INTO guest_notifications (email, account_source, title, message, type, is_read, created_at)
                     VALUES (?, ?, ?, ?, ?, 0, NOW())";
        $notifStmt = $conn->prepare($notifSql);
        $title = "Order Received";
        $msg = "Your order for Room $roomNumber has been placed.";
        $type = "system";
        $notifStmt->bind_param("sssss", $userEmail, $userSource, $title, $msg, $type);
        $notifStmt->execute();
        $notifStmt->close();

        // Transaction
        $refID = "ORD-" . $newOrderId;
        $sqlTrans = "INSERT INTO transactions (user_id, transaction_type, reference_id, amount, payment_method, status, created_at)
                     VALUES (?, 'Food Order', ?, ?, ?, 'Pending', NOW())";
        $transStmt = $conn->prepare($sqlTrans);
        $transStmt->bind_param("isds", $realUserId, $refID, $totalPrice, $paymentMethod);
        $transStmt->execute();
        $transStmt->close();

        // 🟢 TRIGGER REAL-TIME UPDATE FOR DASHBOARD
        $conn->query("UPDATE system_updates SET last_updated = CURRENT_TIMESTAMP WHERE category IN ('food_orders', 'transactions', 'notifications')");

        $conn->commit();
        echo json_encode(["success" => true, "message" => "Order Placed Successfully!"]);
    } else {
        throw new Exception($conn->error);
    }
} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    sendError("System Error: " . $e->getMessage());
}
?>
<?php
// FILE: API/api_get_user_status.php

error_reporting(E_ALL);
ini_set("display_errors", 0);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

include "connection.php";

$uid = isset($_GET["uid"]) ? $_GET["uid"] : "";

if (empty($uid)) {
    echo json_encode(["success" => false, "message" => "UID is required"]);
    exit();
}

// 1. Get User ID and permanent block status
$stmt = $conn->prepare("SELECT id, is_blocked FROM users WHERE firebase_uid = ? OR id = ? LIMIT 1");
$stmt->bind_param("ss", $uid, $uid);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $realUserId = $row["id"];
    $isPermanentlyBlocked = (int)$row["is_blocked"] === 1;

    // 2. Count current pending orders (Limit 4)
    $orderStmt = $conn->prepare("SELECT COUNT(*) as pending_count FROM orders WHERE user_id = ? AND status = 'Pending'");
    $orderStmt->bind_param("i", $realUserId);
    $orderStmt->execute();
    $orderCount = $orderStmt->get_result()->fetch_assoc()["pending_count"] ?? 0;
    $orderStmt->close();

    // 3. Count current pending bookings (Limit 4)
    $bookingStmt = $conn->prepare("SELECT COUNT(*) as pending_count FROM bookings WHERE user_id = ? AND status = 'pending'");
    $bookingStmt->bind_param("i", $realUserId);
    $bookingStmt->execute();
    $bookingCount = $bookingStmt->get_result()->fetch_assoc()["pending_count"] ?? 0;
    $bookingStmt->close();

    // 4. Determine blocked states
    $isOrderBlocked = $isPermanentlyBlocked || ($orderCount >= 4);
    $isBookingBlocked = $isPermanentlyBlocked || ($bookingCount >= 4);

    echo json_encode([
        "success" => true,
        "is_blocked" => $isOrderBlocked, // Main flag used by home_screen for orders
        "is_booking_blocked" => $isBookingBlocked,
        "order_pending_count" => $orderCount,
        "booking_pending_count" => $bookingCount,
        "is_permanently_blocked" => $isPermanentlyBlocked
    ]);
} else {
    echo json_encode(["success" => false, "message" => "User not found"]);
}

$stmt->close();
$conn->close();
?>
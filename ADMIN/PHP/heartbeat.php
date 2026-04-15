<?php
// ADMIN/PHP/heartbeat.php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

$userId = $_SESSION['user']['id'] ?? $_SESSION['user']['ID'] ?? null;

if ($userId) {
    $nowStr = date('Y-m-d H:i:s');
    $currentIp = $_SERVER['REMOTE_ADDR'];
    $currentSessionId = session_id();

    $stmt = $conn->prepare("UPDATE admin_user SET last_activity = ?, last_ip = ?, last_session_id = ? WHERE ID = ?");
    $stmt->bind_param("sssi", $nowStr, $currentIp, $currentSessionId, $userId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['status' => 'success', 'timestamp' => $nowStr]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'No User ID']);
}

$conn->close();
?>

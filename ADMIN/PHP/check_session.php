<?php
// ADMIN/PHP/check_session.php

// 1. DISABLE HTML ERROR OUTPUT (Crucial for JSON endpoints)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json');

try {
    require 'db_connect.php';

    // 2. CHECK SESSION EXISTENCE
    if (!isset($_SESSION['user'])) {
        echo json_encode(['status' => 'logout', 'reason' => 'No active session found']);
        exit;
    }

    $userId = $_SESSION['user']['id'] ?? $_SESSION['user']['ID'] ?? null;
    $clientSessionToken = $_SESSION['active_session_id'] ?? null;

    if ($userId && $clientSessionToken) {
        // 🟢 SINGLE SESSION VALIDATION: Check if DB token matches Session token
        $stmt = $conn->prepare("SELECT active_session_id FROM admin_user WHERE ID = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res->fetch_assoc();

        if ($user && $user['active_session_id'] !== $clientSessionToken) {
            // Token mismatch! Another login occurred.
            session_destroy();
            echo json_encode(['status' => 'logout', 'reason' => 'Session invalidated by another login']);
            exit;
        }
    }

    echo json_encode(['status' => 'active']);
    $conn->close();

} catch (Exception $e) {
    // 6. CATCH ERRORS AND RETURN JSON
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
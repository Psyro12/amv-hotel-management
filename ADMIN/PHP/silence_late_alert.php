<?php
// ADMIN/PHP/silence_late_alert.php
session_start();
require 'db_connect.php';

header('Content-Type: application/json');

// CSRF check
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        die(json_encode(['status' => 'error', 'message' => 'CSRF token mismatch']));
    }
    
    // Set session variable to silence late alerts
    $_SESSION['late_alerts_silenced'] = true;
    
    // Optionally set expiration (e.g., until midnight)
    $_SESSION['late_alerts_silenced_until'] = date('Y-m-d 23:59:59');
    
    echo json_encode(['status' => 'success']);
    exit();
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
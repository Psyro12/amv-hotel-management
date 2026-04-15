<?php
// ADMIN/PHP/update_admin_profile.php

// 1. Start Session & Setup
ob_start();
session_start();
require 'db_connect.php';
ob_clean();
header('Content-Type: application/json');

// 2. Security Check (CSRF Token)
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['status' => 'error', 'message' => 'Security token invalid. Please refresh the page.']);
    exit;
}

// 3. Robust Session Check (ID & Login)
$userId = null;
if (isset($_SESSION['user']['id'])) {
    $userId = $_SESSION['user']['id'];
} elseif (isset($_SESSION['user']['ID'])) {
    $userId = $_SESSION['user']['ID'];
}

if (!$userId) {
    echo json_encode(['status' => 'error', 'message' => 'Session expired. Please login again.']);
    exit;
}

// 🟢 KEEP-ALIVE
if ($userId) {
    $_SESSION['timeout'] = time();
}

// 4. Retrieve & Sanitize Inputs
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$contact = trim($_POST['contact'] ?? '');
$password = $_POST['password'] ?? '';
$confirm = $_POST['confirm_password'] ?? '';

// 🟢 New WiFi Inputs
$wifi_ssid = $_POST['wifi_ssid'] ?? '';
$wifi_pass = $_POST['wifi_password'] ?? '';

// 5. Validation
if (empty($name) || empty($email)) {
    echo json_encode(['status' => 'error', 'message' => 'Name and Email are required.']);
    exit;
}

if ($password !== "" && $password !== $confirm) {
    echo json_encode(['status' => 'error', 'message' => 'Passwords do not match']);
    exit;
}

// 6. Start Database Transaction
$conn->begin_transaction();

try {
    // ---------------------------------------------------------
    // A. UPDATE ADMIN USER
    // ---------------------------------------------------------
    $stmt = null;
    
    if ($password !== "") {
        // Update WITH password
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        
        // Try uppercase 'ID' first (per your schema convention)
        $sql = "UPDATE admin_user SET name = ?, email = ?, contact_number = ?, password = ? WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        
        // Fallback to lowercase 'id' if prepare fails
        if (!$stmt) {
             $sql = "UPDATE admin_user SET name = ?, email = ?, contact_number = ?, password = ? WHERE id = ?";
             $stmt = $conn->prepare($sql);
        }
        
        if ($stmt) $stmt->bind_param("ssssi", $name, $email, $contact, $hashed, $userId);

    } else {
        // Update INFO only (No password change)
        $sql = "UPDATE admin_user SET name = ?, email = ?, contact_number = ? WHERE ID = ?";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
             $sql = "UPDATE admin_user SET name = ?, email = ?, contact_number = ? WHERE id = ?";
             $stmt = $conn->prepare($sql);
        }

        if ($stmt) $stmt->bind_param("sssi", $name, $email, $contact, $userId);
    }

    if (!$stmt || !$stmt->execute()) {
        throw new Exception("Failed to update admin profile: " . ($stmt ? $stmt->error : $conn->error));
    }
    $stmt->close();

    // ---------------------------------------------------------
    // B. 🟢 UPDATE WIFI SETTINGS (Always ID 1)
    // ---------------------------------------------------------
    // Only update if fields are not empty (optional, removes risk of wiping data accidentally)
    if ($wifi_ssid !== '') { 
        $stmtWifi = $conn->prepare("UPDATE wifi_settings SET ssid = ?, password = ? WHERE id = 1");
        if (!$stmtWifi) {
            // Create table if missing (Optional safety net, good for first run)
            // Or just throw error. Usually assumes table exists.
             throw new Exception("WiFi table not found or prepare failed.");
        }
        $stmtWifi->bind_param("ss", $wifi_ssid, $wifi_pass);
        
        if (!$stmtWifi->execute()) {
             throw new Exception("Failed to update WiFi settings: " . $stmtWifi->error);
        }
        $stmtWifi->close();
    }

    // ---------------------------------------------------------
    // C. COMMIT & UPDATE SESSION
    // ---------------------------------------------------------
    $conn->commit();

    // Update Session Data Immediately
    $_SESSION['user']['name'] = $name;
    $_SESSION['user']['email'] = $email;
    
    // Sync keys
    $_SESSION['user']['id'] = $userId;
    $_SESSION['user']['ID'] = $userId;

    echo json_encode(['status' => 'success', 'message' => 'Profile and settings updated successfully']);

} catch (Exception $e) {
    // Rollback changes on any error
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
exit;
?>
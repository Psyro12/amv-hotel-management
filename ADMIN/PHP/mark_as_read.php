<?php
// ADMIN/PHP/mark_as_read.php
require 'db_connect.php';

// IMPORTANT: Start the session so we can save the "silence" flag
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$id = $input['id'] ?? 0;
$type = $input['type'] ?? ''; 

// --- NEW: SILENCE LOGIC ---
// This handles the request from goToBookingsTab to stop the popup for the current session
if ($type === 'silence_late_alerts') {
    $_SESSION['late_alerts_silenced'] = true;
    echo json_encode(['status' => 'success', 'message' => 'Late alerts silenced for this session']);
    exit;
}

// --- EXISTING BULK ACTIONS ---
if ($type === 'notification_all') {
    $stmt = $conn->prepare("UPDATE system_notifications SET is_read = 1 WHERE is_read = 0");
} 
elseif ($type === 'late_all') {
    // This marks existing late notifications as read in the DB
    $stmt = $conn->prepare("UPDATE system_notifications SET is_read = 1 WHERE (type = 'reminder' OR type = 'late') AND (title LIKE '%Late%' OR description LIKE '%missed%')");
}
elseif ($type === 'message_all') {
    $stmt = $conn->prepare("UPDATE guest_messages SET is_read = 1 WHERE is_read = 0");
}
elseif ($type === 'message') {
    $stmt = $conn->prepare("UPDATE guest_messages SET is_read = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
} 
else {
    // Default single notification update
    $stmt = $conn->prepare("UPDATE system_notifications SET is_read = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
}

if ($stmt->execute()) { 
    echo json_encode(['status' => 'success']); 
} else { 
    echo json_encode(['status' => 'error']); 
}
?>
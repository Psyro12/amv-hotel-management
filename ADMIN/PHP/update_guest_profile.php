<?php
// ADMIN/PHP/update_guest_profile.php
session_start();
require 'db_connect.php';

header('Content-Type: application/json');

// 1. CSRF Security Check
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        echo json_encode(['status' => 'error', 'message' => 'Security check failed.']);
        exit();
    }

    // 2. Get Data
    $email = $_POST['original_email']; // Used to find the guest
    $fname = $_POST['firstname'];
    $lname = $_POST['lastname'];
    $phone = $_POST['phone'];
    $nation = $_POST['nationality'];
    $gender = $_POST['gender'];
    $dob = $_POST['birthdate'];
    $addr = $_POST['address'];

    // 3. Update Database
    $sql = "UPDATE booking_guests SET 
            first_name = ?, 
            last_name = ?, 
            phone = ?, 
            nationality = ?, 
            gender = ?, 
            birthdate = ?, 
            address = ? 
            WHERE email = ?";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ssssssss", $fname, $lname, $phone, $nation, $gender, $dob, $addr, $email);
        
        if ($stmt->execute()) {
            // Also update the main 'bookings' table guest name cache if you have one, 
            // but usually joining booking_guests is better.
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database update failed.']);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Query preparation failed.']);
    }
}
?>
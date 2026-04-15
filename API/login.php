<?php
// API/login.php

require 'connection.php';

// 1. Get Input
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

if (!isset($input['email']) || !isset($input['password'])) {
    echo json_encode(['success' => false, 'message' => 'Missing email or password']);
    exit();
}

$email = $input['email'];
$password = $input['password'];

// 2. Query WITHOUT 'role'
$sql = "SELECT id, name, password FROM test_users WHERE email = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    // 3. Verify Password (Plain text for now)
    if ($password === $row['password']) {
        
        echo json_encode([
            'success' => true,
            'message' => 'Login Successful',
            'user' => [
                'id' => $row['id'],
                'name' => $row['name']
                // Role is removed
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid Password']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'User not found']);
}

$stmt->close();
$conn->close();
?>
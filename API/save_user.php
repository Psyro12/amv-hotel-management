<?php
// 1. Headers for Flutter connectivity
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

session_start();

// 2. Database Connection
require '../DB-CONNECTIONS/db_connect.php';

// 3. Get JSON data from Flutter
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode(["error" => "No data received"]);
    exit();
}

// 4. Sanitize Input
$uid   = $conn->real_escape_string($data["uid"]);
$name  = $conn->real_escape_string($data["name"]);
$email = $conn->real_escape_string($data["email"]);
$photo = $conn->real_escape_string($data["photo"]);

// 5. Check if user exists
$sql = "SELECT * FROM users WHERE uid='$uid'";
$result = $conn->query($sql);

if ($result->num_rows == 0) {
    // Insert new user if they don't exist
    $insert = "INSERT INTO users (uid, name, email, photo) 
               VALUES ('$uid', '$name', '$email', '$photo')";
    $conn->query($insert);
} else {
    // Update existing user info
    $update = "UPDATE users 
               SET name='$name', email='$email', photo='$photo' 
               WHERE uid='$uid'";
    $conn->query($update);
}

// 6. Store to session for your web compatibility
$_SESSION["user"] = [
    "uid" => $uid,
    "name" => $name,
    "email" => $email,
    "picture" => $photo
];

// 7. Return success to Flutter
echo json_encode(["success" => true, "redirect" => "index.php"]);

$conn->close();
?>
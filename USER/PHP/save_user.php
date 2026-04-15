<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

session_start();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["error" => "Only POST allowed"]);
    exit();
}

require 'db_connect.php';

$data = json_decode(file_get_contents("php://input"), true);

$uid   = $conn->real_escape_string($data["uid"]);
$name  = $conn->real_escape_string($data["name"]);
$email = $conn->real_escape_string($data["email"]);
$photo = $conn->real_escape_string($data["photo"]);

// Check if user exists
$sql = "SELECT * FROM users WHERE uid='$uid'";
$result = $conn->query($sql);

// Insert new user
if ($result->num_rows == 0) {
    $insert = "INSERT INTO users (uid, name, email, photo)
               VALUES ('$uid', '$name', '$email', '$photo')";
    $conn->query($insert);
} 
// Update existing user
else {
    $update = "UPDATE users
               SET name='$name', email='$email', photo='$photo'
               WHERE uid='$uid'";
    $conn->query($update);
}

// Store to session
$_SESSION["user"] = [
    "uid" => $uid,
    "name" => $name,
    "email" => $email,
    "picture" => $photo
];

// Return redirect
echo json_encode(["redirect" => "index.php"]);

$conn->close();
?>

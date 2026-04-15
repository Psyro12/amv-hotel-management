<?php
session_start();
require 'db_connect.php';

// 🟢 CLEAR EXCLUSIVE ACCESS: Clear last_activity so others can log in
if (isset($_SESSION['user'])) {
    $userId = $_SESSION['user']['id'] ?? $_SESSION['user']['ID'] ?? null;
    if ($userId) {
        $stmt = $conn->prepare("UPDATE admin_user SET last_activity = NULL WHERE ID = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
    }
}

session_unset();
session_destroy();
header('Location: login.php');
exit();



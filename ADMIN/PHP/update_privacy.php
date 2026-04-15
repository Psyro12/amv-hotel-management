<?php
// ADMIN/PHP/update_privacy.php
session_start();
require 'db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

try {
    $json = $_POST['privacy_content'];
    $data = json_decode($json, true);

    if (!is_array($data)) {
        throw new Exception("Invalid data format");
    }

    // 1. Clear existing privacy policy
    $conn->query("TRUNCATE TABLE privacy_policy");

    // 2. Insert new sections
    $stmt = $conn->prepare("INSERT INTO privacy_policy (section_title, content, display_order) VALUES (?, ?, ?)");
    
    $order = 1;
    foreach ($data as $item) {
        $title = $item['title'];
        $content = $item['content'];
        $stmt->bind_param("ssi", $title, $content, $order);
        $stmt->execute();
        $order++;
    }

    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
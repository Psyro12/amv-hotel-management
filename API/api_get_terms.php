<?php
// FILE: API/api_get_terms.php

// 🟢 SECURITY: Disable error display to prevent path leakage
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 🟢 SECURITY: Restrict to GET requests only
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit();
}

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
date_default_timezone_set('Asia/Manila');

require 'connection.php';

// Fetch content ordered by 'display_order'
$sql = "SELECT title, content FROM terms_conditions ORDER BY display_order ASC";
$result = $conn->query($sql);

if ($result) {
    if ($result->num_rows > 0) {
        $data = array();
        while($row = $result->fetch_assoc()) {
            $data[] = array(
                'title' => $row['title'],
                'content' => $row['content']
            );
        }
        echo json_encode(['success' => true, 'data' => $data]);
    } else {
        // Return empty list instead of error for cleaner UI
        echo json_encode(['success' => true, 'data' => []]);
    }
} else {
    // 🟢 SECURITY: Log internal error
    error_log("Database Error (Terms): " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'System error']);
}

$conn->close();
?>
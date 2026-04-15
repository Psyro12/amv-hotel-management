<?php
// ADMIN/PHP/update_terms.php
session_start();
header('Content-Type: application/json');
require 'db_connect.php';

// 1. Check Login
if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

// 2. CSRF Check
$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'], $token)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Security Token']);
    exit();
}

// 3. Get Data
$jsonContent = $_POST['terms_content'] ?? '';
$policies = json_decode($jsonContent, true);

if (!is_array($policies)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid data format']);
    exit();
}

// 4. TRANSACTION
$conn->begin_transaction();

try {
    // A. Wipe existing terms
    $conn->query("TRUNCATE TABLE terms_conditions");

    // 🟢 B. Prepare Insert Statement (Added 'update_at' and 'NOW()')
    // Make sure your database column is exactly named 'update_at'
    $stmt = $conn->prepare("INSERT INTO terms_conditions (title, content, updated_at) VALUES (?, ?, NOW())");

    // C. Loop and Insert
    foreach ($policies as $policy) {
        $title = $policy['title'] ?? 'Untitled Section';
        $content = $policy['content'] ?? '';

        if (!empty(trim($title)) || !empty(trim($content))) {
            // We only bind 2 parameters (title, content). NOW() is handled by MySQL.
            $stmt->bind_param("ss", $title, $content);
            $stmt->execute();
        }
    }

    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => 'Policies saved successfully']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
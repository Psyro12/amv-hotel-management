<?php
// ADMIN/PHP/update_payment_settings.php
session_start();
header('Content-Type: application/json');
require 'db_connect.php';

// 1. Security Check
$token = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
    exit;
}

// 2. Get Data from Form
$accName = $_POST['account_name'] ?? '';
$accNum = $_POST['account_number'] ?? '';
$method = $_POST['payment_method'] ?? 'GCash'; 

// 3. Handle File Upload & Cleanup
$qrPath = null;
if (isset($_FILES['qr_image']) && $_FILES['qr_image']['error'] === UPLOAD_ERR_OK) {
    
    $uploadDir = '../../room_includes/uploads/payment/';
    
    // Ensure directory exists
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $fileExt = strtolower(pathinfo($_FILES['qr_image']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (in_array($fileExt, $allowed)) {
        // A. Generate New Filename
        $newFileName = 'qr_' . time() . '.' . $fileExt;
        $destPath = $uploadDir . $newFileName;

        // B. Fetch OLD Image Filename from Database BEFORE updating
        $oldFile = null;
        $fetchSql = "SELECT qr_image_path FROM payment_settings WHERE id=1";
        $fetchRes = $conn->query($fetchSql);
        if ($fetchRes && $row = $fetchRes->fetch_assoc()) {
            $oldFile = $row['qr_image_path'];
        }

        // C. Upload the NEW Image
        if (move_uploaded_file($_FILES['qr_image']['tmp_name'], $destPath)) {
            $qrPath = $newFileName; // Ready to save to DB

            // D. Delete the OLD Image (Garbage Collection)
            if ($oldFile && !empty($oldFile)) {
                $oldFilePath = $uploadDir . $oldFile;
                
                // Check if file exists AND it's not a default placeholder (optional safety)
                // Also ensure we aren't deleting the file we just uploaded (unlikely but safe)
                if (file_exists($oldFilePath) && is_file($oldFilePath) && $oldFile !== $newFileName) {
                    unlink($oldFilePath); // <--- THIS DELETES THE FILE
                }
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to move uploaded file.']);
            exit;
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid file type. Allowed: JPG, PNG, WEBP']);
        exit;
    }
}

// 4. Update Database
// Use correct column names: method_name, qr_image_path
$sql = "UPDATE payment_settings SET method_name=?, account_name=?, account_number=?";
$params = [$method, $accName, $accNum];
$types = "sss";

// Only update image column if a new one was uploaded
if ($qrPath) {
    $sql .= ", qr_image_path=?";
    $params[] = $qrPath;
    $types .= "s";
}

$sql .= " WHERE id=1";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    echo json_encode([
        'status' => 'success', 
        'message' => 'Payment settings updated!',
        'new_qr' => $qrPath // Return new filename so JS can update preview
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database update failed: ' . $conn->error]);
}
?>
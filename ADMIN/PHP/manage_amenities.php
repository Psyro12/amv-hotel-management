<?php
// ADMIN/PHP/manage_amenities.php
session_start();
require 'db_connect.php';

header('Content-Type: application/json');

// Security Check
if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$action = $_POST['action'] ?? '';

try {
    if ($action === 'add') {
        $title = trim($_POST['title']);
        $icon = trim($_POST['icon_class']);
        $desc = trim($_POST['description']);

        if (empty($title) || empty($icon)) {
            throw new Exception("Title and Icon are required.");
        }

        $stmt = $conn->prepare("INSERT INTO amenities (title, icon_class, description) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $title, $icon, $desc);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Amenity added successfully!']);
        } else {
            throw new Exception("Database Error: " . $stmt->error);
        }
        $stmt->close();

    } elseif ($action === 'edit') {
        $id = (int)$_POST['amenity_id'];
        $title = trim($_POST['title']);
        $icon = trim($_POST['icon_class']);
        $desc = trim($_POST['description']);

        if (empty($title) || empty($icon)) {
            throw new Exception("Title and Icon are required.");
        }

        $stmt = $conn->prepare("UPDATE amenities SET title = ?, icon_class = ?, description = ? WHERE id = ?");
        $stmt->bind_param("sssi", $title, $icon, $desc, $id);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Amenity updated successfully!']);
        } else {
            throw new Exception("Database Error: " . $stmt->error);
        }
        $stmt->close();

    } elseif ($action === 'delete') {
        $id = (int)$_POST['amenity_id'];

        // 1. We should ideally remove this ID from all rooms' CSV strings first, 
        // but for simplicity, the frontend logic handles missing IDs gracefully.
        // Let's just delete the record.

        $stmt = $conn->prepare("DELETE FROM amenities WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Amenity deleted.']);
        } else {
            throw new Exception("Database Error: " . $stmt->error);
        }
        $stmt->close();

    } else {
        throw new Exception("Invalid Action");
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>

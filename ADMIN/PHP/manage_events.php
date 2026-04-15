<?php
// ADMIN/PHP/manage_events.php

// 1. SILENCE PHP ERRORS (Prevents the "<br />" HTML response)
error_reporting(0);
ini_set('display_errors', 0);

session_start();
require 'db_connect.php'; 

header('Content-Type: application/json');

// Check Auth
if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$action = $_POST['action'] ?? '';
$targetDir = "../../room_includes/uploads/events/"; // Folder path

try {

    // --- 1. ADD / EDIT LOGIC ---
    if ($action === 'add' || $action === 'edit') {
        $title = $_POST['title'] ?? '';
        $date = $_POST['event_date'] ?? '';
        $time = $_POST['time_start'] ?? '';
        $desc = $_POST['description'] ?? '';
        $id = $_POST['event_id'] ?? null;

        $imagePath = "";

        // Handle Image Upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

            $fileName = time() . "_" . basename($_FILES["image"]["name"]);
            $targetFile = $targetDir . $fileName;
            
            if (move_uploaded_file($_FILES["image"]["tmp_name"], $targetFile)) {
                $imagePath = $fileName;

                // Delete old image if editing
                if ($action === 'edit' && $id) {
                    $q = $conn->prepare("SELECT image_path FROM hotel_events WHERE id = ?");
                    $q->bind_param("i", $id);
                    $q->execute();
                    $res = $q->get_result();
                    if ($res && $row = $res->fetch_assoc()) {
                        $oldFile = $targetDir . $row['image_path'];
                        if (!empty($row['image_path']) && file_exists($oldFile)) {
                            @unlink($oldFile); // @ suppresses errors if file missing
                        }
                    }
                    $q->close();
                }
            }
        }

        if ($action === 'add') {
            // New Event defaults to Active (1)
            $stmt = $conn->prepare("INSERT INTO hotel_events (title, event_date, time_start, description, image_path, is_active) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt->bind_param("sssss", $title, $date, $time, $desc, $imagePath);
        } else {
            if ($imagePath !== "") {
                $stmt = $conn->prepare("UPDATE hotel_events SET title=?, event_date=?, time_start=?, description=?, image_path=? WHERE id=?");
                $stmt->bind_param("sssssi", $title, $date, $time, $desc, $imagePath, $id);
            } else {
                $stmt = $conn->prepare("UPDATE hotel_events SET title=?, event_date=?, time_start=?, description=? WHERE id=?");
                $stmt->bind_param("ssssi", $title, $date, $time, $desc, $id);
            }
        }

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Event saved successfully!']);
        } else {
            throw new Exception("Database error: " . $stmt->error);
        }
        $stmt->close();

    // --- 2. SOFT DELETE (ARCHIVE) ---
    } elseif ($action === 'delete') {
        $id = $_POST['event_id'];
        
        // Just set is_active to 0
        $stmt = $conn->prepare("UPDATE hotel_events SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Event archived successfully.']);
        } else {
            throw new Exception("Failed to archive event.");
        }
        $stmt->close();

    // --- 3. RESTORE ---
    } elseif ($action === 'restore') {
        $id = $_POST['event_id'];

        // Set is_active to 1
        $stmt = $conn->prepare("UPDATE hotel_events SET is_active = 1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Event restored successfully.']);
        } else {
            throw new Exception("Failed to restore event.");
        }
        $stmt->close();

    // --- 4. HARD DELETE (PERMANENT) ---
    } elseif ($action === 'hard_delete') {
        $id = $_POST['event_id'];

        // 1. Get Image Path
        $query = $conn->prepare("SELECT image_path FROM hotel_events WHERE id = ?");
        $query->bind_param("i", $id);
        $query->execute();
        $result = $query->get_result();
        
        // CHECK if row exists before fetching
        $imageToDelete = "";
        if ($result && $row = $result->fetch_assoc()) {
            $imageToDelete = $row['image_path'];
        }
        $query->close();

        // 2. Delete DB Record
        $stmt = $conn->prepare("DELETE FROM hotel_events WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            // 3. Delete File (Only if DB delete worked)
            if (!empty($imageToDelete)) {
                $fullPath = $targetDir . $imageToDelete;
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
            }
            echo json_encode(['status' => 'success', 'message' => 'Event permanently deleted.']);
        } else {
            throw new Exception("Database deletion failed.");
        }
        $stmt->close();
    } else {
        throw new Exception("Invalid action type.");
    }

} catch (Exception $e) {
    // Catch any PHP errors and return them as JSON
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
<?php
session_start();
require 'db_connect.php'; // Ensure this points to your DB connection file

header('Content-Type: application/json');

// Security Check
if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

// Define the folder globally
$targetDir = "../../room_includes/uploads/news/";

// Handle Image Upload Helper Function
function handleFileUpload($file, $targetDir)
{
    if (!file_exists($targetDir)) {
        if (!mkdir($targetDir, 0777, true)) {
            error_log("Failed to create directory: " . $targetDir);
            return false;
        }
    }

    $originalName = basename($file["name"]);
    $cleanName = preg_replace("/[^a-zA-Z0-9\.]/", "_", $originalName);
    $fileName = time() . '_' . $cleanName;

    $targetFilePath = $targetDir . $fileName;
    $fileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));

    $allowTypes = array('jpg', 'png', 'jpeg', 'gif', 'webp');
    if (in_array($fileType, $allowTypes)) {
        if (move_uploaded_file($file["tmp_name"], $targetFilePath)) {
            return $fileName;
        } else {
            error_log("Failed to move file.");
        }
    }
    return false;
}

// Helper to fetch the updated row to send back to JS
function getNewsItem($conn, $id)
{
    $stmt = $conn->prepare("SELECT * FROM hotel_news WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

$action = $_POST['action'] ?? '';

try {
    if ($action === 'add') {
        $title = $_POST['title'];
        $date = $_POST['news_date'];
        $desc = $_POST['description'];
        $imagePath = '';

        if (!empty($_FILES["image"]["name"])) {
            $uploaded = handleFileUpload($_FILES["image"], $targetDir);
            if ($uploaded)
                $imagePath = $uploaded;
        }

        $stmt = $conn->prepare("INSERT INTO hotel_news (title, news_date, description, image_path) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $title, $date, $desc, $imagePath);

        if ($stmt->execute()) {
            // 🟢 SEAMLESS UPDATE: Fetch the newly created item
            $newId = $conn->insert_id;
            $newItem = getNewsItem($conn, $newId);

            echo json_encode([
                'status' => 'success',
                'message' => 'News added successfully!',
                'data' => $newItem // Send data back to JS
            ]);
        } else {
            throw new Exception("Database error: " . $stmt->error);
        }

    } elseif ($action === 'edit') {
        $id = $_POST['news_id'];
        $title = $_POST['title'];
        $date = $_POST['news_date'];
        $desc = $_POST['description'];

        // Check if new image uploaded
        if (!empty($_FILES["image"]["name"])) {
            $imagePath = handleFileUpload($_FILES["image"], $targetDir);

            if ($imagePath) {
                // Delete old image
                $q = $conn->prepare("SELECT image_path FROM hotel_news WHERE id = ?");
                $q->bind_param("i", $id);
                $q->execute();
                $res = $q->get_result();
                if ($row = $res->fetch_assoc()) {
                    $oldFile = $targetDir . $row['image_path'];
                    if (!empty($row['image_path']) && file_exists($oldFile)) {
                        unlink($oldFile);
                    }
                }
                $q->close();

                // Update with new image
                $stmt = $conn->prepare("UPDATE hotel_news SET title=?, news_date=?, description=?, image_path=? WHERE id=?");
                $stmt->bind_param("ssssi", $title, $date, $desc, $imagePath, $id);
            } else {
                throw new Exception("Image upload failed.");
            }
        } else {
            // Update without changing image
            $stmt = $conn->prepare("UPDATE hotel_news SET title=?, news_date=?, description=? WHERE id=?");
            $stmt->bind_param("sssi", $title, $date, $desc, $id);
        }

        if ($stmt->execute()) {
            // 🟢 SEAMLESS UPDATE: Fetch the updated item
            $updatedItem = getNewsItem($conn, $id);

            echo json_encode([
                'status' => 'success',
                'message' => 'News updated successfully!',
                'data' => $updatedItem // Send data back to JS
            ]);
        } else {
            throw new Exception("Update failed.");
        }

    } elseif ($action === 'delete') {
        $id = $_POST['news_id'];
        $sql = "UPDATE hotel_news SET is_active = 0 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'News archived successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
    } elseif ($action === 'restore') {
        $id = $_POST['news_id'];
        $sql = "UPDATE hotel_news SET is_active = 1 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'News restored successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error.']);
        }
    } // ... inside your if($_SERVER['REQUEST_METHOD'] === 'POST') block ...
    elseif ($action === 'hard_delete') {
        $id = $_POST['news_id'];

        // 1. Get Image Path first so we can delete the file
        $stmt = $conn->prepare("SELECT image_path FROM hotel_news WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($imagePath);
        $stmt->fetch();
        $stmt->close();

        // 2. Delete the Record
        $delStmt = $conn->prepare("DELETE FROM hotel_news WHERE id = ?");
        $delStmt->bind_param("i", $id);

        if ($delStmt->execute()) {
            // 3. Delete File if it exists
            if (!empty($imagePath)) {
                $fullPath = "../../room_includes/uploads/news/" . $imagePath;
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
            }
            echo json_encode(['status' => 'success', 'message' => 'News item permanently deleted.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database deletion failed.']);
        }
        $delStmt->close();
    } else {
        throw new Exception("Invalid Action");
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
<?php
// ADMIN/PHP/manage_rooms.php
session_start();
require 'db_connect.php';

header('Content-Type: application/json');

// Security Check
if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

// 1. Image Upload Helper Function
function handleFileUpload($fileArray, $index)
{
    $baseDir = "../../room_includes/uploads/images/";
    $year = date('Y');
    $month = date('m');
    $targetDir = $baseDir . $year . '/' . $month . '/';

    // Create directory if it doesn't exist
    if (!file_exists($targetDir)) {
        if (!mkdir($targetDir, 0777, true))
            return false;
    }

    $originalName = basename($fileArray["name"][$index]);
    if (empty($originalName))
        return false;

    $cleanName = preg_replace("/[^a-zA-Z0-9\.]/", "_", $originalName);
    // Add unique timestamp to prevent overwriting
    $uniqueName = 'room_' . time() . '_' . $index . '_' . rand(100, 999) . '_' . $cleanName;

    $targetFilePath = $targetDir . $uniqueName;
    $fileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));
    $allowTypes = ['jpg', 'png', 'jpeg', 'gif', 'webp'];

    if (in_array($fileType, $allowTypes)) {
        if (move_uploaded_file($fileArray["tmp_name"][$index], $targetFilePath)) {
            // Return only the relative path stored in DB (e.g. "2025/01/image.jpg")
            return $year . '/' . $month . '/' . $uniqueName;
        }
    }
    return false;
}

// 2. Sync Helper: Updates the CSV string in the main 'rooms' table
function syncRoomImagesString($conn, $roomId)
{
    $stmt = $conn->prepare("SELECT image_path FROM room_images WHERE room_id = ? ORDER BY id ASC");
    $stmt->bind_param("i", $roomId);
    $stmt->execute();
    $result = $stmt->get_result();

    $paths = [];
    while ($row = $result->fetch_assoc()) {
        $paths[] = $row['image_path'];
    }

    $csv = implode(',', $paths);

    $updateStmt = $conn->prepare("UPDATE rooms SET image_path = ? WHERE id = ?");
    $updateStmt->bind_param("si", $csv, $roomId);
    $updateStmt->execute();
}

$action = $_POST['action'] ?? '';
$response = [];

try {

    // --- ADD ROOM ---
    if ($action === 'add') {
        $name = trim($_POST['room_name']);
        $price = (float) $_POST['price'];
        $capacity = (int) $_POST['capacity'];
        $size = $_POST['size'];
        $bed_type = $_POST['bed_type'];
        $description = $_POST['description'];
        $amenities = isset($_POST['amenities']) ? implode(',', $_POST['amenities']) : '';

        // Check Duplicate
        $checkStmt = $conn->prepare("SELECT id FROM rooms WHERE name = ?");
        $checkStmt->bind_param("s", $name);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            throw new Exception('Room name already exists!');
        }

        $type = $bed_type;
        $stmt = $conn->prepare("INSERT INTO rooms (name, type, price, capacity, size, bed_type, description, amenities, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("ssdissss", $name, $type, $price, $capacity, $size, $bed_type, $description, $amenities);

        if ($stmt->execute()) {
            $roomId = $conn->insert_id;
            $response = ['status' => 'success', 'message' => 'Room added successfully', 'new_id' => $roomId];

            // Upload Images
            if (isset($_FILES['images'])) {
                for ($i = 0; $i < 4; $i++) {
                    if (!empty($_FILES['images']['name'][$i])) {
                        $path = handleFileUpload($_FILES['images'], $i);
                        if ($path) {
                            $isPrimary = ($i === 0) ? 1 : 0;
                            $conn->query("INSERT INTO room_images (room_id, image_path, is_primary) VALUES ($roomId, '$path', $isPrimary)");
                        }
                    }
                }
                syncRoomImagesString($conn, $roomId);
            }
        } else {
            throw new Exception("DB Error: " . $stmt->error);
        }
    }

    // --- EDIT ROOM (UPDATED WITH IMAGE DELETE) ---
    elseif ($action === 'edit') {
        $id = (int) $_POST['room_id'];
        $name = trim($_POST['room_name']);
        $price = (float) $_POST['price'];
        $capacity = (int) $_POST['capacity'];
        $size = $_POST['size'];
        $bed_type = $_POST['bed_type'];
        $description = $_POST['description'];
        $amenities = isset($_POST['amenities']) ? implode(',', $_POST['amenities']) : '';

        // 1. Check Duplicate Name
        $checkStmt = $conn->prepare("SELECT id FROM rooms WHERE name = ? AND id != ?");
        $checkStmt->bind_param("si", $name, $id);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            throw new Exception('Room name already taken!');
        }

        // 2. Lock Logic Check (If booked, preserve physical details)
        $checkSql = "SELECT COUNT(*) as total FROM booking_rooms br
             JOIN bookings b ON br.booking_id = b.id
             WHERE br.room_id = ? 
             AND b.status IN ('confirmed', 'pending') 
             AND b.arrival_status != 'checked_out'
             AND b.check_out > CURDATE()";

        $stmtCheck = $conn->prepare($checkSql);
        $stmtCheck->bind_param("i", $id);
        $stmtCheck->execute();
        $isBooked = ($stmtCheck->get_result()->fetch_assoc()['total'] > 0);

        if ($isBooked) {
            $stmtGet = $conn->prepare("SELECT name, capacity, size, bed_type, description FROM rooms WHERE id = ?");
            $stmtGet->bind_param("i", $id);
            $stmtGet->execute();
            $current = $stmtGet->get_result()->fetch_assoc();

            $name = $current['name'];
            $capacity = $current['capacity'];
            $size = $current['size'];
            $bed_type = $current['bed_type'];
            $description = $current['description'];
        }

        // 3. Update Text Details
        // 🟢 SYNC BOTH 'type' AND 'bed_type' columns
        $stmt = $conn->prepare("UPDATE rooms SET name=?, price=?, capacity=?, size=?, type=?, bed_type=?, description=?, amenities=? WHERE id=?");
        $stmt->bind_param("sdisssssi", $name, $price, $capacity, $size, $bed_type, $bed_type, $description, $amenities, $id);

        if ($stmt->execute()) {
            $response = ['status' => 'success', 'message' => 'Room updated successfully'];

            // 4. SMART IMAGE UPDATE
            if (isset($_FILES['images'])) {

                // Get IDs of existing image slots (1, 2, 3, 4)
                $stmtGetImages = $conn->prepare("SELECT id FROM room_images WHERE room_id = ? ORDER BY id ASC");
                $stmtGetImages->bind_param("i", $id);
                $stmtGetImages->execute();
                $resImages = $stmtGetImages->get_result();

                $existingImageRows = [];
                while ($row = $resImages->fetch_assoc()) {
                    $existingImageRows[] = $row['id'];
                }

                // Loop through input slots
                for ($i = 0; $i < 4; $i++) {
                    if (!empty($_FILES['images']['name'][$i])) {

                        $path = handleFileUpload($_FILES['images'], $i);

                        if ($path) {
                            if (isset($existingImageRows[$i])) {
                                // --- UPDATE EXISTING SLOT (WITH DELETE LOGIC) ---
                                $rowIdToUpdate = $existingImageRows[$i];

                                // A. Get Old Path
                                $stmtGetOld = $conn->prepare("SELECT image_path FROM room_images WHERE id = ?");
                                $stmtGetOld->bind_param("i", $rowIdToUpdate);
                                $stmtGetOld->execute();
                                $resOld = $stmtGetOld->get_result();

                                if ($oldRow = $resOld->fetch_assoc()) {
                                    $oldFile = "../../room_includes/uploads/images/" . $oldRow['image_path'];
                                    // B. Delete Old File
                                    if (!empty($oldRow['image_path']) && file_exists($oldFile)) {
                                        unlink($oldFile);
                                    }
                                }
                                $stmtGetOld->close();

                                // C. Update DB with New Path
                                $stmtUpdateImg = $conn->prepare("UPDATE room_images SET image_path = ? WHERE id = ?");
                                $stmtUpdateImg->bind_param("si", $path, $rowIdToUpdate);
                                $stmtUpdateImg->execute();

                            } else {
                                // --- INSERT NEW SLOT ---
                                $isPrimary = ($i === 0) ? 1 : 0;
                                $stmtInsertImg = $conn->prepare("INSERT INTO room_images (room_id, image_path, is_primary) VALUES (?, ?, ?)");
                                $stmtInsertImg->bind_param("isi", $id, $path, $isPrimary);
                                $stmtInsertImg->execute();
                            }
                        }
                    }
                }
            }

            // 5. Sync final string
            syncRoomImagesString($conn, $id);
        } else {
            throw new Exception("Update Error: " . $stmt->error);
        }
    }
    
    // --- DELETE (SOFT DELETE) ---
    elseif ($action === 'delete') {
        $id = (int) $_POST['room_id'];
        $conn->query("UPDATE rooms SET is_active = 0 WHERE id = $id");
        $response = ['status' => 'success', 'message' => 'Room hidden'];
    }

    // --- RESTORE ---
    elseif ($action === 'restore') {
        $id = (int) $_POST['room_id'];
        $conn->query("UPDATE rooms SET is_active = 1 WHERE id = $id");
        $response = ['status' => 'success', 'message' => 'Room restored'];
    }

    // --- HARD DELETE (WITH IMAGE FILE DELETION) ---
    elseif ($action === 'hard_delete') {
        $id = $_POST['room_id'];

        // 1. Fetch the image path string to delete files
        $stmt = $conn->prepare("SELECT image_path FROM rooms WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($imagePathString);
        $stmt->fetch();
        $stmt->close();

        // 2. Delete the Record from Database (Clean up room_images first)
        $conn->query("DELETE FROM room_images WHERE room_id = $id");

        $delStmt = $conn->prepare("DELETE FROM rooms WHERE id = ?");
        $delStmt->bind_param("i", $id);

        if ($delStmt->execute()) {
            // 3. Delete Images from Server
            if (!empty($imagePathString)) {
                // Split string into array (e.g. "img1.jpg,img2.jpg")
                $images = explode(',', $imagePathString);

                foreach ($images as $img) {
                    $img = trim($img);
                    if (!empty($img)) {
                        $fullPath = "../../room_includes/uploads/images/" . $img;
                        if (file_exists($fullPath)) {
                            unlink($fullPath); // Delete file
                        }
                    }
                }
            }
            $response = ['status' => 'success', 'message' => 'Room and images deleted permanently.'];
        } else {
            // Usually fails if there are bookings attached to this room
            $response = ['status' => 'error', 'message' => 'Cannot delete: This room has existing booking records.'];
        }
        $delStmt->close();
    } else {
        throw new Exception("Invalid Action");
    }

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
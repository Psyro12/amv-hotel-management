<?php
session_start();
require 'db_connect.php';

$action = $_REQUEST['action'] ?? '';

// --- ACTIONS THAT CAN BE CALLED VIA GET (FETCHING) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // --- 3. FETCH ROOMS ---
    if ($action === 'fetch_rooms') {
        $result = $conn->query("SELECT * FROM room_details.room_image_details ORDER BY room_id DESC");
        $rooms = [];
        while($row = $result->fetch_assoc()) {
            $rooms[] = $row;
        }
        echo json_encode(['status' => 'success', 'data' => $rooms]);
        exit;
    }

    // --- 5. FETCH EVENTS ---
    if ($action === 'fetch_events') {
        $check = $conn->query("SHOW TABLES LIKE 'hotel_events'");
        if($check->num_rows == 0) {
             echo json_encode(['status' => 'success', 'data' => []]); 
             exit;
        }
        $result = $conn->query("SELECT * FROM hotel_events ORDER BY event_date DESC");
        $events = [];
        while($row = $result->fetch_assoc()) {
            $events[] = $row;
        }
        echo json_encode(['status' => 'success', 'data' => $events]);
        exit;
    }

    // --- 8. FETCH ABOUT US CONTENT ---
    if ($action === 'fetch_about_us') {
        $result = $conn->query("SELECT section_key, content_text FROM about_us_content");
        if ($result) {
            $data = [];
            while($row = $result->fetch_assoc()) {
                $data[$row['section_key']] = $row['content_text'];
            }
            echo json_encode(['status' => 'success', 'data' => $data]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
        }
        exit;
    }
}

// --- ACTIONS THAT REQUIRE POST AND CSRF CHECK (SAVING/DELETING) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(json_encode(['status' => 'error', 'message' => 'CSRF Token Mismatch']));
    }

    // --- 1. SAVE ROOM ---
    if ($action === 'save_room') {
        $id = $_POST['room_id'] ?? '';
        $name = $_POST['room_name'];
        $price = $_POST['room_price'];
        $capacity = $_POST['room_capacity'];
        $desc = $_POST['room_desc'];
        
        $imagePath = ''; 
        if(isset($_FILES['room_image']) && $_FILES['room_image']['error'] == 0) {
            $targetDir = "../../IMG/rooms/";
            if (!file_exists($targetDir)) mkdir($targetDir, 0777, true);
            $fileName = time() . "_" . basename($_FILES["room_image"]["name"]);
            $targetFile = $targetDir . $fileName;
            if(move_uploaded_file($_FILES["room_image"]["tmp_name"], $targetFile)) {
                $imagePath = $targetFile;
            }
        }

        if ($id) {
            $sql = "UPDATE room_details.room_image_details SET image_name=?, price=?, capacity=?, description=?";
            if ($imagePath) $sql .= ", image_path='$imagePath'";
            $sql .= " WHERE room_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sdisi", $name, $price, $capacity, $desc, $id);
        } else {
            $sql = "INSERT INTO room_details.room_image_details (image_name, price, capacity, description, image_path) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sdiss", $name, $price, $capacity, $desc, $imagePath);
        }

        if ($stmt->execute()) echo json_encode(['status' => 'success', 'message' => 'Room saved successfully']);
        else echo json_encode(['status' => 'error', 'message' => $conn->error]);
        exit;
    }

    // --- 2. DELETE ROOM ---
    if ($action === 'delete_room') {
        $id = $_POST['id'];
        $conn->query("DELETE FROM room_details.room_image_details WHERE room_id = $id");
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    // --- 4. SAVE EVENT ---
    if ($action === 'save_event') {
         $id = $_POST['event_id'] ?? '';
         $title = $_POST['event_title'];
         $date = $_POST['event_date'];
         $time = $_POST['event_time'];
         $loc = $_POST['event_location'];
         $desc = $_POST['event_desc'];

         if ($id) {
             $stmt = $conn->prepare("UPDATE hotel_events SET title=?, event_date=?, event_time=?, location=?, description=? WHERE id=?");
             $stmt->bind_param("sssssi", $title, $date, $time, $loc, $desc, $id);
         } else {
             $stmt = $conn->prepare("INSERT INTO hotel_events (title, event_date, event_time, location, description) VALUES (?, ?, ?, ?, ?)");
             $stmt->bind_param("sssss", $title, $date, $time, $loc, $desc);
         }
         
         if ($stmt->execute()) echo json_encode(['status' => 'success']);
         else echo json_encode(['status' => 'error', 'message' => $conn->error]);
         exit;
    }
    
    // --- 6. DELETE EVENT ---
    if ($action === 'delete_event') {
        $id = $_POST['id'];
        $conn->query("DELETE FROM hotel_events WHERE id = $id");
        echo json_encode(['status' => 'success']);
        exit;
    }

    // --- 7. SAVE ABOUT US CONTENT ---
    if ($action === 'save_about_us') {
        $contents = $_POST['contents'] ?? []; 
        $success = true;
        $upload_dir = "../../room_includes/uploads/about/";
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $files = ['sig_relax_img_file' => 'sig_relax_img', 'sig_dining_img_file' => 'sig_dining_img'];
        foreach ($files as $file_key => $db_key) {
            if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES[$file_key]['name'], PATHINFO_EXTENSION);
                $new_name = $db_key . "_" . time() . "." . $ext;
                if (move_uploaded_file($_FILES[$file_key]['tmp_name'], $upload_dir . $new_name)) {
                    $contents[$db_key] = $new_name;
                }
            }
        }

        foreach ($contents as $key => $value) {
            $stmt = $conn->prepare("UPDATE about_us_content SET content_text = ? WHERE section_key = ?");
            $stmt->bind_param("ss", $value, $key);
            if (!$stmt->execute()) $success = false;
            $stmt->close();
        }

        if ($success) echo json_encode(['status' => 'success', 'message' => 'About Us content updated!']);
        else echo json_encode(['status' => 'error', 'message' => 'Some fields failed to update.']);
        exit;
    }
}
?>
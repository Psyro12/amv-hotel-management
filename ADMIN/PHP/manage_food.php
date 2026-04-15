<?php
// ADMIN/PHP/manage_food.php
header('Content-Type: application/json');
require 'db_connect.php';

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';
$foodId = $_POST['food_id'] ?? '';

// Variables for Add/Edit
$itemName = $_POST['item_name'] ?? '';
$category = $_POST['category'] ?? '';
$price = $_POST['price'] ?? 0;

// --- IMAGE UPLOAD LOGIC ---
$imagePath = null;
if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = '../../room_includes/uploads/food/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $fileExt = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    $newFileName = time() . '_' . uniqid() . '.' . $fileExt;
    $targetFile = $uploadDir . $newFileName;

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (in_array($fileExt, $allowed)) {
        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
            $imagePath = $newFileName;
        }
    }
}

// 1. ADD ITEM
if ($action === 'add') {
    // Explicitly set is_active to 1
    $sql = "INSERT INTO food_menu (item_name, category, price, image_path, is_active, is_available) VALUES (?, ?, ?, ?, 1, 1)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssds", $itemName, $category, $price, $imagePath);
    
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Menu item added successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $conn->error]);
    }
    $stmt->close();
} 

// 2. EDIT ITEM
elseif ($action === 'edit') {
    // Fetch old image if no new one provided
    $currentImage = "";
    if (!$imagePath) {
        $q = $conn->query("SELECT image_path FROM food_menu WHERE id = '$foodId'");
        if ($q && $r = $q->fetch_assoc()) {
            $currentImage = $r['image_path'];
        }
    } else {
        $currentImage = $imagePath;
    }

    if ($imagePath) {
        $sql = "UPDATE food_menu SET item_name=?, category=?, price=?, image_path=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssdsi", $itemName, $category, $price, $imagePath, $foodId);
    } else {
        $sql = "UPDATE food_menu SET item_name=?, category=?, price=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssdi", $itemName, $category, $price, $foodId);
    }

    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Menu item updated.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error']);
    }
    $stmt->close();
} 

// 3. SOFT DELETE (Archive)
elseif ($action === 'delete') {
    // Instead of deleting row, set is_active = 0
    $sql = "UPDATE food_menu SET is_active = 0 WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $foodId);
    
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Item archived.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to archive item.']);
    }
    $stmt->close();
}

// 4. RESTORE (Un-Archive)
elseif ($action === 'restore') {
    // Set is_active = 1
    $sql = "UPDATE food_menu SET is_active = 1 WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $foodId);
    
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Item restored to active menu.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to restore item.']);
    }
    $stmt->close();
}

// 5. PERMANENT DELETE (Hard Delete)
elseif ($action === 'hard_delete') {
    // First, get image path to delete the actual file
    $imgSql = $conn->query("SELECT image_path FROM food_menu WHERE id = '$foodId'");
    if ($imgRow = $imgSql->fetch_assoc()) {
        $file = "../../room_includes/uploads/food/" . $imgRow['image_path'];
        if (!empty($imgRow['image_path']) && file_exists($file)) {
            unlink($file); // Delete physical file
        }
    }

    $sql = "DELETE FROM food_menu WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $foodId);
    
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Item permanently deleted.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to delete item.']);
    }
    $stmt->close();
}

// 6. TOGGLE STOCK (Availability)
elseif ($action === 'toggle_stock') {
    $status = $_POST['status']; // 1 or 0
    
    $sql = "UPDATE food_menu SET is_available = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $status, $foodId);
    
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Stock status updated.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to update stock.']);
    }
    $stmt->close();
}

$conn->close();
?>
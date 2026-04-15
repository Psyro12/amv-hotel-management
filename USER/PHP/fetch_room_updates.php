<?php
// 1. Headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// 2. Database Connection
// Ensure these credentials match exactly what you use in db_connect.php
$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'amv_db'; // Ensure this matches your dashboard database name

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// 3. Simple SQL Query (Matches dashboard.php logic)
// We use aliases (AS) to make your 'rooms' table columns match what your home_page.js expects
$sql = "
    SELECT 
        name AS image_name, 
        description, 
        image_path AS file_path 
    FROM 
        rooms 
    WHERE 
        is_active = 1
";

try {
    $stmt = $pdo->query($sql);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Image Logic (The same logic we added to dashboard.php)
    // If there are multiple images (comma separated), take only the first one.
    foreach ($rooms as &$room) {
        $rawPath = $room['file_path'];

        if (strpos($rawPath, ',') !== false) {
            $parts = explode(',', $rawPath);
            $room['file_path'] = trim($parts[0]);
        }
    }
    unset($room); 

    // Return clean JSON
    echo json_encode($rooms);

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Query error: ' . $e->getMessage()]);
}
?>
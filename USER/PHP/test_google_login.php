session_start();

// Prefer canonical lookup by 'image_name', falling back to 'room' or 'room_name' URL params.
// Use the same DB connector used by other pages.
require __DIR__ . '/../DB-CONNECTIONS/db_connect_2.php'; // provides $conn (mysqli)
require __DIR__ . '/../DB-CONNECTIONS/db_connect_3.php'; // provides $pdo (PDO)

$amenities = get_all_amenities($pdo);

// Check if any error occurred during fetching
$error_message = '';
if ($amenities === false) {
    $error_message = "Could not load amenities. Please check the server logs for database query errors.";
}

// Helper escape
function h($s)
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// --- REVISED LOGIC ---
$roomParam = isset($_GET['room']) ? trim($_GET['room']) : (isset($_GET['room_name']) ? trim($_GET['room_name']) : '');

$room = null;

if ($roomParam !== '') {
    // Query the database using the unique image_name (room name)
    $stmt = mysqli_prepare($conn, "SELECT image_name, file_path, description FROM room_image_details WHERE image_name = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $roomParam);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res)
            $room = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
    }
}

// If not found, show a minimal 404-like page and exit
if (!$room) {
    http_response_code(404);
    
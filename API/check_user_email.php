
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'connection.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$response = array();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $uid = $_POST['uid'] ?? '';

    if (empty($uid)) {
        echo json_encode(['success' => false, 'message' => 'UID is required']);
        exit();
    }

    // Query the database for this user's email
    $sql = "SELECT email FROM users WHERE firebase_uid = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit();
    }

    $stmt->bind_param("s", $uid);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $email = $user['email'];

        // Check if email exists and is not empty
        if (!empty($email)) {
            $response['success'] = true;
            $response['has_email'] = true;
            $response['email'] = $email;
        } else {
            $response['success'] = true;
            $response['has_email'] = false;
            $response['message'] = 'User exists but has no email';
        }
    } else {
        $response['success'] = true;
        $response['has_email'] = false;
        $response['message'] = 'User not found in database';
    }

    $stmt->close();

} else {
    $response['success'] = false;
    $response['message'] = 'Invalid Request Method';
}

echo json_encode($response);
?>

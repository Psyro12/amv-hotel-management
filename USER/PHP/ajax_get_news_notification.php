<?php
// USER/PHP/ajax_get_news_notification.php
require 'db_connect.php';
header('Content-Type: application/json');

// Check for all news items posted within the last 3 days
$sql = "SELECT id, title FROM hotel_news 
        WHERE is_active = 1 
        AND news_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY) 
        ORDER BY news_date DESC, created_at DESC";

$result = mysqli_query($conn, $sql);

if ($result && mysqli_num_rows($result) > 0) {
    $news = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $news[] = $row;
    }
    
    // Return the latest one but also include the total count
    echo json_encode([
        'status' => 'success',
        'id' => $news[0]['id'],
        'title' => $news[0]['title'],
        'total_new' => count($news)
    ]);
} else {
    echo json_encode(['status' => 'none']);
}
?>
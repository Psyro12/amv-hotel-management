<?php
// get_events.php
require 'db_connect.php';
header('Content-Type: application/json');

$sql = "SELECT id, title, event_date, description, image_path FROM hotel_events ORDER BY event_date ASC";
$result = $conn->query($sql);

$events = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        // Format the date for the frontend
        $row['event_date'] = date('F j, Y g:i A', strtotime($row['event_date']));
        $events[] = $row;
    }
}

echo json_encode($events);
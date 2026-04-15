<?php
// ADMIN/PHP/auto_update_status.php
require 'db_connect.php';
date_default_timezone_set('Asia/Manila');

$response = ['status' => 'success', 'updates' => 0];

// =======================================================================
// 1. Mark OLD bookings as No-Show (Yesterday or older) - KEEP THIS
// =======================================================================
$conn->query("UPDATE bookings 
              SET status = 'cancelled', 
                  arrival_status = 'no_show' 
              WHERE status = 'confirmed' 
              AND (arrival_status IS NULL OR arrival_status = '' OR arrival_status = 'awaiting_arrival')
              AND check_in < CURDATE()");

// =======================================================================
// 2. Mark TODAY'S late arrivals - REMOVE THIS BLOCK!
// =======================================================================
/* We commented this out because it was automatically cancelling 
   new walk-ins made after 8 PM. Now, the Admin must manually mark 
   guests as "No-Show" using the button in the dashboard.
*/

/*
if ((int) date('H') >= 20) {
    $conn->query("UPDATE bookings 
                  SET status = 'cancelled', 
                      arrival_status = 'no_show' 
                  WHERE status = 'confirmed' 
                  AND (arrival_status IS NULL OR arrival_status = '' OR arrival_status = 'awaiting_arrival')
                  AND check_in = CURDATE()");
    
    $response['updates'] = $conn->affected_rows;
}
*/

// =======================================================================
// 3. Auto-Checkout (1:00 PM) - KEEP THIS
// =======================================================================
// if ((int) date('H') >= 13) {
//     $conn->query("UPDATE bookings 
//                   SET arrival_status = 'checked_out' 
//                   WHERE arrival_status = 'in_house' 
//                   AND check_out = CURDATE()");
// }

echo json_encode($response);
?>
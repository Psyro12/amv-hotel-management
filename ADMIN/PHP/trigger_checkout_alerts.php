<?php
require 'db_connect.php';
date_default_timezone_set('Asia/Manila');

// 1. Time Check: Only run if it is 12:00 PM (12:00) or later
if ((int) date('H') < 12) {
    echo json_encode(['status' => 'skipped', 'message' => 'Too early (Before 12NN)']);
    exit;
}

// 2. Find guests who are "In House" and due Today (or Overdue)
$sql_due = "SELECT b.id, b.booking_reference, bg.first_name, bg.last_name, bg.email, u.account_source,
            GROUP_CONCAT(br.room_name SEPARATOR ', ') as room_names
            FROM bookings b
            JOIN booking_guests bg ON b.id = bg.booking_id
            LEFT JOIN users u ON bg.email = u.email
            JOIN booking_rooms br ON b.id = br.booking_id
            WHERE b.arrival_status = 'in_house' 
            AND b.check_out <= CURDATE() 
            GROUP BY b.id";

$res_due = $conn->query($sql_due);
$notifications_sent = 0;

if ($res_due) {
    while ($row = $res_due->fetch_assoc()) {
        $guestName = $row['first_name'] . ' ' . $row['last_name'];
        $rooms = $row['room_names'];
        $ref = $row['booking_reference'];
        $email = $row['email'];
        $source = $row['account_source'] ?? 'google';

        $notifTitle = "Checkout Due";
        $notifDesc = "Ref: $ref - Standard checkout time (12:00 PM) has passed for $guestName ($rooms).";
        $notifType = "reminder";

        // 3. DUPLICATE CHECK (Crucial for Auto-Triggers)
        // We check if we already alerted about this specific description TODAY
        $check_sql = "SELECT id FROM system_notifications 
                      WHERE description = ? 
                      AND type = ? 
                      AND DATE(created_at) = CURDATE()";

        $stmt_check = $conn->prepare($check_sql);
        $stmt_check->bind_param("ss", $notifDesc, $notifType);
        $stmt_check->execute();
        $stmt_check->store_result();

        // 4. Send Notification if NOT sent yet
        if ($stmt_check->num_rows == 0) {
            // A. Admin Notification
            $stmt_ins = $conn->prepare("INSERT INTO system_notifications (title, description, type, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
            $stmt_ins->bind_param("sss", $notifTitle, $notifDesc, $notifType);
            $stmt_ins->execute();
            $stmt_ins->close();

            // 🟢 NEW: Use centralized notification helper
            require_once 'notification_helper.php';

            // B. Guest App Notification
            if (!empty($email)) {
                $guestTitle = "Checkout Time Passed";
                $guestMsg = "Standard checkout time is 12:00 PM. Please visit the front desk to check out or extend your stay.";

                sendAppNotification($conn, $email, $source, $guestTitle, $guestMsg, 'booking');
            }
            $notifications_sent++;
        }
        $stmt_check->close();
    }
}

echo json_encode(['status' => 'success', 'sent' => $notifications_sent]);
?>
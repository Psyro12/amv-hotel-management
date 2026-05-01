<?php
// FILE: ADMIN/PHP/fetch_cancellation_requests_table.php

// 🟢 SECURITY: Disable error display to prevent leakage
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
require 'db_connect.php'; 
date_default_timezone_set('Asia/Manila');

// 🟢 SECURITY: Ensure proper JSON header
header('Content-Type: application/json');

try {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $status_filter = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : 'pending';

    // Check if table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'cancellation_requests'");
    if ($tableCheck->num_rows == 0) {
        echo json_encode(['success' => true, 'html' => '<div style="padding:40px; text-align:center; color:#999;">Database table not found. Please run the setup script.</div>']);
        exit;
    }

    $where = " WHERE cr.status = '$status_filter' ";

    // Fetch Data with correct column names (room_names is missing in bookings, so we'll use a subquery or join)
    $sql = "SELECT cr.*, b.booking_reference, b.booking_source,
                   bg.first_name, bg.last_name, bg.email,
                   b.check_in, b.check_out, b.total_price
            FROM cancellation_requests cr
            JOIN bookings b ON cr.booking_id = b.id
            LEFT JOIN booking_guests bg ON b.id = bg.booking_id
            $where
            ORDER BY cr.created_at DESC
            LIMIT $limit OFFSET $offset";

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception($conn->error);
    }

    $html = '';

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $guestName = $row['first_name'] . ' ' . $row['last_name'];
            $created_at = date('M d, Y h:i A', strtotime($row['created_at']));
            $check_in = date('M d', strtotime($row['check_in']));
            $check_out = date('M d', strtotime($row['check_out']));
            $ref = $row['booking_reference'];
            $id = $row['id'];
            $reason = htmlspecialchars($row['reason']);
            $total = number_format($row['total_price'], 2);
            
            $sourceIcon = ($row['booking_source'] === 'mobile_app') ? '<i class="fas fa-mobile-alt"></i>' : '<i class="fas fa-globe"></i>';

            $html .= "
            <div class='pending-card' id='cancel-card-$id'>
                <div style='display:flex; justify-content:space-between; margin-bottom:8px;'>
                    <strong style='color:#B88E2F; font-size:1rem;'>$ref</strong>
                    <span style='font-size:0.8rem; color:#888;'>$created_at</span>
                </div>
                
                <div style='font-size:0.95rem; font-weight:600; color:#333; margin-bottom:5px;'>
                    $guestName
                </div>
                
                <div style='display:flex; gap:10px; font-size:0.85rem; color:#666; margin-bottom:12px;'>
                    <span>$sourceIcon {$row['booking_source']}</span>
                    <span><i class='fas fa-calendar-alt'></i> $check_in - $check_out</span>
                </div>

                <div style='background:#fef9ef; border-left:4px solid #B88E2F; padding:12px; border-radius:6px; margin-bottom:15px;'>
                    <div style='font-size:0.75rem; text-transform:uppercase; color:#B88E2F; font-weight:700; margin-bottom:4px;'>Guest Reason:</div>
                    <div style='font-size:0.9rem; color:#555; line-height:1.4;'>\"$reason\"</div>
                </div>

                <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;'>
                    <span style='font-size:0.85rem; color:#888;'>Total Refundable:</span>
                    <strong style='font-size:1.1rem; color:#333;'>₱$total</strong>
                </div>

                <div class='drawer-actions'>
                    <button class='ab-submit-btn' style='background:#dc3545; padding:10px;' onclick='viewCancellationRequest($id)'>
                        Manage Request
                    </button>
                </div>
            </div>";
        }
    } else {
        $html = '
        <div style="text-align:center; padding:60px 20px; color:#9ca3af;">
            <i class="fas fa-check-circle" style="font-size:3.5rem; margin-bottom:15px; color:#D1D5DB;"></i>
            <p style="font-size:1.1rem; font-weight:500;">No pending cancellation requests.</p>
            <p style="font-size:0.9rem;">Everything is up to date!</p>
        </div>';
    }

    echo json_encode(['success' => true, 'html' => $html]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
?>
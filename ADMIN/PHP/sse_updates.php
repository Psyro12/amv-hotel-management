<?php
// ADMIN/PHP/sse_updates.php
session_start();

// 🟢 DISABLE ALL BUFFERING
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
while (ob_get_level()) ob_end_clean();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform'); // 🟢 added no-transform for proxies
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // 🟢 Disable Nginx buffering

require_once 'db_connect.php';

// Auth Check
if (!isset($_SESSION['user'])) {
    echo "event: error\n";
    echo "data: Unauthorized\n\n";
    ob_flush();
    flush();
    exit;
}
session_write_close(); // 🟢 Release session lock to allow concurrent requests

// Keep track of the last seen timestamps for each category
$last_seen = [];

// Initialize last_seen with current state
$result = $conn->query("SELECT category, last_updated FROM system_updates");
while ($row = $result->fetch_assoc()) {
    $last_seen[$row['category']] = $row['last_updated'];
}

// Infinite loop for SSE
while (true) {
    // Check if the connection is still alive
    if (connection_aborted()) break;

    // Check for updates
    $result = $conn->query("SELECT category, last_updated FROM system_updates");
    $updates_found = [];

    while ($row = $result->fetch_assoc()) {
        $cat = $row['category'];
        $ts = $row['last_updated'];

        if (!isset($last_seen[$cat]) || $last_seen[$cat] != $ts) {
            $updates_found[] = $cat;
            $last_seen[$cat] = $ts;
        }
    }

    if (!empty($updates_found)) {
        echo "event: update\n";
        echo "data: " . json_encode($updates_found) . "\n\n";
    } else {
        // Send a heartbeat comment to keep the connection open
        echo ": heartbeat\n\n";
    }

    ob_flush();
    flush();

    // Sleep for 1 second before next check
    sleep(1);
}

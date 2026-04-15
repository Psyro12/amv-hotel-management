<?php
session_start();

// 1. Security Check: Only allow if a valid download token exists in the session
if (!isset($_SESSION['apk_download_allowed']) || $_SESSION['apk_download_allowed'] !== true) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access Denied.");
}

// 2. Clear the token immediately (One-time use)
unset($_SESSION['apk_download_allowed']);

$filePath = 'amv-hotel-app.apk';

if (file_exists($filePath)) {
    // 3. Set headers to hide the real filename and force download
    header('Content-Description: File Transfer');
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="AMV_Hotel_v1.apk"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));
    
    // 4. Stream the file
    readfile($filePath);
    exit;
} else {
    header("HTTP/1.1 404 Not Found");
    exit("File not found.");
}
?>
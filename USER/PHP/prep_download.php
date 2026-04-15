<?php
session_start();
// This is the background authorization step.
// It only runs when the user actually clicks the button on your site.
$_SESSION['apk_intent'] = time(); // Store the time of intent
$_SESSION['apk_download_allowed'] = true;

header('Content-Type: application/json');
echo json_encode(['status' => 'authorized']);
exit;
?>
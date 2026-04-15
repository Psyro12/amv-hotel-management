<?php
session_start();

// 1. SECURITY CHECK: Did they come from our preparation step?
if (!isset($_SESSION['apk_download_allowed']) || $_SESSION['apk_download_allowed'] !== true) {
    // If they just pasted the URL, they won't have this session flag.
    header("HTTP/1.1 403 Forbidden");
    exit("<h2>403 Forbidden: Access Denied.</h2><p>You must initiate the download through the official AMV Hotel website button.</p>");
}

// 2. ADDITIONAL SECURITY: How long ago did they click the button?
// If more than 30 seconds, it's probably not a real click.
if (isset($_SESSION['apk_intent'])) {
    $diff = time() - $_SESSION['apk_intent'];
    if ($diff > 30) {
        unset($_SESSION['apk_download_allowed']);
        unset($_SESSION['apk_intent']);
        header("HTTP/1.1 403 Forbidden");
        exit("Security Error: Link has expired. Please try clicking the download button again.");
    }
}

// 3. DO NOT unset here. 
// We let serve_apk.php do the unsetting so the download actually works.
// unset($_SESSION['apk_download_allowed']); 

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Securing Connection...</title>
    <style>
        body { font-family: 'Montserrat', sans-serif; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100vh; margin: 0; background-color: #f8f5f0; color: #333; }
        .loader { border: 5px solid #f3f3f3; border-top: 5px solid #b8860b; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="loader"></div>
    <h2 style="margin-top:20px;">Starting Secure Download...</h2>
    
    <script>
        // Trigger the actual file streaming script (serve_apk.php)
        // We set a small delay to allow session data to sync if needed
        setTimeout(function() {
            window.location.href = 'serve_apk.php';
            
            // Wait for the browser to register the download stream, then close the tab
            setTimeout(function() {
                window.close();
            }, 3000);
        }, 500);
    </script>
</body>
</html>
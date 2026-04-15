<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Downloading AMV Hotel App...</title>
    <style>
        body {
            font-family: 'Montserrat', sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            background-color: #f8f5f0;
            color: #333;
            text-align: center;
        }
        .loader {
            border: 5px solid #f3f3f3;
            border-top: 5px solid #b8860b;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="loader"></div>
    <h2>Your download is starting...</h2>
    <p>This tab will close automatically once the download starts.</p>

    <script>
        // 1. Trigger the download
        const apkUrl = 'amv-hotel-app.apk';
        const link = document.createElement('a');
        link.href = apkUrl;
        link.download = 'amv-hotel-app.apk';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        // 2. Close the tab
        // We wait a few seconds to ensure the browser has handled the download request
        setTimeout(function() {
            window.close();
            
            // Fallback: If window.close() is blocked (some browsers only allow it if opened via JS)
            // we show a message.
            setTimeout(function() {
                document.querySelector('h2').innerText = "Download Started!";
                document.querySelector('p').innerHTML = "If this tab didn't close, you can <a href='#' onclick='window.close(); return false;'>click here to close it</a> or manually go back to the site.";
            }, 500);
        }, 3000);
    </script>
</body>
</html>
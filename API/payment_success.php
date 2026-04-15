<!DOCTYPE html>
<html>
<head>
    <title>Payment Successful</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: sans-serif; text-align: center; padding: 20px; }
        .loader { border: 5px solid #f3f3f3; border-top: 5px solid #D4AF37; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite; margin: 20px auto; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <br><br>
    <h2>Payment Successful!</h2>
    <p>Redirecting you back to AMV Hotel...</p>
    <div class="loader"></div>
    
    <script>
        // 🟢 THIS IS THE MAGIC: It forces the Android phone to open your app
        setTimeout(function() {
            window.location.href = "amvhotel://success";
        }, 1000); 

        // Backup Manual Button
        setTimeout(function() {
            document.body.innerHTML += '<a href="amvhotel://success" style="padding:10px 20px; background:#D4AF37; color:white; text-decoration:none; border-radius:5px;">Open App Manually</a>';
        }, 3000);
    </script>
</body>
</html>
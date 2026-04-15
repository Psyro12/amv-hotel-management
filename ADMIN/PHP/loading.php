<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authenticating...</title>
    
    <script type="module" src="https://cdn.jsdelivr.net/npm/ldrs/dist/auto/jelly.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            margin: 0;
            padding: 0;
            height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background-color: #f8f9fa; /* Light grey background */
            font-family: 'Montserrat', sans-serif;
            overflow: hidden; /* Prevent scrolling */
        }

        .loader-container {
            text-align: center;
            animation: fadeIn 0.5s ease-in-out;
        }

        h3 {
            margin-top: 30px;
            color: #333;
            font-weight: 600;
            font-size: 1.2rem;
            letter-spacing: 1px;
            animation: pulseText 1.5s infinite ease-in-out;
        }

        p {
            color: #888;
            font-size: 0.9rem;
            margin-top: 5px;
        }

        /* Subtle Fade In */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Text Pulsing Effect */
        @keyframes pulseText {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body>

    <div class="loader-container">
        <l-jelly
            size="60"
            speed="0.9"
            color="#B88E2F" 
        ></l-jelly>

        <h3>AUTHENTICATING</h3>
        <p>Please wait while we access your dashboard...</p>
    </div>

    <script>
        // Wait 2 seconds (2000ms) then redirect to the dashboard
        setTimeout(() => {
            window.location.href = 'dashboard.php?login=success';
        }, 2000);
    </script>

</body>
</html>
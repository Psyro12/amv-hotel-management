<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMV Hotel Loading</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Jost:wght@200;400&display=swap" rel="stylesheet">

    <style>
        /* --- CSS STYLES --- */
        body {
            margin: 0;
            padding: 0;
            font-family: 'Jost', sans-serif; /* Default font */
        }

        /* 1. Loading Screen Container */
        #loading-screen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: #b8860b; /* Gold Background */
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            transition: opacity 0.8s ease-out, visibility 0.8s ease-out;
        }

        #loading-screen.fade-out {
            opacity: 0;
            visibility: hidden;
        }

        /* 2. Text Styling */
        .logo-text-container {
            text-align: center;
            color: #ffffff;
        }

        /* THE MAIN LOGO TEXT */
        .amv-main {
            /* Font Stack: 
               1. Tries your specific "ITC Avant Garde Gothic Extra Light"
               2. Tries "Century Gothic" (Common Windows clone)
               3. Falls back to "Jost" (The Google Font loaded above)
            */
            font-family: 'ITC Avant Garde Gothic Extra Light', 'ITC Avant Garde Gothic', 'Century Gothic', 'Jost', sans-serif;
            font-weight: 200; /* Extra Light */
            font-size: 6rem;  /* Large size to show off the thin lines */
            margin: 0;
            line-height: 0.85; /* Tight line height for geometric look */
            letter-spacing: -2px; /* Tight tracking often seen in Avant Garde logos */
        }

        .hotel-sub {
            font-family: 'Jost', sans-serif;
            font-weight: 400;
            font-size: 1.1rem;
            text-transform: uppercase;
            letter-spacing: 0.8em; /* Very wide spacing for contrast */
            margin-top: 15px;
            padding-left: 0.8em; /* Center alignment correction */
            opacity: 0.9;
        }

        /* 3. Animation Logic */
        .logo-text-container span {
            display: inline-block;
            opacity: 0;
            transform: translateY(40px);
            /* Smooth, luxurious easing */
            animation: softFadeUp 1s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
        }

        @keyframes softFadeUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* 4. Animation Stagger (Delays) */
        /* A M V */
        .amv-main span:nth-child(1) { animation-delay: 0.1s; }
        .amv-main span:nth-child(2) { animation-delay: 0.25s; }
        .amv-main span:nth-child(3) { animation-delay: 0.4s; }

        /* H O T E L */
        .hotel-sub span:nth-child(1) { animation-delay: 0.6s; }
        .hotel-sub span:nth-child(2) { animation-delay: 0.7s; }
        .hotel-sub span:nth-child(3) { animation-delay: 0.8s; }
        .hotel-sub span:nth-child(4) { animation-delay: 0.9s; }
        .hotel-sub span:nth-child(5) { animation-delay: 1.0s; }

    </style>
</head>
<body>

    <div id="loading-screen">
        <div class="logo-text-container">
            <h1 class="amv-main">
                <span>A</span><span>M</span><span>V</span>
            </h1>
            <div class="hotel-sub">
                <span>H</span><span>O</span><span>T</span><span>E</span><span>L</span>
            </div>
        </div>
    </div>

    <div style="padding: 50px; text-align: center;">
        <h1>Welcome to AMV Hotel</h1>
        <p>System Ready.</p>
    </div>

    <script>
        window.addEventListener('load', function() {
            const loadingScreen = document.getElementById('loading-screen');
            // 3 seconds delay to let the animation play out
            setTimeout(() => {
                loadingScreen.classList.add('fade-out');
            }, 3000); 
        });
    </script>

</body>
</html>
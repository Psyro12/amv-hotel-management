<link href="https://fonts.googleapis.com/css2?family=Jost:wght@200;400&display=swap" rel="stylesheet">

<style>
    /* --- 1. LOADER CONTAINER --- */
    #loading-screen {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: #b8860b; /* Gold */
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 99999;
        opacity: 1;
        visibility: visible;
        /* Smooth fade out */
        transition: opacity 0.5s ease-in-out, visibility 0.5s ease-in-out;
    }

    /* Class to hide the loader */
    #loading-screen.loader-hidden {
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
    }

    /* --- 2. LOGO TEXT --- */
    .loader-text-container {
        text-align: center;
        color: #ffffff;
    }

    .amv-main {
        font-family: 'ITC Avant Garde Gothic Extra Light', 'Century Gothic', 'Jost', sans-serif;
        font-weight: 200;
        font-size: 6rem;
        margin: 0;
        line-height: 0.85;
        letter-spacing: -2px;
    }

    .hotel-sub {
        font-family: 'Jost', sans-serif;
        font-weight: 400;
        font-size: 1.1rem;
        text-transform: uppercase;
        letter-spacing: 0.8em;
        margin-top: 15px;
        padding-left: 0.8em;
    }

    /* --- 3. ANIMATION (The "Slide Up") --- */
    .loader-text-container span {
        display: inline-block;
        opacity: 0;
        transform: translateY(40px);
        /* Default Animation */
        animation: softFadeUp 1s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
    }

    @keyframes softFadeUp {
        to { opacity: 1; transform: translateY(0); }
    }

    /* Stagger Delays */
    .amv-main span:nth-child(1) { animation-delay: 0.1s; }
    .amv-main span:nth-child(2) { animation-delay: 0.2s; }
    .amv-main span:nth-child(3) { animation-delay: 0.3s; }
    .hotel-sub span:nth-child(1) { animation-delay: 0.4s; }
    .hotel-sub span:nth-child(2) { animation-delay: 0.5s; }
    .hotel-sub span:nth-child(3) { animation-delay: 0.6s; }
    .hotel-sub span:nth-child(4) { animation-delay: 0.7s; }
    .hotel-sub span:nth-child(5) { animation-delay: 0.8s; }

    /* --- 4. THE FIX: STATIC MODE --- */
    /* This forces the text to be visible instantly without animating */
    body.is-bridging .loader-text-container span {
        animation: none !important;
        opacity: 1 !important;
        transform: translateY(0) !important;
    }

    /* Mobile Sizes */
    @media (max-width: 768px) {
        .amv-main { font-size: 4rem; }
        .hotel-sub { font-size: 0.9rem; }
    }
</style>

<div id="loading-screen">
    <div class="loader-text-container">
        <h1 class="amv-main"><span>A</span><span>M</span><span>V</span></h1>
        <div class="hotel-sub"><span>H</span><span>O</span><span>T</span><span>E</span><span>L</span></div>
    </div>
</div>

<script>
    // 🟢 1. IMMEDIATE CHECK: Run this instantly to stop the "Double Flash"
    // If we came from an internal link, add the class BEFORE the page paints
    if (sessionStorage.getItem('amv_bridge_active') === 'true') {
        document.body.classList.add('is-bridging');
    }

    document.addEventListener("DOMContentLoaded", function() {
        const loader = document.getElementById("loading-screen");
        const isBridging = document.body.classList.contains('is-bridging');

        // 🟢 2. PAGE ENTRY (Fade Out)
        // If bridging, fade out faster (we've already seen the logo).
        // If fresh load, wait longer to show off the animation.
        const waitTime = isBridging ? 500 : 1500; 

        setTimeout(() => {
            loader.classList.add("loader-hidden");
            
            // CLEANUP: Remove the flag so a manual refresh triggers animation again
            sessionStorage.removeItem('amv_bridge_active');
            
            // Remove class after fade out to reset for next time
            setTimeout(() => {
                document.body.classList.remove('is-bridging');
            }, 500);
        }, waitTime);


        // 🟢 3. PAGE EXIT (Link Clicks)
        const links = document.querySelectorAll("a");
        links.forEach(link => {
            link.addEventListener("click", function(e) {
                const targetUrl = this.getAttribute("href");

                // Ignore anchor links or new tabs
                if (!targetUrl || targetUrl.startsWith("#") || targetUrl.startsWith("javascript") || this.target === "_blank") {
                    return;
                }

                e.preventDefault();

                // Set the flag: "We are bridging to another page"
                sessionStorage.setItem('amv_bridge_active', 'true');

                // Ensure animation is ALLOWED to play now (remove static class)
                document.body.classList.remove('is-bridging');
                
                // Force a browser reflow to restart CSS animations
                const textSpans = loader.querySelectorAll('span');
                textSpans.forEach(span => {
                    span.style.animation = 'none';
                    span.offsetHeight; /* trigger reflow */
                    span.style.animation = ''; 
                });

                // Show Loader
                loader.classList.remove("loader-hidden");

                // Wait 1s for animation, then go
                setTimeout(() => {
                    window.location.href = targetUrl;
                }, 1000);
            });
        });

        // 🟢 4. BROWSER BACK BUTTON FIX
        window.addEventListener("pageshow", function(event) {
            if (event.persisted) {
                loader.classList.add("loader-hidden");
            }
        });
    });
</script>
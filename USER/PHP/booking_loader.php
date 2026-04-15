<style>
    /* --- STANDARDIZED RESPONSIVE BOOKING LOADER --- */
    #booking-processing {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: #b8860b; /* AMV Gold Standard */
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        z-index: 200000; /* Extremely High */
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.4s ease, visibility 0.4s ease;
        padding: 20px;
        box-sizing: border-box;
    }

    #booking-processing.active {
        opacity: 1;
        visibility: visible;
    }

    /* SPINNER */
    .booking-spinner {
        width: 60px;
        height: 60px;
        border: 4px solid rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        border-top-color: #ffffff;
        animation: spin 1s linear infinite;
        margin-bottom: 25px;
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    /* TEXT STYLES */
    .booking-text {
        text-align: center;
        color: #ffffff !important;
        font-family: 'Montserrat', sans-serif;
        max-width: 100%;
    }

    .booking-text h2 {
        font-size: 1.8rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 2px;
        margin: 0 0 12px 0;
        color: #ffffff !important;
        line-height: 1.2;
    }

    .booking-text p {
        font-size: 1rem;
        opacity: 0.9;
        margin: 0;
        color: #ffffff !important;
        font-weight: 400;
        letter-spacing: 0.5px;
    }

    /* 📱 RESPONSIVE (Cellphones) */
    @media (max-width: 600px) {
        .booking-spinner {
            width: 50px;
            height: 50px;
            border-width: 3px;
            margin-bottom: 20px;
        }

        .booking-text h2 {
            font-size: 1.3rem; /* Scaled down for mobile */
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .booking-text p {
            font-size: 0.85rem; /* Scaled down for mobile */
            padding: 0 15px;
        }
    }
</style>

<div id="booking-processing">
    <div class="booking-spinner"></div>
    <div class="booking-text">
        <h2 id="loaderTitle">Checking Availability</h2>
        <p id="loaderDesc">Finding the perfect room for your dates...</p>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const bookingLoader = document.getElementById("booking-processing");
        const loaderTitle = document.getElementById("loaderTitle");
        const loaderDesc = document.getElementById("loaderDesc");
        const forms = document.querySelectorAll('form');

        forms.forEach(form => {
            // Only attach to relevant booking forms if necessary
            // For now, attaching to all for consistency
            form.addEventListener('submit', function(e) {
                if (form.getAttribute('data-no-loader') === 'true') return;

                e.preventDefault(); 
                bookingLoader.classList.add('active');
                
                // Allow dynamic updates if script is on a specific page
                setTimeout(() => {
                    form.submit(); 
                }, 1500); 
            });
        });
        
        window.addEventListener("pageshow", function(event) {
            bookingLoader.classList.remove('active');
        });
    });
</script>
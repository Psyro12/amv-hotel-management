document.addEventListener('DOMContentLoaded', () => {
    // Attach listener to the current form on the page
    const form = document.getElementById('bookingForm') || document.getElementById('guestInfoForm');
    
    if (form) {
        form.addEventListener('submit', handleAjaxSubmit);
    }
});

async function handleAjaxSubmit(e) {
    e.preventDefault(); // Stop standard reload

    const form = e.target;
    
    // 1. Validation Logic (Check if room_booking.js validation passes)
    // If you are on the first page, we check the manual validation logic
    if (form.id === 'bookingForm') {
        if (typeof calculateTotal === 'function') {
            const isValid = calculateTotal();
            if (!isValid) {
                alert('Please select valid Check-in and Check-out dates.');
                return;
            }
        }
    }

    // 2. Visual Feedback - Start Animation
    const hero = document.querySelector('.hero');
    const container = document.querySelector('.container');
    
    // Add fade-out class to current content
    hero.classList.add('fade-out');
    container.classList.add('fade-out');

    // 3. Prepare Data
    const formData = new FormData(form);
    const actionUrl = form.getAttribute('action');

    try {
        // 4. Fetch the next page via AJAX
        const response = await fetch(actionUrl, {
            method: 'POST',
            body: formData
        });

        if (!response.ok) throw new Error('Network response was not ok');

        // 5. Get the HTML text from the response
        const htmlText = await response.text();

        // 6. Parse the HTML
        const parser = new DOMParser();
        const doc = parser.parseFromString(htmlText, 'text/html');

        // 7. Wait slightly for the fade-out to look nice (optional)
        await new Promise(r => setTimeout(r, 300));

        // 8. Swap Content
        // We replace the current Body content with the new Body content
        // But we preserve the scripts to ensure they run
        
        // Update Title
        document.title = doc.title;

        // Replace Hero and Container content
        const newHero = doc.querySelector('.hero');
        const newContainer = doc.querySelector('.container');

        if (newHero && newContainer) {
            document.querySelector('.hero').innerHTML = newHero.innerHTML;
            document.querySelector('.container').innerHTML = newContainer.innerHTML;
            
            // Update URL bar without reloading (pushState)
            window.history.pushState({}, "", actionUrl);

            // 9. Re-Initialize Scripts for the new page
            // Because standard <script> tags don't run when injected via innerHTML
            reinitializeScripts(actionUrl);
            
            // 10. Re-attach form listener for the next step
            const newForm = document.getElementById('guestInfoForm') || document.getElementById('bookingForm');
            if (newForm) newForm.addEventListener('submit', handleAjaxSubmit);

            // 11. Fade In
            const currentHero = document.querySelector('.hero');
            const currentContainer = document.querySelector('.container');
            
            currentHero.classList.remove('fade-out');
            currentContainer.classList.remove('fade-out');
            
            currentHero.classList.add('fade-in');
            currentContainer.classList.add('fade-in');
            
            // Scroll to top smoothly
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

    } catch (error) {
        console.error('Error:', error);
        // Fallback: If AJAX fails, submit normally
        form.submit();
    }
}

// Helper to make scripts work after content swap
function reinitializeScripts(url) {
    if (url.includes('guest_information.php')) {
        // Re-attach the combined name logic
        const btn = document.querySelector('.continue-btn-orange') || document.querySelector('.book-now-btn');
        if(btn) {
            btn.onclick = function() {
                const first = document.querySelector('input[name="first_name"]').value;
                const last = document.querySelector('input[name="last_name"]').value;
                const hiddenName = document.getElementById('combinedFullName');
                if(hiddenName && first && last) {
                    hiddenName.value = first + ' ' + last;
                }
                // Trigger the AJAX submit on the form
                const form = document.getElementById('guestInfoForm');
                form.requestSubmit(); // This triggers the submit event listener we added
            };
        }
        
        // Re-attach Accordion logic (Global scope helper)
        window.toggleSummary = function(id) {
            const content = document.getElementById(id);
            if (!content) return;
            
            if (content.style.display === "none" || getComputedStyle(content).display === "none") {
                content.style.display = "block";
                content.classList.add('active');
            } else {
                content.style.display = "none";
                content.classList.remove('active');
            }
        };
    }
}
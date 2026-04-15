document.addEventListener('DOMContentLoaded', function() {
    const authForm = document.getElementById('authForm');
    const otpInputs = document.querySelectorAll('.otp-input');
    const authButton = document.querySelector('.auth-button');

    // 1. Focus first input on page load
    if (otpInputs.length > 0) {
        otpInputs[0].focus();
    }

    // 2. Handle Input Typing (Auto-move to next box)
    otpInputs.forEach((input, index) => {
        input.addEventListener('input', function(e) {
            const value = e.target.value;
            
            // Allow only numbers
            if (!/^\d*$/.test(value)) {
                e.target.value = value.replace(/\D/g, '');
                return;
            }

            // Move to next input if value exists
            if (value && index < otpInputs.length - 1) {
                otpInputs[index + 1].focus();
            }
            
            checkFormCompletion();
        });

        // Handle Backspace (Move to previous box)
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !e.target.value && index > 0) {
                otpInputs[index - 1].focus();
            }
        });

        // Handle Paste (Fill all boxes)
        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedData = e.clipboardData.getData('text');
            const numbers = pastedData.replace(/\D/g, '').slice(0, 6);
            
            if (numbers) {
                numbers.split('').forEach((num, i) => {
                    if (otpInputs[i]) otpInputs[i].value = num;
                });
                const nextEmptyIndex = Math.min(numbers.length, otpInputs.length - 1);
                otpInputs[nextEmptyIndex].focus();
                checkFormCompletion();
            }
        });
    });

    // 3. Enable Button only if all fields filled
    function checkFormCompletion() {
        const allFilled = Array.from(otpInputs).every(input => input.value.trim() !== '');
        authButton.disabled = !allFilled;
        authButton.style.opacity = allFilled ? '1' : '0.6';
        authButton.style.cursor = allFilled ? 'pointer' : 'not-allowed';
    }

    // 4. IMPORTANT: Let the form submit to PHP! 
    // We REMOVED the e.preventDefault() logic that was blocking PHP.
    
    // Optional: Auto-submit when hitting Enter
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !authButton.disabled) {
            if(document.activeElement.classList.contains('otp-input')) {
                authForm.submit(); 
            }
        }
    });

    // Run check initially
    checkFormCompletion();
});
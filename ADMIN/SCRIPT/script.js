document.addEventListener('DOMContentLoaded', function() {
    const loginForm = document.getElementById('loginForm');
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const loginButton = document.querySelector('.login-button');

    // Remove error states on input
    function clearErrors() {
        emailInput.classList.remove('error');
        passwordInput.classList.remove('error');
        
        // Remove existing error messages
        const existingErrors = document.querySelectorAll('.error-message');
        existingErrors.forEach(error => error.remove());
    }

    // Add error state to input
    function showError(input, message) {
        input.classList.add('error');
        
        const errorDiv = document.createElement('div');
        errorDiv.className = 'error-message';
        errorDiv.textContent = message;
        
        input.parentNode.appendChild(errorDiv);
    }

    // Validate email format
    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // Form validation
    function validateForm() {
        clearErrors();
        let isValid = true;

        // Email validation
        if (!emailInput.value.trim()) {
            showError(emailInput, 'Email is required');
            isValid = false;
        } else if (!isValidEmail(emailInput.value.trim())) {
            showError(emailInput, 'Please enter a valid email address');
            isValid = false;
        }

        // Password validation
        if (!passwordInput.value.trim()) {
            showError(passwordInput, 'Password is required');
            isValid = false;
        } else if (passwordInput.value.length < 6) {
            showError(passwordInput, 'Password must be at least 6 characters');
            isValid = false;
        }

        return isValid;
    }

    // Simulate login process
    async function performLogin(email, password) {
        // Simulate API call delay
        return new Promise((resolve) => {
            setTimeout(() => {
                // Demo credentials (in real app, this would be server-side validation)
                if (email === 'admin@amv.com' && password === 'admin123') {
                    resolve({ success: true, message: 'Login successful!' });
                } else {
                    resolve({ success: false, message: 'Invalid email or password' });
                }
            }, 1500);
        });
    }

    // Handle form submission
    loginForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        if (!validateForm()) {
            return;
        }

        // Show loading state
        loginButton.classList.add('loading');
        loginButton.textContent = 'Logging in...';
        loginButton.disabled = true;

        try {
            const result = await performLogin(emailInput.value.trim(), passwordInput.value);
            
            if (result.success) {
                // Success - show success message
                loginButton.textContent = 'Success!';
                loginButton.style.background = 'linear-gradient(135deg, #10b981, #059669)';
                
                // Redirect to authentication page
                setTimeout(() => {
                    window.location.href = 'auth.html';
                }, 1500);
            } else {
                // Error - show error message
                showError(passwordInput, result.message);
                loginButton.textContent = 'Login';
                loginButton.classList.remove('loading');
                loginButton.disabled = false;
            }
        } catch (error) {
            // Handle unexpected errors
            showError(passwordInput, 'An error occurred. Please try again.');
            loginButton.textContent = 'Login';
            loginButton.classList.remove('loading');
            loginButton.disabled = false;
        }
    });

    // Clear errors when user starts typing
    emailInput.addEventListener('input', clearErrors);
    passwordInput.addEventListener('input', clearErrors);

    // Add some interactive effects
    loginButton.addEventListener('mouseenter', function() {
        if (!this.disabled) {
            this.style.transform = 'translateY(-2px)';
        }
    });

    loginButton.addEventListener('mouseleave', function() {
        if (!this.disabled) {
            this.style.transform = 'translateY(0)';
        }
    });

    // Add focus effects to inputs
    const inputs = document.querySelectorAll('.form-input');
    inputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.parentNode.style.transform = 'scale(1.02)';
        });
        
        input.addEventListener('blur', function() {
            this.parentNode.style.transform = 'scale(1)';
        });
    });

    // Add demo credentials info
    const demoInfo = document.createElement('div');
    demoInfo.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: rgba(0, 0, 0, 0.8);
        color: white;
        padding: 15px;
        border-radius: 10px;
        font-size: 12px;
        max-width: 250px;
        z-index: 1001;
    `;
    demoInfo.innerHTML = `
        <strong>Demo Credentials:</strong><br>
        Email: admin@amv.com<br>
        Password: admin123
    `;
    document.body.appendChild(demoInfo);

    // Hide demo info after 10 seconds
    setTimeout(() => {
        demoInfo.style.opacity = '0';
        demoInfo.style.transition = 'opacity 1s ease';
        setTimeout(() => demoInfo.remove(), 1000);
    }, 10000);
});

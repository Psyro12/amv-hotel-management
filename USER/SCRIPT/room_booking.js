// Price per night
        const pricePerNight = 2500;

        // DOM Elements
        const checkInInput = document.getElementById('checkIn');
        const checkOutInput = document.getElementById('checkOut');
        const adultsSelect = document.getElementById('adults');
        const childrenSelect = document.getElementById('children');
        const nightsInfo = document.getElementById('nightsInfo');
        const totalAmount = document.querySelector('.total-amount');
        const bookingForm = document.getElementById('bookingForm');

        // --- NEW: Elements for Hidden Inputs (Required for PHP) ---
        const totalPriceInput = document.getElementById('totalPriceInput');
        const nightsInput = document.getElementById('nightsInput');

        // Set minimum date to today
        const today = new Date();
        const tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);

        checkInInput.min = formatDate(today);
        checkOutInput.min = formatDate(tomorrow);

        // Calculate nights and total price
        function calculateTotal() {
            const checkIn = new Date(checkInInput.value);
            const checkOut = new Date(checkOutInput.value);

            if (checkIn && checkOut && checkOut > checkIn) {
                const nights = Math.ceil((checkOut - checkIn) / (1000 * 60 * 60 * 24));
                const total = nights * pricePerNight;

                // Update Visuals
                nightsInfo.textContent = `${nights} night${nights !== 1 ? 's' : ''}`;
                totalAmount.textContent = `₱${total.toLocaleString()}`;

                // --- UPDATE: Fill Hidden Inputs for PHP ---
                if (nightsInput) nightsInput.value = nights;
                if (totalPriceInput) totalPriceInput.value = total;

                return true; // Valid calculation
            } else {
                // Reset Visuals
                nightsInfo.textContent = '0 nights';
                totalAmount.textContent = '₱0';

                // --- UPDATE: Reset Hidden Inputs ---
                if (nightsInput) nightsInput.value = 0;
                if (totalPriceInput) totalPriceInput.value = 0;

                return false; // Invalid calculation
            }
        }

        // Format date as YYYY-MM-DD
        function formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        // Event listeners
        checkInInput.addEventListener('change', function () {
            if (this.value) {
                const nextDay = new Date(this.value);
                nextDay.setDate(nextDay.getDate() + 1);
                checkOutInput.min = formatDate(nextDay);

                // If check-out is before the new minimum, clear it
                if (checkOutInput.value && new Date(checkOutInput.value) <= new Date(this.value)) {
                    checkOutInput.value = '';
                }
            }
            calculateTotal();
        });

        checkOutInput.addEventListener('change', calculateTotal);

        // Calendar functionality (UNCHANGED)
        let currentDate = new Date(2025, 4, 1); // May 2025
        let currentDate2 = new Date(2025, 5, 1); // June 2025

        function renderCalendar(date, containerId, monthId) {
            const calendarGrid = document.getElementById(containerId);
            const monthYear = document.getElementById(monthId);

            // Set month and year
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'];
            monthYear.textContent = `${monthNames[date.getMonth()]} ${date.getFullYear()}`;

            // Clear previous calendar
            calendarGrid.innerHTML = '';

            // Add day headers
            const days = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
            days.forEach(day => {
                const dayElement = document.createElement('div');
                dayElement.className = 'calendar-day';
                dayElement.textContent = day;
                calendarGrid.appendChild(dayElement);
            });

            // Get first day of month and number of days
            const firstDay = new Date(date.getFullYear(), date.getMonth(), 1).getDay();
            const daysInMonth = new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate();

            // Add empty cells for days before the first day of the month
            for (let i = 0; i < firstDay; i++) {
                const emptyCell = document.createElement('div');
                emptyCell.className = 'calendar-date other-month';
                calendarGrid.appendChild(emptyCell);
            }

            // Add days of the month
            for (let i = 1; i <= daysInMonth; i++) {
                const dateCell = document.createElement('div');
                dateCell.className = 'calendar-date';
                dateCell.textContent = i;
                dateCell.dataset.date = formatDate(new Date(date.getFullYear(), date.getMonth(), i));

                // Mark some dates as unavailable (for demo purposes)
                if (i % 7 === 0 || i % 11 === 0) {
                    dateCell.classList.add('unavailable');
                }

                calendarGrid.appendChild(dateCell);
            }
        }

        // Initialize calendars
        renderCalendar(currentDate, 'calendarGrid', 'currentMonth');
        renderCalendar(currentDate2, 'calendarGrid2', 'currentMonth2');

        // Navigation buttons
        document.getElementById('prevMonth').addEventListener('click', function () {
            currentDate.setMonth(currentDate.getMonth() - 1);
            renderCalendar(currentDate, 'calendarGrid', 'currentMonth');
        });

        document.getElementById('nextMonth').addEventListener('click', function () {
            currentDate.setMonth(currentDate.getMonth() + 1);
            renderCalendar(currentDate, 'calendarGrid', 'currentMonth');
        });

        document.getElementById('prevMonth2').addEventListener('click', function () {
            currentDate2.setMonth(currentDate2.getMonth() - 1);
            renderCalendar(currentDate2, 'calendarGrid2', 'currentMonth2');
        });

        document.getElementById('nextMonth2').addEventListener('click', function () {
            currentDate2.setMonth(currentDate2.getMonth() + 1);
            renderCalendar(currentDate2, 'calendarGrid2', 'currentMonth2');
        });

        // --- UPDATED: Form submission ---
        bookingForm.addEventListener('submit', function (e) {
            // 1. Recalculate to ensure data is fresh and valid
            const isValid = calculateTotal();

            // 2. Check validity
            if (!isValid) {
                // Only stop the redirect if the dates are invalid
                e.preventDefault(); 
                alert('Please select valid Check-in and Check-out dates.');
            }
            
            // 3. IF VALID: We do NOTHING here. 
            // The "e.preventDefault()" is NOT called, so the form submits 
            // and the browser redirects to guest_information.php
        });
document.addEventListener('DOMContentLoaded', () => {
    initializeDashboard();
    setupNavigation();
    initCalendar(); // Always initialize calendar on load
    // Top right icon modals
    const messagesBtn = document.getElementById('messagesBtn');
    const notificationsBtn = document.getElementById('notificationsBtn');
    const messagesModal = document.getElementById('messagesModal');
    const notificationsModal = document.getElementById('notificationsModal');
    const closeMessagesModal = document.getElementById('closeMessagesModal');
    const closeNotificationsModal = document.getElementById('closeNotificationsModal');

    if (messagesBtn && messagesModal) {
        messagesBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            messagesModal.classList.toggle('open');
            notificationsModal && notificationsModal.classList.remove('open');
        });
    }
    if (notificationsBtn && notificationsModal) {
        notificationsBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            notificationsModal.classList.toggle('open');
            messagesModal && messagesModal.classList.remove('open');
        });
    }
    if (closeMessagesModal && messagesModal) {
        closeMessagesModal.addEventListener('click', () => messagesModal.classList.remove('open'));
    }
    if (closeNotificationsModal && notificationsModal) {
        closeNotificationsModal.addEventListener('click', () => notificationsModal.classList.remove('open'));
    }
    // Close modals when clicking outside
    document.addEventListener('click', (e) => {
        if (messagesModal) messagesModal.classList.remove('open');
        if (notificationsModal) notificationsModal.classList.remove('open');
    });
    if (messagesModal) messagesModal.addEventListener('click', e => e.stopPropagation());
    if (notificationsModal) notificationsModal.addEventListener('click', e => e.stopPropagation());
});

// SPA Navigation
function setupNavigation() {
    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const pageId = item.getAttribute('data-page');
            document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
            document.getElementById(pageId).classList.add('active');
            navItems.forEach(n => n.classList.remove('active'));
            item.classList.add('active');
            updatePageTitle(item.querySelector('span').textContent);

            // Lazy initialize per-page features
            if (pageId === 'dashboard') initDashboardCharts();
            if (pageId === 'calendar') initCalendar();
            if (pageId === 'guests') initGuests();
            if (pageId === 'bookings') initBookings();
        });
    });

    // default
    initDashboardCharts();
}

function updatePageTitle(title) {
    const pageTitle = document.querySelector('.page-title');
    const pageSubtitle = document.querySelector('.page-subtitle');
    pageTitle.textContent = title;
    pageSubtitle.textContent = `Welcome to ${title}`;
}

// Dashboard (metrics + charts + progress)
function initializeDashboard() {
    animateStat('#totalBookings', 10);
    animateStat('#totalRevenue', 2500, { currency: true });
    animateStat('#totalOrders', 19);

    // Top-right badges demo
    const msg = document.getElementById('messagesBadge');
    const notif = document.getElementById('notificationsBadge');
    if (msg && notif) {
        setInterval(() => {
            msg.textContent = Math.floor(Math.random() * 5) + 1;
            notif.textContent = Math.floor(Math.random() * 7) + 1;
        }, 15000);
    }
}

function animateStat(selector, value, opts = {}) {
    const el = document.querySelector(selector);
    if (!el) return;
    const duration = 1200;
    let start = 0;
    const step = () => {
        start += value / (duration / 16);
        if (start < value) {
            el.textContent = opts.currency ? `₱${Math.floor(start).toLocaleString()}` : Math.floor(start).toLocaleString();
            requestAnimationFrame(step);
        } else {
            el.textContent = opts.currency ? `₱${value.toLocaleString()}` : value.toLocaleString();
        }
    };
    requestAnimationFrame(step);
}

// Charts
let pieBookingsChart, barMonthlyChart;
// Bar chart months navigation state
// Support multiple years for bar chart
const BAR_YEARS = [2024, 2025]; // Example: 2024 and 2025
const BAR_MONTHS = [];
const BAR_DATA = [];
// Fill BAR_MONTHS and BAR_DATA for each year (example data)
const BAR_DATA_RAW = {
    2024: [42, 58, 63, 71, 66, 80, 74, 68, 72, 79, 83, 77],
    2025: [50, 62, 70, 75, 69, 85, 78, 73, 76, 82, 88, 81]
};
for (let y of BAR_YEARS) {
    for (let m = 0; m < 12; m++) {
        BAR_MONTHS.push({
            label: `${['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][m]} ${y}`,
            year: y,
            month: m
        });
        BAR_DATA.push(BAR_DATA_RAW[y][m]);
    }
}
let barMonthStart = Math.max(0, getCurrentMonthIndex() - 3); // show 4 bars at a time
function initDashboardCharts() {

    // Pie: monthly bookings breakdown
    const pieCtx = document.getElementById('pieBookings');
    if (pieCtx && !pieBookingsChart) {
        const pieData = { labels: ['Check-ins', 'No-show', 'Cancelled'], values: [56, 22, 12] };
        pieBookingsChart = new Chart(pieCtx, {
            type: 'doughnut',
            data: {
                labels: pieData.labels,
                datasets: [{
                    data: pieData.values,
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                    borderColor: ['#10b981', '#f59e0b', '#ef4444'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '80%', // slightly thicker donut
                animation: { duration: 1800, animateRotate: true, animateScale: true },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,  // This will change the legend items to circles
                            pointStyle: 'circle',  // Set legend items to circles
                            pointRadius: 5,  // Adjust this value to make the circle smaller (default is 5)
                            padding: 20  // Add space between the legend circle and the text
                        }
                    }
                },

                doughnutCenterText: {
                    display: true,
                    font: {
                        size: 22,
                        weight: 'bold'
                    },
                    color: '#334155'
                }
            }
        });

        // Plugin to draw total in center
        Chart.register({
            id: 'doughnutCenterText',
            afterDraw(chart) {
                if (chart.config.options.plugins.doughnutCenterText && chart.config.options.plugins.doughnutCenterText.display) {
                    const ctx = chart.ctx;
                    const total = chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
                    const { width, height } = chart;
                    ctx.save();
                    ctx.font = `${chart.config.options.plugins.doughnutCenterText.font.weight} ${chart.config.options.plugins.doughnutCenterText.font.size}px Montserrat, Arial`;
                    ctx.fillStyle = chart.config.options.plugins.doughnutCenterText.color;
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(total, width / 2, height / 2);
                    ctx.restore();
                }
            }
        });

        updateProgressBars(pieData.values);
    }




    // Bar: monthly bookings volume (3 months at a time)
    const barCtx = document.getElementById('barMonthly');
    if (barCtx && !barMonthlyChart) {
        // Always recalculate barMonthStart in case the month has changed
        barMonthStart = Math.max(0, getCurrentMonthIndex() - 2);
        barMonthlyChart = new Chart(barCtx, {
            type: 'bar',
            data: getBarChartData(),
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 1800, easing: 'easeInOutQuart' },
                plugins: { legend: { display: false } },
                scales: { x: { grid: { display: false } }, y: { beginAtZero: true } }
            }
        });
        updateBarMonthLabel();
        setupBarMonthNav();
    }
}

function getBarChartData() {
    // Show 3 months at a time
    let end = Math.min(barMonthStart + 4, BAR_MONTHS.length);
    return {
        labels: BAR_MONTHS.slice(barMonthStart, end).map(m => m.label),
        datasets: [{
            label: 'Bookings',
            data: BAR_DATA.slice(barMonthStart, end),
            backgroundColor: 'rgba(59,130,246,0.85)',
            borderColor: 'rgba(59,130,246,1)',
            borderWidth: 1,
            borderRadius: 8,
            borderSkipped: false,
            barThickness: 60,
            maxBarThickness: 60,
            minBarLength: 2
        }]
    };
}

function updateBarMonthLabel() {
    const label = document.getElementById('barMonthLabel');
    let end = Math.min(barMonthStart + 4, BAR_MONTHS.length);
    if (label) label.textContent = `${BAR_MONTHS[barMonthStart].label} - ${BAR_MONTHS[end - 1].label}`;
}

function setupBarMonthNav() {
    const prevBtn = document.getElementById('barPrevBtn');
    const nextBtn = document.getElementById('barNextBtn');
    if (!prevBtn || !nextBtn) return;
    prevBtn.onclick = () => {
        if (barMonthStart > 0) {
            barMonthStart--;
            updateBarChart();
        }
    };
    nextBtn.onclick = () => {
        // Always recalculate current month index in case the month changed
        const currentMonthIdx = getCurrentMonthIndex();
        if (barMonthStart + 4 < currentMonthIdx + 1) {
            barMonthStart++;
            updateBarChart();
        }
    };
}

function updateBarChart() {
    if (!barMonthlyChart) return;
    // Always recalculate barMonthStart in case the month has changed
    const currentMonthIdx = getCurrentMonthIndex();
    if (barMonthStart + 3 > currentMonthIdx) {
        barMonthStart = Math.max(0, currentMonthIdx - 3);
    }
    barMonthlyChart.data = getBarChartData();
    barMonthlyChart.update();
    updateBarMonthLabel();
}

function getCurrentMonthIndex() {
    // Returns the index of the current month (today's month) in BAR_MONTHS
    const now = new Date();
    const year = now.getFullYear();
    const month = now.getMonth();
    for (let i = 0; i < BAR_MONTHS.length; i++) {
        if (BAR_MONTHS[i].year === year && BAR_MONTHS[i].month === month) return i;
    }
    return BAR_MONTHS.length - 1;
}

function updateProgressBars(values) {
    const total = values.reduce((a, b) => a + b, 0) || 1;
    const [checkin, noshow, cancelled] = values.map(v => Math.round((v / total) * 100));
    const set = (id, val) => {
        const fill = document.getElementById(id);
        if (fill) fill.style.width = `${val}%`;
    };
    set('pbCheckin', checkin); set('pbNoShow', noshow); set('pbCancelled', cancelled);
    const pvC = document.getElementById('pvCheckin'); if (pvC) pvC.textContent = `${checkin}%`;
    const pvN = document.getElementById('pvNoShow'); if (pvN) pvN.textContent = `${noshow}%`;
    const pvX = document.getElementById('pvCancelled'); if (pvX) pvX.textContent = `${cancelled}%`;
}

// Calendar
let calendarState = { current: new Date(), rooms: [101, 102, 103, 104, 105, 106, 107] };
function initCalendar() {
    const prev = document.getElementById('prevMonth');
    const next = document.getElementById('nextMonth');
    if (prev && next && !prev.dataset.bound) {
        prev.dataset.bound = '1';
        prev.addEventListener('click', () => { changeMonth(-1); });
        next.addEventListener('click', () => { changeMonth(1); });
    }
    buildCalendar();
}

function changeMonth(delta) {
    calendarState.current.setMonth(calendarState.current.getMonth() + delta);
    buildCalendar();
}

// Mock data generator: returns status for date (available/reserved/occupied/full)
function getDateStatus(date) {
    // simple demo logic: multiples of day index for different states
    const day = date.getDate();
    const r = (day * 7 + date.getMonth()) % 10;
    if (r < 4) return 'available';
    if (r < 6) return 'reserved';
    if (r < 8) return 'occupied';
    return 'full';
}

function buildCalendar() {
    const grid = document.getElementById('calendarGrid');
    if (!grid) return;
    grid.innerHTML = '';

    const title = document.getElementById('calendarTitle');
    const dt = new Date(calendarState.current.getFullYear(), calendarState.current.getMonth(), 1);
    const monthName = dt.toLocaleString('default', { month: 'long' });
    title.textContent = `${monthName} ${dt.getFullYear()}`;

    const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    dayNames.forEach(d => {
        const el = document.createElement('div');
        el.textContent = d;
        el.className = 'day-name';
        grid.appendChild(el);
    });

    const startDay = dt.getDay();
    const daysInMonth = new Date(dt.getFullYear(), dt.getMonth() + 1, 0).getDate();

    // leading blanks
    for (let i = 0; i < startDay; i++) grid.appendChild(document.createElement('div'));

    for (let day = 1; day <= daysInMonth; day++) {
        const cell = document.createElement('div');
        cell.className = 'calendar-cell cell';
        const dateLabel = document.createElement('div');
        dateLabel.className = 'calendar-date';
        dateLabel.textContent = day;
        cell.appendChild(dateLabel);

        const date = new Date(dt.getFullYear(), dt.getMonth(), day);
        const status = getDateStatus(date);
        cell.classList.add(status);

        const badge = document.createElement('div');
        badge.className = 'cell-badge';
        badge.textContent = status === 'available' ? 'Avail' : status.charAt(0).toUpperCase() + status.slice(1);
        cell.appendChild(badge);

        // Open modal on click
        cell.addEventListener('click', () => showCalendarModal(date));
        grid.appendChild(cell);
    }
}


// Show modal with room status for selected date
function showCalendarModal(date) {
    const modal = document.getElementById('calendarModal');
    const title = document.getElementById('calendarModalTitle');
    const body = document.getElementById('calendarModalBody');
    if (!modal || !title || !body) return;

    title.textContent = `Room Status for ${date.toDateString()}`;
    body.innerHTML = '';

    // Demo logic for room statuses
    let available = 0, reserved = 0, occupied = 0, full = 0;
    const roomList = document.createElement('div');
    roomList.className = 'rooms-list';
    calendarState.rooms.forEach(room => {
        const seed = (room + date.getDate() + date.getMonth()) % 10;
        let rStatus = 'available';
        if (seed < 3) rStatus = 'reserved';
        else if (seed < 6) rStatus = 'occupied';
        else if (seed >= 9) rStatus = 'full';
        if (rStatus === 'available') available++;
        if (rStatus === 'reserved') reserved++;
        if (rStatus === 'occupied') occupied++;
        if (rStatus === 'full') full++;
        const row = document.createElement('div');
        row.className = 'room-card';
        row.innerHTML = `<span class="fs-sm">Room ${room}</span><span class="room-tag ${rStatus}">${rStatus.charAt(0).toUpperCase() + rStatus.slice(1)}</span>`;
        roomList.appendChild(row);
    });
    body.appendChild(roomList);

    // Summary
    const summary = document.createElement('div');
    summary.className = 'room-summary';
    summary.innerHTML = `<strong>Summary:</strong> ${available} available, ${reserved} reserved, ${occupied} occupied, ${full} fully booked`;
    body.appendChild(summary);

    modal.classList.add('open');
}

// Close modal event
document.addEventListener('DOMContentLoaded', () => {
    const closeBtn = document.getElementById('closeCalendarModal');
    if (closeBtn) {
        closeBtn.addEventListener('click', () => {
            const modal = document.getElementById('calendarModal');
            if (modal) modal.classList.remove('open');
        });
    }
});

// Guests
function initGuests() {
    // open modal on view click
    document.querySelectorAll('.view-guest').forEach(btn => {
        btn.addEventListener('click', () => {
            const tr = btn.closest('tr');
            const data = JSON.parse(tr.getAttribute('data-guest'));
            openGuestModal(data);
        });
    });

    document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', closeModals));
}

function openGuestModal(data) {
    const modal = document.getElementById('guestModal');
    const body = document.getElementById('guestModalBody');
    body.innerHTML = `
        <div style="display:grid;gap:.5rem;">
            <div><strong>Name:</strong> ${data.name}</div>
            <div><strong>Email:</strong> ${data.email}</div>
            <div><strong>Phone:</strong> ${data.phone}</div>
            <div><strong>History:</strong><br>${data.history.map(h => `• ${h}`).join('<br>')}</div>
        </div>
    `;
    modal.classList.add('open');
}

function closeModals() { document.querySelectorAll('.modal').forEach(m => m.classList.remove('open')); }

// Bookings
function initBookings() {
    // tabs
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const id = btn.getAttribute('data-tab');
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            document.getElementById(id).classList.add('active');
        });
    });

    // add booking modal
    const open = document.getElementById('openAddBooking');
    const modal = document.getElementById('bookingModal');
    if (open && modal && !open.dataset.bound) {
        open.dataset.bound = '1';
        open.addEventListener('click', () => modal.classList.add('open'));
        modal.querySelector('[data-close]').addEventListener('click', closeModals);
        const form = document.getElementById('addBookingForm');
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            modal.classList.remove('open');
            alert('Booking added (demo).');
        });
    }
}

// Mobile menu
function addMobileMenuToggle() {
    const header = document.querySelector('.top-header');
    if (!header || document.querySelector('.mobile-menu-toggle')) return;
    const btn = document.createElement('button');
    btn.className = 'mobile-menu-toggle';
    btn.style.border = '1px solid #e2e8f0';
    btn.style.background = '#fff';
    btn.style.borderRadius = '8px';
    btn.style.padding = '6px';
    btn.style.marginRight = '8px';
    btn.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>';
    btn.addEventListener('click', () => document.querySelector('.sidebar').classList.toggle('open'));
    header.insertBefore(btn, header.firstChild);
}

// JavaScript function to toggle expand/collapse of messages
function toggleExpand(messageId) {
    const card = document.getElementById(messageId);
    const expandedMessage = card.querySelector('.expanded-message');

    // Toggle expanded message and card height
    if (expandedMessage.style.maxHeight === '0px' || expandedMessage.style.maxHeight === '') {
        expandedMessage.style.maxHeight = '500px'; // Set to a large enough value to show the full content
        card.classList.add('expanded'); // Expand card height
    } else {
        expandedMessage.style.maxHeight = '0px'; // Collapse the message
        card.classList.remove('expanded'); // Collapse card height
    }
}

// Show the room selection modal
document.getElementById('openAddBooking').addEventListener('click', function () {
    document.getElementById('roomModal').style.display = 'flex';
});

// Close the room modal
document.getElementById('closeRoomModal').addEventListener('click', function () {
    document.getElementById('roomModal').style.display = 'none';
});

// Handle room selection
document.querySelectorAll('.room-card').forEach(function (card) {
    card.addEventListener('click', function () {
        const roomNumber = card.getAttribute('data-room');
        document.getElementById('selectedRoom').textContent = roomNumber; // Show selected room number
        document.getElementById('roomModal').style.display = 'none'; // Close room modal
        document.getElementById('bookingModal').style.display = 'flex'; // Show booking form modal
    });
});

// Close the booking modal
document.getElementById('closeBookingModal').addEventListener('click', function () {
    document.getElementById('bookingModal').style.display = 'none';
});

//For filtering food orders
document.getElementById("statusFilter").addEventListener("change", function () {
    const filter = this.value.toLowerCase();
    const rows = document.querySelectorAll("#guestsTable tbody tr");

    rows.forEach(row => {
        const statusCell = row.querySelector(".status");
        if (statusCell) {
            const status = statusCell.textContent.toLowerCase();
            if (filter === "all" || status === filter) {
                row.style.display = "";
            } else {
                row.style.display = "none";
            }
        }
    });
});

        // 🟢 CUSTOM NOTIFICATION HELPERS (SweetAlert2 Toasts)
        const amvToast = Swal.mixin({
            toast: true,
            position: 'top', // 🟢 Top Center
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            customClass: {
                popup: 'amv-swal-toast-popup',
                title: 'amv-swal-toast-title'
            },
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer);
                toast.addEventListener('mouseleave', Swal.resumeTimer);
                const container = Swal.getContainer();
                container.style.zIndex = '999999';
                container.style.paddingTop = '1.5rem'; // [INFO] Lowers toast from the very top
            }
        });

        function showSuccess(msg) {
            amvToast.fire({
                icon: 'success',
                title: msg
            });
        }

        function showError(msg) {
            // Errors stay slightly longer
            amvToast.fire({
                icon: 'error',
                title: 'Error',
                text: msg,
                timer: 5000 
            });
        }

        function showInfo(msg) {
            amvToast.fire({
                icon: 'info',
                title: msg
            });
        }

        async function showConfirm(title, text, icon = 'warning') {
            const result = await Swal.fire({
                title: title,
                text: text,
                icon: icon,
                width: '400px',
                showCancelButton: true,
                confirmButtonColor: '#B88E2F',
                cancelButtonColor: '#6B7280',
                confirmButtonText: 'Confirm',
                cancelButtonText: 'Cancel',
                customClass: {
                    container: 'amv-swal-container',
                    popup: 'amv-swal-popup',
                    title: 'amv-swal-title',
                    htmlContainer: 'amv-swal-content',
                    confirmButton: 'amv-swal-confirm-btn',
                    cancelButton: 'amv-swal-cancel-btn'
                },
                showClass: {
                    popup: 'swal-win11-show'
                },
                hideClass: {
                    popup: 'swal-win11-hide'
                },
                didOpen: () => {
                    Swal.getContainer().style.zIndex = '999999';
                }
            });
            return result.isConfirmed;
        }

// --- GLOBAL STORAGE (This fixes the undefined issue) ---
        window.allNotifications = [];
        window.allMessages = [];
        let currentNotifFilter = 'all';
        let currentMsgFilter = 'all';

        // Global Quill Instances
        var newsQuill;
        var eventQuill;

        // --- GLOBAL VARIABLES ---
        let isProcessingBooking = false;
        let currentChartYear = new Date().getFullYear();
        let isDrawerBusy = false;
        let isSendingEmail = false;

        // [INFO] CHART STATE (Revenue vs Leaderboard)
        let currentChartData = { revenue: [], leaderboard: [], food: [], date: [], availableMonths: [], totalDateCount: 0 };
        let chartViewMode = 'revenue';
        let leaderboardSearchQuery = ''; // [HOT] Global search query
        let expandedLeaderboardRoom = null; // [HOT] Persistent state for expanded room 
        let expandedLeaderboardFood = null; // [HOT] Persistent state for expanded food 

        // --- 1. CHARTS INITIALIZATION ---
        window.myBookingPieChart = null;

        document.addEventListener("DOMContentLoaded", function () {
            // Initial Pie Chart
            const pieCtx = document.getElementById('pieBookings').getContext('2d');

            window.myBookingPieChart = new Chart(pieCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Complete', 'No-Show', 'Cancelled'],
                    datasets: [{
                        data: pieDataRaw,
                        backgroundColor: ['#10B981', '#F59E0B', '#EF4444'],
                        hoverBackgroundColor: ['#059669', '#D97706', '#DC2626'],
                        borderWidth: 0,
                        cutout: '75%',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: 20 },
                    plugins: { legend: { display: false } },
                    // 🟢 SMOOTH PIE ANIMATION
                    animation: {
                        duration: 800,
                        easing: 'easeOutExpo'
                    }
                }
            });

            // Initial Revenue Line Chart
            currentChartData.revenue = initialRevenue;
            renderRevenueChart(initialRevenue);

            // Fetch latest data to populate leaderboard background
            fetchRevenueChart(currentChartYear, true); // Silent initial load
        });

        // --- 2. UPDATE CHART YEAR (Button Click) ---
        function updateYearButtons() {
            const actualYear = new Date().getFullYear();
            const prevBtn = document.getElementById('prevYearBtn');
            const nextBtn = document.getElementById('nextYearBtn');

            // [INFO] ALWAYS RESTORE PREV BTN (Since it's hidden by opacity in other modes)
            if (prevBtn) {
                prevBtn.style.opacity = '1';
                prevBtn.style.cursor = 'pointer';
                prevBtn.style.pointerEvents = 'auto';
            }

            if (nextBtn) {
                if (currentChartYear >= actualYear) {
                    nextBtn.style.opacity = '0.3';
                    nextBtn.style.cursor = 'not-allowed';
                    nextBtn.style.pointerEvents = 'none';
                } else {
                    nextBtn.style.opacity = '1';
                    nextBtn.style.cursor = 'pointer';
                    nextBtn.style.pointerEvents = 'auto';
                }
            }
        }

        function changeChartYear(offset) {
            const nextYear = currentChartYear + offset;
            const actualYear = new Date().getFullYear();

            // Prevent going into the future beyond current year
            if (nextYear > actualYear) return;

            currentChartYear = nextYear;
            updateYearButtons();
            fetchRevenueChart(currentChartYear);
        }

        // [INFO] HANDLE LEADERBOARD SEARCH
        function handleLeaderboardSearch(query) {
            leaderboardSearchQuery = query.trim().toLowerCase();

            // Re-render the current active view with the filter
            if (chartViewMode === 'leaderboard') {
                renderRoomLeaderboard(currentChartData.leaderboard);
            } else if (chartViewMode === 'food') {
                renderFoodLeaderboard(currentChartData.food);
            } else if (chartViewMode === 'date') {
                renderDateLeaderboard(currentChartData.date);
            }
        }

        // --- 3. SWITCH CHART VIEW (Revenue / Leaderboard / Food / Date) ---
        function setChartViewMode(mode) {
            chartViewMode = mode;
            updateChartToggleButtons();

            // [HOT] Reset Search on Mode Switch
            leaderboardSearchQuery = '';
            const searchInput = document.getElementById('leaderboardSearchInput');
            if (searchInput) searchInput.value = '';

            // [HOT] Show/Hide Year Navigation and Search based on mode
            const prevBtn = document.getElementById('prevYearBtn');
            const nextBtn = document.getElementById('nextYearBtn');
            const searchContainer = document.getElementById('leaderboardSearchContainer');

            const isRevenue = (mode === 'revenue');
            const isLeaderboard = (mode === 'leaderboard' || mode === 'food' || mode === 'date');

            if (prevBtn && nextBtn) {
                // Control presence
                prevBtn.style.visibility = isRevenue ? 'visible' : 'hidden';
                nextBtn.style.visibility = isRevenue ? 'visible' : 'hidden';

                // Control interaction state (Disabled/Enabled)
                if (isRevenue) {
                    updateYearButtons(); // This will correctly set opacity/pointer-events
                } else {
                    prevBtn.style.opacity = '0';
                    nextBtn.style.opacity = '0';
                }
            }

            if (searchContainer) {
                searchContainer.style.display = isLeaderboard ? 'block' : 'none';
                if (searchInput) {
                    if (mode === 'food') searchInput.placeholder = "Search foods...";
                    else if (mode === 'date') searchInput.placeholder = "Search dates...";
                    else searchInput.placeholder = "Search rooms...";
                }
            }

            if (mode === 'revenue') {
                renderRevenueChart(currentChartData.revenue);
            } else if (mode === 'leaderboard') {
                renderRoomLeaderboard(currentChartData.leaderboard);
            } else if (mode === 'food') {
                renderFoodLeaderboard(currentChartData.food);
            } else if (mode === 'date') {
                renderDateLeaderboard(currentChartData.date);
            }
        }

        function updateChartToggleButtons() {
            const btnRev = document.getElementById('btnRevenue');
            const btnLead = document.getElementById('btnLeaderboard');
            const btnFood = document.getElementById('btnFood');
            const btnDate = document.getElementById('btnDate');
            const title = document.getElementById('revenueChartTitle');

            // Reset all
            [btnRev, btnLead, btnFood, btnDate].forEach(btn => {
                if (btn) {
                    btn.style.background = '#f4f4f4';
                    btn.style.color = '#555';
                    btn.style.boxShadow = 'none';
                }
            });

            if (chartViewMode === 'revenue') {
                if (btnRev) {
                    btnRev.style.background = '#B88E2F';
                    btnRev.style.color = 'white';
                    btnRev.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
                }
                if (title) title.innerText = "Revenue " + currentChartYear;
            } else if (chartViewMode === 'leaderboard') {
                if (btnLead) {
                    btnLead.style.background = '#B88E2F';
                    btnLead.style.color = 'white';
                    btnLead.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
                }
                if (title) title.innerText = "Room Leaderboard";
            } else if (chartViewMode === 'food') {
                if (btnFood) {
                    btnFood.style.background = '#B88E2F';
                    btnFood.style.color = 'white';
                    btnFood.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
                }
                if (title) title.innerText = "Food Best Sellers";
            } else if (chartViewMode === 'date') {
                if (btnDate) {
                    btnDate.style.background = '#B88E2F';
                    btnDate.style.color = 'white';
                    btnDate.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
                }
                if (title) title.innerText = "Most Booked Dates";
            }
        }

        // --- 4. FETCH CHART DATA ---
        function fetchRevenueChart(year, isSilent = false) {
            const revSkeleton = document.getElementById('revenueChartSkeleton');
            if (revSkeleton && !isSilent) revSkeleton.style.display = 'block';

            return fetch(`get_dashboard_stats.php?chart_year=${year}&_t=${new Date().getTime()}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        currentChartData.revenue = data.revenue_data;
                        currentChartData.leaderboard = data.room_leaderboard;
                        currentChartData.food = data.food_leaderboard;
                        currentChartData.date = data.date_leaderboard;
                        currentChartData.availableMonths = data.available_months; // 🟢 Store months
                        setChartViewMode(chartViewMode);
                    }
                })
                .catch(err => console.error("Chart Error:", err))
                .finally(() => {
                    if (revSkeleton) revSkeleton.style.display = 'none';
                });
        }

        // --- 5. RENDER CHART (LINE GRAPH) ---
        let revenueChartInstance = null; // 🟢 Persistent instance

        function renderRevenueChart(dataValues) {
            const container = document.getElementById('barChartContainer');
            if (!container) return;

            const canvas = document.getElementById('barMonthly');
            if (!canvas) {
                // Initial creation
                container.innerHTML = '';
                const newCanvas = document.createElement('canvas');
                newCanvas.id = 'barMonthly';
                container.appendChild(newCanvas);
                createRevenueChart(newCanvas, dataValues);
            } else {
                // 🟢 REUSE INSTANCE FOR SMOOTHNESS
                if (revenueChartInstance) {
                    revenueChartInstance.data.datasets[0].data = dataValues;
                    revenueChartInstance.update('none'); // Update without animation for background polling
                } else {
                    createRevenueChart(canvas, dataValues);
                }
            }

            // 🔥 Store the actual rendered height to use for the Leaderboard
            setTimeout(() => {
                const activeCanvas = document.getElementById('barMonthly');
                if (activeCanvas && activeCanvas.offsetHeight > 0) {
                    container.dataset.lastChartHeight = activeCanvas.offsetHeight;
                }
            }, 100);
        }

        function createRevenueChart(canvas, dataValues) {
            revenueChartInstance = new Chart(canvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                    datasets: [{
                        label: 'Monthly Revenue',
                        data: dataValues,
                        borderColor: '#B88E2F',
                        backgroundColor: 'rgba(184, 142, 47, 0.1)',
                        borderWidth: 2.5, // 🟢 Slightly thinner line for performance
                        fill: true,
                        tension: 0.4,
                        pointRadius: 3, // 🟢 Smaller points
                        pointBackgroundColor: '#B88E2F',
                        pointHoverRadius: 5,
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: '#B88E2F',
                        pointHoverBorderWidth: 2,
                        spanGaps: true // 🟢 Performance boost
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    // 🟢 ULTRA-SMOOTH HARDWARE OPTIMIZED ANIMATION
                    animation: {
                        duration: 1000,
                        easing: 'easeOutQuart'
                    },
                    // 🟢 Optimized performance settings
                    elements: {
                        line: {
                            capBezierPoints: true // Faster rendering
                        },
                        point: {
                            hitRadius: 10
                        }
                    },
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(255, 255, 255, 0.95)',
                            titleColor: '#333',
                            bodyColor: '#B88E2F',
                            bodyFont: { weight: 'bold', size: 14 },
                            borderColor: '#e5e7eb',
                            borderWidth: 1,
                            padding: 12,
                            displayColors: false,
                            callbacks: {
                                label: (context) => '₱' + context.parsed.y.toLocaleString()
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.03)',
                                drawBorder: false
                            },
                            ticks: {
                                font: { family: 'Montserrat', size: 11 },
                                color: '#9CA3AF',
                                callback: (value) => (value >= 1000) ? '₱' + (value / 1000) + 'k' : '₱' + value
                            }
                        },
                        x: { 
                            grid: { display: false },
                            ticks: {
                                font: { family: 'Montserrat', size: 11 },
                                color: '#9CA3AF'
                            }
                        }
                    }
                }
            });
        }

        // --- 6. RENDER LEADERBOARD (LIST) ---
        function renderRoomLeaderboard(data) {
            const container = document.getElementById('barChartContainer');
            if (!container) return;

            // 🟢 PHASE 1: PRE-CALCULATE DATA
            let displayData = data;
            if (leaderboardSearchQuery) {
                displayData = data.filter(room =>
                    room.name.toLowerCase().includes(leaderboardSearchQuery)
                );
            }

            const dataString = JSON.stringify(displayData);
            if (container.dataset.currentRoomData === dataString && container.querySelector('.room-row-item')) {
                return;
            }
            container.dataset.currentRoomData = dataString;

            let targetHeight = container.dataset.lastChartHeight || 350;
            if (targetHeight < 200) targetHeight = 350;

            const totalBookingsForPeriod = data.reduce((sum, r) => sum + r.count, 0) || 1;

            let listHtml = `<div style="display:block; width:100%; padding:5px 0;">`;

            displayData.forEach((room, i) => {
                const pctOfTotal = (room.count / totalBookingsForPeriod) * 100;
                const displayPct = pctOfTotal.toFixed(1);
                const rank = data.indexOf(room) + 1;

                let rCol = '#6B7280', rBg = '#F3F4F6';
                if (rank === 1) { rCol = '#B88E2F'; rBg = '#FFF8E1'; }
                else if (rank === 2) { rCol = '#4B5563'; rBg = '#F9FAFB'; }
                else if (rank === 3) { rCol = '#92400E'; rBg = '#FFFBEB'; }

                const rowId = `leaderboard-item-${i}`;
                const detailsId = `leaderboard-details-${i}`;
                const isExpanded = (room.name === expandedLeaderboardRoom);

                listHtml += `
                    <div id="${rowId}" class="leaderboard-row room-row-item" onclick="toggleLeaderboardDetails('${detailsId}', '${rowId}', '${room.name}')"
                         style="display:flex; flex-direction:column; background:${isExpanded ? '#fafafa' : '#fff'}; padding:12px; border-radius:12px; border:1px solid #f0f0f0; margin-bottom:10px; cursor:pointer; transition: all 0.3s ease; overflow:hidden; box-shadow:${isExpanded ? '0 4px 12px rgba(0,0,0,0.05)' : 'none'};">

                        <div style="display:flex; align-items:center; gap:15px;">
                            <div style="width:32px; height:32px; background:${rank <= 3 ? rBg : '#F3F4F6'}; color:${rank <= 3 ? rCol : '#6B7280'}; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:0.85rem; flex-shrink:0;">
                                ${rank}
                            </div>
                            <div style="flex:1; min-width:0;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:6px; align-items:center;">
                                    <span style="font-weight:700; color:#333; font-size:0.9rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${room.name}</span>
                                    <span style="font-weight:800; color:#B88E2F; font-size:0.95rem;">${room.count} <small style="font-size:0.7rem; color:#999;">(${displayPct}%)</small></span>
                                </div>
                                <div style="height:6px; background:#f0f0f0; border-radius:10px; overflow:hidden;" title="${displayPct}% of total ${totalBookingsForPeriod} bookings">
                                    <div class="leaderboard-progress-bar" data-width="${pctOfTotal}%"
                                         style="height:100%; width:0; background:${rank === 1 ? '#B88E2F' : '#D1D5DB'}; border-radius:10px; transition: width 1.2s cubic-bezier(0.16, 1, 0.3, 1);"></div>
                                </div>
                            </div>
                            <div style="font-size: 0.7rem; color: #ccc; transition: transform 0.3s; transform:${isExpanded ? 'rotate(180deg)' : 'rotate(0deg)'};" class="chevron-icon">
                                <i class="fas fa-chevron-down"></i>
                            </div>
                        </div>
                        <div id="${detailsId}" class="leaderboard-details" style="max-height:${isExpanded ? '150px' : '0'}; opacity:${isExpanded ? '1' : '0'}; transition: all 0.4s ease; padding-top:${isExpanded ? '5px' : '0'}; pointer-events: none;">
                            <div style="display:flex; flex-wrap: wrap; gap:10px; margin-top:15px; padding-top:15px; border-top:1px dashed #eee;">
                                <div style="flex:1; min-width: 90px; background:#EFF6FF; padding:10px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                                    <div style="width:28px; height:28px; background:#3B82F6; color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.75rem; flex-shrink:0;">
                                        <i class="fas fa-mars"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div style="font-size:0.6rem; color:#60A5FA; font-weight:700; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Males</div>
                                        <div style="font-size:0.9rem; font-weight:800; color:#1E40AF;">${room.male}</div>
                                    </div>
                                </div>
                                <div style="flex:1; min-width: 90px; background:#FFF1F2; padding:10px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                                    <div style="width:28px; height:28px; background:#FB7185; color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.75rem; flex-shrink:0;">
                                        <i class="fas fa-venus"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div style="font-size:0.6rem; color:#FDA4AF; font-weight:700; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Females</div>
                                        <div style="font-size:0.9rem; font-weight:800; color:#9F1239;">${room.female}</div>
                                    </div>
                                </div>
                                <div style="flex:1; min-width: 90px; background:#F3F4F6; padding:10px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                                    <div style="width:28px; height:28px; background:#9CA3AF; color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.75rem; flex-shrink:0;">
                                        <i class="fas fa-user-slash"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div style="font-size:0.6rem; color:#9CA3AF; font-weight:700; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Private</div>
                                        <div style="font-size:0.9rem; font-weight:800; color:#374151;">${room.other}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>`;
            });

            listHtml += `</div>`;

            // 🟢 PHASE 2: ATOMIC UI UPDATE
            requestAnimationFrame(() => {
                container.innerHTML = listHtml;
                container.style.height = targetHeight + 'px';
                container.style.maxHeight = targetHeight + 'px';
                container.style.overflowY = 'auto';
                container.style.overflowX = 'hidden';
                container.style.paddingRight = '8px';

                // Trigger bars
                setTimeout(() => {
                    const bars = container.querySelectorAll('.leaderboard-progress-bar');
                    bars.forEach(bar => {
                        bar.style.width = bar.dataset.width;
                    });
                }, 50);
            });
        }
        // --- 7. TOGGLE LEADERBOARD DETAILS ---
        function toggleLeaderboardDetails(detailsId, rowId, roomName) {
            const details = document.getElementById(detailsId);
            const row = document.getElementById(rowId);
            const chevron = row.querySelector('.chevron-icon');

            const isOpen = details.style.maxHeight !== '0px' && details.style.maxHeight !== '';

            // [HOT] Smooth Accordion: Close all other open items first
            if (!isOpen) {
                const allRows = document.querySelectorAll('.leaderboard-row');
                allRows.forEach(r => {
                    const d = r.querySelector('.leaderboard-details');
                    const c = r.querySelector('.chevron-icon');
                    if (d && d.id !== detailsId && (d.style.maxHeight !== '0px' && d.style.maxHeight !== '')) {
                        // Smoothly close this one
                        d.style.maxHeight = '0px';
                        d.style.opacity = '0';
                        d.style.paddingTop = '0px';
                        r.style.background = '#fff';
                        r.style.boxShadow = 'none';
                        if (c) c.style.transform = 'rotate(0deg)';
                    }
                });
            }

            if (isOpen) {
                // Close current
                details.style.maxHeight = '0px';
                details.style.opacity = '0';
                details.style.paddingTop = '0px';
                row.style.background = '#fff';
                row.style.boxShadow = 'none';
                chevron.style.transform = 'rotate(0deg)';
                expandedLeaderboardRoom = null;
            } else {
                // Open current
                details.style.maxHeight = '150px';
                details.style.opacity = '1';
                details.style.paddingTop = '5px';
                row.style.background = '#fafafa';
                row.style.boxShadow = '0 4px 12px rgba(0,0,0,0.05)';
                chevron.style.transform = 'rotate(180deg)';
                expandedLeaderboardRoom = roomName;
            }
        }

        // --- 8. RENDER FOOD LEADERBOARD ---
        function renderFoodLeaderboard(data) {
            const container = document.getElementById('barChartContainer');
            if (!container) return;

            // 🟢 PHASE 1: PRE-CALCULATE DATA
            let displayData = data;
            if (leaderboardSearchQuery) {
                displayData = data.filter(food =>
                    food.name.toLowerCase().includes(leaderboardSearchQuery)
                );
            }

            const dataString = JSON.stringify(displayData);
            if (container.dataset.currentFoodData === dataString && container.querySelector('.food-row-item')) {
                return;
            }
            container.dataset.currentFoodData = dataString;

            let targetHeight = container.dataset.lastChartHeight || 350;
            if (targetHeight < 200) targetHeight = 350;

            if (!data || data.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:40px; color:#999;">No food orders recorded for this period.</div>';
                return;
            }

            const totalItems = data.reduce((sum, f) => sum + f.count, 0) || 1;
            let listHtml = `<div style="display:block; width:100%; padding:5px 0;">`;

            displayData.forEach((food, i) => {
                const pct = (food.count / totalItems) * 100;
                const displayPct = pct.toFixed(1);
                const rank = data.indexOf(food) + 1; 

                const rowId = `food-item-${i}`;
                const detailsId = `food-details-${i}`;
                const isExpanded = (food.name === expandedLeaderboardFood);

                listHtml += `
                    <div id="${rowId}" class="leaderboard-row food-row-item" onclick="toggleFoodLeaderboardDetails('${detailsId}', '${rowId}', '${food.name}')"
                          style="display:flex; flex-direction:column; background:${isExpanded ? '#fafafa' : '#fff'}; padding:12px; border-radius:12px; border:1px solid #f0f0f0; margin-bottom:10px; cursor:pointer; transition: all 0.3s ease; overflow:hidden; box-shadow:${isExpanded ? '0 4px 12px rgba(0,0,0,0.05)' : 'none'};">

                        <div style="display:flex; align-items:center; gap:15px;">
                            <div style="width:32px; height:32px; background:#F3F4F6; color:#6B7280; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:0.85rem; flex-shrink:0;">
                                ${rank}
                            </div>
                            <div style="flex:1; min-width:0;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:6px; align-items:center;">
                                    <span style="font-weight:700; color:#333; font-size:0.9rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${food.name}</span>
                                    <span style="font-weight:800; color:#B88E2F; font-size:0.95rem;">${food.count} <small style="font-size:0.7rem; color:#999;">(${displayPct}%)</small></span>
                                </div>
                                <div style="height:6px; background:#f0f0f0; border-radius:10px; overflow:hidden;">
                                    <div class="food-progress-bar" data-width="${pct}%"
                                         style="height:100%; width:0; background:#10B981; border-radius:10px; transition: width 1.2s cubic-bezier(0.16, 1, 0.3, 1);"></div>
                                </div>
                            </div>
                            <div style="font-size: 0.7rem; color: #ccc; transition: transform 0.3s; transform:${isExpanded ? 'rotate(180deg)' : 'rotate(0deg)'};" class="chevron-icon">
                                <i class="fas fa-chevron-down"></i>
                            </div>
                        </div>

                        <div id="${detailsId}" class="leaderboard-details" style="max-height:${isExpanded ? '150px' : '0'}; opacity:${isExpanded ? '1' : '0'}; transition: all 0.4s ease; padding-top:${isExpanded ? '5px' : '0'}; pointer-events: none;">
                            <div style="display:flex; flex-wrap: wrap; gap:10px; margin-top:15px; padding-top:15px; border-top:1px dashed #eee;">
                                <div style="flex:1; min-width: 90px; background:#EFF6FF; padding:10px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                                    <div style="width:28px; height:28px; background:#3B82F6; color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.75rem; flex-shrink:0;">
                                        <i class="fas fa-mars"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div style="font-size:0.6rem; color:#60A5FA; font-weight:700; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Males</div>
                                        <div style="font-size:0.9rem; font-weight:800; color:#1E40AF;">${food.male}</div>
                                    </div>
                                </div>
                                <div style="flex:1; min-width: 90px; background:#FFF1F2; padding:10px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                                    <div style="width:28px; height:28px; background:#FB7185; color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.75rem; flex-shrink:0;">
                                        <i class="fas fa-venus"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div style="font-size:0.6rem; color:#FDA4AF; font-weight:700; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Females</div>
                                        <div style="font-size:0.9rem; font-weight:800; color:#9F1239;">${food.female}</div>
                                    </div>
                                </div>
                                <div style="flex:1; min-width: 90px; background:#F3F4F6; padding:10px; border-radius:8px; display:flex; align-items:center; gap:8px;">
                                    <div style="width:28px; height:28px; background:#9CA3AF; color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.75rem; flex-shrink:0;">
                                        <i class="fas fa-user-slash"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div style="font-size:0.6rem; color:#9CA3AF; font-weight:700; text-transform:uppercase; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Private</div>
                                        <div style="font-size:0.9rem; font-weight:800; color:#374151;">${food.other}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>`;
            });

            listHtml += `</div>`;

            // 🟢 PHASE 2: ATOMIC UI UPDATE
            requestAnimationFrame(() => {
                container.innerHTML = listHtml;
                container.style.height = targetHeight + 'px';
                container.style.maxHeight = targetHeight + 'px';
                container.style.overflowY = 'auto';
                container.style.paddingRight = '8px';

                // Trigger bars
                setTimeout(() => {
                    const bars = container.querySelectorAll('.food-progress-bar');
                    bars.forEach(bar => bar.style.width = bar.dataset.width);
                }, 50);
            });
        }

        // --- 8.5 RENDER DATE LEADERBOARD ---
        function renderDateLeaderboard(data) {
            const container = document.getElementById('barChartContainer');
            if (!container) return;

            // 🟢 PHASE 1: PRE-CALCULATE DATA
            if (!Array.isArray(data)) {
                container.innerHTML = `<div style="text-align:center; padding:40px; color:#999;">No data available.</div>`;
                return;
            }

            let displayData = data;
            if (leaderboardSearchQuery) {
                displayData = data.filter(item =>
                    item.name && item.name.toLowerCase().includes(leaderboardSearchQuery)
                );
            }

            const dataString = JSON.stringify(displayData);
            if (container.dataset.currentDateData === dataString && container.querySelector('.date-row-item')) {
                return;
            }
            container.dataset.currentDateData = dataString;

            let targetHeight = container.dataset.lastChartHeight || 350;
            if (targetHeight < 200) targetHeight = 350;

            if (!displayData || displayData.length === 0) {
                container.innerHTML = `<div style="text-align:center; padding:40px; color:#999;">${leaderboardSearchQuery ? 'No dates match your search.' : 'No booked dates found.'}</div>`;
                return;
            }
            // Safe calculation of maxCount
            const counts = data.map(d => d.count);
            const maxCount = (counts.length > 0) ? Math.max(...counts) : 1;

            let listHtml = `<div style="display:block; width:100%; padding:5px 0;">`;

            displayData.forEach((item, i) => {
                const pct = (item.count / maxCount) * 100;
                const rank = data.indexOf(item) + 1;

                let rCol = '#6B7280', rBg = '#F3F4F6';
                if (rank === 1) { rCol = '#B88E2F'; rBg = '#FFF8E1'; }
                else if (rank === 2) { rCol = '#4B5563'; rBg = '#F9FAFB'; }
                else if (rank === 3) { rCol = '#92400E'; rBg = '#FFFBEB'; }

                listHtml += `
                    <div class="leaderboard-row date-row-item" 
                         style="display:flex; align-items:center; gap:15px; background:#fff; padding:12px; border-radius:12px; border:1px solid #f0f0f0; margin-bottom:10px; transition: all 0.3s ease;">
                        
                        <div style="width:32px; height:32px; background:${rank <= 3 ? rBg : '#F3F4F6'}; color:${rank <= 3 ? rCol : '#6B7280'}; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:0.85rem; flex-shrink:0;">
                            ${rank}
                        </div>
                        <div style="flex:1; min-width:0;">
                            <div style="display:flex; justify-content:space-between; margin-bottom:6px; align-items:center;">
                                <span style="font-weight:700; color:#333; font-size:0.9rem;">${item.name}</span>
                                <span style="font-weight:800; color:#B88E2F; font-size:0.95rem;">${item.count} <small style="font-size:0.7rem; color:#999;">bookings</small></span>
                            </div>
                            <div style="height:6px; background:#f0f0f0; border-radius:10px; overflow:hidden;">
                                <div class="date-progress-bar" data-width="${pct}%" 
                                     style="height:100%; width:0; background:${rank === 1 ? '#B88E2F' : '#D1D5DB'}; border-radius:10px; transition: width 1.2s cubic-bezier(0.16, 1, 0.3, 1);"></div>
                            </div>
                        </div>
                    </div>`;
            });

            listHtml += `</div>`;
            container.innerHTML = listHtml;

            setTimeout(() => {
                const bars = container.querySelectorAll('.date-progress-bar');
                bars.forEach(bar => bar.style.width = bar.dataset.width);
            }, 50);
        }

        // --- 8.5 TOGGLE FOOD DETAILS ---
        function toggleFoodLeaderboardDetails(detailsId, rowId, foodName) {
            const details = document.getElementById(detailsId);
            const row = document.getElementById(rowId);
            const chevron = row.querySelector('.chevron-icon');

            const isOpen = details.style.maxHeight !== '0px' && details.style.maxHeight !== '';

            // Accordion: Close others
            if (!isOpen) {
                const allRows = document.querySelectorAll('.food-row-item');
                allRows.forEach(r => {
                    const d = r.querySelector('.leaderboard-details');
                    const c = r.querySelector('.chevron-icon');
                    if (d && d.id !== detailsId && (d.style.maxHeight !== '0px' && d.style.maxHeight !== '')) {
                        d.style.maxHeight = '0px';
                        d.style.opacity = '0';
                        d.style.paddingTop = '0px';
                        r.style.background = '#fff';
                        r.style.boxShadow = 'none';
                        if (c) c.style.transform = 'rotate(0deg)';
                    }
                });
            }

            if (isOpen) {
                details.style.maxHeight = '0px';
                details.style.opacity = '0';
                details.style.paddingTop = '0px';
                row.style.background = '#fff';
                row.style.boxShadow = 'none';
                chevron.style.transform = 'rotate(0deg)';
                expandedLeaderboardFood = null;
            } else {
                details.style.maxHeight = '150px';
                details.style.opacity = '1';
                details.style.paddingTop = '5px';
                row.style.background = '#fafafa';
                row.style.boxShadow = '0 4px 12px rgba(0,0,0,0.05)';
                chevron.style.transform = 'rotate(180deg)';
                expandedLeaderboardFood = foodName;
            }
        }

        // --- 5. FETCH CARDS (Updated for Overall/Custom) ---

        /* [INFO] HELPER: CUSTOM FLATPICKR HEADER INJECTION (Flexible Labels/Dropdowns) */
        function initCustomFpHeader(instance, options = { showDropdowns: false }) {
            const container = instance.calendarContainer;
            const monthHeader = container.querySelector('.flatpickr-months');
            if (!monthHeader || container.querySelector('.custom-fp-header')) return;

            // 1. Create Header Container
            const customHeader = document.createElement('div');
            customHeader.className = 'custom-fp-header';

            // 2. Combined Label Structure
            const isMonthOnly = container.classList.contains('flatpickr-monthSelect-theme');
            
            if (isMonthOnly) {
                // Just Year for Month-only picker (Analytics)
                const yearLabel = document.createElement('div');
                yearLabel.className = 'custom-fp-label-pill year-label';
                yearLabel.textContent = instance.currentYear;
                customHeader.appendChild(yearLabel);
            } else {
                // Month and Year for standard pickers
                const monthPill = document.createElement('div');
                monthPill.className = `custom-fp-label-pill month-label ${options.showDropdowns ? 'clickable' : ''}`;
                monthPill.innerHTML = `<span>${instance.l10n.months.longhand[instance.currentMonth]}</span> ${options.showDropdowns ? '<i class="fas fa-chevron-down" style="font-size:0.6rem; margin-left:5px;"></i>' : ''}`;
                
                const yearPill = document.createElement('div');
                yearPill.className = `custom-fp-label-pill year-label ${options.showDropdowns ? 'clickable' : ''}`;
                yearPill.innerHTML = `<span>${instance.currentYear}</span> ${options.showDropdowns ? '<i class="fas fa-chevron-down" style="font-size:0.6rem; margin-left:5px;"></i>' : ''}`;

                customHeader.appendChild(monthPill);
                customHeader.appendChild(yearPill);

                if (options.showDropdowns) {
                    // Create Dropdowns for Month
                    const monthDropdown = document.createElement('div');
                    monthDropdown.className = 'custom-fp-dropdown month-dropdown';
                    instance.l10n.months.longhand.forEach((m, i) => {
                        const opt = document.createElement('div');
                        opt.className = `custom-fp-option ${i === instance.currentMonth ? 'selected' : ''}`;
                        opt.textContent = m;
                        opt.onclick = (e) => {
                            e.stopPropagation();
                            instance.changeMonth(i);
                            monthPill.querySelector('span').textContent = m;
                            monthDropdown.classList.remove('open');
                            monthPill.classList.remove('open');
                            updateDropdownSelections(instance);
                        };
                        monthDropdown.appendChild(opt);
                    });
                    container.appendChild(monthDropdown);

                    monthPill.onclick = (e) => {
                        e.stopPropagation();
                        const isOpen = monthDropdown.classList.contains('open');
                        closeAllFpDropdowns(container);
                        if (!isOpen) {
                            monthDropdown.classList.add('open');
                            monthPill.classList.add('open');
                            
                            // [INFO] FIX: Better relative positioning
                            const rect = monthPill.getBoundingClientRect();
                            const contRect = container.getBoundingClientRect();
                            monthDropdown.style.top = (rect.bottom - contRect.top + 5) + 'px';
                            monthDropdown.style.left = (rect.left - contRect.left) + 'px';
                        }
                    };

                    // Create Dropdown for Year
                    const yearDropdown = document.createElement('div');
                    yearDropdown.className = 'custom-fp-dropdown year-dropdown';
                    
                    // [INFO] FIX: Larger range and specific scrolling for years
                    const currentYear = new Date().getFullYear();
                    const startYear = currentYear - 100; // Better for Birthdates
                    const endYear = currentYear + 5;
                    
                    for (let y = endYear; y >= startYear; y--) {
                        const opt = document.createElement('div');
                        opt.className = `custom-fp-option ${y === instance.currentYear ? 'selected' : ''}`;
                        opt.textContent = y;
                        opt.onclick = (e) => {
                            e.stopPropagation();
                            instance.changeYear(y);
                            yearPill.querySelector('span').textContent = y;
                            yearDropdown.classList.remove('open');
                            yearPill.classList.remove('open');
                            updateDropdownSelections(instance);
                        };
                        yearDropdown.appendChild(opt);
                    }
                    container.appendChild(yearDropdown);

                    yearPill.onclick = (e) => {
                        e.stopPropagation();
                        const isOpen = yearDropdown.classList.contains('open');
                        closeAllFpDropdowns(container);
                        if (!isOpen) {
                            yearDropdown.classList.add('open');
                            yearPill.classList.add('open');
                            
                            // [INFO] FIX: Better relative positioning
                            const rect = yearPill.getBoundingClientRect();
                            const contRect = container.getBoundingClientRect();
                            yearDropdown.style.top = (rect.bottom - contRect.top + 5) + 'px';
                            yearDropdown.style.left = (rect.left - contRect.left) + 'px';

                            // Scroll to selected year
                            setTimeout(() => {
                                const selected = yearDropdown.querySelector('.selected');
                                if (selected) selected.scrollIntoView({ block: 'center' });
                            }, 10);
                        }
                    };

                    document.addEventListener('click', (e) => {
                        if (!container.contains(e.target)) closeAllFpDropdowns(container);
                    });
                }
            }

            monthHeader.appendChild(customHeader);
        }

        function closeAllFpDropdowns(container) {
            container.querySelectorAll('.custom-fp-dropdown').forEach(d => d.classList.remove('open'));
            container.querySelectorAll('.custom-fp-label-pill').forEach(p => p.classList.remove('open'));
        }

        function updateDropdownSelections(instance) {
            const container = instance.calendarContainer;
            const monthLabel = container.querySelector('.month-label span') || container.querySelector('.month-label');
            const yearLabel = container.querySelector('.year-label span') || container.querySelector('.year-label');
            
            if (monthLabel) monthLabel.textContent = instance.l10n.months.longhand[instance.currentMonth];
            if (yearLabel) yearLabel.textContent = instance.currentYear;

            // Sync Dropdown Highlight
            container.querySelectorAll('.month-dropdown .custom-fp-option').forEach((opt, i) => {
                opt.classList.toggle('selected', i === instance.currentMonth);
            });
            container.querySelectorAll('.year-dropdown .custom-fp-option').forEach((opt) => {
                opt.classList.toggle('selected', parseInt(opt.textContent) === instance.currentYear);
            });
        }

        // [INFO] SEARCHABLE TIME DROPDOWN LOGIC
        function initSearchableTimeDropdown() {
            const timeInput = document.getElementById('eventTimeInput');
            const optionsList = document.getElementById('eventTimeOptions');
            if (!timeInput || !optionsList) return;

            // 1. Generate Times (6 AM to 11:30 PM)
            const times = [];
            for (let h = 6; h < 24; h++) {
                for (let m = 0; m < 60; m += 30) {
                    let hour = h > 12 ? h - 12 : h;
                    if (hour === 0) hour = 12;
                    let ampm = h >= 12 ? 'PM' : 'AM';
                    let timeStr = `${hour.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')} ${ampm}`;
                    times.push(timeStr);
                }
            }

            // 2. Render initial list
            function renderOptions(filter = "") {
                optionsList.innerHTML = '';
                const filtered = times.filter(t => t.toLowerCase().includes(filter.toLowerCase()));
                
                if (filtered.length === 0) {
                    optionsList.innerHTML = '<div style="padding:10px; color:#999; font-size:0.8rem; text-align:center;">No match found</div>';
                    return;
                }

                filtered.forEach(t => {
                    const item = document.createElement('div');
                    item.className = 'time-option-item';
                    item.textContent = t;
                    item.onclick = () => {
                        timeInput.value = t;
                        optionsList.style.display = 'none';
                    };
                    optionsList.appendChild(item);
                });
            }

            // 3. Events
            timeInput.onfocus = () => {
                optionsList.style.display = 'block';
                renderOptions(timeInput.value);
            };

            timeInput.oninput = () => {
                renderOptions(timeInput.value);
            };

            // Close on outside click
            document.addEventListener('click', (e) => {
                if (!timeInput.parentElement.contains(e.target)) {
                    optionsList.style.display = 'none';
                }
            });
        }

        // Call on load
        document.addEventListener("DOMContentLoaded", initSearchableTimeDropdown);

        function toggleMonthInput(val) {
            const wrapper = document.getElementById('customMonthWrapper');
            if (val === 'custom') {
                wrapper.style.display = 'block';
                // Small delay to allow display:block to take effect before animating
                setTimeout(() => {
                    wrapper.style.opacity = '1';
                    wrapper.style.transform = 'translateX(0)';
                }, 10);

                // Initialize Flatpickr if not already done
                if (!document.getElementById('customMonthInput')._flatpickr) {
                    flatpickr("#customMonthInput", {
                        plugins: [
                            new monthSelectPlugin({
                                shorthand: true,
                                dateFormat: "Y-m",
                                altFormat: "F Y",
                                theme: "light"
                            })
                        ],
                        defaultDate: new Date().toISOString().slice(0, 7),
                        onChange: function (selectedDates, dateStr) {
                            fetchDashboardCards();
                        },
                        onReady: function (selectedDates, dateStr, instance) {
                            instance.calendarContainer.classList.add("compact-theme");
                            initCustomFpHeader(instance);
                        },
                        onMonthChange: function (selectedDates, dateStr, instance) {
                            updateDropdownSelections(instance);
                        },
                        onYearChange: function (selectedDates, dateStr, instance) {
                            updateDropdownSelections(instance);
                        }
                    });
                }
            } else {
                wrapper.style.opacity = '0';
                wrapper.style.transform = 'translateX(-10px)';
                setTimeout(() => {
                    wrapper.style.display = 'none';
                }, 300);
            }
        }

        function toggleOverviewSkeletons(show) {
            const cardIds = ['guests', 'revenue', 'occupancy', 'pending', 'orders'];
            cardIds.forEach(id => {
                const card = document.getElementById(`card_${id}`);
                if (!card) return;

                const value = card.querySelector('.stat-value');
                const label = card.querySelector('.stat-label');
                const skeletons = card.querySelectorAll('.skeleton');

                if (show) {
                    if (value) value.style.display = 'none';
                    if (label) label.style.display = 'none';
                    skeletons.forEach(s => s.style.display = 'block');
                } else {
                    if (value) value.style.display = 'block';
                    if (label) label.style.display = 'block';
                    skeletons.forEach(s => s.style.display = 'none');
                }
            });

            // 🟢 Restore Chart Skeletons
            const pieSkeleton = document.getElementById('roomStatsSkeleton');
            if (pieSkeleton) pieSkeleton.style.display = show ? 'block' : 'none';

            const revSkeleton = document.getElementById('revenueChartSkeleton');
            if (revSkeleton) revSkeleton.style.display = show ? 'block' : 'none';
        }

        function fetchDashboardCards(isSilent = true) {
            const picker = document.getElementById('dashboardMonthPicker');
            let selectedDate = picker ? picker.value : 'overall';

            if (selectedDate === 'custom') {
                const monthInput = document.getElementById('customMonthInput');
                selectedDate = monthInput.value || new Date().toISOString().slice(0, 7);
            }

            // 🟢 SHOW SKELETONS ONLY IF NOT SILENT
            if (!isSilent) {
                toggleOverviewSkeletons(true);
            }

            return fetch(`get_dashboard_stats.php?date=${selectedDate}&_t=${new Date().getTime()}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        // 🟢 PHASE 1: RENDER STATS CARDS IMMEDIATELY (Lightweight)
                        if (document.getElementById('stat_guests')) document.getElementById('stat_guests').innerHTML = data.guests;
                        if (document.getElementById('stat_revenue')) document.getElementById('stat_revenue').innerHTML = '₱' + data.revenue;
                        if (document.getElementById('stat_occupancy')) document.getElementById('stat_occupancy').innerHTML = data.occupancy + '%';
                        if (document.getElementById('stat_orders')) document.getElementById('stat_orders').innerHTML = data.kitchen_orders;
                        if (document.getElementById('stat_pending')) document.getElementById('stat_pending').innerHTML = data.pending;

                        // 2. Update Labels dynamically
                        const labelType = (picker.value === 'overall') ? 'Overall' : 'Monthly';
                        if (document.getElementById('label_guests')) document.getElementById('label_guests').innerText = (picker.value === 'overall') ? 'Total Successful Bookings (Cumulative)' : 'Total Successful Bookings (Monthly)';
                        if (document.getElementById('label_revenue')) document.getElementById('label_revenue').innerText = labelType + ' Revenue';
                        if (document.getElementById('label_occupancy')) document.getElementById('label_occupancy').innerText = labelType + ' Occupancy';

                        // 🟢 PHASE 2: PIE CHART (400ms)
                        setTimeout(() => {
                            if (window.myBookingPieChart) {
                                window.myBookingPieChart.data.datasets[0].data = data.pie_data;
                                window.myBookingPieChart.update({
                                    duration: 1000,
                                    easing: 'easeOutQuart'
                                });
                            }

                            // 🟢 PHASE 2.5: LEADERBOARDS (600ms)
                            // We delay leaderboards slightly more to let the pie chart start its animation
                            setTimeout(() => {
                                currentChartData.leaderboard = data.room_leaderboard;
                                currentChartData.food = data.food_leaderboard;
                                currentChartData.date = data.date_leaderboard;
                                currentChartData.totalDateCount = data.total_date_count;

                                if (chartViewMode === 'leaderboard') {
                                    renderRoomLeaderboard(data.room_leaderboard);
                                } else if (chartViewMode === 'food') {
                                    renderFoodLeaderboard(data.food_leaderboard);
                                } else if (chartViewMode === 'date') {
                                    renderDateLeaderboard(data.date_leaderboard);
                                }

                                const counts = data.pie_data;
                                const total = counts.reduce((a, b) => a + b, 0);
                                const cPct = total > 0 ? Math.round((counts[0] / total) * 100) : 0;
                                const nPct = total > 0 ? Math.round((counts[1] / total) * 100) : 0;
                                const aPct = total > 0 ? Math.round((counts[2] / total) * 100) : 0;

                                if (document.getElementById('prog_text_complete')) document.getElementById('prog_text_complete').textContent = cPct + '%';
                                if (document.getElementById('prog_text_noshow')) document.getElementById('prog_text_noshow').textContent = nPct + '%';
                                if (document.getElementById('prog_text_cancelled')) document.getElementById('prog_text_cancelled').textContent = aPct + '%';

                                if (document.getElementById('prog_bar_complete')) document.getElementById('prog_bar_complete').style.width = cPct + '%';
                                if (document.getElementById('prog_bar_noshow')) document.getElementById('prog_bar_noshow').style.width = nPct + '%';
                                if (document.getElementById('prog_bar_cancelled')) document.getElementById('prog_bar_cancelled').style.width = aPct + '%';
                            }, 200);

                            // 🟢 PHASE 3: LINE GRAPH (900ms)
                            setTimeout(() => {
                                // [FIX] Only render the revenue chart if we are actually in revenue mode.
                                // Otherwise, it overwrites the leaderboard HTML list.
                                if (chartViewMode === 'revenue') {
                                    renderRevenueChart(currentChartData.revenue);
                                }
                                
                                // 🟢 HIDE SKELETONS ONLY AFTER LAST PHASE
                                toggleOverviewSkeletons(false);
                            }, 500); 
                        }, 400); 
                    }
                })
                .catch(err => {
                    console.error("Error updating dashboard:", err);
                    toggleOverviewSkeletons(false); // Hide on error too
                });
        }

        // Add this to your dashboard.php JavaScript section
        document.addEventListener("DOMContentLoaded", function () {
            const monthPicker = document.getElementById('dashboardMonthPicker');
            if (monthPicker) {
                monthPicker.addEventListener('change', function () {
                    console.log("Month changed to:", this.value);
                    fetchDashboardCards();
                });
            }
        });

        document.addEventListener("DOMContentLoaded", function () {
            const logModal = document.getElementById('logoutModal');
            const logBtn = document.getElementById('logoutBtn');
            const confirm = document.getElementById('confirmLogout');
            const cancel = document.getElementById('cancelLogout');

            // Open with a slight bounce effect
            if (logBtn) {
                logBtn.onclick = (e) => {
                    e.preventDefault();
                    logModal.style.display = 'block';
                };
            }

            // Standard Actions
            if (confirm) confirm.onclick = () => window.location.href = 'logout.php';
            if (cancel) cancel.onclick = () => logModal.style.display = 'none';

            // Global Close
            window.addEventListener('click', (e) => {
                if (e.target === logModal) logModal.style.display = 'none';
            });
        });

        // --- 3. CALENDAR LOGIC ---
        const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
        let viewDate = new Date();


        // --- DYNAMIC ROOMS FROM DB ---

        const totalRooms = allRoomsList.length; // Count varies based on DB


        // --- RENDER CALENDAR (Main Function) ---
        function renderRealtimeCalendar(isLoading = false) {
            // 1. Get accurate time variables
            const manilaTime = new Date().toLocaleString("en-US", { timeZone: "Asia/Manila" });
            const now = new Date(manilaTime);
            const yearNow = now.getFullYear();
            const monthNow = String(now.getMonth() + 1).padStart(2, '0');
            const dayNow = String(now.getDate()).padStart(2, '0');
            const todayStr = `${yearNow}-${monthNow}-${dayNow}`;

            const year = viewDate.getFullYear();
            const month = viewDate.getMonth();

            document.getElementById('currentMonthYear').innerText = `${monthNames[month]} ${year}`;

            // Disable Prev Button if in current month
            const isCurrentRealtimeMonth = (year === now.getFullYear() && month === now.getMonth());
            const isFutureMonth = (year > now.getFullYear()) || (year === now.getFullYear() && month > now.getMonth());
            document.getElementById('prevMonthBtn').disabled = (!isFutureMonth && isCurrentRealtimeMonth);

            // Get Active Room Count
            const activeTotalRooms = allRoomsList.filter(r => r.is_active == 1).length;

            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const grid = document.getElementById('calendarRealtimeGrid');
            grid.innerHTML = "";

            // Empty cells
            for (let i = 0; i < firstDay; i++) {
                const cell = document.createElement('div');
                cell.className = 'cal-cell other-month';
                grid.appendChild(cell);
            }

            // Days Loop
            for (let i = 1; i <= daysInMonth; i++) {
                const cell = document.createElement('div');
                cell.className = 'cal-cell';

                // Number
                const numSpan = document.createElement('div');
                numSpan.className = 'cal-cell-number';
                
                const currentMonthVal = String(month + 1).padStart(2, '0');
                const currentDayVal = String(i).padStart(2, '0');
                const dStr = `${year}-${currentMonthVal}-${currentDayVal}`;

                if (isLoading) {
                    // 🟢 RENDER SKELETONS FOR BOTH DATE AND STATUS
                    numSpan.classList.add('skeleton');
                    cell.appendChild(numSpan);

                    const statsDiv = document.createElement('div');
                    statsDiv.className = 'cal-stats';
                    statsDiv.innerHTML = `
                        <div class="status-row" style="margin-bottom: 4px; margin-top: 8px;">
                            <div class="skeleton-dot skeleton"></div>
                            <div class="skeleton-row skeleton" style="width: 65%; height: 8px;"></div>
                        </div>
                        <div class="status-row">
                            <div class="skeleton-dot skeleton"></div>
                            <div class="skeleton-row skeleton" style="width: 45%; height: 8px;"></div>
                        </div>
                    `;
                    cell.appendChild(statsDiv);
                    grid.appendChild(cell);
                    continue;
                }

                numSpan.innerText = i;
                cell.appendChild(numSpan);

                // 🟢 CRITICAL FIX START: Check for Past Dates 🟢

                if (dStr < todayStr) {
                    // IF PAST: Disable it and DO NOT add onclick
                    cell.classList.add('disabled-date');
                    cell.style.opacity = '0.5';
                    cell.style.cursor = 'not-allowed';
                    // Notice: No cell.onclick here!
                }
                else {
                    // IF TODAY OR FUTURE: Apply logic and clicks

                    // Highlight Today
                    if (dStr === todayStr) {
                        cell.classList.add('is-today');
                    }

                    const dayData = bookingsDB[dStr] || [];
                    // Only count relevant booking types
                    const bookedCount = dayData.filter(b => b.type === 'in_house' || b.type === 'future').length;
                    let labelText = "";

                    // Check Capacity
                    if (bookedCount >= activeTotalRooms && activeTotalRooms > 0) {
                        // FULLY BOOKED (Red Style)
                        cell.classList.add('status-full');
                        labelText = "FULLY BOOKED";
                    }
                    else {
                        // AVAILABLE (Show Stats)
                        let inHouseCount = 0;
                        let reservedCount = 0;

                        dayData.forEach(b => {
                            if (b.type === 'in_house') inHouseCount++;
                            if (b.type === 'future') reservedCount++;
                        });

                        const statsDiv = document.createElement('div');
                        statsDiv.className = 'cal-stats';

                        if (inHouseCount > 0) {
                            statsDiv.innerHTML += `<div class="status-row"><span class="status-dot dot-occupied"></span> ${inHouseCount} In-House</div>`;
                        }
                        if (reservedCount > 0) {
                            statsDiv.innerHTML += `<div class="status-row"><span class="status-dot dot-reserved"></span> ${reservedCount} Reserved</div>`;
                        }
                        cell.appendChild(statsDiv);
                    }

                    // Add Pill if Full
                    if (labelText) {
                        const pill = document.createElement('div');
                        pill.className = 'status-pill';
                        pill.innerText = labelText;
                        cell.appendChild(pill);
                    }

                    // Add Click Handler (Only for active dates)
                    cell.onclick = function () {
                        openRoomModal(dStr, dayData);
                    };
                }
                // [INFO] CRITICAL FIX END [INFO]

                grid.appendChild(cell);
            }
        }

        // Helper function to format date like "Tue 29 April"
        function formatModalDate(dateStr) {
            const options = { weekday: 'short', day: 'numeric', month: 'long' };
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-GB', options).replace(',', '');
        }

        function openRoomModal(dateStr, dayBookings) {
            // 1. Format Title neatly
            const dateObj = new Date(dateStr);
            const titleDate = dateObj.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });

            // Update Header Text
            document.getElementById('calendarModalTitle').innerText = titleDate;

            const body = document.getElementById('calendarModalBody');
            body.innerHTML = '';

            // [INFO] ADD FILTER BUTTONS
            const filterContainer = document.createElement('div');
            filterContainer.style.cssText = "display:flex; gap:10px; padding: 15px 20px; background:#f8fafc; border-bottom:1px solid #eee; position:sticky; top:0; z-index:10;";

            filterContainer.innerHTML = `
                <button class="modal-filter-btn active" data-filter="all" onclick="filterModalRooms('all')">All</button>
                <button class="modal-filter-btn" data-filter="available" onclick="filterModalRooms('available')">Available</button>
                <button class="modal-filter-btn" data-filter="occupied" onclick="filterModalRooms('occupied')">Reserved/Occupied</button>
            `;
            body.appendChild(filterContainer);

            // Create a container for the list (removes default padding issues)
            const listContainer = document.createElement('div');
            listContainer.className = 'room-list-container';
            listContainer.id = 'modalRoomList'; // ID for filtering

            // 2. Loop through rooms
            allRoomsList.forEach((room, index) => {
                // Skip hidden rooms
                if (room.is_active == 0) return;

                const roomId = room.id;
                const roomName = room.name; // e.g. "Room 201"
                const cleanName = roomName.replace('Room', '').replace('ROOM', '').trim(); // Extract just number if possible

                // Check status
                const booking = dayBookings.find(b => b.room_id == roomId);

                let themeClass = 'theme-available';
                let statusLabel = 'Available';
                let mainText = 'Ready for Booking'; // Default text for available
                let detailText = 'Vacant';
                let filterType = 'available'; // For filter logic

                if (booking) {
                    mainText = booking.guest; // Show Guest Name
                    filterType = 'occupied';

                    // Format dates
                    const checkOutFormatted = new Date(booking.check_out).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });

                    if (booking.type === 'in_house') {
                        themeClass = 'theme-occupied';
                        statusLabel = 'Occupied';
                        detailText = `Until ${checkOutFormatted}`;
                    } else {
                        themeClass = 'theme-reserved';
                        statusLabel = 'Reserved';
                        detailText = `Arriving`;
                    }
                }

                // 3. Build the Card HTML
                const card = document.createElement('div');
                card.className = `room-status-card ${themeClass}`;
                card.dataset.status = filterType; // Attach filter data

                card.innerHTML = `
                    <div class="room-id-box">
                        <span class="room-id-label">Room</span>
                        ${cleanName}
                    </div>
                    
                    <div class="room-info-area">
                        <div class="room-guest-name">${mainText}</div>
                        <div class="room-status-detail">${detailText}</div>
                    </div>
                    
                    <div class="status-badge-pill">
                        ${statusLabel}
                    </div>
                `;

                listContainer.appendChild(card);
            });

            body.appendChild(listContainer);

            // Show Modal
            document.getElementById('calendarModal').style.display = 'block';

            // [INFO] STAGGERED ENTRANCE (Immediate, like Most Booked Dates)
            const cards = listContainer.querySelectorAll('.room-status-card');
            requestAnimationFrame(() => {
                cards.forEach((card, index) => {
                    setTimeout(() => {
                        card.classList.add('show-anim');
                    }, index * 40); // Standard 40ms stagger to match Most Booked Dates
                });
            });
        }

        // [INFO] FILTER LOGIC FOR MODAL (FINAL SMOOTH VERSION)
        function filterModalRooms(type) {
            const list = document.getElementById('modalRoomList');
            const modalContent = document.querySelector('.modal-content-calendar');
            if (!list || !modalContent) return;

            const cards = list.querySelectorAll('.room-status-card');
            const buttons = document.querySelectorAll('.modal-filter-btn');

            // 1. Capture and LOCK current height immediately
            const startHeight = modalContent.getBoundingClientRect().height;
            modalContent.style.height = startHeight + 'px';
            modalContent.style.transition = 'none'; // Disable any current transitions
            modalContent.style.overflow = 'hidden';

            // 2. Update Filter Buttons UI
            buttons.forEach(btn => {
                btn.classList.toggle('active', btn.dataset.filter === type);
            });

            // 3. Filter the cards (This happens while height is LOCKED)
            cards.forEach(card => {
                card.classList.remove('show-anim');
                card.style.display = (type === 'all' || card.dataset.status === type) ? 'flex' : 'none';
            });

            // 4. Measure the new "Auto" height
            modalContent.style.height = 'auto';
            const endHeight = modalContent.getBoundingClientRect().height;
            modalContent.style.height = startHeight + 'px';

            // 5. Trigger the Animation
            requestAnimationFrame(() => {
                modalContent.style.transition = 'height 0.4s cubic-bezier(0.165, 0.84, 0.44, 1)';
                modalContent.style.height = endHeight + 'px';

                // Stagger card entrance (Only for visible ones)
                let visibleIndex = 0;
                cards.forEach(card => {
                    if (card.style.display === 'flex') {
                        setTimeout(() => {
                            card.classList.add('show-anim');
                        }, visibleIndex * 40);
                        visibleIndex++;
                    }
                });
            });

            // 6. Cleanup after animation finishes
            const cleanup = (e) => {
                if (e.propertyName === 'height') {
                    modalContent.style.height = 'auto'; // Release lock
                    modalContent.style.overflow = 'visible';
                    modalContent.removeEventListener('transitionend', cleanup);
                }
            };
            modalContent.addEventListener('transitionend', cleanup);
        }


        // --- REPLACE YOUR CURRENT PREV/NEXT LISTENERS WITH THIS ---

        document.getElementById('prevMonthBtn').addEventListener('click', (e) => {
            e.preventDefault(); // Stop any default link behavior

            // 1. Update the local date object state
            viewDate.setMonth(viewDate.getMonth() - 1);

            // 2. Call your existing AJAX function to fetch new data without reloading
            refreshCalendarData();
        });

        document.getElementById('nextMonthBtn').addEventListener('click', (e) => {
            e.preventDefault(); // Stop any default link behavior

            // 1. Update the local date object state
            viewDate.setMonth(viewDate.getMonth() + 1);

            // 2. Call your existing AJAX function to fetch new data without reloading
            refreshCalendarData();
        });

        document.getElementById('closeCalendarModal').onclick = () => document.getElementById('calendarModal').style.display = 'none';

        renderRealtimeCalendar();

        // --- IMPROVED FILTER & SORT LOGIC ---
        let currentTabStatus = 'today';
        let bookingLimit = 100;
        let bookingOffset = 0;
        let foodLimit = 100;
        let foodOffset = 0;

        function filterTable(filterType) {
            currentTabStatus = filterType;
            bookingOffset = 0; // Reset pagination

            // 1. Update Tab Styling
            const buttons = document.querySelectorAll('.tab-btn');
            buttons.forEach(btn => {
                if (btn.getAttribute('data-target') === filterType) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            // 2. Refresh Table from Server
            refreshBookingTable(true);
        }

        // 3. Initialize on Load
        document.addEventListener("DOMContentLoaded", function () {
            // Set default tab to 'today'
            filterTable('today');

            // Listen for typing
            const searchInput = document.getElementById('bookingSearchInput');
            if (searchInput) {
                searchInput.addEventListener('keyup', function () {
                    bookingOffset = 0; // Reset when searching
                    refreshBookingTable(true);
                });
            }
        });

        // --- 4. ADD BOOKING MODAL LOGIC & SAVING ---

        // A. RESET FUNCTION (Clears everything)
        function resetModal() {
            // 1. Reset standard form fields
            document.getElementById('addBookingForm').reset();

            // 2. Clear Flatpickr Date Inputs (Your existing code)
            const checkinInput = document.getElementById('checkin_picker');
            const checkoutInput = document.getElementById('checkout_picker');
            const birthPicker = document.getElementById('birthdate_picker');
            if (checkinInput._flatpickr) checkinInput._flatpickr.clear();
            if (checkoutInput._flatpickr) checkoutInput._flatpickr.clear();
            if (birthPicker && birthPicker._flatpickr) birthPicker._flatpickr.clear();



            // 3. Reset Wizard Steps (Your existing code)
            currentStep = 1;
            document.querySelectorAll('.ab-step').forEach(step => step.classList.remove('active'));
            document.getElementById('ab-step-1').classList.add('active');
            document.getElementById('abModalTitle').innerText = "Step 1: Select Dates";

            // 4. Clear Selected Rooms (Your existing code)
            selectedRooms = [];
            document.getElementById('roomSelectionContainer').innerHTML = '';

            // [INFO] 5. NEW: RESET CUSTOM SELECT VISUALS [INFO]
            document.querySelectorAll('.custom-select-wrapper').forEach(wrapper => {
                const triggerSpan = wrapper.querySelector('.custom-select-trigger span');
                const options = wrapper.querySelectorAll('.custom-option');

                // Reset text to default (usually "- Select -" or the first option)
                // We find the corresponding hidden select relative to the wrapper
                const hiddenSelect = wrapper.previousElementSibling;
                if (hiddenSelect && hiddenSelect.tagName === 'SELECT') {
                    // Set trigger to the first option's text
                    triggerSpan.textContent = hiddenSelect.options[0].text;
                }

                // Remove 'selected' class from all options
                options.forEach(opt => opt.classList.remove('selected'));
            });
        }

        // B. VALIDATION FUNCTION
        // Checks required fields before showing Confirmation Modal
        function validateAndReview() {
            const form = document.getElementById('addBookingForm');

            // Manual check for hidden but required fields (Custom Selects)
            const requiredFields = form.querySelectorAll('[required]');
            let firstInvalid = null;

            requiredFields.forEach(field => {
                if (!field.value || field.value.trim() === "") {
                    if (!firstInvalid) firstInvalid = field;
                    field.classList.add('invalid-input'); // Optional: for CSS styling
                } else {
                    field.classList.remove('invalid-input');
                }
            });

            if (firstInvalid) {
                // Get the label or field name for a better message
                const label = firstInvalid.parentElement.querySelector('.ab-label')?.innerText.replace('*', '').trim() || firstInvalid.name;

                // Show standard alert instead of relying on browser tooltip
                showError(`Please complete the required field: ${label}`);

                // Try to focus or scroll to it
                const wrapper = firstInvalid.nextElementSibling;
                if (wrapper && wrapper.classList.contains('custom-select-wrapper')) {
                    wrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    // Flash the custom trigger
                    const trigger = wrapper.querySelector('.custom-select-trigger');
                    if (trigger) {
                        trigger.style.borderColor = '#dc2626';
                        setTimeout(() => trigger.style.borderColor = '', 2000);
                    }
                } else {
                    firstInvalid.focus();
                }
                return;
            }

            // If all manual checks pass
            openConfirmationModal();
        }

        // C. OPEN/CLOSE HANDLERS
        const addBookingModal = document.getElementById('addBookingModal');


        // Close (X Button) -> Triggers Reset
        document.getElementById('closeAddBookingModalX').onclick = () => {
            addBookingModal.style.display = 'none';
            resetModal();
        };

        // Close (Click Outside) -> Triggers Reset
        window.onclick = (e) => {
            if (e.target == addBookingModal) {
                addBookingModal.style.display = 'none';
                resetModal();
            }
            if (e.target == document.getElementById('confirmationModal')) {
                document.getElementById('confirmationModal').style.display = 'none';
            }
        };

        // Confirmation Modal Buttons
        document.getElementById('closeConfirmModalX').onclick = () => document.getElementById('confirmationModal').style.display = 'none';
        document.getElementById('cancelConfirmBtn').onclick = () => document.getElementById('confirmationModal').style.display = 'none';

        // D. SAVE TO DATABASE (AJAX)
        // --- SAVE BOOKING (Seamless Update - No Reload) ---
        document.getElementById('finalConfirmBtn').onclick = function () {

            const btn = document.getElementById('finalConfirmBtn');
            const cancelBtn = document.getElementById('cancelConfirmBtn'); // Get Back Button
            const closeX = document.getElementById('closeConfirmModalX');  // Get X Button
            const originalText = btn.innerHTML;

            // 1. LOCK UI (Disable everything)
            isProcessingBooking = true; // Set flag
            toggleUILock(true, "SAVING NEW BOOKING...");

            btn.innerHTML = '<div class="amv-loader-sm"></div> Processing...';
            btn.disabled = true;
            btn.style.opacity = '0.7';

            if (cancelBtn) {
                cancelBtn.disabled = true;
                cancelBtn.style.opacity = '0.5';
                cancelBtn.style.cursor = 'not-allowed';
            }

            if (closeX) {
                closeX.disabled = true;
                closeX.style.opacity = '0.5';
                closeX.style.cursor = 'not-allowed';
            }

            // 2. Prepare Data from Form
            const form = document.getElementById('addBookingForm');
            const formData = new FormData(form);
            const financialData = window.tempBookingPayload;

            // Determine Source & Initial Status
            const sourceElement = document.getElementById('bookingSourceDisplay');
            const sourceValue = sourceElement ? sourceElement.value : 'reservation';

            let initialArrivalStatus = 'awaiting_arrival';
            let finalArrivalTime = formData.get('arrival_time');

            if (sourceValue === 'walk-in') {
                initialArrivalStatus = 'in_house';
                const now = new Date();
                const hours = String(now.getHours()).padStart(2, '0');
                const minutes = String(now.getMinutes()).padStart(2, '0');
                finalArrivalTime = `${hours}:${minutes}`;
            }

            const payload = {
                dates: {
                    checkin: document.getElementById('checkin_picker').value,
                    checkout: document.getElementById('checkout_picker').value
                },
                guest: {
                    salutation: formData.get('salutation'),
                    firstname: formData.get('firstname'),
                    lastname: formData.get('lastname'),
                    gender: formData.get('gender'),
                    birthdate: formData.get('birthdate'),
                    nationality: formData.get('nationality'),
                    email: formData.get('email'),
                    payment_method: formData.get('payment_method'),
                    contact: formData.get('contact'),
                    arrival_time: finalArrivalTime,
                    address: formData.get('address'),
                    adults: formData.get('adults'),
                    children: formData.get('children')
                },
                rooms: selectedRooms,
                totalPrice: financialData.totalPrice,
                amountPaid: financialData.amountPaid,
                paymentStatus: financialData.paymentStatus,
                paymentTerm: financialData.paymentTerm,
                bookingSource: sourceValue,
                arrivalStatus: initialArrivalStatus
            };

            // 3. Send to Server
            fetch('save_booking.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ...payload, csrf_token: csrfToken })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(`Booking Confirmed! Reference: ${data.ref}`);

                        // A. Close Modals
                        document.getElementById('confirmationModal').style.display = 'none';
                        document.getElementById('addBookingModal').style.display = 'none';
                        resetModal();

                        // B. Add row and update stats
                        addBookingRowToTable(data.id || 0, data.ref, payload);
                        fetchDashboardCards();
                    } else {
                        showError(data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("An unexpected error occurred.");
                })
                .finally(() => {
                    // 4. UNLOCK UI (Re-enable everything whether success or fail)
                    isProcessingBooking = false; // Reset flag
                    toggleUILock(false);

                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    btn.style.opacity = '1';

                    // RE-ENABLE BUTTONS HERE:
                    if (cancelBtn) {
                        cancelBtn.disabled = false;
                        cancelBtn.style.opacity = '1';
                        cancelBtn.style.cursor = 'pointer';
                    }

                    if (closeX) {
                        closeX.disabled = false;
                        closeX.style.opacity = '1';
                        closeX.style.cursor = 'pointer';
                    }
                });
        };


        // --- 5. MULTI-STEP WIZARD LOGIC ---
        let currentStep = 1;
        let selectedRooms = [];

        // Mock Data: Simulated Available Rooms
        const mockAvailableRooms = [
            { id: 101, name: 'Deluxe King 101', price: 1500 },
            { id: 102, name: 'Deluxe King 102', price: 1500 },
            { id: 201, name: 'Twin Suite 201', price: 2000 },
            { id: 204, name: 'Family Room 204', price: 3500 },
            { id: 305, name: 'Standard 305', price: 1200 }
        ];



        function goToStep(step) {
            // Validation for Step 1
            if (currentStep === 1 && step === 2) {

                // --- FIX START: Update IDs to match your new HTML ---
                const cin = document.getElementById('checkin_picker').value;
                const cout = document.getElementById('checkout_picker').value;
                // --- FIX END ---

                if (!cin || !cout) {
                    showError("Please select check-in and check-out dates.");
                    return;
                }

                // Show loading state
                const container = document.getElementById('roomSelectionContainer');
                container.innerHTML = '<p style="padding:20px; text-align:center;">Checking availability...</p>';

                // Call the PHP file
                // Get the booking type from the read-only input
                const type = document.getElementById('bookingSourceDisplay').value;

                // Pass it to the API
                fetch(`get_available_rooms.php?checkin=${cin}&checkout=${cout}&type=${type}`)
                    .then(response => response.json())
                    .then(data => {
                        renderAvailableRooms(data); // Pass real data to render function
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        container.innerHTML = '<p style="color:red; padding:20px;">Error loading rooms.</p>';
                    });
            }
            // Validation for Step 2
            if (currentStep === 2 && step === 3) {
                if (selectedRooms.length === 0) {
                    showError("Please select at least one room.");
                    return;
                }
                updateArrivalTimeOptions();
            }

            // Update UI
            document.querySelectorAll('.ab-step').forEach(el => el.classList.remove('active'));
            document.getElementById(`ab-step-${step}`).classList.add('active');

            // Update Title
            const titles = ["Select Dates", "Select Rooms", "Guest Information"];
            document.getElementById('abModalTitle').innerText = `Step ${step}: ${titles[step - 1]}`;

            currentStep = step;
        }

        // Change the function signature to accept 'rooms'
        // --- UPDATED RENDER FUNCTION FOR VIEW DATA ---
        function renderAvailableRooms(rooms) {
            const container = document.getElementById('roomSelectionContainer');
            container.innerHTML = '';

            if (rooms.length === 0) {
                container.innerHTML = `
        <div style="grid-column: 1/-1; text-align:center; padding:30px; color:#666;">
            <p>No rooms available for the selected dates.</p>
        </div>`;
                return;
            }

            rooms.forEach(room => {
                const card = document.createElement('div');
                card.className = 'room-card';

                // Handle clicking the card
                card.onclick = () => toggleRoomSelection(room.id, card, room.name, room.price);

                // --- [INFO] FIX STARTS HERE [INFO] ---
                // 1. Define the folder where images are stored
                const basePath = '../../room_includes/uploads/images/';
                let imageSrc = '';

                if (room.image && room.image.trim() !== '') {
                    // 2. Handle comma-separated images (take the first one)
                    let cleanName = room.image.split(',')[0].trim();

                    // 3. Check if the database gave us just a filename (e.g. "room.jpg")
                    // If so, add the full path to it.
                    if (cleanName.indexOf('/') === -1) {
                        imageSrc = basePath + cleanName;
                    } else {
                        imageSrc = cleanName;
                    }
                } else {
                    // 4. Fallback if database is empty
                    imageSrc = '../../IMG/default_room.jpg';
                }
                // --- [INFO] FIX ENDS HERE [INFO] ---

                card.innerHTML = `
        <div class="room-card-check"></div>
        
        <img src="${imageSrc}" 
             alt="${room.name}" 
             class="room-card-image" 
             onerror="this.onerror=null; this.src='https://placehold.co/600x400?text=No+Image';">
        
        <div class="room-card-body">
            <div class="room-card-header">${room.name}</div>
            
            <div class="room-card-details">
                <span class="detail-badge"><i class="fas fa-users"></i> ${room.capacity} Pax</span>
                <span class="detail-badge"><i class="fas fa-bed"></i> ${room.bed}</span>
                <span class="detail-badge"><i class="fas fa-ruler-combined"></i> ${room.size}</span>
            </div>
            
            <div class="room-card-price">₱${parseFloat(room.price).toLocaleString()}</div>
        </div>
        `;

                // Maintain selection state if re-rendered
                if (selectedRooms.find(r => r.id === room.id)) {
                    card.classList.add('selected');
                }

                container.appendChild(card);
            });
        }


        function toggleRoomSelection(id, cardElement, name, price) {
            if (selectedRooms.find(r => r.id === id)) {
                // Deselect
                selectedRooms = selectedRooms.filter(r => r.id !== id);
                cardElement.classList.remove('selected');
            } else {
                // Select
                selectedRooms.push({ id, name, price });
                cardElement.classList.add('selected');
            }
        }

        function openConfirmationModal() {
            // 1. Gather Data from the Form
            const form = document.getElementById('addBookingForm');
            const formData = new FormData(form);

            const name = `${formData.get('firstname')} ${formData.get('lastname')}`;

            // [FIX] FIX: Define the 'dates' variable here
            const dates = `${formData.get('checkin')} to ${formData.get('checkout')}`;

            const roomNames = selectedRooms.map(r => r.name).join(', ');

            // 2. Calculate Totals (Updated Logic)
            const totalFullPrice = selectedRooms.reduce((sum, r) => sum + r.price, 0);

            const termElement = document.getElementById('payment_term_select');
            const paymentTerm = termElement ? termElement.value : 'full';

            let amountToPayNow = totalFullPrice;
            let balanceDue = 0;
            let paymentStatus = 'paid';

            // --- Payment Logic ---
            if (paymentTerm === 'partial') {
                // 50% Downpayment
                amountToPayNow = totalFullPrice / 2;
                balanceDue = totalFullPrice / 2;
                paymentStatus = 'partial';
            }
            else {
                // Full Payment
                amountToPayNow = totalFullPrice;
                balanceDue = 0;
                paymentStatus = 'paid';
            }

            // 3. Display Data in Modal
            document.getElementById('confirmName').innerText = name;
            document.getElementById('confirmDates').innerText = dates; // This line caused the error
            document.getElementById('confirmRooms').innerText = roomNames;

            // 4. Custom Total Display
            const totalEl = document.getElementById('confirmTotal');

            if (balanceDue > 0) {
                if (amountToPayNow === 0) {
                    // Formatting for "Pay at Checkout"
                    totalEl.innerHTML = `
                    <div style="font-size:1.1rem; color:#333; font-weight:700;">Total: ₱${totalFullPrice.toLocaleString()}</div>
                    <div style="color:#EF4444; font-size:0.9rem; margin-top:5px;">
                        <i class="fas fa-exclamation-circle"></i> No payment collected yet.
                    </div>
                    <div style="color:#555; font-size:0.8rem;">Guest pays full amount upon checkout.</div>
                `;
                } else {
                    // Formatting for "50% Partial"
                    totalEl.innerHTML = `
                    <div style="font-size:0.9rem; color:#555; text-decoration: line-through;">Total: ₱${totalFullPrice.toLocaleString()}</div>
                    <div style="color:#FFA000; font-size:1.2rem;">Pay Now (50%): ₱${amountToPayNow.toLocaleString()}</div>
                    <div style="color:#DC2626; font-size:0.8rem;">Balance upon arrival: ₱${balanceDue.toLocaleString()}</div>
                `;
                }
            } else {
                // Formatting for "Full Payment"
                totalEl.innerText = `₱${totalFullPrice.toLocaleString()} (Full Payment)`;
            }

            // 5. Save data globally so finalConfirmBtn can use it
            window.tempBookingPayload = {
                totalPrice: totalFullPrice,
                amountPaid: amountToPayNow,
                paymentStatus: paymentStatus,
                paymentTerm: paymentTerm
            };

            // 6. Show Modal
            document.getElementById('confirmationModal').style.display = 'block';
        }

        document.addEventListener("DOMContentLoaded", function () {

            flatpickr("#checkin_picker", {
                mode: "range",
                minDate: "today",
                showMonths: 2,
                dateFormat: "Y-m-d",
                plugins: [new rangePlugin({ input: "#checkout_picker" })],
                locale: { firstDayOfWeek: 1 },

                // --- UPDATE THIS SECTION ---
                onOpen: function (selectedDates, dateStr, instance) {
                    document.getElementById('checkin_picker').classList.add('active');
                    document.getElementById('checkout_picker').classList.add('active');

                    // Add the WIDE class when opening
                    instance.calendarContainer.classList.add("double-month-theme");
                },
                // ---------------------------

                onClose: function (selectedDates, dateStr, instance) {
                    document.getElementById('checkin_picker').classList.remove('active');
                    document.getElementById('checkout_picker').classList.remove('active');
                }
            });

        });


        // --- GUEST PROFILE LOGIC ---
        const guestModal = document.getElementById('guestProfileModal');
        const guestLoader = document.getElementById('guestProfileLoader');
        const guestContent = document.getElementById('guestProfileContent');

        // --- GUEST PROFILE LOGIC ---
        let guestHistoryOffset = 0;
        let guestOrdersOffset = 0;
        const guestHistoryLimit = 100;
        let currentProfileEmail = '';

        function openGuestProfile(email) {
            currentProfileEmail = email;
            guestHistoryOffset = 0;
            guestOrdersOffset = 0;
            
            const guestModal = document.getElementById('guestProfileModal');
            const guestLoader = document.getElementById('guestProfileLoader');
            const guestContent = document.getElementById('guestProfileContent');

            guestModal.style.display = 'block';
            guestLoader.style.display = 'block';
            guestContent.style.display = 'none';

            if (typeof toggleGuestEdit === 'function') toggleGuestEdit(false);

            // 🟢 Initial fetch and set default tab
            fetchGuestProfileData().then(() => {
                switchGuestHistoryTab('bookings');
            });
        }

        function fetchGuestProfileData() {
            const url = `get_guest_details.php?email=${encodeURIComponent(currentProfileEmail)}&limit=${guestHistoryLimit}&offset_history=${guestHistoryOffset}&offset_orders=${guestOrdersOffset}`;
            
            return fetch(url)
                .then(res => res.json())
                .then(data => {
                    if (data.error) {
                        showError(data.error);
                        document.getElementById('guestProfileModal').style.display = 'none';
                        return;
                    }

                    window.currentGuestData = data;
                    const info = data.info;
                    document.getElementById('gp_name').innerText = `${info.salutation || ''} ${info.first_name} ${info.last_name}`;
                    document.getElementById('gp_email').innerText = info.email;
                    document.getElementById('gp_phone').innerText = info.phone;
                    document.getElementById('gp_nation').innerText = info.nationality || 'N/A';
                    document.getElementById('gp_gender').innerText = info.gender || 'N/A';
                    document.getElementById('gp_dob').innerText = info.birthdate || 'N/A';
                    document.getElementById('gp_address').innerText = info.address || 'N/A';

                    renderGuestHistory(data.history);
                    renderGuestOrders(data.orders);
                    
                    updateGuestHistoryPagination(data.history_total);
                    updateGuestOrdersPagination(data.orders_total);

                    document.getElementById('guestProfileLoader').style.display = 'none';
                    document.getElementById('guestProfileContent').style.display = 'block';
                });
        }

        function renderGuestHistory(history) {
            const tbody = document.getElementById('gp_history_body');
            tbody.innerHTML = '';
            if (history && history.length > 0) {
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                history.forEach(h => {
                    const checkinObj = new Date(h.check_in);
                    checkinObj.setHours(0, 0, 0, 0);
                    const checkin = checkinObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                    const checkout = new Date(h.check_out).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                    
                    let displayStatus = h.status.toUpperCase();
                    let badgeClass = 'badge-pending';
                    if (h.status === 'cancelled') { displayStatus = 'CANCELLED'; badgeClass = 'badge-cancelled'; }
                    else if (h.arrival_status === 'checked_out') { displayStatus = 'COMPLETE'; badgeClass = 'arrival-checkedout'; }
                    else if (h.arrival_status === 'in_house') { displayStatus = 'IN HOUSE'; badgeClass = 'arrival-inhouse'; }
                    else if (h.status === 'confirmed') {
                        if (checkinObj.getTime() < today.getTime()) { displayStatus = 'NO-SHOW'; badgeClass = 'arrival-overdue'; }
                        else if (checkinObj.getTime() === today.getTime()) { displayStatus = 'ARRIVING TODAY'; badgeClass = 'arrival-today'; }
                        else { displayStatus = 'UPCOMING'; badgeClass = 'badge-confirmed'; }
                    }

                    tbody.innerHTML += `<tr>
                        <td style="font-weight:bold;">${h.booking_reference}</td>
                        <td>${checkin} - ${checkout}</td>
                        <td>${h.room_names || 'Unknown'}</td>
                        <td>₱${parseFloat(h.total_price).toLocaleString()}</td>
                        <td><span class="badge ${badgeClass}">${displayStatus}</span></td>
                    </tr>`;
                });
            } else {
                tbody.innerHTML = `<tr><td colspan="5" class="text-center">No booking history found.</td></tr>`;
            }
        }

        function renderGuestOrders(orders) {
            const orderBody = document.getElementById('gp_orders_body');
            orderBody.innerHTML = '';
            if (orders && orders.length > 0) {
                orders.forEach(o => {
                    let itemsStr = '';
                    try {
                        const items = JSON.parse(o.items);
                        itemsStr = Object.keys(items).map(k => `<span style="white-space:nowrap;"><b>${items[k]}x</b> ${k}</span>`).join(', ');
                    } catch (e) { itemsStr = 'Items unavailable'; }
                    const dateStr = new Date(o.order_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                    let ordBadgeClass = 'badge-pending';
                    if (o.status === 'Delivered') ordBadgeClass = 'badge-confirmed';
                    if (o.status === 'Cancelled') ordBadgeClass = 'badge-cancelled';
                    if (o.status === 'Preparing') ordBadgeClass = 'arrival-today';

                    orderBody.innerHTML += `<tr>
                        <td style="font-weight:bold; color:#888;">#${o.id}</td>
                        <td>${dateStr}</td>
                        <td style="font-size:0.85rem; color:#555;">${itemsStr}</td>
                        <td style="font-weight:bold; color:#B88E2F;">₱${parseFloat(o.total_price).toLocaleString()}</td>
                        <td><span class="badge ${ordBadgeClass}">${o.status}</span></td>
                    </tr>`;
                });
            } else {
                orderBody.innerHTML = `<tr><td colspan="5" class="text-center" style="padding:20px; color:#999;">No food orders found.</td></tr>`;
            }
        }

        function updateGuestHistoryPagination(total) {
            const foot = document.getElementById('gp_history_pagination_foot');
            const container = document.getElementById('gp_history_pagination');
            
            if (total <= guestHistoryLimit) {
                foot.style.display = 'none';
                return;
            }
            
            foot.style.display = 'table-footer-group';
            const currentPage = Math.floor(guestHistoryOffset / guestHistoryLimit) + 1;
            const totalPages = Math.ceil(total / guestHistoryLimit);
            const start = guestHistoryOffset + 1;
            const end = Math.min(guestHistoryOffset + guestHistoryLimit, total);

            container.innerHTML = `
                <div class="pagination-info">
                    Showing <span>${start} - ${end}</span> of ${total} records
                </div>
                <div class="pagination-buttons">
                    <button class="pg-btn pg-btn-nav" ${guestHistoryOffset === 0 ? 'disabled' : ''} onclick="changeGuestPage('history', -1)">
                        <i class="fas fa-chevron-left"></i> Prev
                    </button>
                    ${generateGuestPageNumbers(totalPages, currentPage, 'history')}
                    <button class="pg-btn pg-btn-nav" ${guestHistoryOffset + guestHistoryLimit >= total ? 'disabled' : ''} onclick="changeGuestPage('history', 1)">
                        Next <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            `;
        }

        function updateGuestOrdersPagination(total) {
            const foot = document.getElementById('gp_orders_pagination_foot');
            const container = document.getElementById('gp_orders_pagination');
            
            if (total <= guestHistoryLimit) {
                foot.style.display = 'none';
                return;
            }
            
            foot.style.display = 'table-footer-group';
            const currentPage = Math.floor(guestOrdersOffset / guestHistoryLimit) + 1;
            const totalPages = Math.ceil(total / guestHistoryLimit);
            const start = guestOrdersOffset + 1;
            const end = Math.min(guestOrdersOffset + guestHistoryLimit, total);

            container.innerHTML = `
                <div class="pagination-info">
                    Showing <span>${start} - ${end}</span> of ${total} records
                </div>
                <div class="pagination-buttons">
                    <button class="pg-btn pg-btn-nav" ${guestOrdersOffset === 0 ? 'disabled' : ''} onclick="changeGuestPage('orders', -1)">
                        <i class="fas fa-chevron-left"></i> Prev
                    </button>
                    ${generateGuestPageNumbers(totalPages, currentPage, 'orders')}
                    <button class="pg-btn pg-btn-nav" ${guestOrdersOffset + guestHistoryLimit >= total ? 'disabled' : ''} onclick="changeGuestPage('orders', 1)">
                        Next <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            `;
        }

        function generateGuestPageNumbers(totalPages, currentPage, type) {
            let html = '';
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= currentPage - 1 && i <= currentPage + 1)) {
                    html += `<button class="pg-btn ${i === currentPage ? 'active' : ''}" onclick="setGuestPage('${type}', ${i})">${i}</button>`;
                } else if (i === currentPage - 2 || i === currentPage + 2) {
                    html += `<span class="pg-dots">...</span>`;
                }
            }
            return html;
        }

        // 🟢 ATTACH TO WINDOW TO MAKE THEM GLOBAL
        window.changeGuestPage = function(type, direction) {
            if (type === 'history') guestHistoryOffset += (direction * guestHistoryLimit);
            else guestOrdersOffset += (direction * guestHistoryLimit);
            // Ensure offset doesn't go below 0
            if (guestHistoryOffset < 0) guestHistoryOffset = 0;
            if (guestOrdersOffset < 0) guestOrdersOffset = 0;
            
            fetchGuestProfileData();
        };

        window.setGuestPage = function(type, page) {
            if (type === 'history') guestHistoryOffset = (page - 1) * guestHistoryLimit;
            else guestOrdersOffset = (page - 1) * guestHistoryLimit;
            fetchGuestProfileData();
        };

        // [INFO] NEW FUNCTION: Switch Tabs in Guest Modal
        function switchGuestHistoryTab(tabName) {
            // 1. Get Elements
            const bookingsContainer = document.getElementById('gp_history_container');
            const ordersContainer = document.getElementById('gp_orders_container');
            const btnBookings = document.getElementById('tab-btn-bookings');
            const btnOrders = document.getElementById('tab-btn-orders');

            // 2. Toggle Visibility
            if (tabName === 'bookings') {
                bookingsContainer.style.display = 'block';
                ordersContainer.style.display = 'none';

                // Update Button Styles (Gold for active, Grey for inactive)
                btnBookings.style.color = '#B88E2F';
                btnBookings.style.borderBottomColor = '#B88E2F';
                btnBookings.classList.add('active');

                btnOrders.style.color = '#888';
                btnOrders.style.borderBottomColor = 'transparent';
                btnOrders.classList.remove('active');
            } else {
                bookingsContainer.style.display = 'none';
                ordersContainer.style.display = 'block';

                btnOrders.style.color = '#B88E2F';
                btnOrders.style.borderBottomColor = '#B88E2F';
                btnOrders.classList.add('active');

                btnBookings.style.color = '#888';
                btnBookings.style.borderBottomColor = 'transparent';
                btnBookings.classList.remove('active');
            }
        }

        function closeGuestModal() {
            guestModal.style.display = 'none';
        }

        // [INFO] REMOVED: Outside click listener for guestProfileModal
        // The modal now only closes via the 'X' button as requested.


        // --- BOOKING ACTION MODAL LOGIC ---
        const actionModal = document.getElementById('bookingActionModal');
        let currentDailyPrice = 0; // [INFO] Global store for current room price

        // Updated function: Cancellation valid only within 3 days of BOOKING DATE
        // Updated signature to accept 'bookingSource' and 'specialRequests'
        function openBookingAction(id, name, ref, rooms, checkin, checkout, price, arrivalStatus, amountPaid, currentLabel, createdAt, bookingSource, dailyPrice = 0, specialRequests = '') {
            console.log("Booking Source:", bookingSource);
            console.log("Daily Price from DB:", dailyPrice);
            currentDailyPrice = parseFloat(dailyPrice) || 0;
            console.log("Captured Global Daily Price:", currentDailyPrice);

            // 1. Sanitize Inputs (Crucial for status checks)
            // --- [INFO] FIX: Get the LATEST status from the HTML row (handles real-time updates) ---
            const row = document.getElementById('row-' + id);
            let realTimeStatus = 'confirmed'; // Default fallback

            if (row) {
                // If we just cancelled it via JS, this attribute will be 'cancelled'
                realTimeStatus = row.getAttribute('data-status');
            }

            // 1. Sanitize Inputs
            const safeStatus = arrivalStatus ? arrivalStatus.trim().toLowerCase() : '';

            // Check both the passed status AND the real-time status from the DOM
            const isCancelled = (realTimeStatus === 'cancelled') || ['cancelled', 'no_show'].includes(safeStatus) || ['Cancelled', 'No-Show'].includes(currentLabel);

            if (isCancelled) {
                let statusText = "Booking is Cancelled";
                if (safeStatus === 'no_show' || currentLabel === 'No-Show') statusText = "Booking marked as No-Show";

                // Reset container and show message
                const container = document.getElementById('ba_action_container');
                container.innerHTML = `<div style="text-align:center; font-weight:bold; color:#EF4444; padding: 10px; background:#FEF2F2; border-radius:6px;">${statusText}</div>`;

                // Fill basic details so the modal isn't empty
                document.getElementById('ba_guest').innerText = name;
                document.getElementById('ba_ref').innerText = ref;
                document.getElementById('ba_room').innerText = rooms;
                document.getElementById('ba_dates').innerText = `${checkin} to ${checkout}`;
                document.getElementById('ba_price').innerHTML = `<div>₱${parseFloat(price).toLocaleString()}</div>`; // Simplified price view

                // Handle Special Request
                const srBox = document.getElementById('ba_special_request');
                if (srBox) {
                    srBox.innerText = (specialRequests && specialRequests.trim() !== "") ? specialRequests : "No special request";
                }

                document.getElementById('ba_warning').style.display = 'none';

                document.getElementById('bookingActionModal').style.display = 'block';
                return; // â›” STOP HERE so no buttons are added
            }
            const isWalkIn = (bookingSource && bookingSource.toLowerCase() === 'walk-in');

            // 2. Populate Text Details
            document.getElementById('ba_guest').innerText = name;
            document.getElementById('ba_ref').innerText = ref;
            document.getElementById('ba_room').innerText = rooms;
            document.getElementById('ba_dates').innerText = `${checkin} to ${checkout}`;

            // Handle Special Request
            const srBox = document.getElementById('ba_special_request');
            if (srBox) {
                srBox.innerText = (specialRequests && specialRequests.trim() !== "") ? specialRequests : "No special request";
            }

            // 3. Calculate Balance
            const total = parseFloat(price);
            const paid = amountPaid ? parseFloat(amountPaid) : 0;
            const balance = Math.round((total - paid) * 100) / 100;

            const priceEl = document.getElementById('ba_price');
            if (balance > 0) {
                priceEl.innerHTML = `<div>₱${total.toLocaleString()}</div><div style="font-size:0.8rem; color:#EF4444; font-weight:700;">(Bal: ₱${balance.toLocaleString()})</div>`;
            } else {
                priceEl.innerHTML = `<div>₱${total.toLocaleString()}</div><div style="font-size:0.8rem; color:#10B981; font-weight:700;">(Fully Paid)</div>`;
            }

            // 4. Prepare Container & Reset
            const container = document.getElementById('ba_action_container');
            const warning = document.getElementById('ba_warning');
            container.innerHTML = '';
            warning.style.display = 'none'; // Hide warning by default

            // 5. Handle Cancelled / No-Show
            const isTerminated = ['cancelled', 'no_show'].includes(safeStatus) || ['Cancelled', 'No-Show'].includes(currentLabel);
            if (isTerminated) {
                let statusText = "Booking is Cancelled";
                if (safeStatus === 'no_show' || currentLabel === 'No-Show') statusText = "Booking marked as No-Show";
                container.innerHTML = `<div style="text-align:center; font-weight:bold; color:#EF4444; padding: 10px;">${statusText}</div>`;
                document.getElementById('bookingActionModal').style.display = 'block';
                return;
            }

            // --- BUTTON 1: SETTLE BALANCE (Always show if debt exists) ---
            if (balance > 0) {
                const payBtn = document.createElement('button');
                payBtn.className = 'ab-submit-btn';
                payBtn.style.backgroundColor = '#10B981';
                payBtn.style.marginBottom = '15px';
                payBtn.innerHTML = `<i class="fas fa-money-bill-wave"></i> Settle Balance (₱${balance.toLocaleString()})`;
                payBtn.onclick = function () {
                    settleBalance(id, balance);
                };
                container.appendChild(payBtn);
            }

            // --- BUTTON 2: MAIN ACTIONS (Check In / Check Out) ---

            // Scenario A: Active Guest (In House OR Walk-in not checked out)
            if (safeStatus === 'in_house' || (isWalkIn && safeStatus !== 'checked_out')) {

                // Rule: Cannot check out if balance > 0
                if (balance > 0) {
                    const btnDisabled = document.createElement('button');
                    btnDisabled.className = 'ab-submit-btn';
                    btnDisabled.style.backgroundColor = '#9CA3AF'; // Grey
                    btnDisabled.disabled = true;
                    btnDisabled.style.cursor = 'not-allowed';
                    btnDisabled.innerHTML = '<i class="fas fa-lock"></i> Settle Balance to Check Out';
                    container.appendChild(btnDisabled);
                } else {
                    // [OK] PASTE THIS INSTEAD
                    // Always show the Check Out button if they are In House (Manual Control)
                    const btn = document.createElement('button');
                    btn.className = 'ab-submit-btn';
                    btn.style.backgroundColor = '#7E22CE';
                    btn.innerText = 'Check Out Guest';
                    btn.onclick = function () {
                        updateStatus(id, 'checkout', false, this);
                    };
                    container.appendChild(btn);
                }
            }
            // Scenario B: Checked Out
            else if (safeStatus === 'checked_out') {
                container.innerHTML += `<div style="text-align:center; font-weight:bold; color:#666;">Guest has checked out.</div>`;
            }
            // Scenario C: Pending Reservation
            else {
                // [FIX] NEW LOGIC: CHECK IF DATE IS IN FUTURE
                const today = new Date();
                const year = today.getFullYear();
                const month = String(today.getMonth() + 1).padStart(2, '0');
                const day = String(today.getDate()).padStart(2, '0');
                const todayStr = `${year}-${month}-${day}`;

                // --- NEW RESCHEDULE BUTTON (Available for Upcoming AND Arriving Today) ---
                // 1. Parse the 'Created At' date (passed as 'createdAt' argument)
                const createdDateObj = new Date(createdAt.replace(' ', 'T')); // Fix for Safari/Firefox
                const now = new Date();

                // 2. Calculate the difference in hours
                const diffTime = Math.abs(now - createdDateObj);
                const diffHours = Math.ceil(diffTime / (1000 * 60 * 60));

                // 3. Only show button if within 72 hours (3 days)
                if (diffHours <= 72) {
                    const btnResched = document.createElement('button');
                    btnResched.className = 'btn-secondary';
                    btnResched.style.width = '100%';
                    btnResched.style.marginBottom = '10px';
                    btnResched.style.border = '1px solid #F59E0B'; // Orange border
                    btnResched.style.color = '#B45309'; // Dark Orange text
                    btnResched.innerHTML = '<i class="fas fa-calendar-alt"></i> Reschedule Dates';

                    btnResched.onclick = function () {
                        document.getElementById('bookingActionModal').style.display = 'none';
                        openRescheduleModal(ref);
                    };
                    container.appendChild(btnResched);
                } else {
                    // Optional: Show a disabled/greyed out message explaining why
                    const expiredMsg = document.createElement('div');
                    expiredMsg.style.fontSize = '0.75rem';
                    expiredMsg.style.color = '#999';
                    expiredMsg.style.textAlign = 'center';
                    expiredMsg.style.marginBottom = '10px';
                    expiredMsg.style.fontStyle = 'italic';
                    expiredMsg.innerHTML = '<i class="fas fa-clock"></i> Reschedule period expired (72h limit)';
                    container.appendChild(expiredMsg);
                }

                // If Booking is in the Future (Greater than Today)
                if (checkin > todayStr) {
                    // 1. Show Warning
                    warning.style.display = 'block';
                    warning.innerHTML = `<i class="fas fa-clock"></i> Cannot confirm arrival yet.<br>Check-in date is <b>${checkin}</b>.`;

                    // 2. Only Show Cancel Button (No Confirm Button)
                    const btnCancel = document.createElement('button');
                    btnCancel.className = 'ab-submit-btn';
                    btnCancel.style.backgroundColor = '#EF4444';
                    btnCancel.innerText = 'Cancel Booking';
                    btnCancel.onclick = async function () {
                        if (await showConfirm("Confirmation", "Are you sure you want to cancel this booking?")) updateStatus(id, 'cancel', false, this);
                    };
                    container.appendChild(btnCancel);
                }
                // If Booking is Today or Past
                else {
                    // 1. Calculate specific 8 PM cutoff for THIS booking
                    // Using the checkin date passed to the function
                    const cutoffDate = new Date(checkin + 'T20:00:00'); // 8:00 PM on check-in day
                    const now = new Date();

                    // [FIX] NEW: Add "Mark No-Show" Button if it is past 8 PM
                    if (now > cutoffDate) {
                        const btnNoShow = document.createElement('button');
                        btnNoShow.className = 'ab-submit-btn';
                        btnNoShow.style.backgroundColor = '#F59E0B'; // Orange
                        btnNoShow.style.marginBottom = '10px';
                        btnNoShow.innerText = 'Mark as No-Show';
                        btnNoShow.onclick = function () {
                            // We reuse your updateStatus function
                            updateStatus(id, 'no_show', false, this);
                        };
                        container.appendChild(btnNoShow);
                    }

                    // --- Standard Confirm Arrival Button ---
                    const btnConfirm = document.createElement('button');
                    btnConfirm.className = 'ab-submit-btn';
                    btnConfirm.style.marginBottom = '10px';

                    if (balance > 0) {
                        btnConfirm.style.backgroundColor = '#9CA3AF';
                        btnConfirm.disabled = true;
                        btnConfirm.innerText = 'âš  Settle Balance to Check In';
                    } else {
                        btnConfirm.style.backgroundColor = '#2563EB';
                        btnConfirm.innerText = 'Confirm Arrival';
                        btnConfirm.onclick = function () {
                            updateStatus(id, 'arrive', false, this);
                        };
                    }
                    container.appendChild(btnConfirm);

                    // --- Cancel Button ---
                    const btnCancel = document.createElement('button');
                    btnCancel.className = 'ab-submit-btn';
                    btnCancel.style.backgroundColor = '#EF4444';
                    btnCancel.innerText = 'Cancel Booking';
                    btnCancel.onclick = async function () {
                        if (await showConfirm("Confirmation", "Are you sure you want to cancel this booking?")) updateStatus(id, 'cancel', false, this);
                    };
                    container.appendChild(btnCancel);
                }
            }

            // --- BUTTON 3: EXTEND STAY ---
            // Allow extend only if NOT checked out AND (In House OR Walk-in OR Upcoming)
            // We use safeStatus to ensure we are checking the sanitized string
            if (safeStatus !== 'checked_out' && (safeStatus === 'in_house' || isWalkIn || safeStatus === 'upcoming' || currentLabel === 'Upcoming')) {
                const btnExtend = document.createElement('button');
                btnExtend.className = 'btn-secondary';
                btnExtend.style.width = '100%';
                btnExtend.style.marginTop = '10px';
                btnExtend.style.border = '1px solid #2563EB';
                btnExtend.style.color = '#2563EB';
                btnExtend.innerHTML = '<i class="fas fa-calendar-plus"></i> Extend Stay';
                btnExtend.onclick = function () {
                    openExtendModal(id, checkout);
                };
                container.appendChild(btnExtend);
            }

            document.getElementById('bookingActionModal').style.display = 'block';
        }

        // --- SETTLE BALANCE (Seamless Update - Keeps Modal Open) ---
        async function settleBalance(id, amount) {
            if (!await showConfirm("Confirmation", `Confirm receipt of remaining ₱${amount.toLocaleString()}?`)) return;

            const formData = new FormData();
            formData.append('id', id);
            formData.append('action', 'settle_payment');
            formData.append('csrf_token', csrfToken);

            // Show loading state on the button
            const container = document.getElementById('ba_action_container');
            const payBtn = Array.from(container.querySelectorAll('button')).find(b => b.innerText.includes("Settle Balance"));

            if (payBtn) {
                payBtn.innerHTML = '<div class="amv-loader-sm"></div> Processing...';
                payBtn.disabled = true;
            }

            fetch('update_arrival.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess("Payment Complete!");

                        // 1. ❌ REMOVED: closeActionModal(); 
                        // We keep the modal OPEN.

                        // 2. 🟢 UPDATE MODAL VISUALS (So it looks paid)

                        // A. Update Price Text to Green (Fully Paid)
                        const priceEl = document.getElementById('ba_price');
                        // Keep the dollar amount, just change the red text to green
                        let currentTotal = priceEl.firstElementChild ? priceEl.firstElementChild.innerText : '';
                        priceEl.innerHTML = `<div>${currentTotal}</div><div style="font-size:0.8rem; color:#10B981; font-weight:700;">(Fully Paid)</div>`;

                        // B. Remove the "Settle Balance" Button (since it's paid)
                        if (payBtn) payBtn.remove();

                        // C. Unlock the "Check In" or "Check Out" button
                        // We look for the grey disabled button and re-enable it
                        const lockedBtn = container.querySelector('button:disabled');
                        if (lockedBtn) {
                            lockedBtn.disabled = false;
                            lockedBtn.style.cursor = 'pointer';

                            // Check the text to decide if it's Check-In or Check-Out
                            const btnText = lockedBtn.innerText.toLowerCase();

                            if (btnText.includes('check out')) {
                                // Convert to Check Out Button
                                lockedBtn.style.backgroundColor = '#7E22CE'; // Purple
                                lockedBtn.innerHTML = 'Check Out Guest';
                                lockedBtn.onclick = function () { updateStatus(id, 'checkout', false, this); };
                            } else {
                                // Convert to Confirm Arrival Button
                                lockedBtn.style.backgroundColor = '#2563EB'; // Blue
                                lockedBtn.innerHTML = 'Confirm Arrival';
                                lockedBtn.onclick = function () { updateStatus(id, 'arrive', false, this); };
                            }
                        }

                        // 3. Update the Background Table Row (So if you close, it's correct)
                        const row = document.getElementById('row-' + id);
                        if (row) {
                            // Update Paid Column
                            const paidCell = row.cells[8];
                            if (paidCell) paidCell.innerHTML = '<span style="color:#10B981; font-weight:700; font-size:0.8rem;">Fully Paid</span>';

                            // Update the "View" button's onclick data so it remembers it's paid
                            const actionCell = row.cells[9];
                            const viewBtn = actionCell.querySelector('button');
                            if (viewBtn) {
                                // We replace the balance argument (index 8 approx) with 0 and status 'paid'
                                // Simplest way: just reload dashboard stats, the row is mostly visual
                            }
                        }

                        // 4. Update Header Stats
                        // CORRECT
                        fetchDashboardCards();

                    } else {
                        showError("Error: " + (data.message || "Unknown error"));
                        if (payBtn) {
                            payBtn.innerHTML = `💰 Settle Balance (₱${amount.toLocaleString()})`;
                            payBtn.disabled = false;
                        }
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");
                    if (payBtn) {
                        payBtn.innerHTML = `💰 Settle Balance (₱${amount.toLocaleString()})`;
                        payBtn.disabled = false;
                    }
                });
        }

        function closeActionModal() {
            if (isProcessingBooking) return; // [FIX] Prevent closing via "X" button if processing
            document.getElementById('bookingActionModal').style.display = 'none';
        }

        // --- UPDATE STATUS (With Loading & Safety Lock) ---
        async function updateStatus(id, action, isAuto = false, btnElement = null) {

            // 1. Confirmation (Only for manual clicks)
            if (!isAuto) {
                let confirmMsg = "Are you sure you want to update this status?";
                if (action === 'cancel') confirmMsg = "Are you sure you want to CANCEL this booking? This cannot be undone.";
                if (action === 'checkout') confirmMsg = "Confirm guest check-out?";
                if (action === 'no_show') confirmMsg = "Mark this guest as No-Show?";

                if (!await showConfirm("Confirmation", confirmMsg)) return;
            }

            // 2. LOCK UI (Active Busy Mode)
            isProcessingBooking = true;

            // Map actions to labels for the overlay
            const actionLabels = {
                'arrive': 'CHECKING IN GUEST...',
                'checkout': 'CHECKING OUT GUEST...',
                'no_show': 'MARKING AS NO-SHOW...',
                'cancel': 'CANCELLING BOOKING...'
            };
            toggleUILock(true, actionLabels[action] || "UPDATING STATUS...");

            let originalText = "";
            if (btnElement) {
                originalText = btnElement.innerHTML;
                btnElement.innerHTML = '<div class="amv-loader-sm"></div> Processing...';
                btnElement.disabled = true;
                btnElement.style.opacity = '0.7';
                btnElement.style.cursor = 'not-allowed';

                // Disable sibling buttons
                if (btnElement.parentNode) {
                    const siblings = btnElement.parentNode.querySelectorAll('button');
                    siblings.forEach(b => {
                        b.disabled = true;
                        b.style.opacity = '0.5';
                    });
                }
            }

            const formData = new FormData();
            formData.append('id', id);
            formData.append('action', action);
            formData.append('csrf_token', csrfToken);

            fetch('update_arrival.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {

                        // Success Logic
                        if (!isAuto) {
                            // Short delay to show processing state
                            setTimeout(() => {
                                showSuccess("Status Updated Successfully!");
                                document.getElementById('bookingActionModal').style.display = 'none';
                            }, 100);
                        }

                        // Update Background Row
                        const row = document.getElementById('row-' + id);
                        if (row) {
                            const statusCell = row.cells[3];
                            if (action === 'arrive') {
                                statusCell.innerHTML = '<div class="arrival-badge arrival-inhouse">In House</div>';
                                row.setAttribute('data-arrival', 'in_house');
                            } else if (action === 'checkout') {
                                statusCell.innerHTML = '<div class="arrival-badge arrival-checkedout">Checked Out</div>';
                                row.setAttribute('data-arrival', 'checked_out');
                                row.style.backgroundColor = "#fff3cd";
                            } else if (action === 'no_show' || action === 'cancel') {
                                let badgeClass = action === 'cancel' ? 'badge-cancelled' : 'arrival-overdue';
                                let label = action === 'cancel' ? 'Cancelled' : 'No-Show';
                                statusCell.innerHTML = `<div class="arrival-badge ${badgeClass}">${label}</div>`;
                                row.setAttribute('data-arrival', action);
                                row.setAttribute('data-status', 'cancelled');
                                row.style.backgroundColor = "#FEE2E2";
                            }
                        }
                        // Update Dashboard
                        if (typeof fetchDashboardCards === 'function') fetchDashboardCards();

                    } else {
                        throw new Error(data.message || "Unknown error");
                    }
                })
                .catch(err => {
                    console.error(err);
                    if (!isAuto) showError("Error: " + err.message);
                })
                .finally(() => {
                    // 🔴 UNLOCK UI
                    isProcessingBooking = false;
                    toggleUILock(false);

                    // Restore Buttons
                    if (btnElement) {
                        btnElement.innerHTML = originalText;
                        btnElement.disabled = false;
                        btnElement.style.opacity = '1';
                        btnElement.style.cursor = 'pointer';

                        if (btnElement.parentNode) {
                            const siblings = btnElement.parentNode.querySelectorAll('button');
                            siblings.forEach(b => {
                                b.disabled = false;
                                b.style.opacity = '1';
                            });
                        }
                    }
                });
        }

        document.addEventListener("DOMContentLoaded", function () {

            // --- SHARED BIRTHDATE LOGIC (Add Booking & Edit Profile) ---
            const today = new Date();
            const legalAgeDate = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate());

            // Configuration Object
            const dobConfig = {
                dateFormat: "Y-m-d",
                allowInput: true,
                maxDate: legalAgeDate, // Enforce 18+
                yearSelectorType: 'static',
                monthSelectorType: "static",
                onReady: function (selectedDates, dateStr, instance) {
                    instance.calendarContainer.classList.add("compact-theme");
                    initCustomFpHeader(instance, { showDropdowns: true });
                },
                onMonthChange: function (selectedDates, dateStr, instance) {
                    updateDropdownSelections(instance);
                },
                onYearChange: function (selectedDates, dateStr, instance) {
                    updateDropdownSelections(instance);
                },
                onClose: function (selectedDates, dateStr, instance) {
                    if (selectedDates.length === 0 && dateStr !== "") {
                        showError("Invalid date format. Please use YYYY-MM-DD.");
                        instance.clear();
                    }
                    if (selectedDates.length > 0) {
                        if (selectedDates[0] > legalAgeDate) {
                            showError("Guest must be at least 18 years old.");
                            instance.clear();
                        }
                    }
                }
            };

            // 1. Apply to Add Booking Modal
            flatpickr("#birthdate_picker", dobConfig);

            // 2. Apply to Edit Profile Modal (NEW)
            flatpickr("#edit_dob", dobConfig);

            // --- NATIONALITY VALIDATION FOR EDIT PROFILE (NEW) ---
            const editNationInput = document.getElementById('edit_nation');
            // We use the same array 'nationalities' defined earlier in your script
            if (editNationInput && typeof nationalities !== 'undefined') {
                editNationInput.addEventListener('change', function () {
                    if (!nationalities.includes(this.value)) {
                        showError("Please select a valid nationality from the list.");
                        this.value = ''; // Clear invalid input
                    }
                });
            }
        });


        document.addEventListener("DOMContentLoaded", function () {

            // 1. The Master List of Nationalities
            const nationalities = [
                "Afghan", "Albanian", "Algerian", "American", "Andorran", "Angolan", "Antiguans", "Argentinean", "Armenian", "Australian", "Austrian", "Azerbaijani",
                "Bahamian", "Bahraini", "Bangladeshi", "Barbadian", "Barbudans", "Batswana", "Belarusian", "Belgian", "Belizean", "Beninese", "Bhutanese", "Bolivian",
                "Bosnian", "Brazilian", "British", "Bruneian", "Bulgarian", "Burkinabe", "Burmese", "Burundian", "Cambodian", "Cameroonian", "Canadian", "Cape Verdean",
                "Central African", "Chadian", "Chilean", "Chinese", "Colombian", "Comoran", "Congolese", "Costa Rican", "Croatian", "Cuban", "Cypriot", "Czech",
                "Danish", "Djibouti", "Dominican", "Dutch", "East Timorese", "Ecuadorean", "Egyptian", "Emirian", "Equatorial Guinean", "Eritrean", "Estonian",
                "Ethiopian", "Fijian", "Filipino", "Finnish", "French", "Gabonese", "Gambian", "Georgian", "German", "Ghanaian", "Greek", "Grenadian", "Guatemalan",
                "Guinea-Bissauan", "Guinean", "Guyanese", "Haitian", "Herzegovinian", "Honduran", "Hungarian", "Icelander", "Indian", "Indonesian", "Iranian", "Iraqi",
                "Irish", "Israeli", "Italian", "Ivorian", "Jamaican", "Japanese", "Jordanian", "Kazakhstani", "Kenyan", "Kittian and Nevisian", "Kuwaiti", "Kyrgyz",
                "Laotian", "Latvian", "Lebanese", "Liberian", "Libyan", "Liechtensteiner", "Lithuanian", "Luxembourger", "Macedonian", "Malagasy", "Malawian",
                "Malaysian", "Maldivan", "Malian", "Maltese", "Marshallese", "Mauritanian", "Mauritian", "Mexican", "Micronesian", "Moldovan", "Monacan", "Mongolian",
                "Moroccan", "Mosotho", "Motswana", "Mozambican", "Namibian", "Nauruan", "Nepalese", "New Zealander", "Ni-Vanuatu", "Nicaraguan", "Nigerien",
                "North Korean", "Northern Irish", "Norwegian", "Omani", "Pakistani", "Palauan", "Panamanian", "Papua New Guinean", "Paraguayan", "Peruvian", "Polish",
                "Portuguese", "Qatari", "Romanian", "Russian", "Rwandan", "Saint Lucian", "Salvadoran", "Samoan", "San Marinese", "Sao Tomean", "Saudi", "Scottish",
                "Senegalese", "Serbian", "Seychellois", "Sierra Leonean", "Singaporean", "Slovakian", "Slovenian", "Solomon Islander", "Somali", "South African",
                "South Korean", "Spanish", "Sri Lankan", "Sudanese", "Surinamer", "Swazi", "Swedish", "Swiss", "Syrian", "Taiwanese", "Tajik", "Tanzanian", "Thai",
                "Togolese", "Tongan", "Trinidadian or Tobagonian", "Tunisian", "Turkish", "Tuvaluan", "Ugandan", "Ukrainian", "Uruguayan", "Uzbekistani", "Venezuelan",
                "Vietnamese", "Welsh", "Yemenite", "Zambian", "Zimbabwean"
            ];

            // 2. Populate the datalist
            const dataList = document.getElementById('nationality_options');
            if (dataList) {
                let optionsHTML = '';
                nationalities.forEach(nation => {
                    optionsHTML += `<option value="${nation}">`;
                });
                dataList.innerHTML = optionsHTML;
            }

            // 3. (Optional) Validation: Force user to pick from list
            const input = document.getElementById('nationalityInput');
            if (input) {
                input.addEventListener('change', function () {
                    // Check if the typed value exists in the array
                    if (!nationalities.includes(this.value)) {
                        // Ideally, show a red border or small error text
                        this.setCustomValidity("Please select a valid nationality from the list.");
                    } else {
                        this.setCustomValidity("");
                    }
                });
            }
        });

        // --- 2. ADDRESS AUTOCOMPLETE (Nominatim) ---
        const addrInput = document.getElementById('adminAddressInput');
        const addrHidden = document.getElementById('adminAddressHidden');
        const addrLoader = document.getElementById('adminAddrLoader');
        const addrResults = document.getElementById('adminAddrResults');
        let debounceTimer;

        if (addrInput && addrHidden) {
            addrInput.addEventListener('input', function () {
                // Automatically copy whatever the user types into the hidden field
                addrHidden.value = this.value;
            });
        }

        if (addrInput) {
            addrInput.addEventListener('input', function () {
                const query = this.value.trim();
                clearTimeout(debounceTimer);

                if (query.length < 3) {
                    addrResults.style.display = 'none';
                    return;
                }

                // Wait 600ms after user stops typing
                debounceTimer = setTimeout(() => {
                    fetchAdminAddress(query);
                }, 600);
            });
        }

        function fetchAdminAddress(query) {
            addrLoader.style.display = 'block';

            // Global Search (No country restriction)
            const url = `search_address.php?q=${encodeURIComponent(query)}`;

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    addrLoader.style.display = 'none';
                    renderAdminAddressResults(data);
                })
                .catch(err => {
                    console.error(err);
                    addrLoader.style.display = 'none';
                });
        }

        function renderAdminAddressResults(data) {
            addrResults.innerHTML = '';

            if (data.length === 0) {
                addrResults.style.display = 'none';
                return;
            }

            data.forEach(place => {
                const item = document.createElement('div');
                item.className = 'address-result-item';
                item.innerText = place.display_name;

                item.onclick = () => {
                    // 1. Fill Visible Input
                    addrInput.value = place.display_name;
                    // 2. Fill Hidden Input (This goes to DB)
                    addrHidden.value = place.display_name;
                    // 3. Hide List
                    addrResults.style.display = 'none';
                };

                addrResults.appendChild(item);
            });

            addrResults.style.display = 'block';
        }

        // Close dropdown on click outside
        document.addEventListener('click', function (e) {
            if (addrInput && e.target !== addrInput && e.target !== addrResults) {
                addrResults.style.display = 'none';
            }
        });

        async function editEmailAddress() {
            // 1. Get current email from the display span
            const currentEmail = document.getElementById('gp_email').innerText;

            // 2. Ask Admin for new email using Swal.fire instead of prompt
            const { value: newEmail } = await Swal.fire({
                title: 'Edit Email Address',
                input: 'email',
                inputLabel: 'Enter the correct email address:',
                inputValue: currentEmail,
                showCancelButton: true,
                confirmButtonColor: '#B88E2F',
                cancelButtonColor: '#6B7280',
                customClass: {
                    popup: 'amv-swal-popup',
                    title: 'amv-swal-title',
                    confirmButton: 'amv-swal-confirm-btn',
                    cancelButton: 'amv-swal-cancel-btn'
                },
                inputValidator: (value) => {
                    if (!value) return 'You need to write something!';
                }
            });

            if (newEmail && newEmail !== currentEmail) {
                // 3. Confirm action
                if (!await showConfirm("Confirmation", `Change email from "${currentEmail}" to "${newEmail}"? This will update their entire booking history.`)) return;

                // 4. Send to Server
                const formData = new FormData();
                formData.append('old_email', currentEmail);
                formData.append('new_email', newEmail);
                formData.append('csrf_token', csrfToken);

                fetch('update_guest_email.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            showSuccess("Email updated successfully!");

                            // 1. Update the email on the profile card immediately
                            document.getElementById('gp_email').innerText = newEmail;

                            // 2. Refresh the main table in the background (Seamless)
                            if (typeof fetchGuestList === 'function') {
                                fetchGuestList();
                            }
                        } else {
                            showError("Error: " + data.message);
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        showError("Request failed.");
                    });
            }
        }


        // --- ROOM MANAGEMENT JS ---

        // --- SUBMIT FORM (PREVENTS DUPLICATES) ---
        // We use .onsubmit instead of .addEventListener to ensure only ONE listener exists
        // --- SUBMIT FORM (PREVENTS DUPLICATES) ---
        // --- [INFO] SEAMLESS ROOM REFRESH ---
        function refreshRoomsTable() {
            const tbody = document.getElementById('roomTableBody');
            if (!tbody) return;

            fetch('fetch_rooms_table.php?t=' + new Date().getTime())
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        tbody.innerHTML = data.html;

                        // Re-apply archive visibility if needed
                        const btn = document.getElementById('toggleArchivedBtn');
                        if (btn && btn.innerText === "Hide Archived") {
                            const rows = document.querySelectorAll('.archived-room-row');
                            rows.forEach(r => r.style.display = 'table-row');
                        }
                    }
                })
                .catch(err => console.error("Error refreshing rooms menu:", err));
        }

        // --- SUBMIT FORM (Seamless Update) ---
        document.getElementById('roomForm').onsubmit = function (e) {
            e.preventDefault();

            // 1. Validation
            const bedTypeInput = document.getElementById('roomBedTypeInput');
            if (!bedTypeInput || bedTypeInput.value.trim() === "") {
                showError("Please select a Room Type.");
                if (bedTypeInput) bedTypeInput.focus();
                return;
            }

            // 2. UI Loading State
            const btn = this.querySelector('button[type="submit"]');
            if (btn.disabled) return;
            const originalText = btn.innerText;
            btn.innerText = "Uploading...";
            btn.disabled = true;

            const formData = new FormData(this);

            // 3. Send Request
            fetch('manage_rooms.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(data.message);
                        document.getElementById('roomModal').style.display = 'none';
                        refreshRoomsTable();
                    } else {
                        showError("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error: Check console for details.");
                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        };

        // --- AMENITY MANAGEMENT LOGIC ---

        function previewAmenityIcon(iconClass) {
            const preview = document.getElementById('amenityIconPreview');
            if (preview) {
                preview.innerHTML = `<i class="${iconClass}"></i>`;
            }
        }

        function openAddAmenityModal() {
            document.getElementById('amenityForm').reset();
            document.getElementById('amenityModalTitle').innerText = "Add New Amenity";
            document.getElementById('amenityAction').value = "add";
            document.getElementById('amenityId').value = "";
            previewAmenityIcon('fas fa-question-circle');
            document.getElementById('amenityModal').style.display = 'block';
        }

        function openEditAmenityModal(id, title, icon, desc) {
            document.getElementById('amenityModalTitle').innerText = "Edit Amenity";
            document.getElementById('amenityAction').value = "edit";
            document.getElementById('amenityId').value = id;
            document.getElementById('amenityTitleInput').value = title;
            document.getElementById('amenityIconInput').value = icon;
            document.getElementById('amenityDescInput').value = desc;
            previewAmenityIcon(icon);
            document.getElementById('amenityModal').style.display = 'block';
        }

        document.getElementById('amenityForm').onsubmit = function (e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerText;
            btn.innerText = "Saving...";
            btn.disabled = true;

            const formData = new FormData(this);

            fetch('manage_amenities.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(data.message);
                        document.getElementById('amenityModal').style.display = 'none';
                        fetchAmenitiesTable();
                    } else {
                        showError("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");
                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        };

        function fetchAmenitiesTable() {
            fetch('fetch_amenities_table.php')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.querySelector('#view-amenities .booking-table tbody').innerHTML = data.html;
                        
                        // ALSO: Refresh the Amenities Grid in the "Add/Edit Room" modal so it's synced
                        const grid = document.getElementById('amenitiesGrid');
                        if (grid) {
                            // Since we have the new HTML for the main table, we might need a separate 
                            // endpoint if we want to perfectly refresh the checkboxes, but for now 
                            // we'll focus on the management table. 
                            // Actually, a page reload might still be safest for the ROOM checkboxes 
                            // unless we create a helper for that too.
                        }
                    }
                });
        }

        async function deleteAmenity(id) {
            if (!await showConfirm("Confirmation", "Are you sure you want to delete this amenity? This will remove it from all assigned rooms.")) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('amenity_id', id);

            fetch('manage_amenities.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(data.message);
                        fetchAmenitiesTable();
                    } else {
                        showError("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");
                });
        }

        // --- RESTORE ROOM (Seamless) ---
        async function restoreRoom(id) {
            if (!await showConfirm("Restore Room", "Do you want to restore this room to the active list?")) return;

            const formData = new FormData();
            formData.append('action', 'restore');
            formData.append('room_id', id);

            fetch('manage_rooms.php', { // Make sure this matches your filename (manage_rooms.php or manage_rooms_.php)
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(data.message);

                        // Find row and un-hide it or remove "Archived" styling
                        // Since "Archived" usually hides the row in standard view, we might just remove the "ARCHIVED" badge text
                        // or reload if it's too complex to move it between lists visually.
                        // For seamless:
                        setTimeout(() => { location.reload(); }, 1500); // Restore is rare, reloading here is acceptable to resort the list correctly.
                    } else {
                        showError("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");
                });
        }

        // --- NEWS ARCHIVE LOGIC ---

        // --- TOGGLE ARCHIVED NEWS ---
        function toggleArchivedNews() {
            // 1. Find all rows with the specific class
            const rows = document.querySelectorAll('.archived-news-row');
            const btn = document.getElementById('toggleArchivedNewsBtn');
            let isHidden = false;

            // 2. Debugging: Check if we actually found any rows
            if (rows.length === 0) {
                console.warn("No archived news rows found in the DOM.");
                // Optional: Alert user if list is empty
                // showInfo("No archived items to show.");
                return;
            }

            // 3. Loop and Toggle
            rows.forEach(row => {
                if (row.style.display === 'none') {
                    row.style.display = 'table-row';
                    isHidden = false; // We just showed them
                } else {
                    row.style.display = 'none';
                    isHidden = true; // We just hidden them
                }
            });

            // 4. Update Button Text
            if (btn) {
                btn.innerText = isHidden ? "Show Archived" : "Hide Archived";
            }
        }


        // 3. New Restore Function
        async function restoreNews(id) {
            if (!await showConfirm("Restore News", "Restore this news item to the active list?")) return;

            const formData = new FormData();
            formData.append('action', 'restore');
            formData.append('news_id', id);

            fetch('manage_news.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(data.message);
                        fetchNewsTable();
                    } else {
                        showError(data.message.replace(/^Error:\s*/i, ""));
                    }
                });
        }

        // --- ROOM IMAGE PREVIEW ---
        // function previewRoomImage(input) {
        //     if (input.files && input.files[0]) {
        //         var reader = new FileReader();
        //         reader.onload = function (e) {
        //             document.getElementById('roomImagePreview').src = e.target.result;
        //             document.getElementById('roomImagePreview').style.display = 'block';
        //             document.getElementById('roomImagePlaceholder').style.display = 'none';
        //         };
        //         reader.readAsDataURL(input.files[0]);
        //     }
        // }

        // --- UPDATED ROOM MODAL JS ---


        // --- [FIX] START OF NEW GALLERY LOGIC (Paste this here) ---

        // 1. Preview Image Logic (Updated with Safety Check)
        function previewGalleryImage(input, index) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    // Safe selection
                    const preview = document.getElementById('preview_' + index);
                    const placeholder = document.getElementById('placeholder_' + index);

                    // Only try to set src if the element actually exists
                    if (preview) {
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                    }
                    if (placeholder) {
                        placeholder.style.display = 'none';
                    }
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // 3. Updated Add Room Modal (Resets all 4 boxes)
        function openAddRoomModal() {
            // Reset form text fields
            document.getElementById('roomForm').reset();
            document.getElementById('roomModalTitle').innerText = "Add New Room";
            document.getElementById('roomAction').value = "add";
            document.getElementById('roomId').value = "";

            // Reset all 4 Image Boxes to "Empty" state
            for (let i = 0; i < 4; i++) {
                const preview = document.getElementById('preview_' + i);
                const placeholder = document.getElementById('placeholder_' + i);
                const input = document.getElementById('file_' + i);

                if (preview) {
                    preview.src = "";
                    preview.style.display = 'none';
                }
                if (placeholder) placeholder.style.display = 'block';
                if (input) input.value = ""; // Clear the actual file input
            }

            // Unlock UI if it was locked by 'Edit' mode previously
            const uploaderBoxes = document.querySelectorAll('.gallery-box');
            uploaderBoxes.forEach(box => {
                box.style.pointerEvents = 'auto';
                box.style.opacity = '1';
                box.style.backgroundColor = '#F3F4F6';
            });

            // [INFO] RESET AMENITIES
            document.querySelectorAll('.am-checkbox').forEach(cb => {
                cb.checked = false;
                cb.disabled = false;
                cb.parentElement.style.opacity = '1';
                cb.parentElement.style.cursor = 'pointer';
            });

            document.getElementById('roomModal').style.display = 'block';
        }

        // 2. Edit Room Modal (Fixed: Now locks "Type" dropdown correctly)
        function openEditRoomModal(id, name, price, bedType, capacity, size, description, filePath, isBooked, amenities) {
            // 1. Set IDs
            document.getElementById('roomId').value = id;
            document.getElementById('roomAction').value = "edit";
            document.getElementById('roomModalTitle').innerText = isBooked ? "Edit Room (Locked)" : "Edit Room";

            // 2. Populate Text Fields safely
            if (document.getElementById('roomNameInput')) document.getElementById('roomNameInput').value = name;
            if (document.getElementById('roomPriceInput')) document.getElementById('roomPriceInput').value = price;
            if (document.getElementById('roomBedTypeInput')) document.getElementById('roomBedTypeInput').value = bedType;
            if (document.getElementById('roomCapacityInput')) document.getElementById('roomCapacityInput').value = capacity;
            if (document.getElementById('roomSizeInput')) document.getElementById('roomSizeInput').value = size;
            if (document.getElementById('roomDescInput')) document.getElementById('roomDescInput').value = description;

            // [INFO] POPULATE AMENITIES
            document.querySelectorAll('.am-checkbox').forEach(cb => {
                cb.checked = false; // Reset first
                if (isBooked) {
                    cb.disabled = true;
                    cb.parentElement.style.opacity = '0.6';
                    cb.parentElement.style.cursor = 'not-allowed';
                } else {
                    cb.disabled = false;
                    cb.parentElement.style.opacity = '1';
                    cb.parentElement.style.cursor = 'pointer';
                }
            });

            if (amenities) {
                const amArray = amenities.split(',').map(s => s.trim());
                amArray.forEach(amId => {
                    const checkbox = document.querySelector(`.am-checkbox[value="${amId}"]`);
                    if (checkbox) checkbox.checked = true;
                });
            }

            // [INFO] FORCE REFRESH the Custom Select so it shows the new value (bedType)
            refreshCustomSelect('roomBedTypeInput');

            // 3. Populate Images (Reset & Fill)
            for (let i = 0; i < 4; i++) {
                const preview = document.getElementById('preview_' + i);
                const placeholder = document.getElementById('placeholder_' + i);
                if (preview) { preview.src = ""; preview.style.display = 'none'; }
                if (placeholder) placeholder.style.display = 'block';
            }

            if (filePath && filePath.trim() !== "") {
                const images = filePath.split(',');
                images.forEach((imgName, index) => {
                    if (index < 4) {
                        const cleanName = imgName.trim();
                        if (cleanName !== "") {
                            const preview = document.getElementById('preview_' + index);
                            const placeholder = document.getElementById('placeholder_' + index);
                            if (preview) {
                                preview.src = '../../room_includes/uploads/images/' + cleanName;
                                preview.style.display = 'block';
                                if (placeholder) placeholder.style.display = 'none';
                            }
                        }
                    }
                });
            }

            // [INFO] LOCK LOGIC (Standard Inputs)
            const inputsToLock = ['roomNameInput', 'roomCapacityInput', 'roomSizeInput', 'roomDescInput'];
            inputsToLock.forEach(inputId => {
                const el = document.getElementById(inputId);
                if (el) {
                    el.readOnly = isBooked;
                    el.style.backgroundColor = isBooked ? "#e9ecef" : "#F5F5F5";
                    el.style.cursor = isBooked ? "not-allowed" : "text";
                }
            });

            // [INFO] LOCK THE CUSTOM "TYPE" SELECT MANUALLY
            const typeSelect = document.getElementById('roomBedTypeInput');
            if (typeSelect) {
                // Disable the hidden select (logic)
                typeSelect.disabled = isBooked;

                // Disable the visual wrapper (UI)
                const wrapper = typeSelect.nextElementSibling;
                if (wrapper && wrapper.classList.contains('custom-select-wrapper')) {
                    const trigger = wrapper.querySelector('.custom-select-trigger');
                    if (isBooked) {
                        // Locked State
                        wrapper.style.pointerEvents = 'none'; // Stop clicks
                        wrapper.style.opacity = '0.6'; // Visual dim
                        if (trigger) {
                            trigger.style.backgroundColor = '#e9ecef';
                            trigger.style.cursor = 'not-allowed';
                        }
                    } else {
                        // Active State (Unlocked)
                        wrapper.style.pointerEvents = 'auto';
                        wrapper.style.opacity = '1';
                        if (trigger) {
                            trigger.style.backgroundColor = '#fff';
                            trigger.style.cursor = 'pointer';
                        }
                    }
                }
            }

            // 5. LOCK LOGIC (Images)
            const galleryBoxes = document.querySelectorAll('#roomForm .gallery-box');
            galleryBoxes.forEach(box => {
                if (isBooked) {
                    box.style.pointerEvents = 'none';
                    box.style.opacity = '0.5';
                    box.style.backgroundColor = '#e9ecef';
                    box.style.border = '1px solid #ccc';
                } else {
                    box.style.pointerEvents = 'auto';
                    box.style.opacity = '1';
                    box.style.backgroundColor = '#F3F4F6';
                    box.style.border = '2px dashed #E5E7EB';
                }
            });

            document.getElementById('roomModal').style.display = 'block';
        }

        // 1. Toggle Visibility of Archived Rows
        function toggleArchivedRooms() {
            const rows = document.querySelectorAll('.archived-room-row');
            const btn = document.getElementById('toggleArchivedBtn');

            let isHidden = false;

            rows.forEach(row => {
                if (row.style.display === 'none') {
                    row.style.display = 'table-row';
                    isHidden = false;
                } else {
                    row.style.display = 'none';
                    isHidden = true;
                }
            });

            // Update Button Text
            btn.innerText = isHidden ? "Show Archived" : "Hide Archived";
        }

        // --- 6. GUEST SEARCH LOGIC ---
        function filterGuestTable() {
            // [INFO] UPDATED: Reset offset and fetch from server for real pagination
            guestOffset = 0;
            fetchGuestList(true); // true = silent/manual refresh
        }

        // Attach Event Listener when page loads
        document.addEventListener("DOMContentLoaded", function () {
            const guestInput = document.getElementById('guestSearchInput');
            if (guestInput) {
                guestInput.addEventListener('keyup', filterGuestTable);
            }
        });

        // --- HOTEL NEWS LOGIC ---

        // 1. Initialize Flatpickr for News Modal
        document.addEventListener("DOMContentLoaded", function () {
            flatpickr("#news_date_picker", {
                dateFormat: "Y-m-d",
                defaultDate: "today",
                static: true, // Important for modal positioning
                onReady: (d,s,inst) => inst.calendarContainer.classList.add("compact-theme")
            });
        });

        // 2. Image Preview
        function previewNewsImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    document.getElementById('newsImagePreview').src = e.target.result;
                    document.getElementById('newsImagePreview').style.display = 'block';
                    document.getElementById('newsImagePlaceholder').style.display = 'none';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // 3. Open Modal (Add)
        function openAddNewsModal() {
            document.getElementById('newsForm').reset();

            // [FIX] REPLACE THIS BLOCK:
            // Old TinyMCE code:
            // if (tinymce.get('newsDescInput')) { ... }

            // [INFO] NEW QUILL CODE:
            // 1. Clear the visual editor
            if (newsQuill) {
                newsQuill.setText('');
            }
            // 2. Clear the hidden input field manually
            document.getElementById('newsDescInput').value = '';


            document.getElementById('newsModalTitle').innerText = "Add News";
            document.getElementById('newsAction').value = "add";
            document.getElementById('newsId').value = "";

            // Reset Image
            document.getElementById('newsImagePreview').src = "";
            document.getElementById('newsImagePreview').style.display = 'none';
            document.getElementById('newsImagePlaceholder').style.display = 'block';

            // Reset Date to today
            const datePicker = document.getElementById('news_date_picker')._flatpickr;
            if (datePicker) {
                datePicker.setDate(new Date());
            }

            document.getElementById('newsModal').style.display = 'block';
        }

        // 4. Open Modal (Edit) - UPDATED WITH BASE64 DECODING
        function openEditNewsModal(id, title, date, encodedDesc, imgPath) {
            document.getElementById('eventId').value = ""; // Clear event ID just in case
            document.getElementById('newsId').value = id;
            document.getElementById('newsAction').value = "edit";
            document.getElementById('newsModalTitle').innerText = "Edit News";

            document.getElementById('newsTitleInput').value = title;

            // [INFO] DECODE BASE64 DESCRIPTION SAFELY
            let decodedDesc = "";
            try {
                // This handles special characters, emojis, and HTML tags correctly
                decodedDesc = decodeURIComponent(escape(window.atob(encodedDesc)));
            } catch (e) {
                console.error("Decoding error", e);
                decodedDesc = "";
            }

            // 1. Update the hidden input
            document.getElementById('newsDescInput').value = decodedDesc;

            // 2. Update the Quill Visual Editor
            if (newsQuill) {
                // Quill uses this method to parse HTML strings back into the editor
                // We use a slight delay to ensure the modal is rendered first
                setTimeout(() => {
                    newsQuill.clipboard.dangerouslyPasteHTML(decodedDesc);
                }, 50);
            }

            // Set Date Picker
            const datePicker = document.getElementById('news_date_picker')._flatpickr;
            if (datePicker) {
                datePicker.setDate(date);
            }

            // Image Preview Logic
            const preview = document.getElementById('newsImagePreview');
            const placeholder = document.getElementById('newsImagePlaceholder');

            if (imgPath && imgPath.trim() !== "") {
                preview.src = '../../room_includes/uploads/news/' + imgPath;
                preview.style.display = 'block';
                placeholder.style.display = 'none';
            } else {
                preview.src = "";
                preview.style.display = 'none';
                placeholder.style.display = 'block';
            }

            document.getElementById('newsModal').style.display = 'block';
        }

        // --- News Form Submit Handler ---
        const newsForm = document.getElementById('newsForm');
        if (newsForm) {
            newsForm.addEventListener('submit', function (e) {
                e.preventDefault();

                // 1. SYNC QUILL TO HIDDEN INPUT
                if (newsQuill) {
                    const content = newsQuill.root.innerHTML;
                    document.getElementById('newsDescInput').value = content;
                }

                const btn = this.querySelector('button[type="submit"]');
                const originalText = btn.innerText;
                btn.innerText = "Saving...";
                btn.disabled = true;

                const formData = new FormData(this);

                fetch('manage_news.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            showSuccess(data.message);
                            document.getElementById('newsModal').style.display = 'none';
                            fetchNewsTable();
                        } else {
                            showError(data.message);
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        showError("System Error");
                    })
                    .finally(() => {
                        btn.innerText = originalText;
                        btn.disabled = false;
                    });
            });
        }

        function fetchNewsTable() {
            fetch('fetch_news_table.php')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.querySelector('#view-news .booking-table tbody').innerHTML = data.html;
                        // Reset Archived Toggle state if needed
                        const btn = document.getElementById('toggleArchivedNewsBtn');
                        if (btn && btn.innerText === "Hide Archived") {
                            // If they were showing archived, we need to make them visible again because the fetch returns them with display:none by default
                            const rows = document.querySelectorAll('.archived-news-row');
                            rows.forEach(r => r.style.display = 'table-row');
                        }
                    }
                });
        }
        

        // --- [INFO] SEAMLESS FOOD MENU REFRESH ---
        function refreshFoodMenuTable() {
            const tbody = document.getElementById('foodMenuTableBody');
            if (!tbody) return;

            fetch('fetch_food_menu_table.php?t=' + new Date().getTime())
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        tbody.innerHTML = data.html;

                        // Re-apply archive visibility if needed
                        const btn = document.getElementById('toggleArchivedFoodBtn');
                        if (btn && btn.innerText === "Hide Archived") {
                            const rows = document.querySelectorAll('.archived-food-row');
                            rows.forEach(r => r.style.display = 'table-row');
                        }
                    }
                })
                .catch(err => console.error("Error refreshing food menu:", err));
        }

        // 5. Submit Form (Add/Edit) - SEAMLESS VERSION
        document.getElementById('foodForm').onsubmit = function (e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerText;
            btn.innerText = "Saving...";
            btn.disabled = true;

            const formData = new FormData(this);

            fetch('manage_food.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(data.message);
                        document.getElementById('foodModal').style.display = 'none';
                        refreshFoodMenuTable();
                    } else {
                        showError(data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");
                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        };

        // --- HELPER 1: Add New Row ---
        function addFoodRowToTable(item) {
            const tbody = document.getElementById('foodTableBody');
            const row = document.createElement('tr');
            row.id = 'food-row-' + item.id;
            row.innerHTML = generateFoodRowHTML(item);

            // Insert at top
            if (tbody.firstChild) {
                tbody.insertBefore(row, tbody.firstChild);
            } else {
                tbody.appendChild(row);
            }
        }

        // --- HELPER 2: Update Existing Row ---
        function updateFoodRowInTable(item) {
            const row = document.getElementById('food-row-' + item.id);
            if (row) {
                row.innerHTML = generateFoodRowHTML(item);
            }
        }

        // --- HELPER 3: Generate Row HTML (Shared) ---
        function generateFoodRowHTML(food) {
            // 1. Icon Logic
            const cat = food.category.toLowerCase();
            let iconClass = 'fa-concierge-bell';
            let iconColor = '#9CA3AF';

            if (cat.includes('beverage') || cat.includes('drink')) { iconClass = 'fa-glass-martini-alt'; iconColor = '#3B82F6'; }
            else if (cat.includes('dessert')) { iconClass = 'fa-ice-cream'; iconColor = '#EC4899'; }
            else if (cat.includes('snack') || cat.includes('appetizer')) { iconClass = 'fa-cookie-bite'; iconColor = '#F59E0B'; }
            else if (cat.includes('soup')) { iconClass = 'fa-mug-hot'; iconColor = '#EA580C'; }
            else if (cat.includes('breakfast')) { iconClass = 'fa-bacon'; iconColor = '#8B5CF6'; }
            else if (cat.includes('main')) { iconClass = 'fa-utensils'; iconColor = '#10B981'; }

            // 2. Image Logic
            let imgHTML = '';
            if (food.image_path) {
                imgHTML = `<img src="../../room_includes/uploads/food/${food.image_path}" style="width:100%; height:100%; object-fit:cover;">`;
            } else {
                imgHTML = `<i class="fas ${iconClass}" style="color: ${iconColor}; font-size: 1.1rem;"></i>`;
            }

            // 3. Escape Strings
            const safeName = food.item_name.replace(/'/g, "\\'");

            return `
        <td>
            <div style="width: 60px; height: 50px; background:#eee; border-radius:6px; overflow:hidden; border:1px solid #ddd; display:flex; align-items:center; justify-content:center;">
                ${imgHTML}
            </div>
        </td>
        <td style="text-align:center;">
            <i class="fas ${iconClass}" style="color: ${iconColor};"></i>
        </td>
        <td style="font-weight: 700; color: #333; font-size: 1rem;">${food.item_name}</td>
        <td>
            <span class="badge" style="background:#F3F4F6; color:#555; border:1px solid #ddd; text-transform:uppercase; letter-spacing:0.5px;">
                ${food.category}
            </span>
        </td>
        <td style="font-weight: 700; color: #B88E2F;">₱${parseFloat(food.price).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
        <td style="text-align: right;">
            <div style="display: flex; justify-content: flex-end; gap: 5px;">
                <button class="btn-secondary" style="padding:5px 10px;" onclick="openEditFoodModal(
                    '${food.id}', '${safeName}', '${food.category}', '${food.price}', '${food.image_path || ''}'
                )">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <button class="btn-secondary" style="padding:5px 10px; color:#DC2626; border-color: #FECACA; background: #FEF2F2;"
                    onclick="deleteFood('${food.id}')">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </td>
    `;
        }

        // 6. Delete News - SEAMLESS VERSION
        async function deleteNews(id) {
            if (!await showConfirm("Confirmation", "Are you sure you want to delete this news item?")) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('news_id', id);

            fetch('manage_news.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(data.message);
                        fetchNewsTable();
                    } else {
                        showError("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");
                });
        }


        // --- PERMANENT DELETE NEWS ---
        async function permanentDeleteNews(id) {
            if (!await showConfirm("Confirmation", "[WARN] WARNING: This will PERMANENTLY DELETE this news item from the database.\n\nThis action CANNOT be undone.\n\nAre you sure?")) return;

            const formData = new FormData();
            formData.append('action', 'hard_delete');
            formData.append('news_id', id);

            fetch('manage_news.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess("Item permanently deleted.");
                        fetchNewsTable();
                    } else {
                        showError("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");
                });
        }

        // --- HOTEL NEWS LOGIC ---

        // 1. Initialize Flatpickr for News Modal
        document.addEventListener("DOMContentLoaded", function () {

            // 1. Initialize Flatpickr for News Modal (Compact Theme)
            flatpickr("#news_date_picker", {
                dateFormat: "Y-m-d",
                defaultDate: "today",
                minDate: "today",
                disableMobile: "true",
                showMonths: 1,
                monthSelectorType: "static",
                onReady: function (selectedDates, dateStr, instance) {
                    instance.calendarContainer.classList.add("compact-theme");
                    initCustomFpHeader(instance, { showDropdowns: true });
                },
                onMonthChange: function (selectedDates, dateStr, instance) {
                    updateDropdownSelections(instance);
                },
                onYearChange: function (selectedDates, dateStr, instance) {
                    updateDropdownSelections(instance);
                },
            });

            // 2. REPLACE THE TINYMCE PART WITH THIS (For Quill)
            newsQuill = new Quill('#newsQuillEditor', {
                theme: 'snow',
                placeholder: 'Write news details here...',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline'],
                        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                        ['link', 'clean']
                    ]
                }
            });

        });


        // --- FOOD MENU FUNCTIONS ---

        // --- FOOD MENU FUNCTIONS (SIMPLIFIED) ---

        // 1. Image Preview Function
        function previewFoodImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    document.getElementById('foodImagePreview').src = e.target.result;
                    document.getElementById('foodImagePreview').style.display = 'block';
                    document.getElementById('foodImagePlaceholder').style.display = 'none';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // 2. Open Add Modal
        function openAddFoodModal() {
            document.getElementById('foodForm').reset();
            document.getElementById('foodModalTitle').innerText = "Add Menu Item";
            document.getElementById('foodAction').value = "add";
            document.getElementById('foodId').value = "";

            // Reset Image
            document.getElementById('foodImagePreview').src = "";
            document.getElementById('foodImagePreview').style.display = 'none';
            document.getElementById('foodImagePlaceholder').style.display = 'block';

            document.getElementById('foodModal').style.display = 'block';
        }

        // 3. Open Edit Modal (Accepts imgPath)
        function openEditFoodModal(id, name, category, price, imgPath) {
            document.getElementById('foodId').value = id;
            document.getElementById('foodAction').value = "edit";
            document.getElementById('foodModalTitle').innerText = "Edit Menu Item";

            document.getElementById('foodNameInput').value = name;
            document.getElementById('foodCategoryInput').value = category;
            document.getElementById('foodPriceInput').value = price;

            // Handle Image Preview
            const preview = document.getElementById('foodImagePreview');
            const placeholder = document.getElementById('foodImagePlaceholder');

            if (imgPath) {
                preview.src = '../../room_includes/uploads/food/' + imgPath;
                preview.style.display = 'block';
                placeholder.style.display = 'none';
            } else {
                preview.style.display = 'none';
                placeholder.style.display = 'block';
            }

            document.getElementById('foodModal').style.display = 'block';
        }

        // --- FOOD MENU FUNCTIONS (MANUAL DOM UPDATE) ---

        // 1. Toggle Visibility of Archived Items
        function toggleArchivedFood() {
            const rows = document.querySelectorAll('.archived-food-row');
            const btn = document.getElementById('toggleArchivedFoodBtn');

            if (rows.length === 0) {
                showInfo("No archived items found.");
                return;
            }

            // Check visibility based on the first row found
            // If it's hidden ('none'), we want to show all.
            let showAll = (rows[0].style.display === 'none');

            rows.forEach(row => {
                row.style.display = showAll ? 'table-row' : 'none';
            });

            if (btn) btn.innerText = showAll ? "Hide Archived" : "Show Archived";
        }

        // 2. Soft Delete (Archive)
        async function deleteFood(id) {
            if (!await showConfirm("Archive Item", "Archive this item? It will be hidden from the menu.")) return;

            const formData = new FormData();
            formData.append('action', 'delete'); // Matches PHP 'delete' (soft)
            formData.append('food_id', id);

            fetch('manage_food.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(data.message);
                        refreshFoodMenuTable();
                    } else {
                        showError("Error: " + data.message);
                    }
                })
                .catch(err => console.error(err));
        }

        // 3. Restore Item
        async function restoreFood(id) {
            if (!await showConfirm("Restore Item", "Restore this item to the active menu?")) return;

            const formData = new FormData();
            formData.append('action', 'restore');
            formData.append('food_id', id);

            fetch('manage_food.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(data.message);
                        setTimeout(() => { location.reload(); }, 1000); // Reload to re-render row as Active
                    }
                });
        }

        // 4. Permanent Delete
        async function permanentDeleteFood(id) {
            if (!await showConfirm("PERMANENT DELETE", "This cannot be undone.\n\nAre you sure?", 'error')) return;

            const formData = new FormData();
            formData.append('action', 'hard_delete');
            formData.append('food_id', id);

            fetch('manage_food.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(data.message);
                        // Remove row directly from DOM since it's gone forever
                        const row = document.getElementById('food-menu-row-' + id);
                        if (row) row.remove();
                    } else {
                        showError("Error: " + data.message);
                    }
                });
        }

        // 5. Toggle Stock (In Stock / Out of Stock)
        function toggleStock(id, newStatus) {
            const formData = new FormData();
            formData.append('action', 'toggle_stock');
            formData.append('food_id', id);
            formData.append('status', newStatus);

            fetch('manage_food.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        refreshFoodTable(); // Updates the badge color immediately
                    }
                });
        }

        // Seamless Food Submit is handled by the unified handler at line 3846.

        function updateArrivalTimeOptions() {
            const checkin = document.getElementById('checkin_picker').value;
            const select = document.getElementById('arrival_time_select');

            // 1. Clear existing options
            select.innerHTML = '<option value="" disabled selected>- Select -</option>';

            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const todayStr = `${year}-${month}-${day}`;

            // Standard Check-in is 2 PM (14:00)
            let startHour = 14;
            let endHour = 20; // 8 PM

            // REALTIME CHECK: If booking for TODAY
            if (checkin === todayStr) {
                const currentHour = now.getHours();
                // If it's past 2 PM, start options from the Next Hour
                if (currentHour >= 14) {
                    startHour = currentHour + 1;
                }
            }

            // Loop to generate time options
            for (let h = startHour; h <= endHour; h++) {
                let realHour24 = h;
                let displayHour12 = realHour24;
                let suffix = 'AM';

                if (displayHour12 >= 12) {
                    suffix = 'PM';
                    if (displayHour12 > 12) displayHour12 -= 12;
                }
                if (displayHour12 === 0) displayHour12 = 12;

                // 1. Value to save (24-Hour)
                let valueStr = (realHour24 < 10 ? '0' : '') + realHour24 + ':00';
                // 2. Text to show (12-Hour)
                let textStr = (displayHour12 < 10 ? '0' : '') + displayHour12 + ':00 ' + suffix;

                let opt = document.createElement('option');
                opt.value = valueStr;
                opt.innerText = textStr;
                select.appendChild(opt);

                // Add 30-minute interval
                if (h < endHour) {
                    let halfValue = (realHour24 < 10 ? '0' : '') + realHour24 + ':30';
                    let halfText = (displayHour12 < 10 ? '0' : '') + displayHour12 + ':30 ' + suffix;
                    let halfOpt = document.createElement('option');
                    halfOpt.value = halfValue;
                    halfOpt.innerText = halfText;
                    select.appendChild(halfOpt);
                }
            }

            // [INFO] CRITICAL FIX: Refresh the Custom UI to show these new options
            refreshCustomSelect('arrival_time_select');
        }

        // --- MASTER NAVIGATION & STATE HANDLER (FINAL PERSISTENCE UPDATE) ---
        document.addEventListener("DOMContentLoaded", function () {

            // --- [INFO] INITIALIZE STATE ON RELOAD ---
            const activePage = localStorage.getItem('activePage') || 'dashboard';
            const lastSubView = localStorage.getItem('activeSettingsView');

            // 1. Clear all
            document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.page').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.settings-view').forEach(el => {
                el.classList.remove('active');
                el.style.display = 'none';
            });

            // 2. Activate Main Page
            const targetNav = document.querySelector(`.nav-item[data-page="${activePage}"]`);
            if (targetNav) targetNav.classList.add('active');

            const targetPage = document.getElementById(activePage);
            if (targetPage) targetPage.classList.add('active');

            // 3. Activate Settings Sub-View
            if (activePage === 'settings') {
                const homeGrid = document.getElementById('settings-home');
                if (lastSubView && lastSubView !== 'settings-home') {
                    const sub = document.getElementById(lastSubView);
                    if (sub) {
                        if (homeGrid) homeGrid.style.display = 'none';
                        sub.classList.add('active');
                        sub.style.display = '';
                    } else {
                        if (homeGrid) {
                            homeGrid.classList.add('active');
                            homeGrid.style.display = '';
                        }
                    }
                } else {
                    if (homeGrid) {
                        homeGrid.classList.add('active');
                        homeGrid.style.display = '';
                    }
                }
            }

            function resetSettingsToHome() {
                console.log("Cleaning up Settings views...");
                document.querySelectorAll('.settings-view').forEach(v => {
                    v.classList.remove('active');
                    v.style.display = 'none';
                });

                const home = document.getElementById('settings-home');
                if (home) {
                    home.classList.add('active');
                    home.style.display = ''; // Let CSS flex/block handle it
                }

                // Only clear memory if we are explicitly resetting to home
                localStorage.removeItem('activeSettingsView');
            }

            // [INFO] TABLE LOAD TRACKERS (Prevents repeated spinners when switching tabs)
            let bookingsLoaded = false;
            let guestsLoaded = false;
            let foodLoaded = false;
            let transactionsLoaded = false;

            // --- CLICK HANDLER ---
            document.querySelectorAll('.nav-menu .nav-item').forEach(link => {
                link.onclick = function (e) {
                    const pageId = this.getAttribute('data-page');
                    if (!pageId) return;

                    e.preventDefault();

                    localStorage.setItem('activePage', pageId);

                    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
                    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));

                    // Only reset sub-views if we are moving to a page that ISN'T Settings
                    // This prevents clicking "Settings" in sidebar from wiping your current sub-page
                    if (pageId !== 'settings') {
                        resetSettingsToHome();
                    }

                    this.classList.add('active');
                    const newPage = document.getElementById(pageId);
                    if (newPage) {
                        newPage.classList.add('active');
                        newPage.scrollTop = 0;
                    }

                    // [INFO] Refresh data for the specific page being opened (Only first load is non-silent)
                    if (pageId === 'transactions') {
                        loadTransactions(transactionsLoaded);
                        transactionsLoaded = true;
                    } else if (pageId === 'guests') {
                        fetchGuestList(guestsLoaded);
                        guestsLoaded = true;
                    } else if (pageId === 'bookings') {
                        refreshBookingTable(bookingsLoaded);
                        bookingsLoaded = true;
                    } else if (pageId === 'food-ordered') {
                        refreshFoodTable(foodLoaded);
                        foodLoaded = true;
                    } else if (pageId === 'calendar') {
                        // 🟢 Trigger skeleton immediately on tab switch
                        renderRealtimeCalendar(true);
                        refreshCalendarData();
                    }
                };
            });
        });

        // --- SETTINGS VIEW OPENER (For the Grid Cards) ---
        function openSettingsView(viewId) {
            console.log("Opening Settings Sub-View:", viewId);

            // 1. Reset scroll position of the settings container
            const settingsPage = document.getElementById('settings');
            if (settingsPage) {
                settingsPage.scrollTop = 0;
            }

            // 2. Clear all views
            document.querySelectorAll('.settings-view').forEach(v => {
                v.classList.remove('active');
                v.style.display = 'none'; // Clear manual overrides
            });

            // 3. Show target view
            const target = document.getElementById(viewId);
            if (target) {
                target.classList.add('active');
                // Remove the manual display: block override to let CSS flex take over
                target.style.display = '';

                // CRITICAL: Save to storage so it survives a reload
                localStorage.setItem('activeSettingsView', viewId);

                const homeGrid = document.getElementById('settings-home');
                if (viewId !== 'settings-home' && homeGrid) {
                    homeGrid.style.display = 'none';
                }
            }
        }


        // --- REAL-TIME BADGE UPDATER (Manual Only Mode) ---
        function updateBadgesRealtime() {
            // 1. Get Current Time
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const todayStr = `${year}-${month}-${day}`;

            // 2. Select all booking rows
            const rows = document.querySelectorAll('.booking-row');

            rows.forEach(row => {
                // Get Data from HTML Attributes
                const status = row.getAttribute('data-status');
                const arrival = row.getAttribute('data-arrival');
                const checkinDate = row.getAttribute('data-checkin');

                // --------------------------------------------------------
                // VISUAL BADGE UPDATES ONLY (No Database Changes)
                // --------------------------------------------------------

                // We only update badges for Confirmed bookings that are NOT In-House, Checked-Out, or No-Show.
                // This ensures manual statuses (like 'no_show' set by admin) are respected and not overwritten.
                if (status === 'confirmed' &&
                    arrival !== 'in_house' &&
                    arrival !== 'checked_out' &&
                    arrival !== 'no_show') {

                    const badge = row.querySelector('.arrival-badge');

                    if (badge) {
                        // 1. Explicit DB Status or Today's Date -> FORCE BLUE
                        // If the DB says 'arriving_today' OR the date matches today:
                        // Show "Arriving Today" (Blue). We ignore the time (8PM rule removed).
                        if (arrival === 'arriving_today' || checkinDate === todayStr) {
                            badge.className = 'arrival-badge arrival-today';
                            badge.innerText = 'Arriving Today';
                        }
                        // 2. Future Date -> Yellow
                        else if (checkinDate > todayStr) {
                            badge.className = 'arrival-badge arrival-upcoming';
                            badge.innerText = 'Upcoming';
                        }
                        // 3. Past Date -> Red (Overdue)
                        // Only turns red if the date is strictly yesterday or older.
                        else if (checkinDate < todayStr) {
                            badge.className = 'arrival-badge arrival-overdue';
                            badge.innerText = 'Late Arrival';
                        }
                    }
                }
            });
        }


        // --- RENDER MESSAGES ---

        // --- 1. TOGGLE DROPDOWN (Smart Reset Version) ---
        function toggleDropdown(dropdownId, event) {
            event.stopPropagation();

            // 1. Close any OTHER open dropdowns first
            document.querySelectorAll('.dropdown-menu').forEach(dd => {
                if (dd.id !== dropdownId) dd.classList.remove('show');
            });

            // 2. Get the target dropdown
            const targetDropdown = document.getElementById(dropdownId);

            // 3. Check if we are about to OPEN it (before we toggle the class)
            const isOpening = !targetDropdown.classList.contains('show');

            // 4. Toggle visibility
            targetDropdown.classList.toggle('show');

            // 5. IF OPENING -> RESET EVERYTHING TO 'ALL'
            if (isOpening) {

                // --- A. Reset Notifications ---
                if (dropdownId === 'notifDropdown') {
                    // 1. Reset the "Memory" variable
                    currentNotifFilter = 'all';

                    // 2. Reset the Visual Filter Menu (Hide popup, set 'All' to active)
                    const filterMenu = document.getElementById('notifFilterMenu');
                    if (filterMenu) {
                        filterMenu.style.display = 'none';
                        filterMenu.querySelectorAll('.filter-option').forEach(el => el.classList.remove('active'));
                        if (filterMenu.firstElementChild) filterMenu.firstElementChild.classList.add('active');
                    }

                    // 3. Force render ALL items immediately
                    if (window.allNotifications) {
                        renderNotificationList(window.allNotifications);
                    }
                }

                // --- B. Reset Messages ---
                if (dropdownId === 'msgDropdown') {
                    // 1. Reset the "Memory" variable
                    currentMsgFilter = 'all';

                    // 2. Reset the Visual Filter Menu
                    const msgFilterMenu = document.getElementById('msgFilterMenu');
                    if (msgFilterMenu) {
                        msgFilterMenu.style.display = 'none';
                        msgFilterMenu.querySelectorAll('.filter-option').forEach(el => el.classList.remove('active'));
                        if (msgFilterMenu.firstElementChild) msgFilterMenu.firstElementChild.classList.add('active');
                    }

                    // 3. Force render ALL items immediately
                    if (window.allMessages) {
                        renderMessageList(window.allMessages);
                    }
                }
            }
        }

        // --- UNIFIED WINDOW CLICK HANDLER ---
        window.onclick = function (event) {

            // [FIX] SAFETY CHECK: Prevent closing ANY modal if a process is running
            if (isProcessingBooking || isSendingEmail) {
                // If the user clicks the background of ANY modal while processing, ignore the click.
                if (event.target.classList.contains('modal')) {
                    console.log("[BLOCKED] Click blocked: Operation in progress.");
                    return;
                }
            }

            // 1. Close Modal if background clicked (Normal behavior)
            if (event.target.classList.contains('modal')) {
                
                // [INFO] SPECIAL CASE: Prevent closing Add Booking Modal on Steps 2 and 3
                if (event.target.id === 'addBookingModal') {
                    // Check if currentStep is 2 (Select Rooms) or 3 (Guest Info)
                    if (typeof currentStep !== 'undefined' && (currentStep === 2 || currentStep === 3)) {
                        console.log("[BLOCKED] Outside click blocked: Add Booking Step " + currentStep);
                        return; // Do nothing, keep modal open
                    }
                    resetModal();
                }

                // [INFO] SPECIAL CASE: Prevent closing Guest Profile Modal on outside click
                if (event.target.id === 'guestProfileModal') {
                    console.log("[BLOCKED] Outside click blocked: Guest Profile");
                    return; // Do nothing
                }

                if (event.target.id === 'adminEditModal') toggleAdminEdit(false);
                
                event.target.style.display = 'none';
            }

            // 2. Close Main Dropdowns (Bell/Message)
            if (!event.target.closest('.action-wrapper') && !event.target.closest('.modal')) {
                document.querySelectorAll('.dropdown-menu').forEach(dd => dd.classList.remove('show'));
            }

            // 3. Close Filter Menus if clicking outside
            if (!event.target.closest('.filter-btn') && !event.target.closest('.filter-menu-container')) {
                const notifMenu = document.getElementById('notifFilterMenu');
                const msgMenu = document.getElementById('msgFilterMenu');

                if (notifMenu) notifMenu.style.display = 'none';
                if (msgMenu) msgMenu.style.display = 'none';
            }
        }

        // --- 2. FETCH DATA FROM API ---
        function fetchHeaderData() {
            return fetch('get_header_data.php')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        renderNotifications(data.notifications, data.counts.notifications);
                        renderMessages(data.messages, data.counts.messages);

                        // NEW: Update late arrival alert
                        if (data.counts.late_arrivals !== undefined) {
                            updateLateArrivalAlert(data.counts.late_arrivals);
                        }
                    }
                })
                .catch(err => console.error("Header API Error:", err));
        }

        // --- 1. NEW: Refactored Render Function (Splits Logic) ---
        function renderNotifications(items, count) {
            // A. Store the Master List
            window.allNotifications = items;

            // B. Update the Badge
            const btn = document.querySelector('.btn-notify');
            let badge = btn.querySelector('.icon-badge');
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'icon-badge';
                btn.appendChild(badge);
            }
            badge.style.display = count > 0 ? 'flex' : 'none';
            badge.innerText = count > 9 ? '9+' : count;

            // NEW: Show/hide the floating alert
            updateNotificationAlert(count);

            // C. Render, but respect the active filter!
            filterAndRender();
        }

        // --- 3. NEW: Filter Logic Functions ---

        function toggleNotifFilter(event) {
            event.stopPropagation(); // Stop click from closing the main dropdown
            const menu = document.getElementById('notifFilterMenu');
            menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
        }

        function applyNotifFilter(criteria, element) {
            // 1. Stop click from bubbling (Prevents window.onclick from closing it)
            if (event) event.stopPropagation();

            // 2. Update Visuals (Active Class)
            document.querySelectorAll('.filter-option').forEach(el => el.classList.remove('active'));
            if (element) element.classList.add('active');

            // 3. Save the filter to memory!
            currentNotifFilter = criteria;

            // 4. Apply the filter logic
            filterAndRender();
        }

        // Global variable to store what is currently being shown (for the "View All" button)
        window.currentFilteredList = [];

        // --- NEW VARIABLES FOR DATE FILTERING ---
        let currentNotifDate = null; // Stores the selected date (YYYY-MM-DD)
        let currentMsgDate = null;   // Stores the selected date for messages

        // Initialize Flatpickr for the notification and message filters
        function initFilterPickers() {
            const nInput = document.getElementById("notifDateFilter");
            const mInput = document.getElementById("msgDateFilter");

            if (nInput) {
                flatpickr(nInput, {
                    dateFormat: "Y-m-d",
                    static: true,
                    disableMobile: true,
                    onReady: function (sd, ds, inst) {
                        inst.calendarContainer.classList.add("compact-theme");
                        initCustomFpHeader(inst, { showDropdowns: true });
                    },
                    onMonthChange: function (sd, ds, inst) {
                        updateDropdownSelections(inst);
                    },
                    onYearChange: function (sd, ds, inst) {
                        updateDropdownSelections(inst);
                    },
                    onChange: function (selectedDates, dateStr, instance) {
                        currentNotifDate = dateStr;
                        filterAndRender();
                    }
                });
            }

            if (mInput) {
                flatpickr(mInput, {
                    dateFormat: "Y-m-d",
                    static: true,
                    disableMobile: true,
                    onReady: function (sd, ds, inst) {
                        inst.calendarContainer.classList.add("compact-theme");
                        initCustomFpHeader(inst, { showDropdowns: true });
                    },
                    onMonthChange: function (sd, ds, inst) {
                        updateDropdownSelections(inst);
                    },
                    onYearChange: function (sd, ds, inst) {
                        updateDropdownSelections(inst);
                    },
                    onChange: function (selectedDates, dateStr, instance) {
                        currentMsgDate = dateStr;
                        filterAndRenderMessages();
                    }
                });
            }
        }

        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", initFilterPickers);
        } else {
            initFilterPickers();
        }

        // Function to clear the date filter
        function clearNotifDate() {
            currentNotifDate = null;
            const picker = document.querySelector("#notifDateFilter")._flatpickr;
            if (picker) picker.clear();
            filterAndRender();
        }

        function filterAndRender() {
            let filteredList = window.allNotifications;

            // 1. Filter by Type (Existing Logic)
            if (currentNotifFilter !== 'all') {
                if (currentNotifFilter === 'unread') {
                    filteredList = filteredList.filter(n => n.is_read == 0);
                } else {
                    filteredList = filteredList.filter(n => n.type === currentNotifFilter);
                }
            }

            // 2. Filter by Date (New Logic)
            if (currentNotifDate) {
                filteredList = filteredList.filter(n => {
                    // Extract the 'YYYY-MM-DD' part from the notification's 'created_at' string
                    const notifDate = n.created_at.split(' ')[0];
                    return notifDate === currentNotifDate;
                });
            }

            // 3. Render the result
            renderNotificationList(filteredList);
        }

        // --- 2. NEW: Pure Renderer (Just builds HTML) ---
        function renderNotificationList(items) {
            const listContainer = document.querySelector('#notifDropdown .dropdown-list');
            listContainer.innerHTML = '';

            if (items.length === 0) {
                let msg = "No notifications found";
                let subMsg = "We'll let you know when something important happens.";
                // Customize message if a date is selected
                if (currentNotifDate) {
                    msg = `No notifications`;
                    subMsg = `There are no records found for ${currentNotifDate}.`;
                }
                listContainer.innerHTML = `
                    <div style="padding:40px 20px; text-align:center; color:#94a3b8;">
                        <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;">
                            <div style="width:50px; height:50px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                                <i class="fas fa-bell-slash" style="font-size:1.4rem; color:#cbd5e1;"></i>
                            </div>
                            <div style="font-weight:600; font-size:1rem; color:#64748b;">${msg}</div>
                            <p style="margin:0; font-size:0.8rem; line-height:1.4;">${subMsg}</p>
                        </div>
                    </div>`;
                return;
            }

            // Render items (Showing up to 50 to ensure daily list is visible)
            items.slice(0, 50).forEach(item => {
                let iconClass = 'fa-info-circle', colorClass = 'icon-blue';

                if (item.type === 'booking') { iconClass = 'fa-calendar-check'; colorClass = 'icon-gold'; }
                if (item.type === 'cancel') { iconClass = 'fa-calendar-times'; colorClass = 'icon-red'; }
                if (item.type === 'reminder') { iconClass = 'fa-clock'; colorClass = 'icon-blue'; }

                const bgStyle = item.is_read == 0 ? 'background-color: #f0f9ff;' : '';

                const dateStr = new Date(item.created_at).toLocaleDateString('en-US', {
                    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                });

                const html = `
    <div class="dropdown-item-row" style="${bgStyle}" 
         onclick="openNotificationModal(${item.id})">
        <div class="item-icon-box ${colorClass}"><i class="fas ${iconClass}"></i></div>
        <div class="item-content">
            <div class="item-header">
                <span class="item-title">${item.title}</span>
                <span class="item-time" style="font-size:0.7rem;">${dateStr}</span>
            </div>
            <div class="item-desc">${item.description}</div>
        </div>
    </div>`;

                listContainer.innerHTML += html;
            });
        }

        // --- OPEN NOTIFICATION MODAL (Fixed Logic) ---
        function openNotificationModal(id) {
            // 1. Look up the data from the global list using the ID
            const item = window.allNotifications.find(n => n.id == id);

            if (!item) {
                console.error("Notification data not found for ID:", id);
                return;
            }

            // 2. Populate Modal from the 'item' object we found
            document.getElementById('notifModalTitle').innerText = item.title;
            document.getElementById('notifModalDesc').innerText = item.description;

            // 3. Format Date
            const dateObj = new Date(item.created_at);
            const dateStr = dateObj.toLocaleDateString('en-US', {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });
            document.getElementById('notifModalDate').innerText = dateStr;

            // 4. Show Modal
            document.getElementById('notificationModal').style.display = 'block';

            // 5. Mark as Read
            if (item.is_read == 0) {
                markAsRead(id, 'notification');
                item.is_read = 1; // Update memory so badge doesn't reappear
            }
        }


        // --- 4. RENDER MESSAGES ---
        function renderMessages(items, count) {
            // A. Store Data
            window.allMessages = items;

            // B. Update Badge
            const btn = document.querySelector('.btn-compose');
            let badge = btn.querySelector('.icon-badge');
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'icon-badge';
                btn.appendChild(badge);
            }
            badge.style.display = count > 0 ? 'flex' : 'none';
            badge.innerText = count > 9 ? '9+' : count;

            updateMessageAlert(count);
            // C. Render based on active filter
            filterAndRenderMessages();
        }

        // Pure HTML Builder
        function renderMessageList(items) {
            const list = document.querySelector('#msgDropdown .dropdown-list');
            list.innerHTML = '';

            if (items.length === 0) {
                list.innerHTML = '<div style="padding:20px; text-align:center; color:#999; font-size:0.85rem;">No messages found</div>';
                return;
            }

            items.forEach(item => {
                const bgStyle = item.is_read == 0 ? 'background-color: #f0f9ff;' : '';
                const dateStr = new Date(item.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });

                // Escape special characters for the onclick string
                const safeMsg = item.message.replace(/'/g, "\\'").replace(/"/g, '&quot;');

                const html = `
            <div class="dropdown-item-row" style="${bgStyle}" 
                 onclick="openMessage(${item.id}, '${item.guest_name}', '${item.email}', '${safeMsg}')">
                <div class="item-icon-box icon-blue"><i class="fas fa-envelope"></i></div>
                <div class="item-content">
                    <div class="item-header">
                        <span class="item-title">${item.guest_name}</span>
                        <span class="item-time" style="font-size:0.7rem;">${dateStr}</span>
                    </div>
                    <div class="item-desc">${item.message}</div>
                </div>
            </div>`;

                list.innerHTML += html;
            });
        }

        // --- 5. INTERACTIVITY (Open & Mark Read) ---
        function openMessage(id, name, email, body) {
            document.getElementById('msgModalName').innerText = name;
            document.getElementById('msgModalEmail').innerText = email;
            document.getElementById('msgModalBody').innerText = body;
            document.getElementById('messageModal').style.display = 'block';
            markAsRead(id, 'message');
        }

        // --- MESSAGE FILTER FUNCTIONS ---

        function toggleMsgFilter(event) {
            event.stopPropagation();
            const menu = document.getElementById('msgFilterMenu');
            // Toggle display
            menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';

            // Close the notification filter if it's open (good UX)
            const notifMenu = document.getElementById('notifFilterMenu');
            if (notifMenu) notifMenu.style.display = 'none';
        }

        function applyMsgFilter(criteria, element) {
            if (event) event.stopPropagation();

            // 1. Visuals
            // Find filter options specifically inside the msgFilterMenu
            const container = document.getElementById('msgFilterMenu');
            container.querySelectorAll('.filter-option').forEach(el => el.classList.remove('active'));
            element.classList.add('active');

            // 2. Save State
            currentMsgFilter = criteria;

            // 3. Render
            filterAndRenderMessages();
        }

        function filterAndRenderMessages() {
            let filteredList = window.allMessages || [];

            // 1. Filter by Type
            if (currentMsgFilter === 'unread') {
                filteredList = filteredList.filter(m => m.is_read == 0);
            }

            // 2. Filter by Date (New Logic)
            if (currentMsgDate) {
                filteredList = filteredList.filter(m => {
                    if (!m.created_at) return false;
                    const msgDate = m.created_at.split(' ')[0]; // Assumes "YYYY-MM-DD HH:MM:SS"
                    return msgDate === currentMsgDate;
                });
            }

            renderMessageList(filteredList);
        }

        function clearMsgDate(event) {
            if (event) event.stopPropagation();
            currentMsgDate = null;
            const input = document.getElementById('msgDateFilter');
            if (input && input._flatpickr) {
                input._flatpickr.clear();
            }
            filterAndRenderMessages();
        }

        function markAsRead(id, type) {
            fetch('mark_as_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id, type: type })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') fetchHeaderData(); // Refresh UI
                });
        }

        async function markAllMessagesRead(event) {
            if (event) event.stopPropagation();
            if (!await showConfirm("Confirmation", 'Mark all messages as read?')) return;

            fetch('mark_as_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: 'message_all' })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess("All messages marked as read.");
                        fetchHeaderData(); // Refresh UI
                    }
                })
                .catch(err => console.error(err));
        }

        async function markAllNotificationsRead(event) {
            if (event) event.stopPropagation();
            if (!await showConfirm("Confirmation", 'Mark all notifications as read?')) return;

            fetch('mark_as_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: 'notification_all' })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess("All notifications marked as read.");
                        fetchHeaderData(); // Refresh UI
                    }
                })
                .catch(err => console.error(err));
        }

        async function extendBooking(id, currentCheckout, roomName, currentPrice) {
            // 1. Ask for new date using Swal
            const { value: newDate } = await Swal.fire({
                title: 'Extend Stay',
                html: `Current Checkout: <b>${currentCheckout}</b><br><br>Enter new checkout date:`,
                input: 'date',
                inputValue: currentCheckout,
                showCancelButton: true,
                confirmButtonColor: '#B88E2F',
                cancelButtonColor: '#6B7280',
                inputValidator: (value) => {
                    if (!value) return 'Please select a date!';
                    if (value <= currentCheckout) return 'New date must be later than current checkout!';
                }
            });

            if (!newDate || newDate === currentCheckout) return;

            // 2. Confirm action
            if (!await showConfirm("Confirmation", `Are you sure you want to extend this booking to ${newDate}? This will calculate the new price automatically.`)) return;

            // 3. Send to Server
            const formData = new FormData();
            formData.append('id', id);
            formData.append('action', 'extend');
            formData.append('new_checkout', newDate);
            formData.append('csrf_token', csrfToken);

            fetch('update_arrival.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(`Success! Stay extended to ${newDate}.\nNew Total Price: ₱${data.new_total}`);
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showError("Failed: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("Request failed.");
                });
        }

        // --- EXTEND STAY LOGIC ---
        let extendPickerInstance = null;

        function openExtendModal(id, currentCheckout) {
            // 0. Reset Modal View State (Crucial Fix)
            document.getElementById('ext_main_content').style.display = 'block';
            document.getElementById('ext_conflict_resolution').style.display = 'none';
            document.getElementById('ext_conflict_resolution').innerHTML = '';

            // 1. Set IDs and Text
            document.getElementById('ext_booking_id').value = id;
            document.getElementById('ext_current_date').innerText = currentCheckout;

            // --- [INFO] FETCH ROOMS FOR THIS BOOKING ---
            const roomsList = document.getElementById('ext_rooms_list');
            roomsList.innerHTML = '<span style="font-size:0.8rem; color:#999;">Loading rooms...</span>';

            fetch(`get_booking_rooms.php?id=${id}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        roomsList.innerHTML = '';
                        data.rooms.forEach(room => {
                            const tag = document.createElement('span');
                            tag.style.cssText = "background:#F3F4F6; color:#374151; padding:4px 10px; border-radius:6px; font-size:0.8rem; font-weight:600; border:1px solid #E5E7EB;";
                            tag.innerText = room.room_name;
                            roomsList.appendChild(tag);
                        });
                    }
                })
                .catch(err => {
                    roomsList.innerHTML = '<span style="color:#EF4444; font-size:0.8rem;">Error loading rooms.</span>';
                });

            // Normalize current checkout date
            const currentCheckoutObj = new Date(currentCheckout);
            currentCheckoutObj.setHours(0, 0, 0, 0);

            // Set daily rate
            const dailyRate = parseFloat(currentDailyPrice) || 0;
            document.getElementById('ext_daily_rate').innerText = '₱' + dailyRate.toLocaleString();
            document.getElementById('ext_price_preview').style.display = 'none';

            // 2. Initialize Flatpickr
            const nextDay = new Date(currentCheckoutObj);
            nextDay.setDate(nextDay.getDate() + 1);

            if (extendPickerInstance) {
                extendPickerInstance.destroy();
            }

            const updatePreview = (selectedDate) => {
                if (!selectedDate) return;
                const d2 = new Date(selectedDate);
                d2.setHours(0, 0, 0, 0);

                const diffTime = d2.getTime() - currentCheckoutObj.getTime();
                const diffDays = Math.round(diffTime / (1000 * 60 * 60 * 24));

                if (diffDays > 0) {
                    const totalExtension = diffDays * dailyRate;
                    document.getElementById('ext_extra_nights').innerText = diffDays;
                    document.getElementById('ext_total_cost').innerText = '₱' + totalExtension.toLocaleString();
                    document.getElementById('ext_price_preview').style.display = 'block';
                }
            };

            extendPickerInstance = flatpickr("#extend_date_picker", {
                dateFormat: "Y-m-d",
                minDate: nextDay,
                defaultDate: nextDay,
                static: true,
                appendTo: document.getElementById('extendModal').querySelector('.ab-modal-body'),
                onReady: function (sd, ds, inst) {
                    inst.calendarContainer.classList.add("compact-theme");
                    initCustomFpHeader(inst, { showDropdowns: true });
                },
                onMonthChange: function (sd, ds, inst) {
                    updateDropdownSelections(inst);
                },
                onYearChange: function (sd, ds, inst) {
                    updateDropdownSelections(inst);
                },
                onChange: (selectedDates) => updatePreview(selectedDates[0])
            });

            // Initial Calc
            updatePreview(nextDay);

            // 3. Show Modal
            document.getElementById('extendModal').style.display = 'block';
            document.getElementById('bookingActionModal').style.display = 'none';
        }

        function closeExtendModal() {
            document.getElementById('extendModal').style.display = 'none';
        }

        function toggleUILock(isLocked, message = "PROCESSING...") {
            const overlay = document.getElementById('globalLoadingOverlay');
            if (!overlay) return;

            const text = overlay.querySelector('p');
            if (text) text.innerText = message;

            overlay.style.display = isLocked ? 'flex' : 'none';
        }

        async function submitExtension(ignoreConflicts = false) {
            const id = document.getElementById('ext_booking_id').value;
            const newDate = document.getElementById('extend_date_picker').value;
            const paymentChoice = document.getElementById('ext_payment_choice').value;

            if (!newDate) {
                showError("Please select a new checkout date.");
                return;
            }

            if (!ignoreConflicts && !await showConfirm("Confirmation", `Confirm extension until ${newDate}?`)) return;

            // 🟢 UI LOCKING
            toggleUILock(true, "PROCESSING STAY EXTENSION...");

            const formData = new FormData();
            formData.append('id', id);
            formData.append('action', 'extend');
            formData.append('new_checkout', newDate);
            formData.append('extension_payment', paymentChoice);
            if (ignoreConflicts) formData.append('ignore_conflicts', '1');
            formData.append('csrf_token', csrfToken);

            const btn = document.querySelector('#extendModal .ab-submit-btn');
            const oldText = btn.innerText;
            btn.innerText = "Processing...";
            btn.disabled = true;

            fetch('update_arrival.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(async data => {
                    if (data.status === 'success') {
                        showSuccess(`Stay extended.\nNew Total: ₱${data.new_total}`);
                        location.reload();
                    }
                    else if (data.status === 'conflict') {
                        // 🟢 HANDLE CONFLICT: Unlock UI so they can pick alternatives
                        toggleUILock(false);
                        if (await showConfirm("Conflicts Found", data.message + "\n\nDo you want to proceed and pick alternative rooms for the conflicting ones?")) {
                            renderExtendAlternatives(data.alternatives, data.conflicted_rooms);
                        } else {
                            btn.innerText = oldText;
                            btn.disabled = false;
                        }
                    }
                    else {
                        toggleUILock(false);
                        showError(data.message);
                        btn.innerText = oldText;
                        btn.disabled = false;
                    }
                })
                .catch(err => {
                    toggleUILock(false);
                    console.error(err);
                    showError("System Error.");
                    btn.innerText = oldText;
                    btn.disabled = false;
                });
        }

        let selectedSwaps = {}; // ConflictedRoomID -> SelectedRoomObj

        function renderExtendAlternatives(alternatives, conflictedRooms) {
            const mainContent = document.getElementById('ext_main_content');
            const conflictArea = document.getElementById('ext_conflict_resolution');

            mainContent.style.display = 'none'; // Hide the picker/payment
            conflictArea.style.display = 'block'; // Show the swaps
            selectedSwaps = {};

            let altHtml = `
                <div style="background:#FEF2F2; border:1px solid #FECACA; padding:15px; border-radius:8px; margin-bottom:20px;">
                    <h4 style="margin:0 0 5px 0; color:#DC2626; font-size:0.9rem;">[WARN] Room Conflicts Detected</h4>
                    <p style="font-size:0.8rem; color:#991B1B; margin:0;">
                        The following rooms are unavailable: <b>${conflictedRooms.map(r => r.room_name).join(', ')}</b>.
                    </p>
                </div>
                
                <div id="swap_controls">
                    ${conflictedRooms.map(cr => `
                        <div class="swap-item" style="margin-bottom:20px; padding:15px; border:1px solid #eee; border-radius:8px;">
                            <label class="ab-label" style="font-size:0.85rem; color:#333;">Replace <b>${cr.room_name}</b> with:</label>
                            <div class="room-selection-grid" style="grid-template-columns: 1fr 1fr; gap: 10px; margin-top:10px;">
                                ${alternatives.length > 0 ? alternatives.map(alt => {
                let imgUrl = '../../IMG/default_room.jpg';
                if (alt.image_path) {
                    imgUrl = '../../room_includes/uploads/images/' + alt.image_path.split(',')[0].trim();
                }
                return `
                                    <div class="room-card alt-room-card" 
                                         onclick="selectSwapRoom(${cr.room_id}, ${JSON.stringify(alt).replace(/"/g, '&quot;')}, this)"
                                         style="cursor:pointer; border:1px solid #ddd; position:relative; display: flex; flex-direction: column; overflow: hidden;">
                                        <div class="room-card-check"></div>
                                        <img src="${imgUrl}" class="room-card-image" style="height:80px; width:100%; object-fit:cover;" onerror="this.src='../../IMG/default_room.jpg'">
                                        <div class="room-card-body" style="padding:8px;">
                                            <div style="font-weight:700; font-size:0.8rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${alt.name}</div>
                                            <div style="color:#B88E2F; font-size:0.85rem; font-weight:700;">₱${parseFloat(alt.price).toLocaleString()}</div>
                                        </div>
                                    </div>
                                    `;
            }).join('') : '<div style="color:#999; font-size:0.8rem;">No alternatives found.</div>'}
                            </div>
                        </div>
                    `).join('')}
                </div>

                <div class="ab-grid-footer" style="margin-top: 25px;">
                    <button class="btn-secondary" onclick="location.reload()">Cancel</button>
                    <button class="ab-submit-btn" style="background:#10B981;" onclick="submitExtensionWithSwaps()">Confirm Swaps & Extend</button>
                </div>
            `;

            conflictArea.innerHTML = altHtml;
        }

        function selectSwapRoom(conflictedId, roomObj, element) {
            // Check if this room is ALREADY the selected one for THIS specific conflictedId
            const isAlreadySelected = element.classList.contains('selected');

            // 1. Unselect all rooms in THIS group (this specific conflicted room's grid)
            const parentGrid = element.closest('.room-selection-grid');
            parentGrid.querySelectorAll('.room-card').forEach(c => c.classList.remove('selected'));

            if (isAlreadySelected) {
                // If it was already selected, we now unselect it entirely
                delete selectedSwaps[conflictedId];
            } else {
                // Otherwise, we select it
                element.classList.add('selected');
                selectedSwaps[conflictedId] = roomObj;
            }
        }

        function submitExtensionWithSwaps() {
            const id = document.getElementById('ext_booking_id').value;
            const newDate = document.getElementById('extend_date_picker').value;
            const paymentChoice = document.getElementById('ext_payment_choice').value;

            // [INFO] Visual Loading Feedback
            const btn = document.querySelector('#ext_conflict_resolution .ab-submit-btn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<div class="amv-loader-sm"></div> Processing...';
            }

            // [INFO] UI LOCKING
            toggleUILock(true, "PROCESSING ROOM SWAPS & EXTENSION...");

            const formData = new FormData();
            formData.append('id', id);
            formData.append('action', 'extend');
            formData.append('new_checkout', newDate);
            formData.append('extension_payment', paymentChoice);
            formData.append('room_swaps', JSON.stringify(selectedSwaps));
            formData.append('csrf_token', csrfToken);

            fetch('update_arrival.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess("Stay Extended with room changes!");
                        location.reload();
                    } else {
                        toggleUILock(false);
                        showError(data.message);
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = 'Confirm Swaps & Extend';
                        }
                    }
                })
                .catch(err => {
                    toggleUILock(false);
                    console.error(err);
                    showError("System Error");
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = 'Confirm Swaps & Extend';
                    }
                });
        }

        // --- 1. TOGGLE THE DROPDOWN ---
        function toggleAddBookingDropdown(event) {
            event.stopPropagation();
            // Close other dropdowns first
            document.querySelectorAll('.dropdown-menu').forEach(dd => dd.classList.remove('show'));

            // Toggle this one
            document.getElementById('addBookingDropdown').classList.toggle('show');
        }

        // --- 2. OPEN MODAL WITH PRE-SELECTED TYPE ---
        function openAddBookingModal(type) {
            // 1. Reset everything first
            resetModal();

            // [TIME] 8 PM RULE: For Reservations, disable 'today' if past 8 PM
            const checkinInput = document.getElementById('checkin_picker');
            if (checkinInput && checkinInput._flatpickr) {
                let minDateStr = "today";
                const now = new Date();

                // If it's a Reservation and it's 8:00 PM (20:00) or later
                if (type === 'reservation' && now.getHours() >= 20) {
                    const tomorrow = new Date();
                    tomorrow.setDate(now.getDate() + 1);
                    minDateStr = tomorrow;
                }

                checkinInput._flatpickr.set('minDate', minDateStr);
                checkinInput._flatpickr.clear();
            }

            // 2. SET THE READ-ONLY INPUT
            const sourceInput = document.getElementById('bookingSourceDisplay');
            if (sourceInput) {
                sourceInput.value = type;
            }

            // 3. TOGGLE ARRIVAL TIME FIELD
            const arrivalContainer = document.getElementById('arrivalTimeContainer');
            const arrivalSelect = document.getElementById('arrival_time_select');

            if (type === 'walk-in') {
                // --- WALK-IN: HIDE FIELD ---
                if (arrivalContainer) arrivalContainer.style.display = 'none';
                if (arrivalSelect) arrivalSelect.removeAttribute('required');

                document.getElementById('abModalTitle').innerText = "New Walk-in Booking";

                // Auto-set dates for walk-in (Today -> Tomorrow)
                const today = new Date();
                const tomorrow = new Date();
                tomorrow.setDate(today.getDate() + 1);

                const checkoutFP = document.getElementById('checkout_picker')._flatpickr;

                if (checkinInput._flatpickr) checkinInput._flatpickr.setDate(today);
                if (checkoutFP) checkoutFP.setDate(tomorrow);

            } else {
                // --- RESERVATION: SHOW FIELD ---
                if (arrivalContainer) arrivalContainer.style.display = 'block';
                if (arrivalSelect) arrivalSelect.setAttribute('required', 'true');

                document.getElementById('abModalTitle').innerText = "New Reservation";
            }

            // 4. Show Modal
            document.getElementById('addBookingModal').style.display = 'block';

            // [FIX] CRITICAL FIX IS HERE: Close the correct wrapper ID
            const wrapper = document.getElementById('addBookingWrapper');
            if (wrapper) {
                wrapper.classList.remove('open'); // Rotates the arrow back
                const options = wrapper.querySelector('.custom-options');
                if (options) options.classList.remove('open'); // Hides the menu
            }
        }

        // --- HELPER: Handle Walk-in vs Reservation Logic ---
        function handleBookingTypeChange() {
            const typeSelect = document.getElementById('bookingSourceSelect');

            // Safety check: if the select ID is missing, stop to prevent errors
            if (!typeSelect) return;

            const type = typeSelect.value;
            const checkinInput = document.getElementById('checkin_picker');
            const checkoutInput = document.getElementById('checkout_picker');

            // Get Flatpickr instances
            const checkinFP = checkinInput && checkinInput._flatpickr;
            const checkoutFP = checkoutInput && checkoutInput._flatpickr;

            if (type === 'walk-in') {
                // --- WALK-IN LOGIC ---
                // 1. Set Check-in date to TODAY immediately
                const today = new Date();
                if (checkinFP) {
                    checkinFP.set('minDate', 'today'); // Ensure walk-in can always check-in today
                    checkinFP.setDate(today);
                }

                // 2. Default Check-out to TOMORROW
                const tomorrow = new Date();
                tomorrow.setDate(today.getDate() + 1);
                if (checkoutFP) checkoutFP.setDate(tomorrow);

            } else {
                // --- RESERVATION LOGIC ---
                // [TIME] 8 PM RULE: For Reservations, disable 'today' if past 8 PM
                const now = new Date();
                if (now.getHours() >= 20) {
                    const tomorrow = new Date();
                    tomorrow.setDate(now.getDate() + 1);
                    if (checkinFP) {
                        checkinFP.set('minDate', tomorrow);
                        checkinFP.clear();
                    }
                } else {
                    if (checkinFP) checkinFP.set('minDate', 'today');
                }
            }
        }

        // --- HELPER: Inject New Booking Row into Table ---
        function addBookingRowToTable(newId, newRef, payload) {
            // 1. [FIX] ADD THIS MISSING LINE HERE:
            const nowCreated = new Date().toISOString().slice(0, 19).replace('T', ' ');
            const tbody = document.getElementById('bookingTableBody');

            // 1. Remove "No Data" message if visible
            const noDataMsg = document.getElementById('noDataMessage');
            if (noDataMsg) noDataMsg.style.display = 'none';

            // 2. Format Data for Display
            const guestName = `${payload.guest.firstname} ${payload.guest.lastname}`;
            const roomNames = payload.rooms.map(r => r.name).join(', ');

            // Date Formatting
            const fmtDate = (dStr) => new Date(dStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            const dateRange = `${fmtDate(payload.dates.checkin)} - ${fmtDate(payload.dates.checkout)}`;

            // Time Formatting
            let timeDisplay = "14:00";
            if (payload.guest.arrival_time) {
                const [h, m] = payload.guest.arrival_time.split(':');
                const dateObj = new Date();
                dateObj.setHours(h, m);
                timeDisplay = dateObj.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            }

            // Source Icon & Color
            let sourceHtml = '';
            if (payload.bookingSource === 'walk-in') {
                sourceHtml = `<div class="source-tag source-walkin"><span>[WALK-IN]</span> WALK-IN</div>`;
            } else {
                sourceHtml = `<div class="source-tag source-online"><span><i class="far fa-calendar-alt"></i></span> RESERVATION</div>`;
            }

            // Arrival Badge
            let badgeHtml = '';
            if (payload.arrivalStatus === 'in_house') {
                badgeHtml = `<div class="arrival-badge arrival-inhouse">In House</div>`;
            } else {
                // Check if arriving today
                const today = new Date().toISOString().split('T')[0];
                if (payload.dates.checkin === today) {
                    badgeHtml = `<div class="arrival-badge arrival-today">Arriving Today</div>`;
                } else {
                    badgeHtml = `<div class="arrival-badge arrival-upcoming">Upcoming</div>`;
                }
            }

            // Payment Status
            let paymentHtml = '';
            if (payload.paymentStatus === 'paid') {
                paymentHtml = `<span style="color:#10B981; font-weight:700; font-size:0.8rem;">Fully Paid</span>`;
            } else if (payload.paymentStatus === 'partial') {
                const bal = payload.totalPrice - payload.amountPaid;
                paymentHtml = `
                <div style="font-size:0.75rem; color:#F59E0B; font-weight:600;">Paid: ₱${payload.amountPaid.toLocaleString()}</div>
                <div style="font-size:0.75rem; color:#EF4444; font-weight:600;">Bal: ₱${bal.toLocaleString()}</div>
            `;
            } else {
                paymentHtml = `<span style="color:#EF4444; font-weight:600; font-size:0.8rem;">Unpaid</span>`;
            }

            // 3. Create the Row HTML
            const row = document.createElement('tr');
            row.className = 'booking-row';
            row.id = 'row-' + newId;
            // Set attributes for filtering logic
            row.setAttribute('data-status', 'confirmed');
            row.setAttribute('data-checkin', payload.dates.checkin);
            row.setAttribute('data-checkout', payload.dates.checkout);
            row.setAttribute('data-arrival', payload.arrivalStatus);
            row.setAttribute('data-created', nowCreated);

            row.innerHTML = `
            <td><strong>${newRef}</strong></td>
            <td>
                <div style="font-weight:600; font-size:0.9rem;">${guestName}</div>
                <div class="fs-xxs" style="color:#888;">ID: ${newId}</div>
            </td>
            <td>${sourceHtml}</td>
            <td>${badgeHtml}</td>
            <td>
                <div style="font-weight:600; color:#555; font-size:0.9rem;">
                    <i class="far fa-clock" style="color:#888; margin-right:4px;"></i> ${timeDisplay}
                </div>
            </td>
            <td>${roomNames}</td>
            <td>${dateRange}</td>
            <td>$${payload.totalPrice.toLocaleString()}</td>
            <td>${paymentHtml}</td>
            <td>
                 <button class="btn-secondary" style="padding:5px 10px; font-size:0.8rem;" 
                    onclick="openBookingAction(
                        '${newId}', 
                        '${guestName.replace(/'/g, "\\'")}', 
                        '${newRef}', 
                        '${roomNames.replace(/'/g, "\\'")}', 
                        '${payload.dates.checkin}', 
                        '${payload.dates.checkout}', 
                        '${payload.totalPrice}', 
                        '${payload.arrivalStatus}', 
                        '${payload.amountPaid}', 
                        '${payload.arrivalStatus === 'in_house' ? 'In House' : 'Upcoming'}',
                        '${new Date().toISOString().split('T')[0]}',
                        '${payload.bookingSource}',
                        '${payload.rooms[0].price}',
                        '${(payload.guest.requests || "").replace(/'/g, "\\'").replace(/\n/g, " ")}'
                    )">View</button>
            </td>
        `;

            // 4. Insert at the TOP of the table
            if (tbody.firstChild) {
                tbody.insertBefore(row, tbody.firstChild);
            } else {
                tbody.appendChild(row);
            }

            // 5. Refresh from server for pagination and sorting
            refreshBookingTable(true);
        }

        // --- HELPER 1: Add New Room Row ---
        function addRoomRowToTable(room) {
            const tbody = document.getElementById('roomTableBody');

            // Remove "No rooms" message if exists
            // (You might not have one, but good practice)

            const row = document.createElement('tr');
            row.id = 'room-row-' + room.id;
            row.style.verticalAlign = 'middle';

            // Default placeholder if image is empty or base64 empty
            let displayImage = room.imageSrc;
            if (!displayImage || displayImage.includes('data:application/octet-stream') || displayImage === window.location.href) {
                displayImage = "data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiB2aWV3Qm94PSIwIDAgMTAwIDEwMCI+PHJlY3QgZmlsbD0iI2RkZCIgd2lkdGg9IjEwMCIgaGVpZ2h0PSIxMDAiLz48dGV4dCB4PSI1MCIgeT0iNTAiIGZvbnQtZmFtaWx5PSJhcmlhbCIgZm9udC1zaXplPSIxMiIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzU1NSI+Tm8gSW1hZ2U8L3RleHQ+PC9zdmc+";
            }

            // Safe strings for onclick
            const safeName = room.name.replace(/'/g, "\\'");
            const safeBed = room.bed.replace(/'/g, "\\'");
            const safeDesc = room.desc.replace(/'/g, "\\'").replace(/\n/g, " ");
            const safeSize = room.size.replace(/'/g, "\\'");

            // Note: For 'file_path', newly added rooms won't have the server filename available immediately 
            // without a reload unless PHP returns it. We pass '' for now to prevent broken image links on next edit.

            row.innerHTML = `
            <td style="font-weight: 600; color: #888;">${room.id}</td>
            <td>
                <div style="width: 120px; height: 80px; background:#eee; border-radius:6px; overflow:hidden; border:1px solid #ddd;">
                    <img src="${displayImage}" style="width:100%; height:100%; object-fit:cover;">
                </div>
            </td>
            <td>
                <div class="room-name" style="font-weight: 600; font-size: 1rem; color: #333;">${room.name}</div>
            </td>
            <td>
                <span class="room-bed" style="background: #fff; padding: 4px 10px; border-radius: 4px; border:1px solid #eee; font-size: 0.85rem; font-weight: 500; color: #555;">
                    ${room.bed}
                </span>
            </td>
            <td class="room-price" style="font-weight: 700; color: #333;">₱${parseFloat(room.price).toLocaleString()}</td>
            <td>
                <button class="btn-secondary" style="padding:6px 12px; margin-right: 5px;" 
                    onclick="openEditRoomModal(
                        '${room.id}', 
                        '${safeName}', 
                        '${room.price}', 
                        '${safeBed}', 
                        '${room.capacity}', 
                        '${safeSize}', 
                        '${safeDesc}', 
                        '', 
                        false
                    )">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <button class="btn-secondary" style="padding:6px 12px; color:#555; border-color: #FECACA; background: #FEF2F2;" 
                    onclick="deleteRoom('${room.id}')">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;

            // Prepend to top
            tbody.insertBefore(row, tbody.firstChild);
        }

        // --- HELPER 2: Update Existing Room Row (Crash-Proof Version) ---
        function updateRoomRowInTable(room) {
            // 1. Find the row by ID
            let row = document.getElementById('room-row-' + room.id);

            // Fallback: If row doesn't have ID, scan the first column
            if (!row) {
                const rows = document.querySelectorAll('#roomTableBody tr');
                rows.forEach(r => {
                    if (r.cells[0] && r.cells[0].innerText.trim() == room.id) {
                        row = r;
                    }
                });
            }

            if (row) {
                // 2. Update Image (Cell 1)
                if (room.imageSrc && !room.imageSrc.includes('placeholder')) {
                    const img = row.cells[1].querySelector('img');
                    if (img) img.src = room.imageSrc;
                }

                // 3. Update Name (Cell 2) - SAFETY CHECK ADDED
                // We look for a div with class 'room-name', or fallback to the cell itself
                const nameDiv = row.querySelector('.room-name') || row.cells[2].querySelector('div');

                if (nameDiv) {
                    // Keep the "ARCHIVED" badge if it exists
                    const badge = nameDiv.querySelector('span'); // Save badge
                    nameDiv.innerText = room.name; // Update text
                    if (badge) nameDiv.appendChild(badge); // Put badge back
                } else {
                    // Last resort: just set the cell text (removes badge if structural mismatch)
                    row.cells[2].innerText = room.name;
                }

                // 4. Update Bed Type (Cell 3)
                const bedSpan = row.querySelector('.room-bed') || row.cells[3].querySelector('span');
                if (bedSpan) {
                    bedSpan.innerText = room.bed;
                } else {
                    row.cells[3].innerText = room.bed;
                }

                // 5. Update Price (Cell 4)
                const priceCell = row.querySelector('.room-price') || row.cells[4];
                if (priceCell) {
                    priceCell.innerText = '₱' + parseFloat(room.price).toLocaleString();
                }

                // 6. Update the "Edit" button onclick data
                const actionCell = row.cells[5];
                const editBtn = actionCell.querySelector('button:first-child');

                if (editBtn) {
                    const safeName = room.name.replace(/'/g, "\\'");
                    const safeBed = room.bed.replace(/'/g, "\\'");
                    // Remove newlines to prevent JS errors
                    const safeDesc = room.desc.replace(/'/g, "\\'").replace(/(\r\n|\n|\r)/gm, " ");
                    const safeSize = room.size.replace(/'/g, "\\'");

                    // We pass '' for filePath because we don't have the new server path yet
                    // passing false for isBooked
                    editBtn.setAttribute('onclick', `openEditRoomModal('${room.id}', '${safeName}', '${room.price}', '${safeBed}', '${room.capacity}', '${safeSize}', '${safeDesc}', '', false)`);
                }
            } else {
                console.warn("Could not find row for room ID: " + room.id);
            }
        }

        // --- GUEST EDIT LOGIC ---

        // 1. Toggle between View and Edit
        function toggleGuestEdit(isEditing) {
            const viewMode = document.getElementById('gp_view_mode');
            const editMode = document.getElementById('gp_edit_mode');

            if (isEditing) {
                // Switch to Edit Mode
                viewMode.style.display = 'none';
                editMode.style.display = 'block';

                // Populate Input Fields from current text
                // Note: We need the raw data. Ideally, we save the data object globally when we fetched it.
                // Let's grab it from the DOM for now, but cleaner is to use 'window.currentGuestData'

                if (window.currentGuestData) {
                    const info = window.currentGuestData.info;
                    document.getElementById('edit_fname').value = info.first_name;
                    document.getElementById('edit_lname').value = info.last_name;
                    document.getElementById('edit_email').value = info.email;
                    document.getElementById('edit_original_email').value = info.email;
                    document.getElementById('edit_phone').value = info.phone;
                    document.getElementById('edit_nation').value = info.nationality;
                    document.getElementById('edit_gender').value = info.gender;
                    document.getElementById('edit_dob').value = info.birthdate;
                    document.getElementById('edit_address').value = info.address;
                }

            } else {
                // Switch back to View Mode
                viewMode.style.display = 'block';
                editMode.style.display = 'none';
            }
        }

        // 2. Save Changes (Seamless - No Reload)
        async function saveGuestProfile(event) {
            event.preventDefault(); // Stop the form from submitting normally

            // 🟢 ADD CONFIRMATION
            if (!await showConfirm("Update Profile", "Are you sure you want to save these changes to the guest's profile?")) return;

            const form = document.getElementById('guestEditForm');
            const formData = new FormData(form);
            formData.append('csrf_token', csrfToken);

            const btn = form.querySelector('button[type="submit"]');
            const originalText = btn.innerText;
            btn.innerText = "Saving...";
            btn.disabled = true;

            fetch('update_guest_profile.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess("Guest profile updated!");

                        // A. Update the Global Data Object so we don't need to re-fetch
                        if (window.currentGuestData) {
                            const info = window.currentGuestData.info;
                            // Update values in memory
                            info.first_name = formData.get('firstname');
                            info.last_name = formData.get('lastname');
                            info.phone = formData.get('phone');
                            info.nationality = formData.get('nationality');
                            info.gender = formData.get('gender');
                            info.birthdate = formData.get('birthdate');
                            info.address = formData.get('address');

                            // B. Update the "View Mode" Text on the screen immediately
                            const salutation = info.salutation ? info.salutation : '';
                            document.getElementById('gp_name').innerText = `${salutation} ${info.first_name} ${info.last_name}`;
                            document.getElementById('gp_phone').innerText = info.phone;
                            document.getElementById('gp_nation').innerText = info.nationality;
                            document.getElementById('gp_gender').innerText = info.gender;
                            document.getElementById('gp_dob').innerText = info.birthdate;
                            document.getElementById('gp_address').innerText = info.address;

                            // Note: Email is read-only in this form, so we don't update gp_email here
                        }

                        // C. Switch back to view mode (Seamlessly)
                        toggleGuestEdit(false);

                        // ❌ DELETED: location.reload(); 

                    } else {
                        showError(data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");
                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        }

        // --- ENABLE ADDRESS SEARCH FOR GUEST EDIT ---
        function setupEditAddressSearch() {
            const input = document.getElementById('edit_address');
            const loader = document.getElementById('editAddrLoader');
            const results = document.getElementById('editAddrResults');
            let debounceTimer;

            if (!input) return;

            input.addEventListener('input', function () {
                const query = this.value.trim();
                clearTimeout(debounceTimer);

                if (query.length < 3) {
                    results.style.display = 'none';
                    return;
                }

                // Wait 600ms before searching
                debounceTimer = setTimeout(() => {
                    loader.style.display = 'block';

                    fetch(`search_address.php?q=${encodeURIComponent(query)}`)
                        .then(res => res.json())
                        .then(data => {
                            loader.style.display = 'none';
                            results.innerHTML = '';

                            if (data.length === 0) {
                                results.style.display = 'none';
                                return;
                            }

                            data.forEach(place => {
                                const div = document.createElement('div');
                                div.className = 'address-result-item';
                                div.innerText = place.display_name;
                                div.onclick = function () {
                                    input.value = place.display_name;
                                    results.style.display = 'none';
                                };
                                results.appendChild(div);
                            });

                            results.style.display = 'block';
                        })
                        .catch(err => {
                            console.error(err);
                            loader.style.display = 'none';
                        });
                }, 600);
            });

            // Hide dropdown if clicked outside
            document.addEventListener('click', function (e) {
                if (e.target !== input && e.target !== results) {
                    results.style.display = 'none';
                }
            });
        }

        // --- ACTIVATE IT ON LOAD ---
        document.addEventListener("DOMContentLoaded", function () {

            const today = new Date().toISOString().split('T')[0];
            if (localStorage.getItem('lateAlertDismissed_' + today)) {
                const alertCard = document.getElementById('lateArrivalAlert');
                if (alertCard) alertCard.style.display = 'none';
            }
            // ... your other init codes ...
            setupEditAddressSearch(); // <--- CALL THE FUNCTION HERE
        });

        // Function to handle the "View Bookings" button on the alert card
        // Function to handle the "View Bookings" button on the alert card
        // Function to handle the "View Bookings" button on the alert card
        function goToBookingsTab(filterType) {
            // 1. Mark as dismissed in Local Storage for TODAY
            const today = new Date().toISOString().split('T')[0];
            localStorage.setItem('lateAlertDismissed_' + today, 'true');

            // 2. Hide visually
            const alertCard = document.getElementById('lateArrivalAlert');
            if (alertCard) alertCard.style.display = 'none';

            // 3. Existing navigation logic
            const bookingNav = document.querySelector('.nav-item[data-page="bookings"]');
            if (bookingNav) bookingNav.click();

            setTimeout(() => {
                filterTable(filterType || 'late');
            }, 300);
        }
        // Function to open the Header Notification Dropdown
        function openNotificationPanel(event) {
            if (event) event.stopPropagation();

            // 1. Mark as read in Database
            fetch('mark_as_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: 'notification_all' })
            });

            // 2. Hide the visual alert card
            const alertBox = document.getElementById('newNotificationAlert');
            if (alertBox) alertBox.style.display = 'none';

            // 3. Open the dropdown correctly
            const notifDropdown = document.getElementById('notifDropdown'); // [OK] Correct ID
            if (notifDropdown) {
                // Close other dropdowns first (like messages)
                document.querySelectorAll('.dropdown-menu').forEach(dd => dd.classList.remove('show'));

                // Show this dropdown
                notifDropdown.classList.add('show'); // [OK] Correct Class
            }
        }

        // --- ADMIN PROFILE LOGIC ---

        // ADMIN/PHP/dashboard.php -> Find the loadAdminProfile function

        // --- UPDATED ADMIN PROFILE LOADER ---
        function loadAdminProfile() {
            console.log("1. Starting Profile Load...");

            // Set loading placeholders
            document.getElementById('disp_username').innerText = "Loading...";
            document.getElementById('disp_wifi_ssid').innerText = "...";
            document.getElementById('disp_wifi_pass').innerText = "...";

            // Add timestamp to prevent caching
            const url = 'get_admin_details.php?t=' + new Date().getTime();

            fetch(url)
                .then(res => {
                    if (!res.ok) { throw new Error("HTTP Status: " + res.status); }
                    return res.json();
                })
                .then(data => {
                    if (data.status === 'success') {
                        const u = data.data;

                        // 1. UPDATE VIEW MODE (Display Cards)
                        document.getElementById('disp_username').innerText = u.name || 'No Name Set';
                        document.getElementById('disp_email').innerText = u.email;
                        document.getElementById('disp_contact').innerText = u.contact_number || 'No contact info';

                        // [INFO] Update Wi-Fi Display
                        document.getElementById('disp_wifi_ssid').innerText = u.ssid || 'Not Set';
                        document.getElementById('disp_wifi_pass').innerText = u.wifi_password || 'Not Set';

                        // 2. PRE-FILL EDIT FORM INPUTS
                        if (document.getElementById('edit_username')) document.getElementById('edit_username').value = u.name;
                        if (document.getElementById('edit_admin_email')) document.getElementById('edit_admin_email').value = u.email;
                        if (document.getElementById('edit_contact')) document.getElementById('edit_contact').value = u.contact_number || '';

                        // [INFO] Update Wi-Fi Inputs
                        if (document.getElementById('edit_wifi_ssid')) document.getElementById('edit_wifi_ssid').value = u.ssid || '';
                        if (document.getElementById('edit_wifi_pass')) document.getElementById('edit_wifi_pass').value = u.wifi_password || '';

                    } else {
                        console.warn("PHP returned error:", data.message);
                        if (data.message && data.message.toLowerCase().includes("session")) {
                            showError("Session expired. Please login again.");
                            window.location.href = "login.php";
                        } else {
                            document.getElementById('disp_username').innerHTML = `<span style="color:red; font-size:0.8rem;">${data.message}</span>`;
                        }
                    }
                })
                .catch(err => {
                    console.error("Critical Fetch Error:", err);
                    document.getElementById('disp_username').innerHTML = `<span style="color:red; font-size:0.8rem;">JS Error. Check Console.</span>`;
                });
        }

        // 2. Toggle Edit Mode (Updated to use Modal)
        function toggleAdminEdit(showEdit) {
            const modal = document.getElementById('adminEditModal');

            if (showEdit) {
                // Show the modal
                modal.style.display = 'block';
            } else {
                // Hide the modal
                modal.style.display = 'none';
                // Optional: Reset form fields if cancelled
                // document.getElementById('adminEditForm').reset(); 
            }
        }

        // 3. Save Admin Profile
        function saveAdminProfile(e) {
            e.preventDefault();

            const form = document.getElementById('adminEditForm');
            const formData = new FormData(form);
            formData.append('csrf_token', csrfToken);

            const btn = form.querySelector('button[type="submit"]');
            const oldText = btn.innerText;
            btn.innerText = "Saving...";
            btn.disabled = true;

            fetch('update_admin_profile.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess("Profile Updated Successfully!");
                        loadAdminProfile(); // Refresh Data
                        toggleAdminEdit(false); // Go back to view
                    } else {
                        showError("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");
                })
                .finally(() => {
                    btn.innerText = oldText;
                    btn.disabled = false;
                });
        }

        // 4. Attach to the sidebar click to ensure data is fresh
        document.addEventListener("DOMContentLoaded", function () {
            const profileCard = document.querySelector('.tree-item-card[onclick*="view-profile"]');
            if (profileCard) {
                profileCard.addEventListener('click', () => {
                    loadAdminProfile();
                    loadPaymentSettings(); // [INFO] ADD THIS LINE
                });
            }
            // Load once on startup
            loadAdminProfile();
            loadPaymentSettings(); // [INFO] ADD THIS LINE
        });

        // --- REAL-TIME GUEST LIST ---
        let isGuestSearchActive = false; // Flag to pause updates if user is typing
        let guestOffset = 0;
        const guestLimit = 100;

        function fetchGuestList(isSilent = false) {
            const searchInput = document.getElementById('guestSearchInput');
            const search = searchInput ? searchInput.value.trim() : '';

            // [INFO] AUTO-REFRESH: Only run if NOT searching OR if explicitly told to (silent)
            if (!isSilent && search !== "") {
                return;
            }

            const url = `get_all_guests.php?limit=${guestLimit}&offset=${guestOffset}&search=${encodeURIComponent(search)}`;

            const tbody = document.getElementById('guestTableBody');
            if (!isSilent && tbody) {
                tbody.innerHTML = `<tr><td colspan="7" style="padding: 100px 0; text-align: center;">
                    <div class="amv-loader-container">
                        <div class="amv-loader"></div>
                        <div style="font-weight: 600; font-size: 1.1rem; letter-spacing: 0.5px; color: #B88E2F;">Loading Guest Data...</div>
                    </div>
                </td></tr>`;
            }

            fetch(url)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        renderGuestTable(data.data);
                        updateGuestPaginationUI(data.total, data.limit, data.offset);
                    }
                })
                .catch(err => console.error("Guest Fetch Error:", err));
        }

        function updateGuestPaginationUI(total, limit, offset) {
            const container = document.getElementById('guestPagination');
            const foot = document.getElementById('guestPaginationFoot');
            if (container) {
                const isVisible = total > limit;
                container.style.display = isVisible ? 'flex' : 'none';
                if (foot) foot.style.display = isVisible ? 'table-footer-group' : 'none';
            }

            const start = total === 0 ? 0 : offset + 1;
            const end = Math.min(offset + limit, total);
            const currentPage = Math.floor(offset / limit) + 1;
            const totalPages = Math.ceil(total / limit);

            document.getElementById('guestPageStart').innerText = start;
            document.getElementById('guestPageEnd').innerText = end;
            document.getElementById('guestTotalCount').innerText = total;

            const btnContainer = document.getElementById('guestPageButtons');
            if (!btnContainer) return;
            btnContainer.innerHTML = '';

            const addDots = () => {
                const span = document.createElement('span');
                span.className = 'pg-dots';
                span.innerText = '...';
                btnContainer.appendChild(span);
            };

            // Prev
            const prevBtn = document.createElement('button');
            prevBtn.className = 'pg-btn pg-btn-nav';
            prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i> Prev';
            prevBtn.disabled = (currentPage === 1);
            prevBtn.onclick = (e) => {
                e.preventDefault();
                guestOffset = Math.max(0, guestOffset - limit);
                fetchGuestList(true);
            };
            btnContainer.appendChild(prevBtn);

            // Pages
            let startPage = Math.max(1, currentPage - 1);
            let endPage = Math.min(totalPages, startPage + 2);
            if (endPage - startPage < 2) startPage = Math.max(1, endPage - 2);

            if (startPage > 1) {
                btnContainer.appendChild(createGuestPageBtn(1, limit, 1 === currentPage));
                if (startPage > 2) addDots();
            }

            for (let i = startPage; i <= endPage; i++) {
                if (i > 0) btnContainer.appendChild(createGuestPageBtn(i, limit, i === currentPage));
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) addDots();
                btnContainer.appendChild(createGuestPageBtn(totalPages, limit, totalPages === currentPage));
            }

            // Next
            const nextBtn = document.createElement('button');
            nextBtn.className = 'pg-btn pg-btn-nav';
            nextBtn.innerHTML = 'Next <i class="fas fa-chevron-right"></i>';
            nextBtn.disabled = (currentPage === totalPages || total === 0);
            nextBtn.onclick = (e) => {
                e.preventDefault();
                guestOffset += limit;
                fetchGuestList(true);
            };
            btnContainer.appendChild(nextBtn);
        }

        function createGuestPageBtn(page, limit, isActive) {
            const btn = document.createElement('button');
            btn.className = 'pg-btn' + (isActive ? ' active' : '');
            btn.innerText = page;
            btn.onclick = (e) => {
                e.preventDefault();
                guestOffset = (page - 1) * limit;
                fetchGuestList(true);
            };
            return btn;
        }

        function renderGuestTable(guests) {
            const tbody = document.getElementById('guestTableBody');
            if (!tbody) return;

            // Save current scroll position (optional polish)
            // const scrollPos = tbody.parentElement.scrollTop;

            let html = '';

            if (guests.length === 0) {
                html = `<tr><td colspan="7" style="text-align:center; padding:60px 20px; color:#94a3b8;">
               <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;">
                   <div style="width:64px; height:64px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                       <i class="fas fa-users-slash" style="font-size:1.8rem; color:#cbd5e1;"></i>
                   </div>
                   <div style="font-weight:600; font-size:1.1rem; color:#64748b;">No Guests Found</div>
                   <p style="margin:0; font-size:0.9rem; max-width:250px; line-height:1.5;">We couldn't find any guest records matching your criteria.</p>
               </div>
            </td></tr>`;
            } else {                guests.forEach(g => {
                    const fullName = `${g.first_name || ''} ${g.last_name || ''}`.trim();
                    const email = g.email || '';
                    // Escape strings for safety in onclick
                    const safeEmail = email.replace(/'/g, "\\'");

                    html += `
    <tr class="guest-row clickable-row" onclick="openGuestProfile('${safeEmail}')" style="cursor: pointer;">
        <td><div style="font-weight:600; color:#333;">${fullName}</div></td>
        <td>${email}</td>
        <td>${g.phone || ''}</td>
        <td>${g.nationality || ''}</td>
       <td style="text-align: center;">
            <span class="badge" style="background:#e0f2fe; color:#0284c7; font-size:0.9rem; font-weight:700; padding: 6px 15px; min-width: 40px; border-radius: 6px;">
                ${g.total_stays}
            </span>
        </td>
        <td style="text-align: center;">
            <span class="badge" style="background:#FFF7ED; color:#C2410C; font-size:0.9rem; font-weight:700; padding: 6px 15px; min-width: 40px; border-radius: 6px;">
                ${g.total_orders}
            </span>
        </td>
        <td style="text-align: center;">
            <span style="color:#B88E2F; font-size:0.8rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">
                Tap to View <i class="fas fa-chevron-right" style="font-size:0.7rem; margin-left:5px;"></i>
            </span>
        </td>
    </tr>`;
                });
            }

            tbody.innerHTML = html;
        }

        document.addEventListener("DOMContentLoaded", function () {

            // Check if the element exists first to avoid errors
            if (document.getElementById('termsQuillEditor')) {

                // Initialize Quill for Terms & Conditions
                var termsQuill = new Quill('#termsQuillEditor', {
                    theme: 'snow',
                    placeholder: 'Enter terms and conditions here...',
                    modules: {
                        toolbar: [
                            [{ 'header': [1, 2, 3, false] }], // Custom headers
                            ['bold', 'italic', 'underline'],
                            [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                            ['link', 'clean']
                        ]
                    }
                });

                // OPTIONAL: Auto-sync to hidden input if you have a form submission for this
                // termsQuill.on('text-change', function() {
                //     document.getElementById('termsHiddenInput').value = termsQuill.root.innerHTML;
                // });
            }

        });

        // --- POLICY BUILDER ENGINE (JSON VERSION) ---

        // 1. Initialize on Load
        document.addEventListener("DOMContentLoaded", function () {
            renderTerms();
        });

        function renderTerms() {
            const rawDataEl = document.getElementById('rawDatabaseContent');
            if (!rawDataEl) return;
            const rawData = rawDataEl.value.trim();
            const container = document.getElementById('policy-builder-container');
            if (!container) return;

            // Clear container
            container.innerHTML = '';

            try {
                // A. Attempt to parse the data as JSON (The New Way)
                const policies = JSON.parse(rawData);

                if (Array.isArray(policies) && policies.length > 0) {
                    // Success! We have structured data. Create a card for each policy.
                    policies.forEach(policy => {
                        addPolicySection(policy.title, policy.content);
                    });
                } else {
                    // JSON is valid but empty
                    addPolicySection();
                }

            } catch (e) {
                // B. Fallback: Data is not JSON (It's the Old HTML Format)
                if (rawData === "") {
                    addPolicySection();
                } else {
                    addPolicySection("General Policies", rawData);
                }
            }
        }

        // 2. Function to Add a New Section (Card) - QUILL VERSION
        function addPolicySection(initialTitle = "", initialContent = "") {
            const container = document.getElementById('policy-builder-container');
            if (!container) return;

            // Generate a unique ID for this specific editor instance
            const uniqueId = "policy_quill_" + Math.random().toString(36).substr(2, 9) + "_" + new Date().getTime();

            // Create the HTML for the card
            const card = document.createElement('div');
            card.className = 'policy-card';
            card.innerHTML = `
        <div class="policy-header">
            <input type="text" class="policy-title-input" placeholder="Enter Policy Title (e.g. Booking Rules)" value="${initialTitle.replace(/"/g, '&quot;')}">
            <button class="btn-delete-policy" onclick="deletePolicySection(this)">
                <i class="fas fa-trash-alt"></i> Delete
            </button>
        </div>
        
        <input type="hidden" class="policy-hidden-content" value='${initialContent.replace(/'/g, "&#39;")}'>

        <div id="${uniqueId}" style="height: 300px; background: white;"></div>
    `;

            container.appendChild(card);

            // Initialize Quill on the unique ID we just created
            var quill = new Quill('#' + uniqueId, {
                theme: 'snow',
                placeholder: 'Enter policy details here...',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline'],
                        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                        ['link', 'clean']
                    ]
                }
            });

            // If there is existing content (from database), load it into Quill
            if (initialContent) {
                quill.clipboard.dangerouslyPasteHTML(initialContent);
            }

            // AUTO-SYNC: Whenever text changes in Quill, update the hidden input
            quill.on('text-change', function () {
                const html = quill.root.innerHTML;
                card.querySelector('.policy-hidden-content').value = html;
            });

            // Enter Key Logic (Jump from Title to Editor)
            const titleInput = card.querySelector('.policy-title-input');
            titleInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    quill.focus(); // Focus the Quill editor
                }
            });

            // 🟢 NEW: Scroll the added section into view and focus title
            if (initialTitle === "" && initialContent === "") {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                titleInput.focus();
            }
        }

        // 3. Function to Delete a Section - QUILL VERSION
        async function deletePolicySection(buttonElement) {
            if (!await showConfirm("Confirmation", "Are you sure you want to delete this entire policy section?")) return;

            const card = buttonElement.closest('.policy-card');

            // With Quill, removing the element from the DOM is sufficient.
            if (card) {
                card.remove();
                showSuccess("Policy section deleted.");
            }
        }

        // 4. Master Save Function (Saves as JSON)
        function saveTerms() {
            const btn = document.querySelector('#view-terms .ab-submit-btn');
            const originalText = btn.innerText;

            btn.innerText = "Processing...";
            btn.disabled = true;

            let policies = [];

            // [INFO] FIX: Select ONLY cards inside the Terms & Conditions container
            const cards = document.querySelectorAll('#policy-builder-container .policy-card');

            cards.forEach(card => {
                const title = card.querySelector('.policy-title-input').value.trim();
                const content = card.querySelector('.policy-hidden-content').value;

                if (title || content) {
                    policies.push({
                        title: title,
                        content: content
                    });
                }
            });

            const jsonString = JSON.stringify(policies);
            const formData = new FormData();
            formData.append('terms_content', jsonString);
            if (typeof csrfToken !== 'undefined') formData.append('csrf_token', csrfToken);

            fetch('update_terms.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess("Terms & Conditions updated successfully!");
                        document.getElementById('rawDatabaseContent').value = jsonString;
                        renderTerms();
                    } else {
                        showError(data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        }

        // --- AUTOMATED DAILY REMINDER TRIGGER (SMART CATCH-UP) ---

        function checkAutoReminders() {
            const now = new Date();
            const hours = now.getHours(); // 0-23

            // 1. Set your Target Hour (9 = 9:00 AM)
            const targetHour = 9;

            // 2. Create a unique key for TODAY (e.g., "reminder_sent_2026-01-05")
            // We use local time string to ensure it matches your computer's date correctly
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const todayStr = `${year}-${month}-${day}`;

            const storageKey = 'reminder_sent_' + todayStr;

            // 3. THE LOGIC CHANGE:
            // IF the current hour is 9 or later...
            // AND we haven't marked it as "done" for today...
            if (hours >= targetHour && !localStorage.getItem(storageKey)) {

                console.log(`â° It's after ${targetHour}:00 AM and reminders haven't run. Triggering now...`);

                // Lock it immediately to prevent double-firing if multiple tabs open at once
                localStorage.setItem(storageKey, 'true');

                // 4. Call the PHP Script
                fetch('send_reminders.php')
                    .then(res => res.text())
                    .then(data => {
                        console.log("[OK] Auto-Process Result:", data);
                        // Optional: showInfo("Missed task: Daily Reminders have been sent!"); 
                    })
                    .catch(err => {
                        console.error("[ERROR] Auto-Process Failed:", err);
                        // If it fails, unlock it so it tries again next check
                        localStorage.removeItem(storageKey);
                    });
            }
        }

        // --- GALLERY UPLOAD FUNCTIONS (Must be global) ---

        // 1. Triggers the hidden file input when you click the box
        function triggerGalleryUpload(index) {
            const fileInput = document.getElementById('file_' + index);
            if (fileInput) {
                fileInput.click();
            } else {
                console.error("File input not found for index: " + index);
            }
        }

        // Update this function in your JS
        function refreshCalendarData(isSilent = true) {
            const calendarPage = document.getElementById('calendar');
            if (!calendarPage.classList.contains('active')) return;

            // 🟢 Show Skeleton State ONLY IF NOT SILENT
            if (!isSilent) {
                renderRealtimeCalendar(true);
            }

            // Get current view params
            const m = viewDate.getMonth() + 1;
            const y = viewDate.getFullYear();

            // Pass them to the AJAX call
            const fetchPromise = fetch(`get_calendar_data.php?month=${m}&year=${y}`).then(res => res.json());
            
            // 🟢 Minimum wait only if not silent
            const minWait = isSilent ? Promise.resolve() : new Promise(resolve => setTimeout(resolve, 600)); 

            Promise.all([fetchPromise, minWait])
                .then(([data]) => {
                    if (data.status === 'success') {
                        bookingsDB = data.bookings;
                        if (data.rooms) allRoomsList = data.rooms;
                        renderRealtimeCalendar(false); // 🟢 Show Actual Data
                        console.log("Calendar synced.");
                    }
                })
                .catch(err => {
                    console.error("Calendar Sync Error:", err);
                    renderRealtimeCalendar(false); // 🟢 Hide skeleton even on error
                });
        }

        // --- DELETE ROOM (Soft Delete) ---
        async function deleteRoom(id) {
            if (!await showConfirm("Archive Room", "Are you sure you want to archive this room? It will be hidden from the active list.")) return;

            const formData = new FormData();
            formData.append('action', 'delete'); // This triggers the soft-delete in your PHP
            formData.append('room_id', id);

            fetch('manage_rooms.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(data.message);
                        setTimeout(() => {
                            location.reload(); // Reload to update the lists
                        }, 1000);
                    } else {
                        showError("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error: Could not connect to server.");
                });
        }

        // --- PERMANENT DELETE ROOM ---
        async function permanentDeleteRoom(id) {
            // 1. Strong Warning
            if (!await showConfirm("CRITICAL WARNING", "This will PERMANENTLY DELETE this room and its images.\n\nAny booking history associated with this room might be affected.\n\nAre you absolutely sure?", 'error')) return;

            const formData = new FormData();
            formData.append('action', 'hard_delete');
            formData.append('room_id', id);

            // Visual feedback
            const row = document.getElementById('room-row-' + id);
            if (row) row.style.opacity = '0.3';

            fetch('manage_rooms.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess("Room permanently deleted.");
                        // Remove row from DOM immediately
                        if (row) row.remove();
                    } else {
                        showError("Error: " + data.message);
                        // Revert visual if failed (likely due to foreign key constraint with existing bookings)
                        if (row) row.style.opacity = '1';
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");
                    if (row) row.style.opacity = '1';
                });
        }


        // --- RESCHEDULE LOGIC ---
        async function rescheduleGuestBooking(reference, newStart, newEnd) {

            if (!await showConfirm("Confirmation", "Attempting to reschedule. The 3-day rule will be checked.")) return;

            fetch('guest_reschedule.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    booking_reference: reference, // e.g., "REF12345"
                    new_check_in: newStart,       // e.g., "2025-12-01"
                    new_check_out: newEnd         // e.g., "2025-12-05"
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'error') {
                        // This shows the 3-Day Rule Error
                        showError(data.message);
                    } else {
                        showSuccess(data.message + "\nNew Price: ₱" + data.new_total);
                        location.reload();
                    }
                })
                .catch(err => console.error(err));
        }

        // --- RESCHEDULE LOGIC ---
        let reschedCheckinFP = null;
        let reschedCheckoutFP = null;
        let rescheduleInitialBody = null; // [INFO] To store original HTML

        function openRescheduleModal(reference) {
            const modal = document.getElementById('rescheduleModal');
            const body = modal.querySelector('.ab-modal-body');

            // 1. Save initial body only once
            if (!rescheduleInitialBody) {
                rescheduleInitialBody = body.innerHTML;
            } else {
                // 2. RESTORE initial body (Fixes the bug where selection screen persists)
                body.innerHTML = rescheduleInitialBody;
            }

            document.getElementById('resched_ref_id').value = reference;

            if (reschedCheckinFP) reschedCheckinFP.destroy();

            reschedCheckinFP = flatpickr("#resched_checkin", {
                mode: "range",
                minDate: "today",
                showMonths: 1,
                dateFormat: "Y-m-d",
                plugins: [new rangePlugin({ input: "#resched_checkout" })],
                static: false,
                appendTo: document.body,
                onReady: function (selectedDates, dateStr, instance) {
                    instance.calendarContainer.classList.add("compact-theme");
                    instance.calendarContainer.classList.remove("double-month-theme");
                }
            });

            modal.style.display = 'block';
        }

        function closeRescheduleModal() {
            document.getElementById('rescheduleModal').style.display = 'none';
            // Re-open main modal (Optional)
            // document.getElementById('bookingActionModal').style.display = 'block';
        }

        async function submitReschedule(overrideRoomId = null) {
            const ref = document.getElementById('resched_ref_id').value;
            const newIn = document.getElementById('resched_checkin').value;
            const newOut = document.getElementById('resched_checkout').value;

            // Select the button based on context (Main modal vs Alternative view)
            let btn = document.querySelector('#rescheduleModal .ab-submit-btn');
            if (overrideRoomId) {
                // If we are confirming a new room, find the specific button inside the alternative view
                btn = document.getElementById('btn-confirm-alt');
            }

            if (!newIn || !newOut) {
                showError("Please select the new dates.");
                return;
            }

            if (!overrideRoomId && !await showConfirm("Confirmation", "Are you sure you want to reschedule?")) return;

            // 🟢 UI LOCKING (Locker System)
            toggleUILock(true, "PROCESSING RESCHEDULE...");

            // UI Loading State
            const originalText = btn.innerText;
            btn.innerText = "Checking...";
            btn.disabled = true;

            // Prepare Payload
            const payload = {
                booking_reference: ref,
                new_check_in: newIn,
                new_check_out: newOut
            };

            // Add Room ID if user selected an alternative
            if (overrideRoomId) {
                payload.new_room_id = overrideRoomId;
            }

            fetch('guest_reschedule.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(data.message + "\n\nNew Total Price: ₱" + parseFloat(data.new_total).toLocaleString());
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    }
                    else if (data.status === 'selection_required' || data.status === 'conflict') {
                        // --- UI MAGIC: SWITCH TO ROOM SELECTION VIEW ---
                        toggleUILock(false);
                        renderRescheduleAlternatives(data.message, data.next_date, data.alternatives, data.current_room_id);
                    }
                    else {
                        toggleUILock(false);
                        showError(data.message);
                        btn.innerText = originalText;
                        btn.disabled = false;
                    }
                })
                .catch(err => {
                    toggleUILock(false);
                    console.error(err);
                    showError("System Error");
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        }

        let selectedAltRoomId = null;

        function renderRescheduleAlternatives(msg, nextDate, rooms, currentRoomId = null) {
            const modalBody = document.querySelector('#rescheduleModal .ab-modal-body');

            // Capture existing values before clearing the DOM
            const ref = document.getElementById('resched_ref_id').value;
            const newIn = document.getElementById('resched_checkin').value;
            const newOut = document.getElementById('resched_checkout').value;

            // 1. Build the HTML for the room list (Using your existing CSS classes)
            let roomsHtml = '';

            if (rooms.length > 0) {
                rooms.forEach(room => {
                    // Fix Image Path Logic (Same as your main table)
                    let imgUrl = '../../IMG/default_room.jpg';
                    if (room.image_path) {
                        const parts = room.image_path.split(',');
                        imgUrl = '../../room_includes/uploads/images/' + parts[0].trim();
                    }

                    const isCurrent = (room.id == currentRoomId);

                    roomsHtml += `
            <div class="room-card ${isCurrent ? 'selected' : ''}" onclick="selectAltRoom(this, ${room.id})" style="border:1px solid #ddd; margin-bottom:0; position:relative;">
                ${isCurrent ? '<div style="position:absolute; top:5px; left:5px; background:#10B981; color:#fff; font-size:10px; padding:2px 5px; border-radius:4px; z-index:5;">Current</div>' : ''}
                <div class="room-card-check"></div>
                <img src="${imgUrl}" class="room-card-image" style="height:120px;" onerror="this.src='../../IMG/default_room.jpg'">
                <div class="room-card-body" style="padding:10px;">
                    <div class="room-card-header" style="font-size:0.9rem;">${room.name}</div>
                    <div class="room-card-details">
                        <span class="detail-badge">[PAX] ${room.capacity}</span>
                        <span class="detail-badge">[BED] ${room.bed_type}</span>
                    </div>
                    <div class="room-card-price" style="font-size:1rem;">₱${parseFloat(room.price).toLocaleString()}</div>
                </div>
            </div>`;
                });
            } else {
                roomsHtml = `<div style="grid-column:1/-1; text-align:center; padding:20px; background:#f9f9f9; border-radius:8px;">No other rooms available on these dates.</div>`;
            }

            // If current room was automatically selected, update global var
            if (currentRoomId) selectedAltRoomId = currentRoomId;

            // 2. Inject the New UI into the Modal
            modalBody.innerHTML = `
        <div style="background-color: #EFF6FF; color: #1E40AF; padding: 12px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 15px; border: 1px solid #DBEAFE;">
            <i class="fas fa-info-circle"></i> <strong>Room Availability</strong><br>
            ${msg}
            ${nextDate ? `<br><small>Busy until: <strong>${nextDate}</strong></small>` : ''}
        </div>

        <h4 style="margin:0 0 10px 0; font-size:0.9rem; color:#333;">Select a Room to Finalize:</h4>
        
        <div class="room-selection-grid" style="grid-template-columns: 1fr 1fr; gap: 10px; max-height: 250px; overflow-y: auto; padding: 2px;">
            ${roomsHtml}
        </div>

        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <span class="footer-heading" style="margin:0; font-size: 0.7rem; color: #999; text-transform: uppercase;">Dates Selection</span>
                <button type="button" onclick="openRescheduleModal('${ref}')" style="background: none; border: none; color: #B88E2F; font-size: 0.7rem; font-weight: 700; cursor: pointer; text-transform: uppercase;">
                    <i class="fas fa-edit"></i> Change
                </button>
            </div>
            <div class="ab-grid-2" style="gap: 10px;">
                <div class="ab-input-wrapper">
                    <input type="text" class="ab-input" value="${newIn}" readonly style="background-color: #F9FAFB; cursor: default; font-size: 0.8rem; padding: 10px 12px;">
                    <i class="far fa-calendar-alt ab-calendar-icon" style="color: #9CA3AF; font-size: 0.8rem;"></i>
                </div>
                <div class="ab-input-wrapper">
                    <input type="text" class="ab-input" value="${newOut}" readonly style="background-color: #F9FAFB; cursor: default; font-size: 0.8rem; padding: 10px 12px;">
                    <i class="far fa-calendar-alt ab-calendar-icon" style="color: #9CA3AF; font-size: 0.8rem;"></i>
                </div>
            </div>
        </div>

        <!-- Hidden inputs to persist state -->
        <input type="hidden" id="resched_ref_id" value="${ref}">
        <input type="hidden" id="resched_checkin" value="${newIn}">
        <input type="hidden" id="resched_checkout" value="${newOut}">

        <div class="ab-grid-footer" style="margin-top: 15px;">
            <button class="btn-secondary" onclick="location.reload()">Cancel</button>
            <button class="ab-submit-btn" id="btn-confirm-alt" onclick="confirmAltRoom()" ${!currentRoomId ? 'disabled style="opacity:0.5;"' : ''}>
                Confirm Reschedule
            </button>
        </div>
    `;
        }

        function selectAltRoom(card, id) {
            // Visual Select
            document.querySelectorAll('.room-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');

            // Logic Select
            selectedAltRoomId = id;

            // Enable Button
            const btn = document.getElementById('btn-confirm-alt');
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.innerText = "Confirm Room Change";
        }

        function confirmAltRoom() {
            if (!selectedAltRoomId) return;
            // Re-call the submit function, but pass the new room ID
            submitReschedule(selectedAltRoomId);
        }


        // --- REAL-TIME BOOKING TABLE ---
        let isTableUpdating = false;

        function refreshBookingTable(isSilent = false) {
            const searchInput = document.getElementById('bookingSearchInput');
            const search = searchInput ? searchInput.value.trim() : '';

            // [INFO] AUTO-REFRESH: Only run if NOT searching OR if explicitly told to (silent)
            if (!isSilent && search !== "") {
                return Promise.resolve();
            }

            const url = `fetch_booking_table.php?limit=${bookingLimit}&offset=${bookingOffset}&search=${encodeURIComponent(search)}&filter=${currentTabStatus}&t=${new Date().getTime()}`;

            const tbody = document.getElementById('bookingTableBody');
            // Show loading if not a background/silent refresh
            if (!isSilent && tbody) {
                tbody.innerHTML = `<tr><td colspan="10" style="padding: 100px 0; text-align: center;">
                    <div class="amv-loader-container">
                        <div class="amv-loader"></div>
                        <div style="font-weight: 600; font-size: 1.1rem; letter-spacing: 0.5px; color: #B88E2F;">Loading Bookings...</div>
                    </div>
                </td></tr>`;
            }

            return fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        const tbody = document.getElementById('bookingTableBody');
                        if (!tbody) return;

                        // 1. Update Table Body
                        tbody.innerHTML = data.html;

                        // 2. Update Pagination UI
                        updateBookingPaginationUI(data.total, data.limit, data.offset);
                    }
                })
                .catch(err => console.error("Table refresh error:", err));
        }

        function updateBookingPaginationUI(total, limit, offset) {
            const container = document.getElementById('bookingPagination');
            const foot = document.getElementById('bookingPaginationFoot');
            if (container) {
                const isVisible = total > limit;
                container.style.display = isVisible ? 'flex' : 'none';
                if (foot) foot.style.display = isVisible ? 'table-footer-group' : 'none';
            }

            const start = total === 0 ? 0 : offset + 1;
            const end = Math.min(offset + limit, total);
            const currentPage = Math.floor(offset / limit) + 1;
            const totalPages = Math.ceil(total / limit);

            document.getElementById('bookingPageStart').innerText = start;
            document.getElementById('bookingPageEnd').innerText = end;
            document.getElementById('bookingTotalCount').innerText = total;
            const btnContainer = document.getElementById('bookingPageButtons');
            if (!btnContainer) return;
            btnContainer.innerHTML = '';

            const addDots = () => {
                const span = document.createElement('span');
                span.className = 'pg-dots';
                span.innerText = '...';
                btnContainer.appendChild(span);
            };

            // Prev
            const prevBtn = document.createElement('button');
            prevBtn.className = 'pg-btn pg-btn-nav';
            prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i> Prev';
            prevBtn.disabled = (currentPage === 1);
            prevBtn.onclick = (e) => {
                e.preventDefault();
                bookingOffset = Math.max(0, bookingOffset - limit);
                refreshBookingTable(true);
            };
            btnContainer.appendChild(prevBtn);

            // Pages
            let startPage = Math.max(1, currentPage - 1);
            let endPage = Math.min(totalPages, startPage + 2);
            if (endPage - startPage < 2) startPage = Math.max(1, endPage - 2);

            if (startPage > 1) {
                btnContainer.appendChild(createBookingPageBtn(1, limit, 1 === currentPage));
                if (startPage > 2) addDots();
            }

            for (let i = startPage; i <= endPage; i++) {
                if (i > 0) btnContainer.appendChild(createBookingPageBtn(i, limit, i === currentPage));
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) addDots();
                btnContainer.appendChild(createBookingPageBtn(totalPages, limit, totalPages === currentPage));
            }

            // Next
            const nextBtn = document.createElement('button');
            nextBtn.className = 'pg-btn pg-btn-nav';
            nextBtn.innerHTML = 'Next <i class="fas fa-chevron-right"></i>';
            nextBtn.disabled = (currentPage === totalPages || total === 0);
            nextBtn.onclick = (e) => {
                e.preventDefault();
                bookingOffset += limit;
                refreshBookingTable(true);
            };
            btnContainer.appendChild(nextBtn);
        }

        function createBookingPageBtn(page, limit, isActive) {
            const btn = document.createElement('button');
            btn.className = 'pg-btn' + (isActive ? ' active' : '');
            btn.innerText = page;
            btn.onclick = (e) => {
                e.preventDefault();
                bookingOffset = (page - 1) * limit;
                refreshBookingTable(true);
            };
            return btn;
        }

        // --- [INFO] NEW: SEAMLESS FOOD TABLE REFRESH ---
        function refreshFoodTable(isSilent = false) {
            const tbody = document.getElementById('foodTableBody');
            if (!tbody) return;

            const url = `fetch_food_table.php?limit=${foodLimit}&offset=${foodOffset}&t=${new Date().getTime()}`;

            // Show loading if not silent
            if (!isSilent) {
                tbody.innerHTML = `<tr><td colspan="9" style="padding: 100px 0; text-align: center;">
                    <div class="amv-loader-container">
                        <div class="amv-loader"></div>
                        <div style="font-weight: 600; font-size: 1.1rem; letter-spacing: 0.5px; color: #B88E2F;">Loading Food Orders...</div>
                    </div>
                </td></tr>`;
            }

            fetch(url)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        // 1. Update Table Body
                        tbody.innerHTML = data.html;

                        // 2. Update Pagination UI
                        updateFoodPaginationUI(data.total, data.limit, data.offset);
                    }
                })
                .catch(err => console.error("Food table sync error:", err));
        }

        function updateFoodPaginationUI(total, limit, offset) {
            const container = document.getElementById('foodPagination');
            const foot = document.getElementById('foodPaginationFoot');
            if (container) {
                const isVisible = total > limit;
                container.style.display = isVisible ? 'flex' : 'none';
                if (foot) foot.style.display = isVisible ? 'table-footer-group' : 'none';
            }

            const start = total === 0 ? 0 : offset + 1;
            const end = Math.min(offset + limit, total);
            const currentPage = Math.floor(offset / limit) + 1;
            const totalPages = Math.ceil(total / limit);

            if (document.getElementById('foodPageStart')) document.getElementById('foodPageStart').innerText = start;
            if (document.getElementById('foodPageEnd')) document.getElementById('foodPageEnd').innerText = end;
            if (document.getElementById('foodTotalCount')) document.getElementById('foodTotalCount').innerText = total;

            const btnContainer = document.getElementById('foodPageButtons');
            if (!btnContainer) return;
            btnContainer.innerHTML = '';

            const addDots = () => {
                const span = document.createElement('span');
                span.className = 'pg-dots';
                span.innerText = '...';
                btnContainer.appendChild(span);
            };

            // Prev
            const prevBtn = document.createElement('button');
            prevBtn.className = 'pg-btn pg-btn-nav';
            prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i> Prev';
            prevBtn.disabled = (currentPage === 1);
            prevBtn.onclick = (e) => {
                e.preventDefault();
                foodOffset = Math.max(0, foodOffset - limit);
                refreshFoodTable();
            };
            btnContainer.appendChild(prevBtn);

            // Pages
            let startPage = Math.max(1, currentPage - 1);
            let endPage = Math.min(totalPages, startPage + 2);
            if (endPage - startPage < 2) startPage = Math.max(1, endPage - 2);

            if (startPage > 1) {
                btnContainer.appendChild(createFoodPageBtn(1, limit, 1 === currentPage));
                if (startPage > 2) addDots();
            }

            for (let i = startPage; i <= endPage; i++) {
                if (i > 0) btnContainer.appendChild(createFoodPageBtn(i, limit, i === currentPage));
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) addDots();
                btnContainer.appendChild(createFoodPageBtn(totalPages, limit, totalPages === currentPage));
            }

            // Next
            const nextBtn = document.createElement('button');
            nextBtn.className = 'pg-btn pg-btn-nav';
            nextBtn.innerHTML = 'Next <i class="fas fa-chevron-right"></i>';
            nextBtn.disabled = (currentPage === totalPages || total === 0);
            nextBtn.onclick = (e) => {
                e.preventDefault();
                foodOffset += limit;
                refreshFoodTable();
            };
            btnContainer.appendChild(nextBtn);
        }

        function createFoodPageBtn(page, limit, isActive) {
            const btn = document.createElement('button');
            btn.className = 'pg-btn' + (isActive ? ' active' : '');
            btn.innerText = page;
            btn.onclick = (e) => {
                e.preventDefault();
                foodOffset = (page - 1) * limit;
                refreshFoodTable();
            };
            return btn;
        }

        // --- BACKGROUND AUTO-UPDATER ---
        function triggerAutoUpdates() {
            fetch('auto_update_status.php')
                .then(res => res.json())
                .then(data => {
                    if (data.updates > 0) {
                        console.log("System Auto-Update: " + data.updates + " bookings marked as No-Show.");
                        // If an update happened, refresh the table so the user sees it immediately
                        refreshBookingTable(true);
                        fetchDashboardCards();
                    }
                })
                .catch(err => console.error("Auto-update check failed:", err));
        }


        // --- QR SCANNER LOGIC ---
        let html5QrcodeScanner = null;

        function openScannerModal() {
            document.getElementById('qrScannerModal').style.display = 'block';

            // Initialize Scanner only if not already running
            if (!html5QrcodeScanner) {
                html5QrcodeScanner = new Html5Qrcode("qr-reader");
            }

            const config = { fps: 10, qrbox: { width: 250, height: 250 } };

            // Start Camera (Rear camera preferred)
            html5QrcodeScanner.start({ facingMode: "environment" }, config, onScanSuccess, onScanFailure)
                .catch(err => {
                    console.error("Camera start failed", err);
                    document.getElementById('qr-result').innerText = "Error starting camera. Please allow permissions.";
                });
        }

        function closeScannerModal() {
            document.getElementById('qrScannerModal').style.display = 'none';

            // Stop Camera to save battery
            if (html5QrcodeScanner) {
                html5QrcodeScanner.stop().then(() => {
                    console.log("Scanner stopped");
                }).catch(err => {
                    console.warn("Failed to stop scanner", err);
                });
            }
        }

        // SUCCESS CALLBACK
        function onScanSuccess(decodedText, decodedResult) {
            // 1. Pause scanning so we don't spam the server
            html5QrcodeScanner.pause();

            document.getElementById('qr-result').innerHTML = `<span style="color:blue;">Processing: ${decodedText}...</span>`;

            // 2. Send to Backend (confirm_qr.php)
            fetch('confirm_qr.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reference: decodedText })
            })
                .then(res => res.json())
                .then(data => {
                    // Clear the "Processing..." text once we get a response
                    document.getElementById('qr-result').innerHTML = "";

                    // Standard configuration for all modals to ensure they are on top
                    const swalConfig = {
                        customClass: {
                            popup: 'amv-swal-popup',
                            title: 'amv-swal-title',
                            htmlContainer: 'amv-swal-content',
                            confirmButton: 'amv-swal-confirm-btn',
                            cancelButton: 'amv-swal-cancel-btn'
                        },
                        didOpen: () => {
                            const container = Swal.getContainer();
                            if (container) container.style.zIndex = '999999';
                        }
                    };

                    if (data.status === 'success') {
                        // [OK] SUCCESS
                        Swal.fire({
                            ...swalConfig,
                            icon: 'success',
                            title: 'Check-in Confirmed!',
                            text: data.message,
                            timer: 3000,
                            showConfirmButton: false
                        }).then(() => {
                            refreshBookingTable();
                            fetchDashboardCards();
                            closeScannerModal();
                        });

                    } else if (data.status === 'warning') {
                        // [WARN] WARNING
                        Swal.fire({
                            ...swalConfig,
                            icon: 'warning',
                            title: 'Note',
                            text: data.message,
                            confirmButtonColor: '#f8bb86'
                        }).then(() => {
                            html5QrcodeScanner.resume();
                        });

                    } else {
                        // [ERROR] ERROR
                        Swal.fire({
                            ...swalConfig,
                            icon: 'error',
                            title: 'Access Denied',
                            text: data.message,
                            confirmButtonColor: '#d33'
                        }).then(() => {
                            html5QrcodeScanner.resume();
                        });
                    }
                })
                .catch(err => {
                    console.error(err);
                    Swal.fire({
                        icon: 'error',
                        title: 'System Error',
                        text: 'Check connection to the server.',
                        didOpen: () => {
                            const container = Swal.getContainer();
                            if (container) container.style.zIndex = '9999';
                        }
                    });
                    html5QrcodeScanner.resume();
                });
        }

        function onScanFailure(error) {
            // Handle scan failure, usually better to ignore as it fires constantly while looking for a code
            // console.warn(`Code scan error = ${error}`);
        }

        // [INFO] NEW: HELPER TO HANDLE TOAST EXIT ANIMATION
        function triggerToastExit(alertCard) {
            if (!alertCard || alertCard.style.display === 'none') return;

            // Add exit class
            alertCard.classList.add('toast-out');

            // Wait for animation to finish then hide
            setTimeout(() => {
                alertCard.style.display = 'none';
                alertCard.classList.remove('toast-out');
                repositionToasts(); // Recalculate positions
            }, 400); // Matches CSS animation duration
        }

        // [INFO] NEW: HELPER TO STACK TOASTS DYNAMICALLY
        function repositionToasts() {
            const toastIds = ['newNotificationAlert', 'newMessageAlert', 'lateArrivalAlert', 'newOrderAlert'];
            let currentTop = 90;
            const gap = 95;

            toastIds.forEach(id => {
                const el = document.getElementById(id);
                // Check if element is visible and not animating out
                if (el && getComputedStyle(el).display === 'flex' && !el.classList.contains('toast-out')) {
                    el.style.top = currentTop + 'px';
                    currentTop += gap;
                }
            });
        }

        function updateNotificationAlert(unreadCount) {
            const alertCard = document.getElementById('newNotificationAlert');
            const countDisplay = document.getElementById('nb_count_display');
            const progressBar = alertCard ? alertCard.querySelector('.toast-progress-bar') : null;

            if (!alertCard || !countDisplay) return;

            const lastCount = parseInt(localStorage.getItem('lastNotificationCount') || '0');

            if (unreadCount > 0 && unreadCount > lastCount) {
                // [INFO] TRIGGER: New notification arrived
                countDisplay.innerText = unreadCount;

                if (getComputedStyle(alertCard).display === 'none' || alertCard.classList.contains('toast-out')) {
                    alertCard.style.display = 'flex';
                    alertCard.classList.remove('toast-out');
                    repositionToasts();
                }

                // Animate Progress Bar (Force reflow for stability)
                if (progressBar) {
                    progressBar.style.transition = 'none';
                    progressBar.style.transform = 'scaleX(1)';
                    void progressBar.offsetWidth; // Force Reflow
                    progressBar.style.transition = 'transform 10s linear';
                    progressBar.style.transform = 'scaleX(0)';
                }

                // Auto-hide after 10 seconds with animation
                clearTimeout(window.alertHideTimeout);
                window.alertHideTimeout = setTimeout(() => {
                    triggerToastExit(alertCard);
                }, 10000);
            } else if (unreadCount === 0) {
                if (getComputedStyle(alertCard).display === 'flex' && !alertCard.classList.contains('toast-out')) {
                    triggerToastExit(alertCard);
                }
            }

            localStorage.setItem('lastNotificationCount', unreadCount);
        }

        function updateMessageAlert(unreadCount) {
            const alertCard = document.getElementById('newMessageAlert');
            const countDisplay = document.getElementById('msg_count_display');
            const progressBar = alertCard ? alertCard.querySelector('.toast-progress-bar') : null;

            if (!alertCard || !countDisplay) return;

            const lastCount = parseInt(localStorage.getItem('lastMessageCount') || '0');

            if (unreadCount > 0 && unreadCount > lastCount) {
                // [INFO] TRIGGER: New message arrived
                countDisplay.innerText = unreadCount;

                if (getComputedStyle(alertCard).display === 'none' || alertCard.classList.contains('toast-out')) {
                    alertCard.style.display = 'flex';
                    alertCard.classList.remove('toast-out');
                    repositionToasts();
                }

                // Animate Progress Bar (Force reflow)
                if (progressBar) {
                    progressBar.style.transition = 'none';
                    progressBar.style.transform = 'scaleX(1)';
                    void progressBar.offsetWidth; // Force Reflow
                    progressBar.style.transition = 'transform 10s linear';
                    progressBar.style.transform = 'scaleX(0)';
                }

                // Auto-hide after 10 seconds
                clearTimeout(window.msgAlertHideTimeout);
                window.msgAlertHideTimeout = setTimeout(() => {
                    triggerToastExit(alertCard);
                }, 10000);
            } else if (unreadCount === 0) {
                if (getComputedStyle(alertCard).display === 'flex' && !alertCard.classList.contains('toast-out')) {
                    triggerToastExit(alertCard);
                }
            }

            localStorage.setItem('lastMessageCount', unreadCount);
        }

        function updateLateArrivalAlert(lateCount) {
            const alertCard = document.getElementById('lateArrivalAlert');
            const countDisplay = document.getElementById('late_count_display');
            const progressBar = alertCard ? alertCard.querySelector('.toast-progress-bar') : null;

            if (!alertCard || !countDisplay) return;

            const today = new Date().toISOString().split('T')[0];
            const isDismissed = localStorage.getItem('lateAlertDismissed_' + today);
            const lastCount = parseInt(localStorage.getItem('lastLateArrivalCount') || '0');

            if (lateCount > 0 && lateCount > lastCount && !isDismissed) {
                // [INFO] TRIGGER: New late arrival detected
                countDisplay.innerText = lateCount;

                if (getComputedStyle(alertCard).display === 'none' || alertCard.classList.contains('toast-out')) {
                    alertCard.style.display = 'flex';
                    alertCard.classList.remove('toast-out');
                    repositionToasts();
                }

                // Animate Progress Bar (Force reflow)
                if (progressBar) {
                    progressBar.style.transition = 'none';
                    progressBar.style.transform = 'scaleX(1)';
                    void progressBar.offsetWidth; // Force Reflow
                    progressBar.style.transition = 'transform 15s linear';
                    progressBar.style.transform = 'scaleX(0)';
                }

                // Auto-hide after 15 seconds
                clearTimeout(window.lateAlertHideTimeout);
                window.lateAlertHideTimeout = setTimeout(() => {
                    triggerToastExit(alertCard);
                }, 15000);
            } else if (lateCount === 0 || isDismissed) {
                if (getComputedStyle(alertCard).display === 'flex' && !alertCard.classList.contains('toast-out')) {
                    triggerToastExit(alertCard);
                }
            }

            localStorage.setItem('lastLateArrivalCount', lateCount);
        }

        function updateOrderAlert(unreadCount) {
            const alertCard = document.getElementById('newOrderAlert');
            const countDisplay = document.getElementById('order_count_display');
            const progressBar = alertCard ? alertCard.querySelector('.toast-progress-bar') : null;

            if (!alertCard || !countDisplay) return;

            const lastCount = parseInt(localStorage.getItem('lastOrderCount') || '0');

            if (unreadCount > 0 && unreadCount > lastCount) {
                // [INFO] TRIGGER: New order arrived
                countDisplay.innerText = unreadCount;

                if (getComputedStyle(alertCard).display === 'none' || alertCard.classList.contains('toast-out')) {
                    alertCard.style.display = 'flex';
                    alertCard.classList.remove('toast-out');
                    repositionToasts();
                }

                // Animate Progress Bar (Force reflow)
                if (progressBar) {
                    progressBar.style.transition = 'none';
                    progressBar.style.transform = 'scaleX(1)';
                    void progressBar.offsetWidth;
                    progressBar.style.transition = 'transform 10s linear';
                    progressBar.style.transform = 'scaleX(0)';
                }

                // Auto-hide after 10 seconds
                clearTimeout(window.orderAlertHideTimeout);
                window.orderAlertHideTimeout = setTimeout(() => {
                    triggerToastExit(alertCard);
                }, 10000);
            } else if (unreadCount === 0) {
                if (getComputedStyle(alertCard).display === 'flex' && !alertCard.classList.contains('toast-out')) {
                    triggerToastExit(alertCard);
                }
            }

            localStorage.setItem('lastOrderCount', unreadCount);
        } function openUnreadMessages(event) {
            if (event) event.stopPropagation();

            // 1. Force Open the Dropdown
            const dropdown = document.getElementById('msgDropdown');
            dropdown.classList.add('show');

            // 2. Hide other dropdowns (like notifications) to prevent overlap
            document.querySelectorAll('.dropdown-menu').forEach(dd => {
                if (dd.id !== 'msgDropdown') dd.classList.remove('show');
            });

            // 3. Find the "Unread" filter option button in the DOM
            // We look inside the filter menu for the element that has the 'unread' onclick
            const filterMenu = document.getElementById('msgFilterMenu');
            const unreadOption = filterMenu.querySelector('.filter-option:nth-child(2)'); // Usually the 2nd option is 'Unread'

            // 4. Apply the existing filter logic
            // This reuses your existing code to filter the list and highlight the "Unread" button
            if (unreadOption) {
                applyMsgFilter('unread', unreadOption);
            }

            // 5. Hide the Alert Card immediately
            const alertCard = document.getElementById('newMessageAlert');
            if (alertCard) alertCard.style.display = 'none';
        }

        // --- [INFO] EVENT MANAGEMENT LOGIC ---

        // 1. Initialize Date Picker & TinyMCE for Events
        document.addEventListener("DOMContentLoaded", function () {

            // 1. Initialize Flatpickr for Events (Match News/Birthdate style)
            flatpickr("#event_date_picker", {
                dateFormat: "Y-m-d",
                minDate: "today",
                disableMobile: "true",
                showMonths: 1,
                monthSelectorType: "static",
                onReady: function (selectedDates, dateStr, instance) {
                    instance.calendarContainer.classList.add("compact-theme");
                    initCustomFpHeader(instance, { showDropdowns: true });
                },
                onMonthChange: function (selectedDates, dateStr, instance) {
                    updateDropdownSelections(instance);
                },
                onYearChange: function (selectedDates, dateStr, instance) {
                    updateDropdownSelections(instance);
                },
            });

            // [INFO] 2. Initialize Custom Time Picker for Events
            flatpickr("#eventTimeInput", {
                enableTime: true,
                noCalendar: true,
                dateFormat: "h:i K",
                altFormat: "h:i K",
                disableMobile: "true",
                position: "auto right", // [INFO] Align to the right edge of the input
                onReady: function (selectedDates, dateStr, instance) {
                    instance.calendarContainer.classList.add("compact-theme");
                    instance.calendarContainer.classList.add("time-picker-theme");
                }
            });

            // 2. REPLACE TINYMCE WITH QUILL (For Events)
            eventQuill = new Quill('#eventQuillEditor', {
                theme: 'snow',
                placeholder: 'Write event details here...',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline'],
                        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                        ['link', 'clean']
                    ]
                }
            });

        });

        // 2. Image Preview
        function previewEventImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    document.getElementById('eventImagePreview').src = e.target.result;
                    document.getElementById('eventImagePreview').style.display = 'block';
                    document.getElementById('eventImagePlaceholder').style.display = 'none';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // 3. Open Modal (Add)
        function openAddEventModal() {
            document.getElementById('eventForm').reset();

            // [INFO] NEW: Clear Quill Editor
            if (eventQuill) {
                eventQuill.setText('');
            }
            // Clear hidden input
            document.getElementById('eventDescInput').value = '';

            document.getElementById('eventModalTitle').innerText = "Add New Event";
            document.getElementById('eventAction').value = "add";
            document.getElementById('eventId').value = "";

            // Reset Image
            const preview = document.getElementById('eventImagePreview');
            const placeholder = document.getElementById('eventImagePlaceholder');

            preview.src = "";
            preview.style.display = 'none';
            placeholder.style.display = 'block';

            document.getElementById('eventModal').style.display = 'block';
        }

        // 4. Open Modal (Edit) - UPDATED WITH BASE64 DECODING
        function openEditEventModal(id, title, date, time, encodedDesc, imgPath) {
            document.getElementById('eventId').value = id;
            document.getElementById('eventAction').value = "edit";
            document.getElementById('eventModalTitle').innerText = "Edit Event";

            document.getElementById('eventTitleInput').value = title;

            // Set Date Picker
            const datePicker = document.getElementById('event_date_picker')._flatpickr;
            if (datePicker) {
                datePicker.setDate(date);
            }

            document.getElementById('eventTimeInput').value = time;

            // [INFO] DECODE BASE64 DESCRIPTION SAFELY
            let decodedDesc = "";
            try {
                decodedDesc = decodeURIComponent(escape(window.atob(encodedDesc)));
            } catch (e) {
                console.error("Decoding error", e);
                decodedDesc = "";
            }

            // 1. Update hidden input
            document.getElementById('eventDescInput').value = decodedDesc;

            // 2. Update Quill Editor
            if (eventQuill) {
                setTimeout(() => {
                    eventQuill.clipboard.dangerouslyPasteHTML(decodedDesc);
                }, 50);
            }

            // Image Logic
            const preview = document.getElementById('eventImagePreview');
            const placeholder = document.getElementById('eventImagePlaceholder');

            if (imgPath) {
                preview.src = '../../room_includes/uploads/events/' + imgPath;
                preview.style.display = 'block';
                placeholder.style.display = 'none';
            } else {
                preview.src = "";
                preview.style.display = 'none';
                placeholder.style.display = 'block';
            }

            document.getElementById('eventModal').style.display = 'block';
        }

        // 5. Submit Form
        const eventForm = document.getElementById('eventForm');
        if (eventForm) {
            eventForm.addEventListener('submit', function (e) {
                e.preventDefault();

                // [INFO] NEW: Sync Quill to Hidden Input manually
                if (eventQuill) {
                    document.getElementById('eventDescInput').value = eventQuill.root.innerHTML;
                }

                const btn = this.querySelector('button[type="submit"]');
                const originalText = btn.innerText;
                btn.innerText = "Saving...";
                btn.disabled = true;

                const formData = new FormData(this);

                fetch('manage_events.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            showSuccess(data.message);
                            document.getElementById('eventModal').style.display = 'none';
                            fetchEventsTable();
                        } else {
                            showError(data.message.replace(/^Error:\s*/i, ""));
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        showError("System Error");
                    })
                    .finally(() => {
                        btn.innerText = originalText;
                        btn.disabled = false;
                    });
            });
        }

        function fetchEventsTable() {
            fetch('fetch_events_table.php')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.querySelector('#view-events .booking-table tbody').innerHTML = data.html;
                        const btn = document.getElementById('toggleArchivedEventsBtn');
                        if (btn && btn.innerText === "Hide Archived") {
                            const rows = document.querySelectorAll('.archived-event-row');
                            rows.forEach(r => r.style.display = 'table-row');
                        }
                    }
                });
        }
        

        // --- 1. TOGGLE ARCHIVED EVENTS ---
        function toggleArchivedEvents() {
            const rows = document.querySelectorAll('.archived-event-row');
            const btn = document.getElementById('toggleArchivedEventsBtn');
            let isHidden = false;

            rows.forEach(row => {
                if (row.style.display === 'none') {
                    row.style.display = 'table-row';
                    isHidden = false;
                } else {
                    row.style.display = 'none';
                    isHidden = true;
                }
            });

            btn.innerText = isHidden ? "Show Archived" : "Hide Archived";
        }

        // --- 2. SOFT DELETE EVENT (Archive) ---
        async function deleteEvent(id) {
            if (!await showConfirm("Archive Event", "Are you sure you want to archive this event? It will be hidden from the website.")) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('event_id', id);

            fetch('manage_events.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(data.message);
                        fetchEventsTable();
                    } else {
                        showError("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");
                });
        }

        // --- 3. RESTORE EVENT ---
        async function restoreEvent(id) {
            if (!await showConfirm("Restore Event", "Restore this event to the active list?")) return;

            const formData = new FormData();
            formData.append('action', 'restore');
            formData.append('event_id', id);

            fetch('manage_events.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(data.message);
                        fetchEventsTable();
                    } else {
                        showError("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");
                });
        }

        // --- 4. PERMANENT DELETE EVENT ---
        async function permanentDeleteEvent(id) {
            if (!await showConfirm("PERMANENT DELETE", "This will PERMANENTLY DELETE this event and its image.\n\nThis action CANNOT be undone.\n\nAre you sure?", 'error')) return;

            const formData = new FormData();
            formData.append('action', 'hard_delete');
            formData.append('event_id', id);

            fetch('manage_events.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess("Event permanently deleted.");
                        fetchEventsTable();
                    } else {
                        showError("Error: " + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");
                });
        }

        // --- PRIVACY POLICY BUILDER LOGIC ---

        // 1. Initialize Privacy Data on Load
        document.addEventListener("DOMContentLoaded", function () {
            const rawDataEl = document.getElementById('rawPrivacyContent');
            const container = document.getElementById('privacy-builder-container');

            if (!rawDataEl || !container) return;

            const rawData = rawDataEl.value.trim();

            try {
                if (rawData !== "") {
                    const policies = JSON.parse(rawData);
                    if (Array.isArray(policies) && policies.length > 0) {
                        policies.forEach(policy => {
                            addPrivacySection(policy.title, policy.content);
                        });
                    } else {
                        addPrivacySection();
                    }
                } else {
                    addPrivacySection();
                }
            } catch (e) {
                addPrivacySection();
            }
        });

        // 2. Function to Add a Privacy Section Card
        function addPrivacySection(initialTitle = "", initialContent = "") {
            const container = document.getElementById('privacy-builder-container');
            if (!container) return;

            // Generate a unique ID for this specific editor instance
            const uniqueId = "privacy_quill_" + Math.random().toString(36).substr(2, 9) + "_" + new Date().getTime();

            const card = document.createElement('div');
            card.className = 'policy-card'; // Reusing your existing CSS class for consistent styling
            card.innerHTML = `
        <div class="policy-header">
            <input type="text" class="policy-title-input" placeholder="Section Title (e.g. Information We Collect)" value="${initialTitle.replace(/"/g, '&quot;')}">
            <button class="btn-delete-policy" onclick="deletePolicySection(this)">
                <i class="fas fa-trash-alt"></i> Delete
            </button>
        </div>
        <input type="hidden" class="policy-hidden-content" value='${initialContent.replace(/'/g, "&#39;")}'>
        <div id="${uniqueId}" style="height: 250px; background: white;"></div>
    `;

            container.appendChild(card);

            // Initialize Quill
            var quill = new Quill('#' + uniqueId, {
                theme: 'snow',
                placeholder: 'Enter privacy details...',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline'],
                        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                        ['link', 'clean']
                    ]
                }
            });

            if (initialContent) {
                quill.clipboard.dangerouslyPasteHTML(initialContent);
            }

            // Sync to hidden input
            quill.on('text-change', function () {
                card.querySelector('.policy-hidden-content').value = quill.root.innerHTML;
            });

            // Enter Key Logic (Jump from Title to Editor)
            const titleInput = card.querySelector('.policy-title-input');
            titleInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    quill.focus(); // Focus the Quill editor
                }
            });

            // 🟢 NEW: Scroll the added section into view and focus title
            if (initialTitle === "" && initialContent === "") {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                titleInput.focus();
            }
        }

        // 3. Save Function
        function savePrivacy() {
            const btn = document.querySelector('#view-privacy .ab-submit-btn');
            const originalText = btn.innerText;

            btn.innerText = "Processing...";
            btn.disabled = true;

            let policies = [];
            // Select cards specifically inside the privacy container
            const cards = document.querySelectorAll('#privacy-builder-container .policy-card');

            cards.forEach(card => {
                const title = card.querySelector('.policy-title-input').value.trim();
                const content = card.querySelector('.policy-hidden-content').value;

                if (title || content) {
                    policies.push({
                        title: title,
                        content: content
                    });
                }
            });

            const jsonString = JSON.stringify(policies);
            const formData = new FormData();
            formData.append('privacy_content', jsonString);
            formData.append('csrf_token', csrfToken); // Using your existing global token

            // Point to a new PHP file for saving
            fetch('update_privacy.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess("Privacy Policy updated successfully!");
                        const rawEl = document.getElementById('rawPrivacyContent');
                        if (rawEl) {
                            rawEl.value = jsonString;
                            renderPrivacy();
                        }
                    } else {
                        showError(data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        }

        function renderPrivacy() {
            const container = document.getElementById('privacy-builder-container');
            if (!container) return;
            const rawDataEl = document.getElementById('rawPrivacyContent');
            if (!rawDataEl) return;
            const rawData = rawDataEl.value.trim();

            container.innerHTML = '';
            try {
                if (rawData !== "") {
                    const policies = JSON.parse(rawData);
                    if (Array.isArray(policies) && policies.length > 0) {
                        policies.forEach(policy => {
                            addPrivacySection(policy.title, policy.content);
                        });
                    } else {
                        addPrivacySection();
                    }
                } else {
                    addPrivacySection();
                }
            } catch (e) {
                addPrivacySection();
            }
        }

        // --- [INFO] CUSTOM ADMIN SELECT INITIALIZATION ---
        document.addEventListener("DOMContentLoaded", function () {
            initAdminCustomSelects();
        });

        // --- [INFO] CUSTOM ADMIN SELECT (PORTAL VERSION) ---
        function initAdminCustomSelects() {
            const targets = ['.ab-select', '.rm-input'];
            const selector = targets.join(', select');
            const selects = document.querySelectorAll(selector);

            selects.forEach(originalSelect => {
                if (originalSelect.tagName !== 'SELECT') return;
                // Avoid duplicates
                if (originalSelect.nextElementSibling && originalSelect.nextElementSibling.classList.contains('custom-select-wrapper')) return;

                // Create Unique ID for linking
                const uniqueId = 'custom-opt-' + Math.random().toString(36).substr(2, 9);

                // 1. Wrapper
                const wrapper = document.createElement('div');
                wrapper.classList.add('custom-select-wrapper');
                wrapper.dataset.targetId = uniqueId; // Link to options

                // 2. Trigger
                const trigger = document.createElement('div');
                trigger.classList.add('custom-select-trigger');
                const selectedOption = originalSelect.options[originalSelect.selectedIndex];
                const initialText = selectedOption ? selectedOption.text : "- Select -";
                trigger.innerHTML = `<span>${initialText}</span> <i class="fas fa-chevron-down custom-arrow"></i>`;

                // 3. Options Container (APPEND TO BODY, NOT WRAPPER)
                const optionsDiv = document.createElement('div');
                optionsDiv.classList.add('custom-options');
                optionsDiv.id = uniqueId; // ID for linking
                document.body.appendChild(optionsDiv); // [FIX] Key Fix: Move to Body

                // 4. Build Options
                populateCustomOptions(originalSelect, optionsDiv, trigger, wrapper);

                // 5. Insert Wrapper
                wrapper.appendChild(trigger);
                originalSelect.parentNode.insertBefore(wrapper, originalSelect.nextSibling);

                // 6. Click Handler
                trigger.addEventListener('click', function (e) {
                    e.stopPropagation();

                    // [INFO] FIX: Do not open if original select is disabled
                    if (originalSelect.disabled) return;

                    const isOpen = wrapper.classList.contains('open');

                    // Close ALL other dropdowns first
                    closeAllCustomSelects();

                    if (!isOpen) {
                        // Open THIS one
                        wrapper.classList.add('open');
                        optionsDiv.classList.add('open');

                        // [FIX] Calculate Position Dynamically
                        const rect = wrapper.getBoundingClientRect();
                        optionsDiv.style.width = rect.width + 'px';
                        optionsDiv.style.top = (rect.bottom + window.scrollY + 5) + 'px';
                        optionsDiv.style.left = (rect.left + window.scrollX) + 'px';
                    }
                });
            });

            // 7. Global Listeners
            document.addEventListener('click', closeAllCustomSelects);
            window.addEventListener('resize', closeAllCustomSelects);

            // Close on scroll (in any scrollable container) to prevent floating ghosts
            document.addEventListener('scroll', closeAllCustomSelects, true);
        }

        // Helper to populate options
        function populateCustomOptions(originalSelect, optionsDiv, trigger, wrapper) {
            optionsDiv.innerHTML = '';
            Array.from(originalSelect.options).forEach(option => {
                const divOption = document.createElement('div');
                divOption.classList.add('custom-option');
                divOption.textContent = option.text;
                divOption.dataset.value = option.value;

                if (option.selected) divOption.classList.add('selected');

                divOption.addEventListener('click', function (e) {
                    e.stopPropagation();
                    trigger.querySelector('span').textContent = this.textContent;

                    // Visual Update
                    optionsDiv.querySelectorAll('.custom-option').forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');

                    // Logic Update
                    originalSelect.value = this.dataset.value;
                    originalSelect.dispatchEvent(new Event('change'));

                    closeAllCustomSelects();
                });
                optionsDiv.appendChild(divOption);
            });
        }

        function closeAllCustomSelects() {
            document.querySelectorAll('.custom-select-wrapper').forEach(ws => ws.classList.remove('open'));
            document.querySelectorAll('.custom-options').forEach(opt => opt.classList.remove('open'));
        }

        // [INFO] Updated Refresh Function
        function refreshCustomSelect(selectId) {
            const originalSelect = document.getElementById(selectId);
            if (!originalSelect) return;

            const wrapper = originalSelect.nextElementSibling;
            if (!wrapper || !wrapper.classList.contains('custom-select-wrapper')) return;

            const targetId = wrapper.dataset.targetId;
            const optionsDiv = document.getElementById(targetId);
            const trigger = wrapper.querySelector('.custom-select-trigger');

            if (optionsDiv && trigger) {
                populateCustomOptions(originalSelect, optionsDiv, trigger, wrapper);

                // Update Trigger Text if needed
                if (originalSelect.value !== "") {
                    const selected = originalSelect.options[originalSelect.selectedIndex];
                    if (selected) trigger.querySelector('span').textContent = selected.text;
                } else {
                    trigger.querySelector('span').textContent = "- Select -";
                }
            }
        }

        // 🟢 SEAMLESS UPDATE: Handles Food Order Status changes without reload
        async function updateOrderStatus(id, action) {
            const btnText = action === 'prepare' ? "Start Preparing" : "Mark as Served";
            if (!await showConfirm("Confirmation", `Are you sure you want to ${btnText} this order?`)) return;

            const formData = new FormData();
            formData.append('id', id);
            formData.append('action', action);

            // UI Feedback: Find the button and show loading state
            const row = document.getElementById('order-row-' + id);
            const btn = row ? row.querySelector('button') : null;
            let originalBtnContent = "";

            if (btn) {
                originalBtnContent = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ...';
                btn.disabled = true;
            }

            fetch('update_order.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {

                        // --- DOM MANIPULATION (Seamless Update) ---
                        if (row) {
                            const statusBadge = row.querySelector('.badge');
                            const actionCell = row.lastElementChild; // The last <td> contains the button

                            if (action === 'prepare') {
                                // 1. Update Status Badge to "Preparing" (Blue style)
                                if (statusBadge) {
                                    statusBadge.className = 'badge arrival-today';
                                    statusBadge.innerText = 'Preparing';
                                }

                                // 2. Change Button to "Serve"
                                actionCell.innerHTML = `
                                <button class="btn-secondary" style="background:#DCFCE7; color:#166534; border:1px solid #BBF7D0;"
                                    onclick="updateOrderStatus(${id}, 'deliver')">
                                    <i class="fas fa-check"></i> Serve
                                </button>
                            `;

                            } else if (action === 'deliver') {
                                // 1. Update Status Badge to "Delivered" (Green style)
                                if (statusBadge) {
                                    statusBadge.className = 'badge badge-confirmed';
                                    statusBadge.innerText = 'Delivered';
                                }

                                // 2. Remove Button, show "Completed" text
                                actionCell.innerHTML = `<span style="font-size:0.8rem; color:#aaa;">Completed</span>`;
                            }
                        }

                    } else {
                        showError("Error: " + data.message);
                        // Revert button if failed
                        if (btn) {
                            btn.innerHTML = originalBtnContent;
                            btn.disabled = false;
                        }
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");
                    if (btn) {
                        btn.innerHTML = originalBtnContent;
                        btn.disabled = false;
                    }
                });
        }
        // --- PAYMENT SETTINGS LOGIC ---

        // 1. Fetch Data & Populate Card + Modal
        function loadPaymentSettings() {
            document.getElementById('disp_pay_method').innerText = "Loading...";

            fetch('get_payment_settings.php')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        const d = data.data;

                        // A. Populate VIEW Card
                        document.getElementById('disp_pay_method').innerText = d.payment_method;
                        document.getElementById('disp_acc_name').innerText = d.account_name;
                        document.getElementById('disp_acc_num').innerText = d.account_number;

                        const qrView = document.getElementById('disp_qr');
                        const qrFallback = document.getElementById('qrFallback');

                        if (d.qr_image && d.qr_image.trim() !== "") {
                            // 1. Show Image
                            qrView.src = '../../room_includes/uploads/payment/' + d.qr_image + '?t=' + new Date().getTime();
                            qrView.style.display = 'block';

                            // 2. Hide Fallback
                            if (qrFallback) qrFallback.style.display = 'none';
                        } else {
                            // 1. Hide Image
                            qrView.style.display = 'none';
                            qrView.src = ""; // Clear src to prevent broken link icon

                            // 2. Show Fallback (Flex to keep it centered)
                            if (qrFallback) qrFallback.style.display = 'flex';
                        }

                        // B. Populate EDIT Modal fields (Pre-fill)
                        document.getElementById('edit_pay_method').value = d.payment_method;
                        document.getElementById('edit_acc_name').value = d.account_name;
                        document.getElementById('edit_acc_num').value = d.account_number;

                        // Handle QR Preview in Edit Modal
                        const editQrPreview = document.getElementById('editQrPreview');
                        const editQrPlace = document.getElementById('editQrPlaceholder');

                        if (d.qr_image && d.qr_image.trim() !== "") {
                            editQrPreview.src = '../../room_includes/uploads/payment/' + d.qr_image + '?t=' + new Date().getTime();
                            editQrPreview.style.display = 'block';
                            if (editQrPlace) editQrPlace.style.display = 'none';
                        } else {
                            editQrPreview.src = "";
                            editQrPreview.style.display = 'none';
                            if (editQrPlace) editQrPlace.style.display = 'block';
                        }

                    } else {
                        document.getElementById('disp_pay_method').innerText = "Not Configured";
                    }
                })
                .catch(err => console.error(err));
        }

        // 2. Toggle Payment Edit Modal
        function togglePaymentEdit(show) {
            const modal = document.getElementById('paymentEditModal');
            modal.style.display = show ? 'block' : 'none';
        }

        // 3. QR Preview Function
        function previewPaymentQR(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    document.getElementById('editQrPreview').src = e.target.result;
                    document.getElementById('editQrPreview').style.display = 'block';
                    document.getElementById('editQrPlaceholder').style.display = 'none';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // 4. Save Payment Settings
        function savePaymentSettings(e) {
            e.preventDefault();

            const form = document.getElementById('paymentEditForm');
            const btn = form.querySelector('button[type="submit"]');
            const originalText = btn.innerText;

            btn.innerText = "Saving...";
            btn.disabled = true;

            const formData = new FormData(form);
            formData.append('csrf_token', csrfToken);

            fetch('update_payment_settings.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess("Payment details updated successfully!");
                        togglePaymentEdit(false);
                        loadPaymentSettings(); // Refresh the view card immediately
                    } else {
                        showError(data.message.replace(/^Error:\s*/i, ""));
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        }

        // --- PENDING BOOKINGS DRAWER LOGIC ---

        // 1. Toggle Drawer Visibility
        function togglePendingDrawer() {
            // [INFO] SAFETY CHECK: If busy, stop here.
            if (isDrawerBusy) {
                return;
            }

            const drawer = document.getElementById('pendingDrawer');
            const overlay = document.getElementById('drawerOverlay');
            const isOpen = drawer.classList.contains('open');

            if (isOpen) {
                drawer.classList.remove('open');
                overlay.classList.remove('show');
            } else {
                drawer.classList.add('open');
                overlay.classList.add('show');
                fetchPendingBookings();
            }
        }

        // 2. Fetch Data from API
        function fetchPendingBookings() {
            return fetch('get_pending_bookings.php')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        renderPendingList(data.data);
                        updatePendingPulse(data.data.length); // Update the red dot on header icon
                    }
                })
                .catch(err => console.error("Error fetching pending:", err));
        }

        // 3. Render the List inside the Drawer (Updated with Room Name)
        function renderPendingList(bookings) {
            const container = document.getElementById('pendingDrawerBody');
            container.innerHTML = '';

            if (bookings.length === 0) {
                container.innerHTML = `
            <div style="text-align:center; padding:40px; color:#9ca3af;">
                <i class="fas fa-check-circle" style="font-size:3rem; margin-bottom:15px; color:#D1D5DB;"></i>
                <p>All clear! No pending bookings.</p>
            </div>`;
                return;
            }

            bookings.forEach(b => {
                // Image Path Logic
                let receiptHtml = '';
                if (b.payment_proof) {
                    const imgSrc = '../../room_includes/uploads/receipts/' + b.payment_proof;
                    receiptHtml = `
                <div class="receipt-preview-box" onclick="viewReceipt('${imgSrc}')">
                    <img src="${imgSrc}" class="receipt-img" onerror="this.src='../../IMG/default_image.svg'">
                    <div style="position:absolute; bottom:5px; right:5px; background:rgba(0,0,0,0.6); color:white; padding:2px 6px; border-radius:4px; font-size:0.7rem;">
                        <i class="fas fa-search-plus"></i> Zoom
                    </div>
                </div>`;
                } else {
                    receiptHtml = `
                <div class="receipt-preview-box" style="background:#fee2e2; border-color:#fecaca; cursor:default;">
                    <div style="text-align:center; color:#dc2626;">
                        <i class="fas fa-exclamation-triangle"></i><br>
                        <small>No Receipt Uploaded</small>
                    </div>
                </div>`;
                }

                const card = document.createElement('div');
                card.className = 'pending-card';
                card.innerHTML = `
            <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                <strong style="color:#333;">${b.booking_reference}</strong>
                <span style="font-size:0.8rem; color:#666;">${b.created_at.substring(0, 10)}</span>
            </div>
            
            <div style="font-size:0.9rem; color:#555; margin-bottom:5px;">
                <i class="fas fa-user"></i> ${b.first_name} ${b.last_name}
            </div>

            <div style="font-size:0.85rem; color:#3B82F6; background:#EFF6FF; padding:5px 10px; border-radius:4px; margin-bottom:10px; font-weight:600;">
                <i class="fas fa-bed"></i> ${b.room_names || 'Unknown Room'}
            </div>

            ${receiptHtml}

            <div style="display:flex; justify-content:space-between; font-size:0.9rem; margin-bottom:10px;">
                <span>Total: <strong>₱${parseFloat(b.total_price).toLocaleString()}</strong></span>
                <span style="color:#d97706;">Claimed: ₱${parseFloat(b.amount_paid).toLocaleString()}</span>
            </div>

            <div class="drawer-actions">
                <button class="ab-submit-btn" style="background:#EF4444; padding:8px;" onclick="rejectBooking(${b.id}, this)">
                    Reject
                </button>
                
                <button class="ab-submit-btn" style="background:#FFA500; padding:8px;" onclick="verifyBooking(${b.id}, this)">
                    Verify & Confirm
                </button>
            </div>
        `;
                container.appendChild(card);
            });
        }

        // 4. Update Header Icon Pulse
        function updatePendingPulse(count) {
            const dot = document.getElementById('pendingPulse');
            if (count > 0) {
                dot.style.display = 'block';
            } else {
                dot.style.display = 'none';
            }
        }

        // 5. Lightbox for Receipts
        function viewReceipt(src) {
            const modal = document.getElementById('receiptLightbox');
            const img = document.getElementById('lightboxImage');
            img.src = src;
            modal.style.display = 'flex';
        }



        // --- UPDATED VERIFY FUNCTION (With Rotating Spinner) ---
        async function verifyBooking(id, btnElement) {
            if (!await showConfirm("Confirmation", "Are you sure the receipt matches? Confirm Booking?")) return;

            // 1. LOCK UI GLOBALLY
            isDrawerBusy = true;
            toggleUILock(true, "CONFIRMING BOOKING...");

            // 2. Visual Loading State (Save original content, show spinner)
            const originalContent = btnElement.innerHTML; // Save the old text/icon
            btnElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
            btnElement.disabled = true;
            btnElement.style.opacity = '0.7';

            // 3. Lock the container visuals
            const drawerBody = document.getElementById('pendingDrawerBody');
            drawerBody.style.pointerEvents = 'none';
            drawerBody.style.opacity = '0.8';

            const formData = new FormData();
            formData.append('id', id);

            fetch('approve_booking.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        // Success! Keep spinner for a split second before refreshing
                        showSuccess(data.message);
                        fetchPendingBookings(); // Reload sidebar list
                        refreshBookingTable();  // Reload main table
                        fetchDashboardCards();  // Reload stats
                    } else {
                        // Error: Revert button
                        showError("Error: " + data.message);
                        btnElement.innerHTML = originalContent;
                        btnElement.disabled = false;
                        btnElement.style.opacity = '1';
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error: Check console.");
                    // Revert button on crash
                    btnElement.innerHTML = originalContent;
                    btnElement.disabled = false;
                    btnElement.style.opacity = '1';
                })
                .finally(() => {
                    // 4. UNLOCK UI
                    if (drawerBody) {
                        drawerBody.style.opacity = '1';
                        drawerBody.style.pointerEvents = 'auto';
                    }
                    isDrawerBusy = false;
                    toggleUILock(false);
                });
        }

        // --- UPDATED REJECT FUNCTION (With Rotating Spinner) ---
        async function rejectBooking(id, btnElement) {
            if (!await showConfirm("Confirmation", "Are you sure you want to REJECT this booking?")) return;

            // 1. LOCK UI GLOBALLY
            isDrawerBusy = true;
            toggleUILock(true, "REJECTING BOOKING...");

            // 2. Visual Loading State
            const originalContent = btnElement.innerHTML;
            btnElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Rejecting...';
            btnElement.disabled = true;
            btnElement.style.opacity = '0.7';

            // Lock the container visuals
            const drawerBody = document.getElementById('pendingDrawerBody');
            drawerBody.style.pointerEvents = 'none';
            drawerBody.style.opacity = '0.8';

            const formData = new FormData();
            formData.append('id', id);
            formData.append('action', 'cancel');

            fetch('update_arrival.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess("Booking Rejected.");
                        fetchPendingBookings();
                        refreshBookingTable();
                        fetchDashboardCards();
                    } else {
                        showError("Error: " + data.message);
                        // Revert button
                        btnElement.innerHTML = originalContent;
                        btnElement.disabled = false;
                        btnElement.style.opacity = '1';
                    }
                })
                .catch(err => {
                    showError("System Error");
                    // Revert button
                    btnElement.innerHTML = originalContent;
                    btnElement.disabled = false;
                    btnElement.style.opacity = '1';
                })
                .finally(() => {
                    // 2. UNLOCK UI
                    if (drawerBody) {
                        drawerBody.style.pointerEvents = 'auto';
                        drawerBody.style.opacity = '1';
                    }
                    isDrawerBusy = false;
                    toggleUILock(false);
                });
        }

        /* --- [INFO] FOOD ORDER DRAWER LOGIC --- */

        // 1. Toggle Drawer
        function toggleOrderDrawer() {
            if (isDrawerBusy) return; // UI Lock check

            const drawer = document.getElementById('orderDrawer');
            const overlay = document.getElementById('drawerOverlay'); // Reuse existing overlay
            const isOpen = drawer.classList.contains('open');

            if (isOpen) {
                drawer.classList.remove('open');
                overlay.classList.remove('show');
            } else {
                drawer.classList.add('open');
                overlay.classList.add('show');
                fetchPendingOrders(); // Load data
            }
        }

        // 2. Fetch Data
        function fetchPendingOrders() {
            return fetch('get_pending_orders.php')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        renderOrderList(data.data);
                        updateOrderPulse(data.data.length);
                        updateOrderAlert(data.data.length); // [INFO] Show toast notification
                    }
                })
                .catch(err => console.error("Error fetching orders:", err));
        }

        // [INFO] REPLACEMENT: renderOrderList
        // Prioritizes Payment Method over Image to prevent "Charge to Room" text errors
        function renderOrderList(orders) {
            const container = document.getElementById('orderDrawerBody');
            container.innerHTML = '';

            if (orders.length === 0) {
                container.innerHTML = `
            <div style="text-align:center; padding:40px; color:#9ca3af;">
                <i class="fas fa-check-circle" style="font-size:3rem; margin-bottom:15px; color:#D1D5DB;"></i>
                <p>No pending food orders.</p>
            </div>`;
                return;
            }

            orders.forEach(o => {
                // Parse Items
                let itemsHtml = '';
                if (o.items_decoded) {
                    for (const [item, qty] of Object.entries(o.items_decoded)) {
                        itemsHtml += `<div style="font-size:0.85rem; color:#555;"><b>${qty}x</b> ${item}</div>`;
                    }
                }

                // [INFO] SMART LOGIC FIX: Check Method FIRST, then Image
                let receiptHtml = '';

                if (o.payment_method === 'Charge to Room') {
                    // Case 1: Room Charge (Always Blue Badge, ignore payment_proof content)
                    receiptHtml = `
                <div class="receipt-preview-box" style="background:#E0E7FF; border-color:#C7D2FE; cursor:default; height:100px; flex-direction:column;">
                    <i class="fas fa-door-open" style="font-size:2rem; color:#4338CA; margin-bottom:5px;"></i>
                    <div style="font-size:0.8rem; font-weight:700; color:#4338CA;">Charged to Room</div>
                </div>`;
                }
                else if (o.payment_method === 'Cash') {
                    // Case 2: Cash (Always Green Badge)
                    receiptHtml = `
                <div class="receipt-preview-box" style="background:#DCFCE7; border-color:#86EFAC; cursor:default; height:100px; flex-direction:column;">
                    <i class="fas fa-money-bill-wave" style="font-size:2rem; color:#15803D; margin-bottom:5px;"></i>
                    <div style="font-size:0.8rem; font-weight:700; color:#15803D;">Pay Cash</div>
                </div>`;
                }
                else if (o.payment_proof && o.payment_proof.trim() !== '' && o.payment_proof !== 'Charge to Room') {
                    // Case 3: Has Valid Image (GCash/Online) AND filename is not "Charge to Room"
                    const imgSrc = '../../room_includes/uploads/receipts/' + o.payment_proof;
                    receiptHtml = `
                <div class="receipt-preview-box" onclick="viewReceipt('${imgSrc}')">
                    <img src="${imgSrc}" class="receipt-img" onerror="this.style.display='none'; this.nextElementSibling.innerText='Image Error';">
                    <div style="position:absolute; bottom:5px; right:5px; background:rgba(0,0,0,0.6); color:white; padding:2px 6px; border-radius:4px; font-size:0.7rem;">
                        <i class="fas fa-search-plus"></i> Zoom
                    </div>
                </div>`;
                }
                else {
                    // Case 4: No Receipt (GCash but missing image)
                    receiptHtml = `
                <div class="receipt-preview-box" style="background:#FEE2E2; border-color:#FECACA; cursor:default; height:100px; flex-direction:column;">
                    <i class="fas fa-exclamation-triangle" style="font-size:2rem; color:#B91C1C; margin-bottom:5px;"></i>
                    <div style="font-size:0.8rem; font-weight:700; color:#B91C1C;">No Receipt Uploaded</div>
                </div>`;
                }

                // Method Badge Color
                let methodColor = '#6B7280';
                if (o.payment_method === 'GCash') methodColor = '#3B82F6';
                if (o.payment_method === 'Maya') methodColor = '#10B981';
                if (o.payment_method === 'Charge to Room') methodColor = '#F59E0B';
                if (o.payment_method === 'Cash') methodColor = '#10B981';

                const card = document.createElement('div');
                card.className = 'pending-card';
                card.innerHTML = `
            <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                <strong style="color:#333;">Order #${o.id}</strong>
                <span style="font-size:0.8rem; color:#666;">${o.order_date.substring(11, 16)}</span>
            </div>
            <div style="font-size:0.9rem; color:#555; margin-bottom:10px;">
                <i class="fas fa-door-open"></i> ${o.room_number} <span style="color:#ccc;">|</span> ${o.guest_name || 'Guest'}
            </div>

            <div style="background:#f9f9f9; padding:10px; border-radius:6px; margin-bottom:10px;">
                ${itemsHtml}
            </div>

            ${receiptHtml}

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; margin-top:10px;">
                <span style="font-size:0.8rem; font-weight:700; color:${methodColor}; border:1px solid ${methodColor}; padding:2px 6px; border-radius:4px;">
                    ${o.payment_method}
                </span>
                <strong style="font-size:1.1rem; color:#333;">₱${parseFloat(o.total_price).toLocaleString()}</strong>
            </div>

            <div class="drawer-actions">
                <button class="ab-submit-btn" style="background:#EF4444; padding:8px;" onclick="processOrder(${o.id}, 'reject', this)">
                    Reject
                </button>
                <button class="ab-submit-btn" style="background:#FFA500; padding:8px;" onclick="processOrder(${o.id}, 'approve', this)">
                    Accept & Prepare
                </button>
            </div>
        `;
                container.appendChild(card);
            });
        }

        // --- [INFO] SMART OVERLAY HANDLER (Handles Both Drawers) ---
        function closeDrawersSmart() {
            // 1. SAFETY LOCK: If system is busy (Loading/Verifying), IGNORE click
            if (isDrawerBusy) {
                console.log("ðŸ”’ Overlay click blocked: System is busy.");
                return;
            }

            // 2. Check Booking Drawer
            const pendingDrawer = document.getElementById('pendingDrawer');
            if (pendingDrawer && pendingDrawer.classList.contains('open')) {
                togglePendingDrawer(); // Uses existing toggle logic
            }

            // 3. Check Food Order Drawer
            const orderDrawer = document.getElementById('orderDrawer');
            if (orderDrawer && orderDrawer.classList.contains('open')) {
                toggleOrderDrawer(); // Uses existing toggle logic
            }
        }

        // --- UPDATED PROCESS ORDER FUNCTION (With Rotating Spinner & Text) ---
        async function processOrder(id, action, btnElement) {
            const actionText = action === 'approve' ? "Accept" : "Reject";

            if (!await showConfirm("Confirmation", `Are you sure you want to ${actionText} this order?`)) return;

            // 1. LOCK UI GLOBALLY
            isDrawerBusy = true;
            toggleUILock(true, action === 'approve' ? "ACCEPTING ORDER..." : "REJECTING ORDER...");

            // 2. Visual Loading State (Save original, set new state)
            const originalContent = btnElement.innerHTML;
            const loadingLabel = action === 'approve' ? 'Accepting...' : 'Rejecting...';

            // [INFO] The Magic Line: Spinner + Specific Action Text
            btnElement.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${loadingLabel}`;
            btnElement.disabled = true;
            btnElement.style.opacity = '0.7';

            // 3. Lock the container visuals (Gray out list)
            const drawerBody = document.getElementById('orderDrawerBody');
            drawerBody.style.pointerEvents = 'none';
            drawerBody.style.opacity = '0.8';

            const formData = new FormData();
            formData.append('id', id);
            formData.append('action', action);

            fetch('approve_order.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        // 4. Success Animation (Slide Out)
                        const card = btnElement.closest('.pending-card');
                        if (card) {
                            card.style.transition = "all 0.5s ease";
                            card.style.opacity = "0";
                            // Slide Right for Approve, Left for Reject
                            card.style.transform = action === 'approve' ? "translateX(50px)" : "translateX(-50px)";

                            setTimeout(() => {
                                card.remove(); // Remove from DOM

                                // Check if list is empty
                                if (document.querySelectorAll('#orderDrawerBody .pending-card').length === 0) {
                                    document.getElementById('orderDrawerBody').innerHTML = `
                                    <div style="text-align:center; padding:40px; color:#9ca3af;">
                                        <i class="fas fa-check-circle" style="font-size:3rem; margin-bottom:15px; color:#D1D5DB;"></i>
                                        <p>No pending food orders.</p>
                                    </div>`;
                                }

                                // Refresh Tables & Stats
                                refreshFoodTable();
                                fetchDashboardCards();
                                showSuccess(data.message);

                                // 5. Unlock UI (After animation finishes)
                                isDrawerBusy = false;
                                drawerBody.style.pointerEvents = 'auto';
                                drawerBody.style.opacity = '1';
                                toggleUILock(false);
                            }, 500); // Wait for CSS transition
                        }
                    } else {
                        // Error: Revert Button
                        showError(data.message);
                        btnElement.innerHTML = originalContent;
                        btnElement.disabled = false;
                        btnElement.style.opacity = '1';

                        // Unlock UI
                        isDrawerBusy = false;
                        drawerBody.style.pointerEvents = 'auto';
                        drawerBody.style.opacity = '1';
                        toggleUILock(false);
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");                    // Revert Button
                    btnElement.innerHTML = originalContent;
                    btnElement.disabled = false;
                    btnElement.style.opacity = '1';

                    // Unlock UI
                    isDrawerBusy = false;
                    drawerBody.style.pointerEvents = 'auto';
                    drawerBody.style.opacity = '1';
                    toggleUILock(false);
                });
        }

        // 5. Update Red Dot
        function updateOrderPulse(count) {
            const dot = document.getElementById('orderPulse');
            if (dot) dot.style.display = count > 0 ? 'block' : 'none';
        }

        // --- RECEIPT ARCHIVE LOGIC ---

        // ==========================================
        // [INFO] RECEIPT ARCHIVE LOGIC (SHOW ALL + FILTER)
        // ==========================================

        document.addEventListener("DOMContentLoaded", function () {
            if (document.getElementById("receiptFilterDate")) {
                flatpickr("#receiptFilterDate", {
                    dateFormat: "Y-m-d",
                    altInput: true,
                    altFormat: "F j, Y",
                    disableMobile: "true",
                    onReady: function (selectedDates, dateStr, instance) {
                        instance.calendarContainer.classList.add("compact-theme");
                        initCustomFpHeader(instance, { showDropdowns: true });
                    },
                    onMonthChange: function (selectedDates, dateStr, instance) {
                        updateDropdownSelections(instance);
                    },
                    onYearChange: function (selectedDates, dateStr, instance) {
                        updateDropdownSelections(instance);
                    },
                    onChange: function (selectedDates, dateStr, instance) {
                        loadReceipts();
                    }
                });

                // Load initially (Will load 'All' if input is empty, or 'Today' if defaultDate is set)
                loadReceipts();
            }

            // Menu click listener
            const receiptMenuBtn = document.querySelector('.tree-item-card[onclick*="view-receipts"]');
            if (receiptMenuBtn) {
                receiptMenuBtn.addEventListener('click', () => {
                    // Optional: Auto-clear filter when opening the page fresh
                    // clearReceiptFilter(); 
                    loadReceipts();
                });
            }
        });

        // [INFO] NEW: Clear Filter Function
        function clearReceiptFilter() {
            const picker = document.querySelector("#receiptFilterDate")._flatpickr;
            if (picker) {
                picker.clear(); // Clears the visual input
            }
            loadReceipts(); // Reloads data with empty date (Show All)
        }

        // [INFO] PAYMENT RECEIPT PAGINATION
        let receiptCurrentPage = 1;
        const receiptLimit = 30;

        function changeReceiptPage(step) {
            receiptCurrentPage += step;
            if (receiptCurrentPage < 1) receiptCurrentPage = 1;
            loadReceipts();
            
            // Scroll to top of container
            const container = document.querySelector(".receipt-gallery-container");
            if (container) container.scrollTop = 0;
        }

        // [INFO] UPDATED: Fetch Receipts with Pagination
        function loadReceipts(resetPage = false) {
            if (resetPage) receiptCurrentPage = 1;
            
            const input = document.getElementById('receiptFilterDate');
            if (!input) return;

            const dateVal = input.value; 
            const offset = (receiptCurrentPage - 1) * receiptLimit;

            const container = document.getElementById('receiptGrid');
            container.innerHTML = `
                <div style="grid-column:1/-1; text-align:center; padding:100px 0;">
                    <div class="amv-loader-container">
                        <div class="amv-loader"></div>
                        <div style="font-weight: 600; font-size: 1.1rem; letter-spacing: 0.5px; color: #B88E2F;">Loading Receipts...</div>
                    </div>
                </div>`;

            fetch(`get_all_receipts.php?date=${dateVal}&limit=${receiptLimit}&offset=${offset}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        renderReceiptGrid(data.data);
                        updateReceiptPagination(data.total);
                    } else {
                        container.innerHTML = `<div style="grid-column:1/-1; text-align:center; color:red;">Error loading data.</div>`;
                    }
                })
                .catch(err => {
                    console.error(err);
                    container.innerHTML = `<div style="grid-column:1/-1; text-align:center; color:red;">System Error.</div>`;
                });
        }

        function updateReceiptPagination(total) {
            const totalPages = Math.ceil(total / receiptLimit) || 1;
            const info = document.getElementById('receiptPageInfo');
            const prevBtn = document.getElementById('receiptPrevBtn');
            const nextBtn = document.getElementById('receiptNextBtn');

            if (info) info.innerText = `Page ${receiptCurrentPage} of ${totalPages} (${total} total)`;
            
            if (prevBtn) prevBtn.disabled = (receiptCurrentPage <= 1);
            if (nextBtn) nextBtn.disabled = (receiptCurrentPage >= totalPages);

            const pagDiv = document.getElementById('receiptPagination');
            if (pagDiv) pagDiv.style.display = (total > 0) ? 'flex' : 'none';
        }

        // --- UPDATED RENDER FUNCTION ---
        function renderReceiptGrid(receipts) {
            const container = document.getElementById('receiptGrid');
            container.innerHTML = '';

            if (!receipts || receipts.length === 0) {
                container.innerHTML = `
        <div style="grid-column:1/-1; text-align:center; padding:50px; color:#999;">
            <i class="fas fa-file-invoice-dollar" style="font-size:3rem; margin-bottom:15px; opacity:0.3;"></i>
            <p>No online payment receipts found.</p>
        </div>`;
                return;
            }

            receipts.forEach(r => {
                const imgPath = '../../room_includes/uploads/receipts/' + r.image;

                const dateStr = new Date(r.date_time).toLocaleDateString('en-US', {
                    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                });

                const card = document.createElement('div');
                card.className = 'receipt-card';
                card.onclick = (e) => {
                    if (!e.target.closest('.delete-btn')) {
                        viewReceipt(imgPath);
                    }
                };

                card.innerHTML = `
            <div style="position:relative;">
                <button class="delete-btn" 
                    onclick="deleteReceipt(this, event, ${r.id}, '${r.source_table}', '${r.image}')"
                    style="position:absolute; top:10px; right:10px; background:rgba(239, 68, 68, 0.9); color:white; border:none; width:30px; height:30px; border-radius:50%; cursor:pointer; z-index:10; display:flex; align-items:center; justify-content:center; transition:0.2s;">
                    <i class="fas fa-trash-alt" style="font-size:0.8rem;"></i>
                </button>
                
                <img src="${imgPath}" class="receipt-thumb" loading="lazy" 
                     onerror="this.src='https://placehold.co/200x300?text=Image+Error'">
            </div>
            <div class="receipt-info">
                <span class="r-type">${r.type}</span>
                <div class="r-ref">${r.ref}</div>
                <div class="r-date"><i class="far fa-clock"></i> ${dateStr}</div>
            </div>
        `;
                container.appendChild(card);
            });
        }

        // --- NEW DELETE RECEIPT FUNCTION ---
        async function deleteReceipt(btn, event, id, table, filename) {
            if (event) event.stopPropagation(); // Prevent opening the lightbox

            if (!await showConfirm("Confirmation", "Are you sure you want to PERMANENTLY delete this receipt image? This cannot be undone.")) return;

            // Visual feedback: change icon to spinner
            const originalContent = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btn.disabled = true;

            const formData = new FormData();
            formData.append('id', id);
            formData.append('table', table);
            formData.append('filename', filename);
            // formData.append('csrf_token', csrfToken); // Uncomment if you enforce CSRF on this file

            fetch('delete_receipt.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess(data.message || "Receipt deleted successfully.");
                        // Remove the card from UI immediately with a fade out
                        const card = btn.closest('.receipt-card');
                        if (card) {
                            card.style.opacity = '0';
                            setTimeout(() => card.remove(), 300);
                        }
                    } else {
                        showError(data.message.replace(/^Error:\s*/i, ""));
                        btn.innerHTML = originalContent;
                        btn.disabled = false;
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System Error");                    btn.innerHTML = originalContent;
                    btn.disabled = false;
                });
        }

        // ==========================================
        // [INFO] UPDATED TRANSACTION PAGE LOGIC (INTERACTIVE PAGINATION)
        // ==========================================

        let transOffset = 0;
        const transLimit = 100;

        // 1. Main Load Function
        function loadTransactions(isSilent = false) {
            const tbody = document.getElementById('transactions_body');
            const filterType = document.getElementById('transFilterType').value;

            if (!isSilent && tbody) {
                tbody.innerHTML = `<tr><td colspan="9" style="padding: 100px 0; text-align: center;">
                    <div class="amv-loader-container">
                        <div class="amv-loader"></div>
                        <div style="font-weight: 600; font-size: 1.1rem; letter-spacing: 0.5px; color: #B88E2F;">Loading Transactions...</div>
                    </div>
                </td></tr>`;
            }

            const url = `get_transactions.php?limit=${transLimit}&offset=${transOffset}&type=${filterType}`;

            fetch(url)
                .then(res => res.json())
                .then(response => {
                    tbody.style.opacity = '1';
                    tbody.style.pointerEvents = 'auto';

                    if (response.status === 'success') {
                        renderTransactionTable(response.data, filterType);
                        updateTransPaginationUI(response.total, response.limit, response.offset);
                    } else {
                        if (!isSilent) tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:red; padding:20px;">Error: ${response.message}</td></tr>`;
                    }
                })
                .catch(err => {
                    tbody.style.opacity = '1';
                    tbody.style.pointerEvents = 'auto';
                    console.error("Load Error:", err);
                    if (!isSilent) tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:red; padding:20px;">System Error</td></tr>`;
                });
        }

        // 2. Interactive Pagination UI Generator
        function updateTransPaginationUI(total, limit, offset) {
            const container = document.getElementById('transPagination');
            const foot = document.getElementById('transPaginationFoot');
            if (container) {
                const isVisible = total > limit;
                container.style.display = isVisible ? 'flex' : 'none';
                if (foot) foot.style.display = isVisible ? 'table-footer-group' : 'none';
            }

            const start = total === 0 ? 0 : offset + 1;
            const end = Math.min(offset + limit, total);
            const currentPage = Math.floor(offset / limit) + 1;
            const totalPages = Math.ceil(total / limit);

            document.getElementById('transPageStart').innerText = start;
            document.getElementById('transPageEnd').innerText = end;
            document.getElementById('transTotalCount').innerText = total;

            const btnContainer = document.getElementById('transPageButtons');
            btnContainer.innerHTML = ''; // Clear existing

            // Helper to add dots
            const addDots = () => {
                const span = document.createElement('span');
                span.className = 'pg-dots';
                span.innerText = '...';
                btnContainer.appendChild(span);
            };

            // Previous Button
            const prevBtn = document.createElement('button');
            prevBtn.className = 'pg-btn pg-btn-nav';
            prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i> Prev';
            prevBtn.disabled = (currentPage === 1);
            prevBtn.onclick = (e) => {
                e.preventDefault();
                transOffset = Math.max(0, transOffset - limit);
                loadTransactions();
            };
            btnContainer.appendChild(prevBtn);

            // Page Numbers Logic (Smart Sliding Window)
            let startPage = Math.max(1, currentPage - 1);
            let endPage = Math.min(totalPages, startPage + 2);

            if (endPage - startPage < 2) {
                startPage = Math.max(1, endPage - 2);
            }

            // First Page + Dots
            if (startPage > 1) {
                btnContainer.appendChild(createPageBtn(1, limit, 1 === currentPage));
                if (startPage > 2) addDots();
            }

            // Middle Pages
            for (let i = startPage; i <= endPage; i++) {
                if (i > 0) btnContainer.appendChild(createPageBtn(i, limit, i === currentPage));
            }

            // Last Page + Dots
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) addDots();
                btnContainer.appendChild(createPageBtn(totalPages, limit, totalPages === currentPage));
            }

            // Next Button
            const nextBtn = document.createElement('button');
            nextBtn.className = 'pg-btn pg-btn-nav';
            nextBtn.innerHTML = 'Next <i class="fas fa-chevron-right"></i>';
            nextBtn.disabled = (currentPage === totalPages || total === 0);
            nextBtn.onclick = (e) => {
                e.preventDefault();
                transOffset += limit;
                loadTransactions();
            };
            btnContainer.appendChild(nextBtn);
        }

        function createPageBtn(page, limit, isActive) {
            var btn = document.createElement('button');
            var cls = 'pg-btn';
            if (isActive) cls += ' active';
            btn.className = cls;
            btn.innerText = page;
            btn.onclick = function() {
                transOffset = (page - 1) * limit;
                loadTransactions();
            };
            return btn;
        }

        // [INFO] DEPRECATED (Moved Logic to loadTransactions)
        function changeTransPage(direction) { }

        // 4. Render Function (Keep your existing table builder)
        function renderTransactionTable(data, filter) {
            const tbody = document.getElementById('transactions_body');
            tbody.innerHTML = '';

            if (!data || data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:60px 20px; color:#94a3b8;">
               <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;">
                   <div style="width:64px; height:64px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                       <i class="fas fa-history" style="font-size:1.8rem; color:#cbd5e1;"></i>
                   </div>
                   <div style="font-weight:600; font-size:1.1rem; color:#64748b;">No Transactions Found</div>
                   <p style="margin:0; font-size:0.9rem; max-width:250px; line-height:1.5;">There are no payment records or order history available yet.</p>
               </div>
            </td></tr>`;
                return;
            }
            data.forEach(t => {
                let typeBadge = '';
                if (t.transaction_type === 'Booking') {
                    typeBadge = '<span class="badge" style="background:#E0E7FF; color:#3730A3; border:1px solid #C7D2FE;">Booking</span>';
                } else {
                    typeBadge = '<span class="badge" style="background:#FFF7ED; color:#9A3412; border:1px solid #FED7AA;">Food Order</span>';
                }

                let statusClass = 'badge-pending';
                if (t.status === 'Paid') statusClass = 'badge-confirmed';
                if (t.status === 'Failed' || t.status === 'Cancelled') statusClass = 'badge-cancelled';

                let methodIcon = '<i class="fas fa-money-bill-wave" style="color:#10B981;"></i>';
                if (t.payment_method === 'GCash' || t.payment_method === 'Maya') {
                    methodIcon = '<i class="fas fa-mobile-alt" style="color:#3B82F6;"></i>';
                }

                const dateObj = new Date(t.created_at);
                const dateStr = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                const timeStr = dateObj.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

                const tr = document.createElement('tr');
                tr.style.cursor = 'pointer';
                tr.className = 'transaction-row';
                tr.onclick = function () { openTransactionDetails(t); };

                tr.innerHTML = `
                    <td style="color:#888;">#${t.id}</td>
                    <td>
                        <div style="font-weight:600; color:#333;">${t.user_name || 'Guest User'}</div>
                        <div style="font-size:0.75rem; color:#888;">${t.email || 'No email'}</div>
                    </td>
                    <td style="font-family:monospace; font-size:0.9rem; color:#555; font-weight:700;">${t.reference_id}</td>
                    <td>${typeBadge}</td>
                    <td style="font-weight:700; color:#333;">₱${parseFloat(t.amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                    <td style="color:#555;">${methodIcon} ${t.payment_method}</td>
                    <td><span class="badge ${statusClass}">${t.status}</span></td>
                    <td style="text-align:right;">
                        <div style="font-size:0.85rem; color:#333;">${dateStr}</div>
                        <div style="font-size:0.75rem; color:#999;">${timeStr}</div>
                    </td>
                    <td style="text-align: center;">
                        <span style="color:#B88E2F; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">
                            Tap to View <i class="fas fa-chevron-right" style="font-size:0.65rem; margin-left:3px;"></i>
                        </span>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        // [INFO] NEW: Open Modal Function
        function openTransactionDetails(t) {
            // 1. Populate Text Fields
            document.getElementById('trans_id').innerText = '#' + t.id;
            document.getElementById('trans_ref').innerText = t.reference_id;
            document.getElementById('trans_type').innerText = t.transaction_type;
            document.getElementById('trans_method').innerText = t.payment_method;
            document.getElementById('trans_amount').innerText = '₱' + parseFloat(t.amount).toLocaleString(undefined, { minimumFractionDigits: 2 });

            document.getElementById('trans_user_name').innerText = t.user_name || 'Guest User';
            document.getElementById('trans_user_email').innerText = t.email || 'No Email Provided';

            // 2. Format Date
            const d = new Date(t.created_at);
            const dateFormatted = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' - ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            document.getElementById('trans_date').innerText = dateFormatted;

            // 3. Style the Status Badge
            const badge = document.getElementById('trans_status_badge');
            badge.innerText = t.status.toUpperCase();

            // Reset classes
            badge.style.backgroundColor = '#eee';
            badge.style.color = '#333';
            badge.style.border = '1px solid #ddd';

            if (t.status === 'Paid') {
                badge.style.backgroundColor = '#DCFCE7'; // Green
                badge.style.color = '#166534';
                badge.style.borderColor = '#BBF7D0';
            } else if (t.status === 'Pending') {
                badge.style.backgroundColor = '#FFF7ED'; // Orange
                badge.style.color = '#9A3412';
                badge.style.borderColor = '#FED7AA';
            } else if (t.status === 'Failed' || t.status === 'Cancelled') {
                badge.style.backgroundColor = '#FEE2E2'; // Red
                badge.style.color = '#991B1B';
                badge.style.borderColor = '#FECACA';
            }

            // 4. Show Modal
            document.getElementById('transactionModal').style.display = 'block';
        }

        // --- TOGGLE ADD BOOKING SELECT ---
        function toggleAddBookingSelect(e) {
            e.stopPropagation(); // Prevent immediate closing

            const wrapper = document.getElementById('addBookingWrapper');
            const options = wrapper.querySelector('.custom-options');

            // 1. Close all other custom selects first (to keep UI clean)
            document.querySelectorAll('.custom-select-wrapper').forEach(ws => {
                if (ws !== wrapper) {
                    ws.classList.remove('open');
                    const opt = ws.querySelector('.custom-options');
                    if (opt) opt.classList.remove('open');
                }
            });

            // 2. Toggle 'open' class on Wrapper (Rotates the Arrow)
            wrapper.classList.toggle('open');

            // 3. Toggle 'open' class on Options (Shows the Menu with Animation)
            if (wrapper.classList.contains('open')) {
                options.classList.add('open');
            } else {
                options.classList.remove('open');
            }
        }

        // Ensure clicking outside closes it (Reuse your existing global listener or add this)
        window.addEventListener('click', function (e) {
            const wrapper = document.getElementById('addBookingWrapper');
            if (wrapper && !wrapper.contains(e.target)) {
                wrapper.classList.remove('open');
                const options = wrapper.querySelector('.custom-options');
                if (options) options.classList.remove('open');
            }
        });


        // --- COMPOSE MESSAGE LOGIC ---

        function openComposeModal(prefillEmail = '') {
            // 1. Reset form
            document.getElementById('composeForm').reset();
            document.getElementById('customSubjectInput').style.display = 'none';

            // 2. Prefill if email is passed (e.g. from table action)
            if (prefillEmail) {
                document.getElementById('composeEmail').value = prefillEmail;
            }

            // 3. Show Modal
            document.getElementById('composeModal').style.display = 'block';

            // 4. Close the dropdown menu if open
            document.getElementById('msgDropdown').classList.remove('show');
        }

        function closeComposeModal() {
            // [FIX] LOCK CHECK: If sending, do nothing
            if (isSendingEmail) {
                return;
            }
            document.getElementById('composeModal').style.display = 'none';
        }

        function toggleCustomSubject() {
            const select = document.getElementById('composeSubjectType');
            const input = document.getElementById('customSubjectInput');
            if (select.value === 'Other') {
                input.style.display = 'block';
                input.setAttribute('required', 'true');
            } else {
                input.style.display = 'none';
                input.removeAttribute('required');
            }
        }

        async function sendGuestEmail(e) {
            e.preventDefault();

            // Ask for confirmation before sending
            if (!await showConfirm("Confirmation", "Are you sure you want to send this email to the guest?")) return;

            // Prevent double clicks
            if (isSendingEmail) return;

            const form = document.getElementById('composeForm');
            const submitBtn = form.querySelector('button[type="submit"]');
            const cancelBtn = form.querySelector('button[type="button"]'); // The Cancel button
            const closeXBtn = document.querySelector('#composeModal .ab-close-btn'); // The X button in header

            const originalText = submitBtn.innerHTML;

            // 1. LOCK UI (Active Busy Mode)
            isSendingEmail = true;
            toggleUILock(true, "SENDING EMAIL TO GUEST...");

            // Change Send Button State
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            submitBtn.style.opacity = '0.7';

            // Disable Cancel & Close Buttons (Visual Feedback)
            if (cancelBtn) {
                cancelBtn.disabled = true;
                cancelBtn.style.opacity = '0.5';
                cancelBtn.style.cursor = 'not-allowed';
            }
            if (closeXBtn) {
                closeXBtn.disabled = true;
                closeXBtn.style.opacity = '0.5';
                closeXBtn.style.cursor = 'not-allowed';
            }

            const formData = new FormData(form);
            formData.append('csrf_token', csrfToken);

            fetch('send_guest_email.php', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        showSuccess("Email sent successfully!");
                        // Important: Reset flag BEFORE closing so the close function works
                        isSendingEmail = false;
                        closeComposeModal();
                    } else {
                        showError(data.message.replace(/^Error:\s*/i, ""));
                    }
                })
                .catch(err => {
                    console.error(err);
                    showError("System error sending email. Check console.");
                })
                .finally(() => {
                    // 2. UNLOCK UI
                    isSendingEmail = false;
                    toggleUILock(false);

                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                    submitBtn.style.opacity = '1';

                    if (cancelBtn) {
                        cancelBtn.disabled = false;
                        cancelBtn.style.opacity = '1';
                        cancelBtn.style.cursor = 'pointer';
                    }
                    if (closeXBtn) {
                        closeXBtn.disabled = false;
                        closeXBtn.style.opacity = '1';
                        closeXBtn.style.cursor = 'pointer';
                    }
                });
        }
        // ---------------------------------------------------------------
        // [INFO] FINAL SMART REAL-TIME LOGIC (Consolidated & Corrected)
        // ---------------------------------------------------------------

        // [INFO] SINGLE SESSION SECURITY CHECK
        setInterval(function () {
            fetch('check_session.php')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'logout') {
                        showError("Session Terminated. You have been logged out because your account was accessed from another device or browser tab.");
                        window.location.href = 'login.php'; // Redirect to login
                    }
                })
                .catch(err => console.error("Security check failed", err));
        }, 5000); // Checks every 5 seconds

        // [INFO] TABLE HOVER PROTECTION [INFO]
        // These variables track if the mouse is over a table.
        // If it is, we pause the polling refresh to prevent flickering and losing hover effects.
        let isHoveringTransactions = false;
        let isHoveringFood = false;
        let isHoveringBookings = false;
        let isHoveringGuests = false;
        let isHoveringCalendar = false;
        let isHoveringCharts = false;
        let isHoveringPendingDrawer = false;
        let isHoveringOrderDrawer = false;

        // Setup event listeners for each table
        document.addEventListener('DOMContentLoaded', () => {
            // Drawers Hover Protection
            const pDrawer = document.getElementById('pendingDrawer');
            if(pDrawer) {
                pDrawer.addEventListener('mouseenter', () => isHoveringPendingDrawer = true);
                pDrawer.addEventListener('mouseleave', () => isHoveringPendingDrawer = false);
            }
            const oDrawer = document.getElementById('orderDrawer');
            if(oDrawer) {
                oDrawer.addEventListener('mouseenter', () => isHoveringOrderDrawer = true);
                oDrawer.addEventListener('mouseleave', () => isHoveringOrderDrawer = false);
            }

            const tTable = document.getElementById('transactionsMainTable');
            if(tTable) {
                tTable.addEventListener('mouseenter', () => isHoveringTransactions = true);
                tTable.addEventListener('mouseleave', () => isHoveringTransactions = false);
            }
            const fTable = document.getElementById('foodMainTable');
            if(fTable) {
                fTable.addEventListener('mouseenter', () => isHoveringFood = true);
                fTable.addEventListener('mouseleave', () => isHoveringFood = false);
            }
            const bTable = document.getElementById('bookingMainTable');
            if(bTable) {
                bTable.addEventListener('mouseenter', () => isHoveringBookings = true);
                bTable.addEventListener('mouseleave', () => isHoveringBookings = false);
            }
            const gTable = document.getElementById('guestMainTable');
            if(gTable) {
                gTable.addEventListener('mouseenter', () => isHoveringGuests = true);
                gTable.addEventListener('mouseleave', () => isHoveringGuests = false);
            }
            // Calendar Hover Protection
            const cGrid = document.getElementById('calendarRealtimeGrid');
            if(cGrid) {
                cGrid.addEventListener('mouseenter', () => isHoveringCalendar = true);
                cGrid.addEventListener('mouseleave', () => isHoveringCalendar = false);
            }
            // Charts/Leaderboard Hover Protection
            const cChart = document.getElementById('barChartContainer');
            if(cChart) {
                cChart.addEventListener('mouseenter', () => isHoveringCharts = true);
                cChart.addEventListener('mouseleave', () => isHoveringCharts = false);
            }
        });

        // 1. Transactions (Every 1 second)
        setInterval(() => {
            const page = document.getElementById('transactions');
            if (page && page.classList.contains('active') && !isHoveringTransactions) {
                loadTransactions(true);
            }
        }, 1000);

        // 2. Food Orders Table (Every 1 second)
        setInterval(() => {
            const page = document.getElementById('food-ordered');
            const drawerOpen = document.getElementById('orderDrawer').classList.contains('open');

            // Only refresh table if active AND the side drawer is CLOSED AND NOT HOVERING
            if (page && page.classList.contains('active') && !drawerOpen && !isHoveringFood) {
                refreshFoodTable(true);
            }
        }, 1000);

        // 3. Bookings Table (Every 1 second)
        setInterval(() => {
            const page = document.getElementById('bookings');
            const searchInput = document.getElementById('bookingSearchInput');
            const isTyping = searchInput && searchInput.value.trim() !== "";

            // Only refresh if active AND user is NOT typing AND NOT HOVERING
            if (page && page.classList.contains('active') && !isTyping && !isHoveringBookings) {
                refreshBookingTable(true);
            }
        }, 1000);

        // 4. Calendar (Every 2 seconds)
        setInterval(() => {
            const page = document.getElementById('calendar');
            const cModal = document.getElementById('calendarModal');
            const isModalOpen = cModal && (cModal.style.display === 'block' || cModal.classList.contains('active'));

            // Only refresh if active AND NOT HOVERING AND NOT MODAL OPEN
            if (page && page.classList.contains('active') && !isHoveringCalendar && !isModalOpen) {
                refreshCalendarData(true);
            }
        }, 2000);

        // 5. Dashboard Overview (Every 2 seconds)
        setInterval(() => {
            const page = document.getElementById('dashboard');
            const dModal = document.getElementById('dateLeaderboardModal');
            const isDModalOpen = dModal && (dModal.style.display === 'block' || dModal.classList.contains('active'));

            if (page && page.classList.contains('active') && !isHoveringCharts && !isDModalOpen) {
                fetchDashboardCards(true);
            }
        }, 2000);

        // 6. Guests Database (Every 2 seconds)
        setInterval(() => {
            const page = document.getElementById('guests');
            const searchInput = document.getElementById('guestSearchInput');
            const isTyping = searchInput && searchInput.value.trim() !== "";

            // Only refresh if active AND user is NOT typing AND NOT HOVERING
            if (page && page.classList.contains('active') && !isTyping && !isHoveringGuests) {
                fetchGuestList(true);
            }
        }, 2000);

        // Checks every 60 seconds if it is past 12:00 PM and triggers alerts
        setInterval(function () {
            // Optional: Only fetch if it's actually past 11 AM to save bandwidth
            const currentHour = new Date().getHours();
            if (currentHour >= 12) {
                fetch('trigger_checkout_alerts.php')
                    .then(res => res.json())
                    .then(data => {
                        if (data.sent > 0) {
                            console.log("ðŸ”” Auto-Alert: Sent " + data.sent + " checkout notifications.");
                            fetchHeaderData(); // Refresh the bell icon immediately
                        }
                    })
                    .catch(err => console.error("Auto-Alert Error:", err));
            }
        }, 60000); // Runs every 1 minute

        // 7. Global Background Tasks (Runs on ALL pages for Header Icons)
        // This replaces the 15s timers you deleted. Now they run every 5s.
        setInterval(() => {
            fetchHeaderData();        // Updates Bell & Message Badges
            if (!isHoveringPendingDrawer) fetchPendingBookings();   // Updates Clipboard Red Dot
            if (!isHoveringOrderDrawer) fetchPendingOrders();     // Updates Food Tray Red Dot
        }, 5000);

        setInterval(checkAutoReminders, 60000); // Check emails every 1 min
        setInterval(triggerAutoUpdates, 60000);   // Check no-shows every 1 min

        // [INFO] EXCLUSIVE ACCESS HEARTBEAT: Renew the lock every 1 minute
        setInterval(function () {
            fetch('heartbeat.php').catch(err => console.error("Heartbeat failed", err));
        }, 60000);

        // [INFO] MOST BOOKED DATES MODAL LOGIC
        function openDateLeaderboardModal() {
            const modal = document.getElementById('dateLeaderboardModal');
            modal.style.display = 'block';

            // 1. Render Category Buttons (Months)
            renderDateCategoryButtons(currentChartData.availableMonths);

            // [INFO] SYNC WITH DASHBOARD: Check current dashboard picker
            const picker = document.getElementById('dashboardMonthPicker');
            const customMonth = document.getElementById('customMonthInput').value; // e.g. "2026-04"
            
            let targetMonth = 'all';
            if (picker && picker.value === 'custom' && customMonth) {
                targetMonth = customMonth;
            }

            // 2. Initial Render: Sync with Dashboard month if available
            const btns = document.querySelectorAll('.month-category-btn');
            const targetBtn = Array.from(btns).find(b => b.dataset.month === targetMonth);
            
            if (targetBtn) {
                filterDateLeaderboardByMonth(targetMonth, targetBtn);
            } else {
                filterDateLeaderboardByMonth('all');
            }
        }

        function closeDateLeaderboardModal() {
            document.getElementById('dateLeaderboardModal').style.display = 'none';
        }

        function renderDateCategoryButtons(months) {
            const container = document.getElementById('dateCategoryContainer');
            if (!container) return;

            // Start with "All Time" button
            let html = `<button class="month-category-btn active" data-month="all" onclick="filterDateLeaderboardByMonth('all', this)">All Time</button>`;

            if (Array.isArray(months)) {
                months.forEach(m => {
                    html += `<button class="month-category-btn" data-month="${m.value}" onclick="filterDateLeaderboardByMonth('${m.value}', this)">${m.label}</button>`;
                });
            }

            container.innerHTML = html;
        }

        function filterDateLeaderboardByMonth(monthVal, btn = null) {
            if (btn) {
                document.querySelectorAll('.month-category-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');

                // [INFO] UPDATE MODAL TITLE DYNAMICALLY
                const titleEl = document.querySelector('#dateLeaderboardModal .ab-modal-title');
                if (titleEl) {
                    const monthLabel = btn.innerText;
                    titleEl.innerHTML = `<i class="fas fa-calendar-star"></i> Most Booked Dates <span style="font-weight:400; color:#999; font-size:0.9rem; margin-left:8px;">(${monthLabel})</span>`;
                }
            }

            const listContainer = document.getElementById('modalDateLeaderboardList');
            const modalContent = document.querySelector('#dateLeaderboardModal .ab-modal-content');
            if (!listContainer || !modalContent) return;

            // [INFO] LOCK HEIGHT: Capture current height to prevent jumping
            const startHeight = listContainer.offsetHeight;
            listContainer.style.height = startHeight + 'px';
            listContainer.style.overflow = 'hidden';
            listContainer.style.opacity = '0.6';

            const processData = (data, totalCount) => {
                // 1. Render content
                renderDateLeaderboardInModal(data, totalCount);

                // 2. Measure NEW height
                listContainer.style.transition = 'none';
                listContainer.style.height = 'auto';
                listContainer.style.overflowY = 'visible';
                const contentHeight = listContainer.offsetHeight;
                
                // Cap height to 85vh minus header/filters (approx 150px)
                const viewportMax = window.innerHeight * 0.85 - 150;
                const targetHeight = Math.min(contentHeight, viewportMax);
                const needsScroll = contentHeight > viewportMax;

                // 3. Snap back for animation
                listContainer.style.height = startHeight + 'px';
                listContainer.style.overflow = 'hidden';
                listContainer.style.scrollbarGutter = 'stable'; // [INFO] PREVENT WIDTH SHIFT

                // Force Reflow
                listContainer.offsetHeight;

                // [INFO] STABLE SCROLL & ANIMATION FIX
                // If the height is almost same, skip animation for responsiveness
                if (Math.abs(startHeight - targetHeight) < 2) {
                    listContainer.style.height = targetHeight + 'px';
                    listContainer.style.overflowY = needsScroll ? 'auto' : 'hidden';
                    listContainer.style.opacity = '1';
                    listContainer.style.transition = 'none';
                    listContainer.style.pointerEvents = 'auto';
                } else {
                    // 4. Glide to new height
                    // [INFO] SYNC SCROLLBAR: Set overflow immediately so it appears WITH the expansion
                    listContainer.style.overflowY = needsScroll ? 'auto' : 'hidden';
                    
                    listContainer.style.transition = 'height 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease';
                    listContainer.style.height = targetHeight + 'px';
                    listContainer.style.opacity = '1';
                    listContainer.style.pointerEvents = 'auto';

                    // 5. RELIABLE CLEANUP
                    setTimeout(() => {
                        listContainer.style.transition = 'none';
                        listContainer.style.height = targetHeight + 'px';
                    }, 450);
                }
            };

            if (monthVal === 'all') {
                processData(currentChartData.date, currentChartData.totalDateCount);
            } else {
                fetch(`get_dashboard_stats.php?date=${monthVal}&_t=${new Date().getTime()}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            processData(data.date_leaderboard, data.total_date_count);
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        listContainer.innerHTML = '<div style="text-align:center; padding:40px; color:red;">Error loading data.</div>';
                        listContainer.style.height = 'auto';
                        listContainer.style.opacity = '1';
                    });
            }
        }

        function renderDateLeaderboardInModal(data, totalCount = null) {
            const container = document.getElementById('modalDateLeaderboardList');
            if (!container) return;

            if (!Array.isArray(data) || data.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:40px; color:#999;">No booked dates found for this selection.</div>';
                return;
            }

            // [INFO] NEW: TOTAL COUNT LOGIC (Same as Room Leaderboard)
            const denominator = totalCount || data.reduce((sum, d) => sum + d.count, 0) || 1;
            let listHtml = '';

            data.forEach((item, i) => {
                const pct = (item.count / denominator) * 100;
                const displayPct = pct.toFixed(1);
                const rank = i + 1;

                let rCol = '#6B7280', rBg = '#F3F4F6';
                if (rank === 1) { rCol = '#B88E2F'; rBg = '#FFF8E1'; }
                else if (rank === 2) { rCol = '#4B5563'; rBg = '#F9FAFB'; }
                else if (rank === 3) { rCol = '#92400E'; rBg = '#FFFBEB'; }

                listHtml += `
                    <div class="leaderboard-row" 
                         style="display:flex; align-items:center; gap:15px; background:#fff; padding:15px; border-radius:12px; border:1px solid #f0f0f0; margin-bottom:12px; transition: all 0.3s ease; animation: cardFadeInUp 0.4s cubic-bezier(0.165, 0.84, 0.44, 1) forwards; animation-delay: ${i * 40}ms; opacity: 0;">
                        
                        <div style="width:36px; height:36px; background:${rBg}; color:${rCol}; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:0.9rem; flex-shrink:0; border: 1px solid ${rank <= 3 ? rCol + '44' : 'transparent'};">
                            ${rank}
                        </div>
                        <div style="flex:1; min-width:0;">
                            <div style="display:flex; justify-content:space-between; margin-bottom:8px; align-items:center;">
                                <span style="font-weight:700; color:#333; font-size:1rem;">${item.name}</span>
                                <span style="font-weight:800; color:#B88E2F; font-size:1.1rem;">${item.count} <small style="font-size:0.75rem; color:#999; font-weight:600;">(${displayPct}%)</small></span>
                            </div>
                            <div style="height:8px; background:#f1f5f9; border-radius:10px; overflow:hidden;">
                                <div class="modal-date-progress-bar" style="height:100%; width:${pct}%; background:${rank === 1 ? '#B88E2F' : '#cbd5e1'}; border-radius:10px; transition: width 1s ease-out;"></div>
                            </div>
                        </div>
                    </div>`;
            });

            container.innerHTML = listHtml;
        }

        // [INFO] INITIAL LOAD (Runs once immediately when page opens)
        // ---------------------------------------------------------------
        document.addEventListener("DOMContentLoaded", function () {
            // 1. CLEAN URL (Remove ?login=success so it doesn't show on reload)
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('login')) {
                const newUrl = window.location.pathname;
                window.history.replaceState({}, document.title, newUrl);
            }

            // Track essential data loading
            const essentialFetches = [
                fetchHeaderData(),
                fetchPendingBookings(),
                fetchPendingOrders(),
                fetchDashboardCards(false),
                refreshBookingTable(),
                fetchRevenueChart(currentChartYear)
            ];

            // Load other content non-blocking
            filterTable('today'); // 🟢 Default selected tab for bookings
            fetchGuestList();
            checkAutoReminders();
            
            // 🟢 Handle Calendar Initialization
            const activePage = localStorage.getItem('activePage') || 'dashboard';
            if (activePage === 'calendar') {
                renderRealtimeCalendar(true); // Show skeleton immediately
                refreshCalendarData(false); // Fetch fresh data
            } else {
                renderRealtimeCalendar(); // Normal render for background
            }

            updateYearButtons();

            // [INFO] HIDE THE INITIAL DASHBOARD LOADER
            // Only hide when critical data is fetched OR a timeout is reached
            const minWait = new Promise(resolve => setTimeout(resolve, 1000));

            Promise.allSettled([...essentialFetches, minWait]).then(() => {
                const loader = document.getElementById('initialDashboardLoader');
                if (loader) {
                    loader.classList.add('hidden');
                    // Remove from DOM after transition finishes
                    setTimeout(() => {
                        loader.remove();
                    }, 500);
                }
            });
        });

        /**
 * ðŸ”’ MULTI-TAB PREVENTION SYSTEM
 * This uses the BroadcastChannel API to communicate between tabs.
 */
        (function () {
            const channel = new BroadcastChannel('amv_admin_session');

            // 1. When a new tab opens, it "pings" other tabs to see if they exist
            channel.postMessage({ type: 'NEW_TAB_OPENED' });

            // 2. Listen for messages from other tabs
            channel.onmessage = (event) => {
                if (event.data.type === 'NEW_TAB_OPENED') {
                    // An existing tab heard a new tab opening. 
                    // It sends back an "I'M ALREADY HERE" message.
                    channel.postMessage({ type: 'SESSION_ALREADY_ACTIVE' });
                }

                if (event.data.type === 'SESSION_ALREADY_ACTIVE') {
                // This tab just opened, but received word that another tab is active.
                // We block this tab immediately.
                showError("Access Denied: You already have an active Admin session open in another tab.");
                window.location.href = "login.php?error=multiple_tabs";
                }            };
        })();

        async function triggerReminders() {
            if (!await showConfirm("Confirmation", "Send emails to all guests checking out TODAY (12:00 PM)?")) return;

            // Show loading state
            const btn = document.querySelector('button[onclick="triggerReminders()"]');
            const originalText = btn.innerText;
            btn.innerText = "Sending...";
            btn.disabled = true;

            fetch("send_reminders.php")
                .then(res => res.text())
                .then(data => {
                    showSuccess("Process Complete:\n" + data);
                    fetchHeaderData();
                })
                .catch(err => {
                    showError("Error: " + err);
                })
                .finally(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
        }

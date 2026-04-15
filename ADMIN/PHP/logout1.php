<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/flowbite/2.2.1/flowbite.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            font-family: 'Montserrat', sans-serif;
        }

        /* Style for the donut chart's center text */
        .chart-container {
            position: relative;
            width: 100%;
            max-width: 400px;
            height: 300px;
            margin: auto;
        }

        .chart-container canvas {
            width: 100% !important;
            height: 100% !important;
        }

        /* CSS Styling for the center text */
        .center-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 22px;
            font-weight: bold;
            color: #334155;
            opacity: 0;
            /* Initially hidden for fade-in animation */
            transition: opacity 1s ease-in-out;
            z-index: 10;

            .d-flex {
                display: flex;
                align-items: center;
                /* Vertically centers the checkboxes and labels */
                gap: 15px;
                /* Space between checkboxes */
            }

            .d-flex label {
                display: flex;
                align-items: center;
                /* Aligns the checkbox with the label text */
            }

        }
    </style>
</head>

<body>
    <div class="icon-modal" id="messagesModal">
        <div class="modal-content-icon p-3">
            <div class="modal-header">
                <h4>Messages</h4>
                <button class="modal-close" id="closeMessagesModal">✕</button>
            </div>
            <div class="px-2">
                <!-- Card for each message -->
                <div class="header-card">
                    <div class="header-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24" stroke-width="2">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                        </svg>
                    </div>
                    <div class="header-details g-1">
                        <div class="header-name">John Doe</div>
                        <div class="header-text">Can I check in early?</div>
                    </div>
                    <div class="header-time">2m ago</div>
                </div>
                <!-- Add other message cards as needed -->
            </div>
        </div>
    </div>

    <!-- Donut Chart -->
    <div class="d-flex">
        <label>
            <input type="checkbox" name="breakfast" /> With Breakfast
        </label>
        <label>
            <input type="checkbox" name="extraBed" /> Extra Bed
        </label>
    </div>


    <script>
        const pieCtx = document.getElementById('pieBookings');
        const pieData = {
            labels: ['Check-ins', 'No-show', 'Cancelled'],
            values: [56, 22, 12]
        };

        const totalValue = pieData.values.reduce((sum, val) => sum + val, 0);

        let pieBookingsChart;

        // Initialize Donut Chart
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
                animation: {
                    duration: 1800,
                    easing: 'easeOutCubic',
                    animateRotate: true,
                    animateScale: true,
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            pointRadius: 5,
                            padding: 15, // space between circle and text
                        }
                    },
                    tooltip: { enabled: true }
                }
            },
            plugins: [{
                id: 'animatedCenterText',
                afterDraw(chart) {
                    const { ctx, width, height } = chart;
                    let currentTotal = 0;
                    const increment = totalValue / 60; // 60 animation steps
                    let step = 0;

                    const animate = () => {
                        step++;
                        currentTotal += increment;
                        if (currentTotal > totalValue) currentTotal = totalValue;

                        // Clear the center area
                        ctx.save();
                        ctx.clearRect(width / 2 - 30, height / 2 - 30, 60, 60);

                        // Draw the animated total
                        ctx.font = 'bold 22px Montserrat, Arial';
                        ctx.fillStyle = '#334155';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';
                        ctx.fillText(Math.floor(currentTotal), width / 2, height / 2);
                        ctx.restore();

                        // If animation is not complete, keep animating
                        if (step < 60) {
                            requestAnimationFrame(animate);
                        }
                    };

                    animate();
                }
            }]
        });

        // Update progress bars if needed
        function updateProgressBars(values) {
            const total = values.reduce((a, b) => a + b, 0) || 1;
            const [checkin, noshow, cancelled] = values.map(v => Math.round((v / total) * 100));

            const set = (id, val) => {
                const fill = document.getElementById(id);
                if (fill) fill.style.width = `${val}%`;
            };

            set('pbCheckin', checkin);
            set('pbNoShow', noshow);
            set('pbCancelled', cancelled);

            // Update progress values displayed beside each bar
            const pvC = document.getElementById('pvCheckin');
            if (pvC) pvC.textContent = `${checkin}%`;
            const pvN = document.getElementById('pvNoShow');
            if (pvN) pvN.textContent = `${noshow}%`;
            const pvX = document.getElementById('pvCancelled');
            if (pvX) pvX.textContent = `${cancelled}%`;
        }

        updateProgressBars(pieData.values);
    </script>
</body>

</html>
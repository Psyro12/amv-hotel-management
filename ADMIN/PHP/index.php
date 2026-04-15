<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AMV - Dashboard Overview Interface</title>
    <link rel="icon" type="image/png" href="../../IMG/5.png">
    
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* --- GLOBAL RESET & TYPOGRAPHY --- */
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            background-color: #f4f6f9; /* Light grey background */
            font-family: 'Montserrat', sans-serif;
            color: #333;
        }

        /* --- LAYOUT CONTAINER --- */
        .page-content {
            padding: 20px;
            max-width: 1600px;
            margin: 0 auto;
        }

        /* --- HEADER SECTION --- */
        .overview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        h2 { margin: 0; font-size: 1.5rem; font-weight: 700; color: #1f2937; }

        .date-filter {
            background: #fff;
            border: 1px solid #e5e7eb;
            padding: 8px 15px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            color: #555;
            font-weight: 600;
        }

        /* --- TOP STATS GRID --- */
        .stats-grid {
            display: grid;
            /* Auto-fit: Creates as many columns as fit, min 200px wide */
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 25px 15px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
            border: 1px solid #f0f0f0;
            transition: transform 0.2s;
        }

        .stat-card:hover { transform: translateY(-3px); }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: #1f2937;
            margin: 0 0 5px 0;
        }

        .stat-label {
            font-size: 0.85rem;
            color: #9ca3af;
            font-weight: 600;
            text-transform: uppercase;
            margin: 0;
        }

        /* --- CHARTS SECTION (Responsive Layout) --- */
        .charts-row {
            display: flex;
            gap: 20px;
            /* Fixed height on desktop ensures alignment */
            height: 480px; 
            width: 100%;
        }

        /* LEFT SIDE: Doughnut Chart Wrapper */
        .doughnut-wrapper {
            /* flex: 0 1 320px -> "Don't grow much, can shrink, start at 320px" */
            /* Using fixed width for sidebar feel on desktop */
            flex: 0 0 350px; 
            display: flex;
            flex-direction: column;
            gap: 20px;
            min-width: 0; /* Important for flex child */
        }

        /* RIGHT SIDE: Bar Chart Wrapper */
        .bar-wrapper {
            flex: 1; /* Take all remaining space */
            min-width: 0; /* Allows chart to shrink below default size */
        }

        /* COMMON CARD STYLE */
        .card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
            border: 1px solid #f0f0f0;
            display: flex;
            flex-direction: column;
        }

        .doughnut-wrapper .card {
            flex: 1; /* Fill vertical space in wrapper */
            justify-content: center;
        }

        .bar-wrapper.card {
            height: 100%;
        }

        .chart-title {
            font-size: 1rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: 15px;
            text-align: center;
        }

        /* CHART CONTAINER (The canvas holder) */
        .chart-box {
            position: relative;
            flex-grow: 1;
            width: 100%;
            height: 100%;
            min-height: 200px; /* Safety height */
        }

        /* --- PROGRESS BARS (Bottom Left Card) --- */
        .progress-item { margin-bottom: 12px; }
        .progress-header { display: flex; justify-content: space-between; font-size: 0.75rem; color: #666; margin-bottom: 4px; font-weight: 600; }
        .progress-track { background: #f3f4f6; height: 8px; border-radius: 10px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 10px; }

        /* --- RESPONSIVENESS (The Magic) --- */
        
        /* TABLET & MOBILE Breakpoint */
        @media (max-width: 1000px) {
            .charts-row {
                flex-direction: column; /* Stack vertically */
                height: auto; /* Let height grow naturally */
            }

            .doughnut-wrapper {
                flex: none; /* Stop being fixed width */
                width: 100%; /* Go full width */
                flex-direction: row; /* Put Pie and Progress side-by-side */
                height: 320px; /* Fixed height for this row */
            }

            .bar-wrapper.card {
                height: 400px; /* Fixed height for bar chart on tablet */
            }
        }

        /* MOBILE PHONE Breakpoint */
        @media (max-width: 768px) {
            .doughnut-wrapper {
                flex-direction: column; /* Stack vertically again */
                height: auto;
            }

            .doughnut-wrapper .card {
                min-height: 300px; /* Give space for pie chart */
            }
        }
    </style>
</head>
<body>

    <div class="page-content">
        
        <div class="overview-header">
            <h2>Overview</h2>
            <div class="date-filter">
                <span>View Month:</span>
                <span>December 2025 📅</span>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">5</div>
                <div class="stat-label">Total Guests</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">$84,090</div>
                <div class="stat-label">Monthly Revenue</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">0%</div>
                <div class="stat-label">Occupancy Rate</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">5</div>
                <div class="stat-label">Pending Arrivals</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">0</div>
                <div class="stat-label">Kitchen Orders</div>
            </div>
        </div>

        <div class="charts-row">

            <div class="doughnut-wrapper">
                
                <div class="card">
                    <div class="chart-title">Booking Outcomes</div>
                    <div class="chart-box">
                        <canvas id="pieChart"></canvas>
                    </div>
                </div>

                <div class="card" style="justify-content: flex-start;">
                    <div class="progress-item">
                        <div class="progress-header"><span>Complete</span><span>53%</span></div>
                        <div class="progress-track"><div class="progress-fill" style="width: 53%; background: #10B981;"></div></div>
                    </div>
                    <div class="progress-item">
                        <div class="progress-header"><span>No-Show</span><span>32%</span></div>
                        <div class="progress-track"><div class="progress-fill" style="width: 32%; background: #F59E0B;"></div></div>
                    </div>
                    <div class="progress-item">
                        <div class="progress-header"><span>Cancelled</span><span>15%</span></div>
                        <div class="progress-track"><div class="progress-fill" style="width: 15%; background: #EF4444;"></div></div>
                    </div>
                </div>

            </div>

            <div class="bar-wrapper card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <button style="border:none; background:none; cursor:pointer; font-size: 1.2rem;">◀</button>
                    <div class="chart-title" style="margin:0;">Revenue 2025</div>
                    <button style="border:none; background:none; cursor:pointer; font-size: 1.2rem;">▶</button>
                </div>
                
                <div class="chart-box">
                    <canvas id="barChart"></canvas>
                </div>
            </div>

        </div>

    </div>

    <script>
        // 1. Doughnut Chart
        const pieCtx = document.getElementById('pieChart').getContext('2d');
        new Chart(pieCtx, {
            type: 'doughnut',
            data: {
                labels: ['Complete', 'No-Show', 'Cancelled'],
                datasets: [{
                    data: [53, 32, 15],
                    backgroundColor: ['#10B981', '#F59E0B', '#EF4444'], // Green, Orange, Red
                    borderWidth: 0,
                    cutout: '70%'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // CRITICAL for responsiveness
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            font: { family: 'Montserrat', size: 10 }
                        }
                    }
                }
            }
        });

        // 2. Bar Chart
        const barCtx = document.getElementById('barChart').getContext('2d');
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'Revenue',
                    data: [12000, 19000, 30000, 50000, 20000, 30000, 45000, 40000, 35000, 60000, 75000, 85590],
                    backgroundColor: '#CDBD46', // Gold Color
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // CRITICAL for responsiveness
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) { return '₱' + value/1000 + 'k'; }
                        }
                    },
                    x: {
                        grid: { display: false }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    </script>
</body>
</html>
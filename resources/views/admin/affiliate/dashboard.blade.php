@extends('admin.layout')

@section('title', 'Tổng quan Affiliate')

@section('styles')
    <style>
        /* Premium Dashboard CSS Styles */
        .metric-card {
            background: #ffffff;
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 16px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.015);
            padding: 24px;
            position: relative;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            align-items: center;
        }

        .metric-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.04);
            border-color: rgba(59, 130, 246, 0.3);
        }

        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: transparent;
            transition: background 0.3s ease;
        }

        .metric-card.card-blue::before { background: #3b82f6; }
        .metric-card.card-purple::before { background: #8b5cf6; }
        .metric-card.card-orange::before { background: #f97316; }
        .metric-card.card-green::before { background: #10b981; }

        .metric-icon-wrapper {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
            margin-right: 18px;
            transition: transform 0.3s ease;
        }

        .metric-card:hover .metric-icon-wrapper {
            transform: scale(1.08) rotate(5deg);
        }

        /* Card icon dynamic backgrounds */
        .bg-light-blue { background-color: #eff6ff; color: #3b82f6; }
        .bg-light-purple { background-color: #f5f3ff; color: #8b5cf6; }
        .bg-light-orange { background-color: #fff7ed; color: #f97316; }
        .bg-light-green { background-color: #f0fdf4; color: #10b981; }

        .metric-details {
            flex-grow: 1;
        }

        .metric-title {
            font-size: 13.5px;
            font-weight: 500;
            color: #64748b;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .metric-value {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
            line-height: 1.2;
        }

        .trend-tag {
            font-size: 12.5px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 6px;
        }

        .trend-up {
            color: #10b981;
            background-color: #ecfdf5;
        }

        .trend-down {
            color: #ef4444;
            background-color: #fef2f2;
        }

        .card-options {
            position: absolute;
            top: 16px;
            right: 18px;
            color: #94a3b8;
            cursor: pointer;
            font-size: 16px;
            transition: color 0.2s ease;
        }

        .card-options:hover {
            color: #475569;
        }

        /* Chart Card custom layout styling */
        .chart-card {
            background: #ffffff;
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 16px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.015);
            padding: 28px;
            margin-bottom: 24px;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 18px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .chart-title-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }

        .info-tooltip-icon {
            color: #94a3b8;
            cursor: pointer;
            font-size: 14px;
        }

        .chart-filters {
            display: flex;
            gap: 10px;
        }

        .chart-filter-select {
            padding: 6px 16px 6px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13.5px;
            color: #475569;
            background-color: #f8fafc;
            outline: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .chart-filter-select:focus {
            border-color: #3b82f6;
            background-color: #ffffff;
        }

        .chart-stats-summary {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .revenue-label-group {
            display: flex;
            flex-direction: column;
        }

        .revenue-label {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .revenue-value-large {
            font-size: 28px;
            font-weight: 800;
            color: #2563eb;
            line-height: 1;
        }

        .chart-growth-group {
            text-align: right;
        }

        .chart-container-wrapper {
            position: relative;
            width: 100%;
            height: 380px;
        }

        /* Micro-interactions & animations */
        .fade-in-up {
            animation: fadeInUp 0.5s ease forwards;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(12px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Mobile specific adjustments */
        @media (max-width: 575px) {
            .chart-stats-summary {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .chart-growth-group {
                text-align: left;
            }
            .chart-container-wrapper {
                height: 280px;
            }
        }
    </style>
@endsection

@section('content')
    <div class="container-fluid px-0">
        <!-- Dashboard Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 fade-in-up">
            <div>
                <h2 class="mb-1 fw-bold text-dark">Tổng quan Affiliate</h2>
                <div class="text-muted">Báo cáo hiệu suất, đơn hàng giới thiệu và dòng doanh thu affiliate của hệ thống.</div>
            </div>
        </div>

        <!-- Metrics Row -->
        <div class="row mb-4">
            <!-- Card 1: Tổng khách hàng -->
            <div class="col-sm-6 col-lg-3 mb-3 fade-in-up" style="animation-delay: 0.05s;">
                <div class="metric-card card-blue">
                    <div class="metric-icon-wrapper bg-light-blue">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="metric-details">
                        <div class="metric-title">Tổng khách hàng</div>
                        <div class="metric-value">{{ number_format($totalCustomers, 0, ',', '.') }}</div>
                        <div class="trend-tag {{ $customerGrowth >= 0 ? 'trend-up' : 'trend-down' }}">
                            <i class="fas {{ $customerGrowth >= 0 ? 'fa-arrow-up' : 'fa-arrow-down' }}"></i>
                            {{ number_format(abs($customerGrowth), 1, ',', '.') }}%
                            <span class="text-muted fw-normal">tháng trước</span>
                        </div>
                    </div>
                    <div class="card-options">
                        <i class="fas fa-ellipsis-h"></i>
                    </div>
                </div>
            </div>

            <!-- Card 2: Tổng doanh thu -->
            <div class="col-sm-6 col-lg-3 mb-3 fade-in-up" style="animation-delay: 0.1s;">
                <div class="metric-card card-purple">
                    <div class="metric-icon-wrapper bg-light-purple">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="metric-details">
                        <div class="metric-title">Tổng doanh thu</div>
                        <div class="metric-value">{{ number_format($totalRevenue, 0, ',', '.') }} đ</div>
                        <div class="trend-tag {{ $revenueGrowth >= 0 ? 'trend-up' : 'trend-down' }}">
                            <i class="fas {{ $revenueGrowth >= 0 ? 'fa-arrow-up' : 'fa-arrow-down' }}"></i>
                            {{ number_format(abs($revenueGrowth), 1, ',', '.') }}%
                            <span class="text-muted fw-normal">tháng trước</span>
                        </div>
                    </div>
                    <div class="card-options">
                        <i class="fas fa-ellipsis-h"></i>
                    </div>
                </div>
            </div>

            <!-- Card 3: Tổng đơn hàng từ Affiliate -->
            <div class="col-sm-6 col-lg-3 mb-3 fade-in-up" style="animation-delay: 0.15s;">
                <div class="metric-card card-orange">
                    <div class="metric-icon-wrapper bg-light-orange">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="metric-details">
                        <div class="metric-title">Tổng đơn hàng</div>
                        <div class="metric-value">{{ number_format($totalOrders, 0, ',', '.') }}</div>
                        <div class="trend-tag {{ $ordersGrowth >= 0 ? 'trend-up' : 'trend-down' }}">
                            <i class="fas {{ $ordersGrowth >= 0 ? 'fa-arrow-up' : 'fa-arrow-down' }}"></i>
                            {{ number_format(abs($ordersGrowth), 1, ',', '.') }}%
                            <span class="text-muted fw-normal">tháng trước</span>
                        </div>
                    </div>
                    <div class="card-options">
                        <i class="fas fa-ellipsis-h"></i>
                    </div>
                </div>
            </div>

            <!-- Card 4: Tổng yêu cầu rút tiền -->
            <div class="col-sm-6 col-lg-3 mb-3 fade-in-up" style="animation-delay: 0.2s;">
                <div class="metric-card card-green">
                    <div class="metric-icon-wrapper bg-light-green">
                        <i class="fas fa-credit-card"></i>
                    </div>
                    <div class="metric-details">
                        <div class="metric-title">Yêu cầu rút tiền</div>
                        <div class="metric-value">{{ number_format($totalWithdrawals, 0, ',', '.') }}</div>
                        <div class="trend-tag {{ $withdrawalsGrowth >= 0 ? 'trend-up' : 'trend-down' }}">
                            <i class="fas {{ $withdrawalsGrowth >= 0 ? 'fa-arrow-up' : 'fa-arrow-down' }}"></i>
                            {{ number_format(abs($withdrawalsGrowth), 1, ',', '.') }}%
                            <span class="text-muted fw-normal">tháng trước</span>
                        </div>
                    </div>
                    <div class="card-options">
                        <i class="fas fa-ellipsis-h"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Revenue Chart Card -->
        <div class="row mb-4">
            <div class="col-12 fade-in-up" style="animation-delay: 0.25s;">
                <div class="chart-card">
                    <!-- Chart Header -->
                    <div class="chart-header">
                        <div class="chart-title-group">
                            <h3 class="chart-title">Tổng doanh thu</h3>
                            <i class="fas fa-info-circle info-tooltip-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="Doanh thu tích lũy từ các đơn hàng thành công được giới thiệu qua Affiliate."></i>
                        </div>
                        <div class="chart-filters">
                            <div class="chart-filter-select-wrapper">
                                <i class="far fa-calendar-alt text-muted me-1" style="font-size: 13.5px;"></i>
                                <select class="chart-filter-select">
                                    <option value="12">12 tháng qua</option>
                                    <option value="6">6 tháng qua</option>
                                    <option value="3">3 tháng qua</option>
                                </select>
                            </div>
                            <select class="chart-filter-select">
                                <option value="monthly">Theo tháng</option>
                                <option value="weekly" disabled>Theo tuần</option>
                                <option value="daily" disabled>Theo ngày</option>
                            </select>
                        </div>
                    </div>

                    <!-- Statistics Summary -->
                    <div class="chart-stats-summary">
                        <div class="revenue-label-group">
                            <span class="revenue-label">Doanh thu (VND)</span>
                            <span class="revenue-value-large">{{ number_format($total12MonthsRevenue, 0, ',', '.') }} đ</span>
                        </div>
                        <div class="chart-growth-group">
                            <div class="trend-tag {{ $chartGrowth >= 0 ? 'trend-up' : 'trend-down' }}">
                                <i class="fas {{ $chartGrowth >= 0 ? 'fa-arrow-up' : 'fa-arrow-down' }}"></i>
                                {{ number_format(abs($chartGrowth), 1, ',', '.') }}%
                            </div>
                            <div class="text-muted mt-1" style="font-size: 12.5px;">so với 12 tháng trước</div>
                        </div>
                    </div>

                    <!-- Chart Canvas -->
                    <div class="chart-container-wrapper">
                        <canvas id="affiliateRevenueChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        $(function () {
            // Enable tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            });

            // Revenue Line Chart configuration
            const ctx = document.getElementById('affiliateRevenueChart').getContext('2d');
            
            // Create a gradient for the line fill
            const gradientFill = ctx.createLinearGradient(0, 0, 0, 350);
            gradientFill.addColorStop(0, 'rgba(59, 130, 246, 0.24)');
            gradientFill.addColorStop(1, 'rgba(59, 130, 246, 0.00)');

            const labels = {!! json_encode($labels) !!};
            const values = {!! json_encode($values) !!};

            const chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Doanh thu (VND)',
                        data: values,
                        fill: true,
                        backgroundColor: gradientFill,
                        borderColor: '#3b82f6',
                        borderWidth: 3,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#3b82f6',
                        pointBorderWidth: 2.5,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        pointHoverBackgroundColor: '#3b82f6',
                        pointHoverBorderColor: '#ffffff',
                        pointHoverBorderWidth: 2,
                        tension: 0.38
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: {
                                boxWidth: 10,
                                boxHeight: 10,
                                padding: 16,
                                font: {
                                    size: 13,
                                    weight: '500'
                                },
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            titleColor: '#ffffff',
                            bodyColor: '#e2e8f0',
                            padding: 12,
                            cornerRadius: 10,
                            displayColors: false,
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        label += new Intl.NumberFormat('vi-VN').format(context.parsed.y) + ' đ';
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: '#f1f5f9',
                                drawTicks: false
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 12
                                },
                                padding: 8,
                                callback: function(value) {
                                    if (value >= 1e9) {
                                        return (value / 1e9).toFixed(1) + 'B';
                                    }
                                    if (value >= 1e6) {
                                        return (value / 1e6).toFixed(0) + 'M';
                                    }
                                    if (value >= 1e3) {
                                        return (value / 1e3).toFixed(0) + 'K';
                                    }
                                    return value;
                                }
                            },
                            border: {
                                dash: [5, 5]
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: '#94a3b8',
                                font: {
                                    size: 12
                                },
                                padding: 8
                            }
                        }
                    }
                }
            });
        });
    </script>
@endsection

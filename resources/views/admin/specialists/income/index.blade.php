@extends('admin.layout')

@section('content')
    @php
        $money = fn($amount) => number_format((float) $amount, 0, ',', '.') . ' đ';
        $number = fn($value) => number_format((int) $value, 0, ',', '.');
        
        $userName = function ($user): string {
            if (! $user) {
                return 'Tất cả chuyên viên';
            }
            return $user->full_name ?: ($user->name ?: ($user->email ?: 'N/A'));
        };

        $statusBadge = fn (string $status): string => match (strtolower($status)) {
            'paid', 'đã thanh toán' => 'success',
            'approved', 'đã duyệt' => 'info',
            'waiting_approval', 'pending', 'pending_settlement', 'draft', 'chờ đối soát' => 'warning text-dark',
            'active', 'approved' => 'success',
            'suspended', 'inactive', 'blocked', 'closed', 'cancelled' => 'danger',
            default => 'secondary',
        };

        $statusDotColor = function ($label) {
            if (str_contains($label, 'Chờ đối soát'))
                return '#ff9800';
            if (str_contains($label, 'thanh toán'))
                return '#2ec866';
            if (str_contains($label, 'duyệt'))
                return '#1e88e5';
            if (str_contains($label, 'hủy') || str_contains($label, 'Hủy'))
                return '#dc3545';
            if (str_contains($label, 'treo') || str_contains($label, 'Treo') || str_contains($label, 'giữ'))
                return '#fd7e14';
            return '#64748b';
        };

        $periodStatusDotColor = function ($status) {
            if (str_contains($status, 'Chờ') || str_contains($status, 'WAITING'))
                return '#ff9800';
            if (str_contains($status, 'thanh toán') || str_contains($status, 'PAID'))
                return '#2ec866';
            if (str_contains($status, 'duyệt') || str_contains($status, 'APPROVED'))
                return '#1e88e5';
            if (str_contains($status, 'hủy') || str_contains($status, 'CANCELLED'))
                return '#dc3545';
            return '#64748b';
        };

        $statusBadgeClass = function ($status) {
            $statusLower = strtolower($status);
            if (str_contains($statusLower, 'chờ') || str_contains($statusLower, 'pending') || str_contains($statusLower, 'waiting') || str_contains($statusLower, 'draft')) {
                return 'status-badge-pending';
            }
            if (str_contains($statusLower, 'thanh toán') || str_contains($statusLower, 'paid')) {
                return 'status-badge-paid';
            }
            if (str_contains($statusLower, 'duyệt') || str_contains($statusLower, 'approved')) {
                return 'status-badge-approved';
            }
            if (str_contains($statusLower, 'hủy') || str_contains($statusLower, 'cancel')) {
                return 'status-badge-cancelled';
            }
            return 'status-badge-default';
        };
    @endphp

    <style>
        :root {
            --recon-primary: #1f6fff;
            --recon-primary-light: #f5f9ff;
            --recon-primary-border: #b8cdf2;
            --recon-border: #e2e8f0;
            --recon-text: #0f172a;
            --recon-muted: #64748b;
            --recon-success: #10b981;
            --recon-success-light: #d1fae5;
            --recon-warning: #f59e0b;
            --recon-warning-light: #fef3c7;
            --recon-info: #3b82f6;
            --recon-info-light: #dbeafe;
            --recon-danger: #ef4444;
            --recon-danger-light: #fee2e2;
        }

        .income-page-wrapper {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--recon-text);
            padding-bottom: 30px;
        }

        /* Top Line Breadcrumb */
        .recon-breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--recon-muted);
            font-size: 13.5px;
            font-weight: 500;
        }
        .recon-breadcrumb a {
            color: var(--recon-muted);
            text-decoration: none;
        }
        .recon-breadcrumb a:hover {
            color: var(--recon-primary);
        }
        .recon-breadcrumb-separator {
            color: #cbd5e1;
        }

        .icon-header-box {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            background-color: var(--recon-primary-light);
            border: 1px solid var(--recon-primary-border);
            flex-shrink: 0;
        }

        /* Card styles */
        .recon-card {
            background: #ffffff;
            border: 1px solid var(--recon-border);
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03), 0 2px 4px -1px rgba(0, 0, 0, 0.015);
            transition: all 0.2s ease;
        }
        .recon-card .card-header {
            background-color: transparent;
            border-bottom: 1px solid var(--recon-border);
            padding: 16px 20px;
            font-weight: 700;
            color: var(--recon-text);
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .recon-card .card-body {
            padding: 20px;
        }

        /* KPI grid styling */
        .recon-kpi-card {
            background: #ffffff;
            border: 1px solid var(--recon-border);
            border-radius: 12px;
            padding: 18px 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);
            display: flex;
            align-items: flex-start;
            gap: 14px;
            height: 100%;
        }
        .kpi-icon-box {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .kpi-content-wrapper {
            display: flex;
            flex-direction: column;
            gap: 12px;
            width: 100%;
        }
        .kpi-group-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .kpi-label {
            font-size: 11px;
            font-weight: 500;
            color: var(--recon-muted);
            text-transform: none;
            letter-spacing: 0.01em;
            line-height: 1.2;
        }
        .kpi-value {
            font-size: 22px;
            font-weight: 700;
            color: var(--recon-text);
            line-height: 1.2;
        }
        .kpi-value-sub {
            font-size: 15px;
            font-weight: 600;
            line-height: 1.2;
        }
        .kpi-value-sub.text-warning {
            color: #fd7e14 !important;
        }

        .icon-box-blue {
            background-color: var(--recon-primary-light);
            color: var(--recon-primary);
            border: 1px solid var(--recon-primary-border);
        }
        .icon-box-green {
            background-color: var(--recon-success-light);
            color: var(--recon-success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        .icon-box-red {
            background-color: var(--recon-danger-light);
            color: var(--recon-danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        .icon-box-orange {
            background-color: var(--recon-warning-light);
            color: var(--recon-warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 6px;
        }

        /* Status badges */
        .bg-success-light { background-color: var(--recon-success-light) !important; color: #065f46 !important; }
        .bg-info-light { background-color: var(--recon-info-light) !important; color: #1e40af !important; }
        .bg-danger-light { background-color: var(--recon-danger-light) !important; color: #991b1b !important; }
        .bg-warning-light { background-color: var(--recon-warning-light) !important; color: #854d0e !important; }

        .status-badge-custom {
            display: inline-block;
            padding: 3px 8px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 4px;
            text-align: center;
            white-space: nowrap;
        }
        .status-badge-pending {
            background-color: #fff4e6;
            color: #ff9200;
            border: 1px solid #ffe8cc;
        }
        .status-badge-paid {
            background-color: #ebfbee;
            color: #2ec866;
            border: 1px solid #d3f9d8;
        }
        .status-badge-approved {
            background-color: #e8f4fd;
            color: #1a73e8;
            border: 1px solid #d0e7fc;
        }
        .status-badge-cancelled {
            background-color: #fff5f5;
            color: #fa5252;
            border: 1px solid #ffe3e3;
        }
        .status-badge-default {
            background-color: #f1f3f5;
            color: #495057;
            border: 1px solid #e9ecef;
        }

        /* Form elements */
        .form-label {
            margin-bottom: 6px;
        }
        .form-control, .form-select {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 13.5px;
            height: 40px;
            color: #0f172a;
            background-color: #ffffff;
            transition: all 0.2s ease-in-out;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--recon-primary);
            box-shadow: 0 0 0 3px rgba(31, 111, 255, 0.12);
            outline: none;
        }

        /* Table custom styling */
        .table {
            margin-bottom: 0;
            width: 100%;
        }
        .table th {
            font-size: 11px !important;
            font-weight: 600 !important;
            color: #334155 !important;
            text-transform: none !important;
            white-space: normal !important;
            padding: 6px 4px !important;
            background-color: #f8fafc !important;
            border-bottom: 1px solid var(--recon-border) !important;
            vertical-align: middle !important;
            letter-spacing: normal !important;
            line-height: 1.2 !important;
        }
        .table td {
            padding: 6px 4px !important;
            font-size: 11px !important;
            color: #0f172a !important;
            border-bottom: 1px solid #f1f5f9 !important;
            vertical-align: middle !important;
            line-height: 1.3 !important;
        }
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        .table tfoot td {
            padding: 8px 4px !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            background-color: #f8fafc !important;
            border-top: 2px solid #e2e8f0 !important;
            vertical-align: middle !important;
        }
        .nowrap {
            white-space: nowrap !important;
        }
        .store-name-col {
            max-width: 110px !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        .fee-col {
            max-width: 80px !important;
            white-space: normal !important;
            font-size: 10.5px !important;
            line-height: 1.2 !important;
        }

        /* Responsive View Segmentation */
        @media (max-width: 767.98px) {
            .desktop-layout {
                display: none !important;
            }

            .mobile-layout {
                display: block !important;
            }

            body,
            .main-panel,
            .page-inner {
                background-color: #ffffff !important;
            }

            .page-inner {
                padding: 16px 20px !important;
            }

            .container-fluid {
                padding-left: 0 !important;
                padding-right: 0 !important;
            }

            .custom-template,
            .custom-toggle {
                display: none !important;
            }
        }

        @media (min-width: 768px) {
            .desktop-layout {
                display: block !important;
            }

            .mobile-layout {
                display: none !important;
            }
        }

        /* Mobile specific styling */
        .mobile-layout {
            padding: 4px 0;
        }

        .mobile-filter-section {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
            margin-bottom: 20px;
            background: #ffffff;
            overflow: hidden;
        }

        .mobile-filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .mobile-filter-card {
            background: #f8fafc;
            border-radius: 10px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #edf2f7;
            min-width: 0;
        }

        .mobile-filter-card.full-width {
            grid-column: span 2;
        }

        .filter-card-icon {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
        }

        .filter-card-content {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
            min-width: 0;
            overflow: hidden;
        }

        .filter-card-label {
            font-size: 9px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
        }

        .filter-select {
            border: none !important;
            background: transparent !important;
            padding: 0 16px 0 0 !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            outline: none !important;
            width: 100% !important;
            box-shadow: none !important;
            margin-top: 2px;
            text-overflow: ellipsis;
            white-space: nowrap;
            overflow: hidden;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e") !important;
            background-repeat: no-repeat !important;
            background-position: right center !important;
            background-size: 10px !important;
            cursor: pointer;
        }

        .filter-input {
            border: none !important;
            background: transparent !important;
            padding: 0 !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            outline: none !important;
            width: 100% !important;
            box-shadow: none !important;
            margin-top: 2px;
        }

        .filter-input::-webkit-calendar-picker-indicator {
            cursor: pointer;
            opacity: 0.6;
            filter: invert(0.2);
        }

        /* Summary Card and Rows */
        .mobile-summary-card {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #edf2f7;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.02);
            padding: 16px;
            margin-bottom: 20px;
        }

        .mobile-summary-title {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 12px;
        }

        .mobile-summary-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 4px;
        }

        .mobile-summary-item {
            text-align: center;
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 2px 0;
        }

        .mobile-summary-item:not(:last-child) {
            border-right: 1px solid #f1f5f9;
        }

        .mobile-summary-item .num {
            font-size: 12px;
            font-weight: 800;
            word-break: break-all;
        }

        .mobile-summary-item .lbl {
            font-size: 8px;
            color: #64748b;
            font-weight: 600;
        }

        .mobile-summary-row-secondary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 4px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px dashed #f1f5f9;
        }

        /* Section cards */
        .mobile-section-card {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #edf2f7;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.02);
            margin-bottom: 20px;
            padding: 16px;
        }

        .mobile-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid #f8fafc;
        }

        .mobile-section-title {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
        }

        .mobile-section-link {
            font-size: 11px;
            color: #1a73e8 !important;
            font-weight: 600;
            text-decoration: none !important;
        }

        /* Daily item progress styles */
        .daily-item-main {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .daily-date {
            font-size: 12px;
            color: #334155;
            font-weight: 500;
            min-width: 75px;
        }

        .daily-progress-box {
            margin: 0 10px;
        }

        .daily-tickets {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            min-width: 20px;
            text-align: right;
        }

        .daily-income {
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
            min-width: 75px;
            text-align: right;
        }
    </style>

    <div class="container-fluid income-page-wrapper">
        <!-- Desktop Layout -->
        <div class="desktop-layout">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                <div class="d-flex align-items-center">
                    <div class="icon-header-box me-3">
                        <i class="fas fa-wallet text-primary"></i>
                    </div>
                    <div>
                        <h1 class="h3 mb-1 fw-bold text-dark">{{ $isSaleUser ? 'Thu nhập của tôi' : 'Thu nhập chuyên viên' }}</h1>
                        <p class="text-muted mb-0 small">Dữ liệu được tính theo ticket, đơn hàng, khiếu nại và trạng thái đối soát trong phạm vi phụ trách.</p>
                    </div>
                </div>
                <div class="recon-breadcrumb d-none d-md-flex align-items-center gap-2">
                    <a href="#" class="text-decoration-none">Trang chủ</a>
                    <span class="recon-breadcrumb-separator">/</span>
                    <a href="#" class="text-decoration-none">Báo cáo</a>
                    <span class="recon-breadcrumb-separator">/</span>
                    <span class="active text-primary fw-semibold">Thu nhập chuyên viên</span>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="card recon-card mb-4">
                <div class="card-body p-3">
                    <form method="GET" action="{{ route('admin.specialist-income.index') }}" class="row g-3 align-items-end">
                        @if (!$isSaleUser)
                            <div class="col-lg col-md-4 col-sm-6">
                                <label class="form-label fw-semibold text-secondary small">Chuyên viên</label>
                                <select class="form-select select2-specialist" name="specialist_id">
                                    <option value="">Tất cả chuyên viên</option>
                                    @foreach ($specialistOptions as $specialist)
                                        <option value="{{ $specialist->id }}" @selected((string) $filters['specialist_id'] === (string) $specialist->id)>
                                            {{ $specialist->full_name ?: ($specialist->name ?: $specialist->email) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        <div class="col-lg col-md-4 col-sm-6">
                            <label class="form-label fw-semibold text-secondary small">Cửa hàng</label>
                            <select class="form-select select2-store" name="store_id">
                                <option value="">Tất cả cửa hàng</option>
                                @foreach ($storeOptions as $store)
                                    <option value="{{ $store->id }}" @selected((string) $filters['store_id'] === (string) $store->id)>
                                        {{ $store->name }}{{ !empty($store->store_code) ? ' - ' . $store->store_code : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-lg col-md-4 col-sm-6">
                            <label class="form-label fw-semibold text-secondary small">Loại ticket</label>
                            <select class="form-select" name="ticket_type_code">
                                <option value="">Tất cả loại ticket</option>
                                @foreach ($ticketTypeOptions as $ticketType)
                                    <option value="{{ $ticketType['code'] }}" @selected(($filters['ticket_type_code'] ?? '') === $ticketType['code'])>
                                        {{ $ticketType['name'] }} ({{ $ticketType['code'] }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-lg col-md-4 col-sm-6">
                            <label class="form-label fw-semibold text-secondary small">Trạng thái</label>
                            <select class="form-select" name="status">
                                @foreach ($statusOptions as $key => $label)
                                    <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-lg col-md-4 col-sm-6">
                            <label class="form-label fw-semibold text-secondary small">Từ ngày</label>
                            <input type="date" class="form-control" name="date_from" value="{{ $filters['date_from'] }}">
                        </div>

                        <div class="col-lg col-md-4 col-sm-6">
                            <label class="form-label fw-semibold text-secondary small">Đến ngày</label>
                            <input type="date" class="form-control" name="date_to" value="{{ $filters['date_to'] }}">
                        </div>

                        <div class="col-lg-auto col-md-4 col-sm-12 ms-auto d-flex gap-2">
                            <button type="submit" class="btn btn-primary px-3">
                                <i class="fas fa-filter me-1"></i> Lọc dữ liệu
                            </button>
                            <a href="{{ route('admin.specialist-income.index') }}" class="btn btn-outline-secondary px-3">
                                <i class="fas fa-sync-alt me-1"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Summary KPI Cards -->
            <div class="row g-3 mb-4">
                <!-- KPI 1 -->
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="recon-kpi-card">
                        <div class="kpi-icon-box icon-box-blue align-self-start">
                            <i class="fas fa-ticket-alt"></i>
                        </div>
                        <div class="kpi-content-wrapper flex-grow-1">
                            <div class="kpi-group-item">
                                <span class="kpi-label">Tổng ticket hợp lệ</span>
                                <span class="kpi-value text-dark">{{ $number($summary['total_eligible_tickets']) }}</span>
                            </div>
                            <div class="kpi-group-item mt-3">
                                <span class="kpi-label">Phi / ticket</span>
                                <span class="kpi-value-sub text-dark">{{ $money($summary['fee_per_ticket']) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- KPI 2 -->
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="recon-kpi-card">
                        <div class="kpi-icon-box icon-box-blue align-self-start">
                            <i class="fas fa-sack-dollar"></i>
                        </div>
                        <div class="kpi-content-wrapper flex-grow-1">
                            <div class="kpi-group-item">
                                <span class="kpi-label">Thu nhập tạm tính</span>
                                <span class="kpi-value text-primary">{{ $money($summary['estimated_income']) }}</span>
                            </div>
                            <div class="kpi-group-item mt-3">
                                <span class="kpi-label">Thu nhập chờ đối soát</span>
                                <span class="kpi-value-sub text-warning">{{ $money($summary['pending_settlement_income']) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- KPI 3 -->
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="recon-kpi-card">
                        <div class="kpi-icon-box icon-box-green align-self-start">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="kpi-content-wrapper flex-grow-1">
                            <div class="kpi-group-item">
                                <span class="kpi-label">Thu nhập đã duyệt</span>
                                <span class="kpi-value text-success">{{ $money($summary['approved_income']) }}</span>
                            </div>
                            <div class="kpi-group-item mt-3">
                                <span class="kpi-label">Thu nhập đã thanh toán</span>
                                <span class="kpi-value-sub text-success">{{ $money($summary['paid_income']) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- KPI 4 -->
                <div class="col-lg-3 col-md-6 col-12">
                    <div class="recon-kpi-card">
                        <div class="kpi-icon-box icon-box-red align-self-start">
                            <i class="fas fa-lock"></i>
                        </div>
                        <div class="kpi-content-wrapper flex-grow-1">
                            <div class="kpi-group-item">
                                <span class="kpi-label">Thu nhập bị giữ lại</span>
                                <span class="kpi-value text-danger">{{ $money($summary['held_income']) }}</span>
                            </div>
                            <div class="kpi-group-item mt-3">
                                <span class="kpi-label">Thu nhập bị hủy</span>
                                <span class="kpi-value-sub text-danger">{{ $money($summary['cancelled_income']) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sum calculations for desktop tables -->
            @php
                $totalDailyEligible = collect($dailyBreakdown)->sum('eligible_tickets');
                $totalDailyIncome = collect($dailyBreakdown)->sum('income');
                $totalDailyHeld = collect($dailyBreakdown)->sum('held_amount');
                $totalDailyCancelled = collect($dailyBreakdown)->sum('cancelled_amount');

                $totalStoreEligible = collect($storeBreakdown)->sum('eligible_tickets');
                $totalStoreIncome = collect($storeBreakdown)->sum('income');
                $totalStoreHeld = collect($storeBreakdown)->sum('held_amount');
                $totalStoreCancelled = collect($storeBreakdown)->sum('cancelled_amount');

                $totalTypeEligible = collect($ticketTypeBreakdown)->sum('eligible_tickets');
                $totalTypeIncome = collect($ticketTypeBreakdown)->sum('income');
                $totalTypeHeld = collect($ticketTypeBreakdown)->sum('held_amount');
                $totalTypeCancelled = collect($ticketTypeBreakdown)->sum('cancelled_amount');

                $totalStatusTotal = collect($statusBreakdown)->sum('total_tickets');
                $totalStatusEligible = collect($statusBreakdown)->sum('eligible_tickets');
                $totalStatusIncome = collect($statusBreakdown)->sum('income');
            @endphp

            <!-- Breakdown Tables Row 1: 3 Columns -->
            <div class="row g-4 mb-4">
                <!-- Breakdown theo ngày -->
                <div class="col-lg-4 col-md-6 col-12">
                    <div class="card recon-card h-100">
                        <div class="card-header bg-white">
                            <i class="far fa-calendar-alt text-primary me-2"></i> Breakdown theo ngày
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th class="nowrap">Ngày</th>
                                            <th class="text-end nowrap">Ticket hợp lệ</th>
                                            <th class="text-end nowrap">Thu nhập</th>
                                            <th class="text-end nowrap">Treo</th>
                                            <th class="text-end nowrap">Hủy</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($dailyBreakdown as $row)
                                            <tr>
                                                <td class="fw-semibold text-dark nowrap">{{ $row['label'] !== 'N/A' ? \Carbon\Carbon::parse($row['label'])->format('d/m/Y') : 'N/A' }}</td>
                                                <td class="text-end nowrap">{{ $number($row['eligible_tickets']) }}</td>
                                                <td class="text-end text-primary fw-semibold nowrap">{{ $money($row['income']) }}</td>
                                                <td class="text-end text-danger nowrap">{{ $money($row['held_amount']) }}</td>
                                                <td class="text-end text-danger nowrap">{{ $money($row['cancelled_amount']) }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-4">Chưa có dữ liệu trong khoảng lọc.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                    @if (count($dailyBreakdown) > 0)
                                        <tfoot class="table-light fw-bold border-top">
                                            <tr>
                                                <td class="nowrap">Tổng cộng</td>
                                                <td class="text-end nowrap">{{ $number($totalDailyEligible) }}</td>
                                                <td class="text-end text-primary nowrap">{{ $money($totalDailyIncome) }}</td>
                                                <td class="text-end text-danger nowrap">{{ $money($totalDailyHeld) }}</td>
                                                <td class="text-end text-danger nowrap">{{ $money($totalDailyCancelled) }}</td>
                                            </tr>
                                        </tfoot>
                                    @endif
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Breakdown theo cửa hàng -->
                <div class="col-lg-4 col-md-6 col-12">
                    <div class="card recon-card h-100">
                        <div class="card-header bg-white">
                            <i class="fas fa-store text-primary me-2"></i> Breakdown theo cửa hàng
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Cửa hàng</th>
                                            <th class="text-end nowrap">Ticket hợp lệ</th>
                                            <th class="text-end nowrap">Thu nhập</th>
                                            <th class="text-end nowrap">Treo</th>
                                            <th class="text-end nowrap">Hủy</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($storeBreakdown as $row)
                                            <tr>
                                                <td class="fw-semibold text-dark">
                                                    <div class="store-name-col" title="{{ $row['label'] }}">{{ $row['label'] }}</div>
                                                </td>
                                                <td class="text-end nowrap">{{ $number($row['eligible_tickets']) }}</td>
                                                <td class="text-end text-primary fw-semibold nowrap">{{ $money($row['income']) }}</td>
                                                <td class="text-end text-danger nowrap">{{ $money($row['held_amount']) }}</td>
                                                <td class="text-end text-danger nowrap">{{ $money($row['cancelled_amount']) }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-4">Chưa có dữ liệu trong khoảng lọc.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                    @if (count($storeBreakdown) > 0)
                                        <tfoot class="table-light fw-bold border-top">
                                            <tr>
                                                <td class="nowrap">Tổng cộng</td>
                                                <td class="text-end nowrap">{{ $number($totalStoreEligible) }}</td>
                                                <td class="text-end text-primary nowrap">{{ $money($totalStoreIncome) }}</td>
                                                <td class="text-end text-danger nowrap">{{ $money($totalStoreHeld) }}</td>
                                                <td class="text-end text-danger nowrap">{{ $money($totalStoreCancelled) }}</td>
                                            </tr>
                                        </tfoot>
                                    @endif
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Breakdown theo loại ticket -->
                <div class="col-lg-4 col-md-12 col-12">
                    <div class="card recon-card h-100">
                        <div class="card-header bg-white">
                            <i class="fas fa-tags text-primary me-2"></i> Breakdown theo loại ticket
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Loại ticket</th>
                                            <th class="text-end nowrap">Ticket hợp lệ</th>
                                            <th class="text-end">Phí áp dụng</th>
                                            <th class="text-end nowrap">Thu nhập</th>
                                            <th class="text-end nowrap">Treo</th>
                                            <th class="text-end nowrap">Hủy</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($ticketTypeBreakdown as $row)
                                            <tr>
                                                <td class="fw-semibold text-dark nowrap">{{ $row['ticket_type_code'] }}</td>
                                                <td class="text-end nowrap">{{ $number($row['eligible_tickets']) }}</td>
                                                <td class="text-end text-secondary">
                                                     <div class="fee-col ms-auto">{{ $row['fee_label'] }}</div>
                                                 </td>
                                                <td class="text-end text-primary fw-semibold nowrap">{{ $money($row['income']) }}</td>
                                                <td class="text-end text-danger nowrap">{{ $money($row['held_amount']) }}</td>
                                                <td class="text-end text-danger nowrap">{{ $money($row['cancelled_amount']) }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="6" class="text-center text-muted py-4">Chưa có dữ liệu trong khoảng lọc.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                    @if (count($ticketTypeBreakdown) > 0)
                                        <tfoot class="table-light fw-bold border-top">
                                            <tr>
                                                <td class="nowrap">Tổng cộng</td>
                                                <td class="text-end nowrap">{{ $number($totalTypeEligible) }}</td>
                                                <td class="text-end nowrap">-</td>
                                                <td class="text-end text-primary nowrap">{{ $money($totalTypeIncome) }}</td>
                                                <td class="text-end text-danger nowrap">{{ $money($totalTypeHeld) }}</td>
                                                <td class="text-end text-danger nowrap">{{ $money($totalTypeCancelled) }}</td>
                                            </tr>
                                        </tfoot>
                                    @endif
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Breakdown Tables Row 2: 2 Columns -->
            <div class="row g-4">
                <!-- Breakdown theo kỳ -->
                <div class="col-lg-7 col-12">
                    <div class="card recon-card h-100">
                        <div class="card-header bg-white">
                            <i class="fas fa-history text-primary me-2"></i> Breakdown theo kỳ
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th class="nowrap">Kỳ</th>
                                            <th class="nowrap">Trạng thái</th>
                                            <th class="text-end nowrap">Ticket hợp lệ</th>
                                            <th class="text-end nowrap">Tổng thu nhập</th>
                                            <th class="text-end nowrap">Điều chỉnh</th>
                                            <th class="text-end nowrap">Chốt trả</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($periodBreakdown as $row)
                                            <tr>
                                                <td class="fw-semibold text-dark nowrap">{{ $row['label'] }}</td>
                                                <td class="nowrap">
                                                    <span class="status-badge-custom {{ $statusBadgeClass($row['status']) }}">{{ $row['status'] }}</span>
                                                </td>
                                                <td class="text-end nowrap">{{ $number($row['eligible_tickets']) }}</td>
                                                <td class="text-end nowrap">{{ $money($row['gross_income']) }}</td>
                                                <td class="text-end nowrap {{ $row['adjustment_amount'] < 0 ? 'text-danger' : 'text-success' }}">
                                                    {{ $money($row['adjustment_amount']) }}
                                                </td>
                                                <td class="text-end text-primary fw-semibold nowrap">{{ $money($row['final_income']) }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="6" class="text-center text-muted py-4">Chưa có dữ liệu trong khoảng lọc.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Breakdown theo trạng thái -->
                <div class="col-lg-5 col-12">
                    <div class="card recon-card h-100">
                        <div class="card-header bg-white">
                            <i class="fas fa-info-circle text-primary me-2"></i> Breakdown theo trạng thái
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th class="nowrap">Trạng thái</th>
                                            <th class="text-end nowrap">Tổng ticket</th>
                                            <th class="text-end nowrap">Ticket hợp lệ</th>
                                            <th class="text-end nowrap">Giá trị</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($statusBreakdown as $row)
                                            <tr>
                                                <td class="fw-semibold text-dark nowrap">
                                                    {{ $row['label'] }}
                                                </td>
                                                <td class="text-end nowrap">{{ $number($row['total_tickets']) }}</td>
                                                <td class="text-end text-success nowrap">{{ $number($row['eligible_tickets']) }}</td>
                                                <td class="text-end text-primary fw-semibold nowrap">{{ $money($row['income']) }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="text-center text-muted py-4">Chưa có dữ liệu trong khoảng lọc.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                    @if (count($statusBreakdown) > 0)
                                        <tfoot class="table-light fw-bold border-top">
                                            <tr>
                                                <td class="nowrap">Tổng cộng</td>
                                                <td class="text-end nowrap">{{ $number($totalStatusTotal) }}</td>
                                                <td class="text-end text-success nowrap">{{ $number($totalStatusEligible) }}</td>
                                                <td class="text-end text-primary nowrap">{{ $money($totalStatusIncome) }}</td>
                                            </tr>
                                        </tfoot>
                                    @endif
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile Layout -->
        <div class="mobile-layout">
            <!-- Mobile Header (matching desktop style) -->
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h1 class="h3 mb-1 fw-bold text-dark">{{ $isSaleUser ? 'Thu nhập của tôi' : 'Thu nhập chuyên viên' }}</h1>
                    <p class="text-muted mb-0 small">Dữ liệu được tính theo ticket, đơn hàng, khiếu nại và trạng thái đối soát trong phạm vi phụ trách.</p>
                </div>
            </div>

            <!-- Mobile Filter Section -->
            <div class="mobile-filter-section card">
                <div class="mobile-filter-header d-flex justify-content-between align-items-center"
                    data-bs-toggle="collapse" data-bs-target="#mobileFilterPanel" aria-expanded="true"
                    style="cursor: pointer; padding: 12px 16px;">
                    <span class="fw-bold text-dark" style="font-size: 14px;">Bộ lọc</span>
                    <span class="filter-toggle-text text-primary" style="font-size: 11px; font-weight: 600;">Thu gọn <i
                            class="fas fa-chevron-up ms-1"></i></span>
                </div>
                <div class="collapse show" id="mobileFilterPanel">
                    <div class="card-body pt-0 px-3 pb-3">
                        <form method="GET" action="{{ route('admin.specialist-income.index') }}">
                            <div class="mobile-filter-grid">
                                <!-- Specialist (Only if not isSaleUser) -->
                                @if (!$isSaleUser)
                                    <div class="mobile-filter-card full-width">
                                        <div class="filter-card-icon icon-box-orange">
                                            <i class="fas fa-user-tie"></i>
                                        </div>
                                        <div class="filter-card-content">
                                            <span class="filter-card-label">Chuyên viên</span>
                                            <select class="filter-select" name="specialist_id">
                                                <option value="">Tất cả chuyên viên</option>
                                                @foreach ($specialistOptions as $specialist)
                                                    <option value="{{ $specialist->id }}" @selected((string) $filters['specialist_id'] === (string) $specialist->id)>
                                                        {{ $specialist->full_name ?: ($specialist->name ?: $specialist->email) }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                @endif

                                <!-- Store -->
                                <div class="mobile-filter-card full-width">
                                    <div class="filter-card-icon icon-box-blue">
                                        <i class="fas fa-store"></i>
                                    </div>
                                    <div class="filter-card-content">
                                        <span class="filter-card-label">Cửa hàng</span>
                                        <select class="filter-select" name="store_id">
                                            <option value="">Tất cả cửa hàng</option>
                                            @foreach ($storeOptions as $store)
                                                <option value="{{ $store->id }}" @selected((string) $filters['store_id'] === (string) $store->id)>
                                                    {{ $store->name }}{{ !empty($store->store_code) ? ' - ' . $store->store_code : '' }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <!-- Ticket Type -->
                                <div class="mobile-filter-card">
                                    <div class="filter-card-icon icon-box-purple">
                                        <i class="fas fa-ticket-alt"></i>
                                    </div>
                                    <div class="filter-card-content">
                                        <span class="filter-card-label">Loại ticket</span>
                                        <select class="filter-select" name="ticket_type_code">
                                            <option value="">Tất cả loại ticket</option>
                                            @foreach ($ticketTypeOptions as $ticketType)
                                                <option value="{{ $ticketType['code'] }}" @selected(($filters['ticket_type_code'] ?? '') === $ticketType['code'])>
                                                    {{ $ticketType['name'] }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <!-- Status -->
                                <div class="mobile-filter-card">
                                    <div class="filter-card-icon icon-box-green">
                                        <i class="fas fa-info-circle"></i>
                                    </div>
                                    <div class="filter-card-content">
                                        <span class="filter-card-label">Trạng thái</span>
                                        <select class="filter-select" name="status">
                                            @foreach ($statusOptions as $key => $label)
                                                <option value="{{ $key }}" @selected($filters['status'] === $key)>
                                                    {{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <!-- From Date -->
                                <div class="mobile-filter-card">
                                    <div class="filter-card-icon icon-box-blue">
                                        <i class="far fa-calendar-alt"></i>
                                    </div>
                                    <div class="filter-card-content">
                                        <span class="filter-card-label">Từ ngày</span>
                                        <input type="date" class="filter-input" name="date_from"
                                            value="{{ $filters['date_from'] }}">
                                    </div>
                                </div>

                                <!-- To Date -->
                                <div class="mobile-filter-card">
                                    <div class="filter-card-icon icon-box-blue">
                                        <i class="far fa-calendar-alt"></i>
                                    </div>
                                    <div class="filter-card-content">
                                        <span class="filter-card-label">Đến ngày</span>
                                        <input type="date" class="filter-input" name="date_to"
                                            value="{{ $filters['date_to'] }}">
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-2 mt-2">
                                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold"
                                    style="border-radius: 8px; font-size: 13px;"><i class="fas fa-filter me-1"></i> Lọc dữ
                                    liệu</button>
                                <a href="{{ route('admin.specialist-income.index') }}"
                                    class="btn btn-outline-secondary w-100 py-2 fw-bold text-center"
                                    style="border-radius: 8px; font-size: 13px; line-height: 1.8;"><i
                                        class="fas fa-sync-alt me-1"></i> Reset</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Tổng quan thu nhập -->
            <div class="mobile-summary-card">
                <div class="mobile-summary-title">Tổng quan thu nhập</div>
                <!-- Main row (Estimated, Pending, Paid, Cancelled) -->
                <div class="mobile-summary-row">
                    <div class="mobile-summary-item">
                        <span class="num text-primary">{{ $money($summary['estimated_income']) }}</span>
                        <span class="lbl">Tạm tính</span>
                    </div>
                    <div class="mobile-summary-item">
                        <span class="num text-warning">{{ $money($summary['pending_settlement_income']) }}</span>
                        <span class="lbl">Chờ đối soát</span>
                    </div>
                    <div class="mobile-summary-item">
                        <span class="num text-success">{{ $money($summary['paid_income']) }}</span>
                        <span class="lbl">Đã thanh toán</span>
                    </div>
                    <div class="mobile-summary-item">
                        <span class="num text-danger">{{ $money($summary['cancelled_income']) }}</span>
                        <span class="lbl">Bị hủy</span>
                    </div>
                </div>
                <!-- Secondary Row -->
                <div class="mobile-summary-row-secondary">
                    <div class="mobile-summary-item">
                        <span class="num text-dark">{{ $number($summary['total_eligible_tickets']) }}</span>
                        <span class="lbl">Ticket hợp lệ</span>
                    </div>
                    <div class="mobile-summary-item">
                        <span class="num text-purple"
                            style="color: #5e50f9;">{{ $money($summary['fee_per_ticket']) }}</span>
                        <span class="lbl">Phí / ticket</span>
                    </div>
                    <div class="mobile-summary-item">
                        <span class="num text-info">{{ $money($summary['approved_income']) }}</span>
                        <span class="lbl">Đã duyệt</span>
                    </div>
                    <div class="mobile-summary-item">
                        <span class="num text-danger" style="color: #fd7e14;">{{ $money($summary['held_income']) }}</span>
                        <span class="lbl">Bị giữ</span>
                    </div>
                </div>
            </div>

            <!-- Breakdown sections -->
            @php
                $slice = function ($col, $offset, $length = null) {
                    if ($col instanceof \Illuminate\Support\Collection) {
                        return $col->slice($offset, $length);
                    }
                    return $length === null ? array_slice($col, $offset) : array_slice($col, $offset, $length);
                };

                $dailyTop = $slice($dailyBreakdown, 0, 4);
                $dailyRest = $slice($dailyBreakdown, 4);
                $maxDailyTickets = 1;
                foreach ($dailyBreakdown as $row) {
                    if ($row['eligible_tickets'] > $maxDailyTickets) {
                        $maxDailyTickets = $row['eligible_tickets'];
                    }
                }

                $storeTop = $slice($storeBreakdown, 0, 3);
                $storeRest = $slice($storeBreakdown, 3);

                $ticketTypeTop = $slice($ticketTypeBreakdown, 0, 3);
                $ticketTypeRest = $slice($ticketTypeBreakdown, 3);

                $statusTop = $slice($statusBreakdown, 0, 3);
                $statusRest = $slice($statusBreakdown, 3);

                $periodTop = $slice($periodBreakdown, 0, 3);
                $periodRest = $slice($periodBreakdown, 3);
            @endphp

            <!-- Hiệu suất theo ngày -->
            <div class="mobile-section-card">
                <div class="mobile-section-header">
                    <span class="mobile-section-title">Hiệu suất theo ngày</span>
                    @if (count($dailyRest) > 0)
                        <a class="mobile-section-link collapsed" data-bs-toggle="collapse" href="#collapseDaily" role="button"
                            aria-expanded="false" aria-controls="collapseDaily" data-text-toggle="Daily">
                            Xem thêm <i class="fas fa-chevron-down ms-1"></i>
                        </a>
                    @endif
                </div>
                <div class="mobile-section-body">
                    <div class="daily-list">
                        @forelse ($dailyTop as $row)
                            @php
                                $percent = ($row['eligible_tickets'] / $maxDailyTickets) * 100;
                                $dateLabel = $row['label'] !== 'N/A' ? \Carbon\Carbon::parse($row['label'])->format('d/m/Y') : 'N/A';
                            @endphp
                            <div class="daily-item py-2 border-bottom">
                                <div class="daily-item-main d-flex align-items-center justify-content-between">
                                    <div class="daily-date">{{ $dateLabel }}</div>
                                    <div class="daily-progress-box flex-grow-1 mx-2">
                                        <div class="progress"
                                            style="height: 6px; background-color: #f1f5f9; border-radius: 3px;">
                                            <div class="progress-bar"
                                                style="width: {{ $percent }}%; background-color: #1a73e8; border-radius: 3px;">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="daily-tickets me-2">{{ $row['eligible_tickets'] }}</div>
                                    <div class="daily-income">{{ $money($row['income']) }}</div>
                                </div>
                                @if ((float) $row['held_amount'] > 0 || (float) $row['cancelled_amount'] > 0)
                                    <div class="text-end mt-1" style="font-size: 10px;">
                                        @if ((float) $row['held_amount'] > 0)
                                            <span class="text-danger me-2">Treo: {{ $money($row['held_amount']) }}</span>
                                        @endif
                                        @if ((float) $row['cancelled_amount'] > 0)
                                            <span class="text-danger">Hủy: {{ $money($row['cancelled_amount']) }}</span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="text-center text-muted py-3" style="font-size: 12px;">Chưa có dữ liệu trong khoảng lọc.
                            </div>
                        @endforelse

                        @if (count($dailyRest) > 0)
                            <div class="collapse" id="collapseDaily">
                                @foreach ($dailyRest as $row)
                                    @php
                                        $percent = ($row['eligible_tickets'] / $maxDailyTickets) * 100;
                                        $dateLabel = $row['label'] !== 'N/A' ? \Carbon\Carbon::parse($row['label'])->format('d/m/Y') : 'N/A';
                                    @endphp
                                    <div class="daily-item py-2 border-bottom">
                                        <div class="daily-item-main d-flex align-items-center justify-content-between">
                                            <div class="daily-date">{{ $dateLabel }}</div>
                                            <div class="daily-progress-box flex-grow-1 mx-2">
                                                <div class="progress"
                                                    style="height: 6px; background-color: #f1f5f9; border-radius: 3px;">
                                                    <div class="progress-bar"
                                                        style="width: {{ $percent }}%; background-color: #1a73e8; border-radius: 3px;">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="daily-tickets me-2">{{ $row['eligible_tickets'] }}</div>
                                            <div class="daily-income">{{ $money($row['income']) }}</div>
                                        </div>
                                        @if ((float) $row['held_amount'] > 0 || (float) $row['cancelled_amount'] > 0)
                                            <div class="text-end mt-1" style="font-size: 10px;">
                                                @if ((float) $row['held_amount'] > 0)
                                                    <span class="text-danger me-2">Treo: {{ $money($row['held_amount']) }}</span>
                                                @endif
                                                @if ((float) $row['cancelled_amount'] > 0)
                                                    <span class="text-danger">Hủy: {{ $money($row['cancelled_amount']) }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Theo cửa hàng -->
            <div class="mobile-section-card">
                <div class="mobile-section-header">
                    <span class="mobile-section-title">Theo cửa hàng</span>
                    @if (count($storeRest) > 0)
                        <a class="mobile-section-link collapsed" data-bs-toggle="collapse" href="#collapseStores" role="button"
                            aria-expanded="false" aria-controls="collapseStores" data-text-toggle="Stores">
                            Xem thêm <i class="fas fa-chevron-down ms-1"></i>
                        </a>
                    @endif
                </div>
                <div class="mobile-section-body">
                    <div class="store-list">
                        @forelse ($storeTop as $row)
                            <div class="store-item py-2 border-bottom">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <div class="store-icon"
                                        style="background-color: #e8f5e9; color: #2ec866; width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                        <i class="fas fa-store" style="font-size: 12px;"></i>
                                    </div>
                                    <div class="store-name fw-bold text-dark" style="font-size: 12px; line-height: 1.3;">
                                        {{ $row['label'] }}</div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div style="font-size: 13px; font-weight: 700;">{{ $number($row['eligible_tickets']) }}
                                        </div>
                                        <div style="font-size: 9px; color: #64748b; font-weight: 500;">Ticket hợp lệ</div>
                                    </div>
                                    <div class="text-end">
                                        <div class="text-primary" style="font-size: 13px; font-weight: 700;">
                                            {{ $money($row['income']) }}</div>
                                        <div style="font-size: 9px; color: #64748b; font-weight: 500;">Thu nhập tạm tính</div>
                                    </div>
                                </div>
                                @if ((float) $row['held_amount'] > 0 || (float) $row['cancelled_amount'] > 0)
                                    <div class="text-end mt-1" style="font-size: 10px;">
                                        @if ((float) $row['held_amount'] > 0)
                                            <span class="text-danger me-2">Treo: {{ $money($row['held_amount']) }}</span>
                                        @endif
                                        @if ((float) $row['cancelled_amount'] > 0)
                                            <span class="text-danger">Hủy: {{ $money($row['cancelled_amount']) }}</span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="text-center text-muted py-3" style="font-size: 12px;">Chưa có dữ liệu trong khoảng lọc.
                            </div>
                        @endforelse

                        @if (count($storeRest) > 0)
                            <div class="collapse" id="collapseStores">
                                @foreach ($storeRest as $row)
                                    <div class="store-item py-2 border-bottom">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <div class="store-icon"
                                                style="background-color: #e8f5e9; color: #2ec866; width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                <i class="fas fa-store" style="font-size: 12px;"></i>
                                            </div>
                                            <div class="store-name fw-bold text-dark" style="font-size: 12px; line-height: 1.3;">
                                                {{ $row['label'] }}</div>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <div style="font-size: 13px; font-weight: 700;">
                                                    {{ $number($row['eligible_tickets']) }}</div>
                                                <div style="font-size: 9px; color: #64748b; font-weight: 500;">Ticket hợp lệ</div>
                                            </div>
                                            <div class="text-end">
                                                <div class="text-primary" style="font-size: 13px; font-weight: 700;">
                                                    {{ $money($row['income']) }}</div>
                                                <div style="font-size: 9px; color: #64748b; font-weight: 500;">Thu nhập tạm tính
                                                </div>
                                            </div>
                                        </div>
                                        @if ((float) $row['held_amount'] > 0 || (float) $row['cancelled_amount'] > 0)
                                            <div class="text-end mt-1" style="font-size: 10px;">
                                                @if ((float) $row['held_amount'] > 0)
                                                    <span class="text-danger me-2">Treo: {{ $money($row['held_amount']) }}</span>
                                                @endif
                                                @if ((float) $row['cancelled_amount'] > 0)
                                                    <span class="text-danger">Hủy: {{ $money($row['cancelled_amount']) }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Theo loại ticket -->
            <div class="mobile-section-card">
                <div class="mobile-section-header">
                    <span class="mobile-section-title">Theo loại ticket</span>
                    @if (count($ticketTypeRest) > 0)
                        <a class="mobile-section-link collapsed" data-bs-toggle="collapse" href="#collapseTicketTypes"
                            role="button" aria-expanded="false" aria-controls="collapseTicketTypes"
                            data-text-toggle="TicketTypes">
                            Xem thêm <i class="fas fa-chevron-down ms-1"></i>
                        </a>
                    @endif
                </div>
                <div class="mobile-section-body">
                    <div class="ticket-type-list">
                        @forelse ($ticketTypeTop as $row)
                            <div class="ticket-type-item d-flex align-items-center justify-content-between py-2 border-bottom">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="ticket-icon"
                                        style="background-color: #e3f2fd; color: #1a73e8; width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                        <i class="fas fa-ticket-alt" style="font-size: 12px;"></i>
                                    </div>
                                    <div>
                                        <div class="ticket-code fw-bold text-dark" style="font-size: 12px;">
                                            {{ $row['ticket_type_code'] }}</div>
                                        <div class="ticket-fee text-muted" style="font-size: 9px; font-weight: 500;">
                                            {{ $row['fee_label'] }}</div>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="ticket-count fw-bold" style="font-size: 12px;">
                                        {{ $number($row['eligible_tickets']) }} tkt</div>
                                    <div class="ticket-income text-primary fw-bold" style="font-size: 12px;">
                                        {{ $money($row['income']) }}</div>
                                    @if ((float) $row['held_amount'] > 0 || (float) $row['cancelled_amount'] > 0)
                                        <div class="text-end mt-1 text-danger" style="font-size: 9px;">
                                            @if ((float) $row['held_amount'] > 0)
                                                <span class="me-1">Treo: {{ $money($row['held_amount']) }}</span>
                                            @endif
                                            @if ((float) $row['cancelled_amount'] > 0)
                                                <span>Hủy: {{ $money($row['cancelled_amount']) }}</span>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="text-center text-muted py-3" style="font-size: 12px;">Chưa có dữ liệu trong khoảng lọc.
                            </div>
                        @endforelse

                        @if (count($ticketTypeRest) > 0)
                            <div class="collapse" id="collapseTicketTypes">
                                @foreach ($ticketTypeRest as $row)
                                    <div
                                        class="ticket-type-item d-flex align-items-center justify-content-between py-2 border-bottom">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="ticket-icon"
                                                style="background-color: #e3f2fd; color: #1a73e8; width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                <i class="fas fa-ticket-alt" style="font-size: 12px;"></i>
                                            </div>
                                            <div>
                                                <div class="ticket-code fw-bold text-dark" style="font-size: 12px;">
                                                    {{ $row['ticket_type_code'] }}</div>
                                                <div class="ticket-fee text-muted" style="font-size: 9px; font-weight: 500;">
                                                    {{ $row['fee_label'] }}</div>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <div class="ticket-count fw-bold" style="font-size: 12px;">
                                                {{ $number($row['eligible_tickets']) }} tkt</div>
                                            <div class="ticket-income text-primary fw-bold" style="font-size: 12px;">
                                                {{ $money($row['income']) }}</div>
                                            @if ((float) $row['held_amount'] > 0 || (float) $row['cancelled_amount'] > 0)
                                                <div class="text-end mt-1 text-danger" style="font-size: 9px;">
                                                    @if ((float) $row['held_amount'] > 0)
                                                        <span class="me-1">Treo: {{ $money($row['held_amount']) }}</span>
                                                    @endif
                                                    @if ((float) $row['cancelled_amount'] > 0)
                                                        <span>Hủy: {{ $money($row['cancelled_amount']) }}</span>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Theo trạng thái -->
            <div class="mobile-section-card">
                <div class="mobile-section-header">
                    <span class="mobile-section-title">Theo trạng thái</span>
                    @if (count($statusRest) > 0)
                        <a class="mobile-section-link collapsed" data-bs-toggle="collapse" href="#collapseStatuses"
                            role="button" aria-expanded="false" aria-controls="collapseStatuses" data-text-toggle="Statuses">
                            Xem thêm <i class="fas fa-chevron-down ms-1"></i>
                        </a>
                    @endif
                </div>
                <div class="mobile-section-body">
                    <div class="status-list">
                        @forelse ($statusTop as $row)
                            <div
                                class="status-breakdown-item d-flex align-items-center justify-content-between py-2 border-bottom">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="status-dot"
                                        style="width: 8px; height: 8px; border-radius: 50%; display: inline-block; background-color: {{ $statusDotColor($row['label']) }};"></span>
                                    <span class="status-name fw-semibold text-dark"
                                        style="font-size: 12px;">{{ $row['label'] }}</span>
                                </div>
                                <div class="text-end">
                                    <div class="status-count text-muted" style="font-size: 10px; font-weight: 500;">Hợp lệ:
                                        {{ $number($row['eligible_tickets']) }} / {{ $number($row['total_tickets']) }}</div>
                                    <div class="status-income fw-bold text-dark" style="font-size: 12px;">
                                        {{ $money($row['income']) }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="text-center text-muted py-3" style="font-size: 12px;">Chưa có dữ liệu trong khoảng lọc.
                            </div>
                        @endforelse

                        @if (count($statusRest) > 0)
                            <div class="collapse" id="collapseStatuses">
                                @foreach ($statusRest as $row)
                                    <div
                                        class="status-breakdown-item d-flex align-items-center justify-content-between py-2 border-bottom">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="status-dot"
                                                style="width: 8px; height: 8px; border-radius: 50%; display: inline-block; background-color: {{ $statusDotColor($row['label']) }};"></span>
                                            <span class="status-name fw-semibold text-dark"
                                                style="font-size: 12px;">{{ $row['label'] }}</span>
                                        </div>
                                        <div class="text-end">
                                            <div class="status-count text-muted" style="font-size: 10px; font-weight: 500;">Hợp lệ:
                                                {{ $number($row['eligible_tickets']) }} / {{ $number($row['total_tickets']) }}</div>
                                            <div class="status-income fw-bold text-dark" style="font-size: 12px;">
                                                {{ $money($row['income']) }}</div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Theo kỳ -->
            <div class="mobile-section-card">
                <div class="mobile-section-header">
                    <span class="mobile-section-title">Theo kỳ đối soát</span>
                    @if (count($periodRest) > 0)
                        <a class="mobile-section-link collapsed" data-bs-toggle="collapse" href="#collapsePeriods" role="button"
                            aria-expanded="false" aria-controls="collapsePeriods" data-text-toggle="Periods">
                            Xem thêm <i class="fas fa-chevron-down ms-1"></i>
                        </a>
                    @endif
                </div>
                <div class="mobile-section-body">
                    <div class="period-list">
                        @forelse ($periodTop as $row)
                            <div class="period-breakdown-item py-2 border-bottom">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="period-dot"
                                        style="width: 8px; height: 8px; border-radius: 50%; display: inline-block; background-color: {{ $periodStatusDotColor($row['status']) }};"></span>
                                    <span class="period-label fw-bold text-dark"
                                        style="font-size: 12px;">{{ $row['label'] }}</span>
                                </div>
                                <div class="period-sub-row d-flex align-items-center justify-content-between ps-3 text-muted"
                                    style="font-size: 11px;">
                                    <span><i class="fas fa-angle-right me-1" style="font-size: 9px;"></i> {{ $row['status'] }}
                                        ({{ $row['eligible_tickets'] }} tkt)</span>
                                    <span class="fw-bold text-dark">{{ $money($row['final_income']) }}</span>
                                </div>
                            </div>
                        @empty
                            <div class="text-center text-muted py-3" style="font-size: 12px;">Chưa có dữ liệu trong khoảng lọc.
                            </div>
                        @endforelse

                        @if (count($periodRest) > 0)
                            <div class="collapse" id="collapsePeriods">
                                @foreach ($periodRest as $row)
                                    <div class="period-breakdown-item py-2 border-bottom">
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="period-dot"
                                                style="width: 8px; height: 8px; border-radius: 50%; display: inline-block; background-color: {{ $periodStatusDotColor($row['status']) }};"></span>
                                            <span class="period-label fw-bold text-dark"
                                                style="font-size: 12px;">{{ $row['label'] }}</span>
                                        </div>
                                        <div class="period-sub-row d-flex align-items-center justify-content-between ps-3 text-muted"
                                            style="font-size: 11px;">
                                            <span><i class="fas fa-angle-right me-1" style="font-size: 9px;"></i>
                                                {{ $row['status'] }} ({{ $row['eligible_tickets'] }} tkt)</span>
                                            <span class="fw-bold text-dark">{{ $money($row['final_income']) }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    @section('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                // Filter collapse toggle indicator
                const filterPanel = document.getElementById('mobileFilterPanel');
                const toggleText = document.querySelector('.filter-toggle-text');
                if (filterPanel && toggleText) {
                    filterPanel.addEventListener('hide.bs.collapse', function () {
                        toggleText.innerHTML = 'Mở rộng <i class="fas fa-chevron-down ms-1"></i>';
                    });
                    filterPanel.addEventListener('show.bs.collapse', function () {
                        toggleText.innerHTML = 'Thu gọn <i class="fas fa-chevron-up ms-1"></i>';
                    });
                }

                // Section Xem thêm toggles
                document.querySelectorAll('.mobile-section-link').forEach(link => {
                    const type = link.getAttribute('data-text-toggle');
                    const collapseEl = document.getElementById('collapse' + type);
                    if (collapseEl) {
                        collapseEl.addEventListener('hide.bs.collapse', function () {
                            link.innerHTML = 'Xem thêm <i class="fas fa-chevron-down ms-1"></i>';
                        });
                        collapseEl.addEventListener('show.bs.collapse', function () {
                            link.innerHTML = 'Thu gọn <i class="fas fa-chevron-up ms-1"></i>';
                        });
                    }
                });
            });
        </script>
    @endsection
@endsection
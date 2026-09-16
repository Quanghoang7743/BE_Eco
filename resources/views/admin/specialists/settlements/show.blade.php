@extends('admin.layout')

@section('title', 'Chi tiết đối soát chuyên viên')

@section('content')
    @php
        $money = fn ($value) => number_format((float) $value, 0, ',', '.') . ' đ';
        $number = fn ($value) => number_format((int) $value, 0, ',', '.');
        $userName = function ($user): string {
            if (! $user) {
                return 'N/A';
            }

            return $user->full_name ?: ($user->name ?: ($user->email ?: 'N/A'));
        };
        $statusClass = fn (string $status): string => match ($status) {
            'DRAFT' => 'secondary',
            'WAITING_APPROVAL' => 'warning text-dark',
            'APPROVED' => 'info',
            'PAID' => 'success',
            'CANCELLED' => 'danger',
            default => 'light text-dark',
        };
        $periodText = optional($settlement->period_from)->format('d/m/Y') . ' - ' . optional($settlement->period_to)->format('d/m/Y');
        
        $translateTicketStatus = fn ($status) => match ($status) {
            'AVAILABLE' => 'Chưa sử dụng',
            'USED' => 'Đã sử dụng',
            'EXPIRED' => 'Hết hạn',
            default => $status ?: 'N/A',
        };
        
        $translateEarningStatus = fn ($status) => match (strtolower((string)$status)) {
            'draft' => 'Nháp',
            'eligible' => 'Hợp lệ',
            'calculated' => 'Đã tính',
            'paid' => 'Đã trả',
            default => $status ?: 'N/A',
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

        /* Responsive Layouts */
        @media (max-width: 767.98px) {
            .desktop-layout {
                display: none !important;
            }
            .mobile-layout {
                display: block !important;
            }
            body, .main-panel, .page-inner {
                background-color: #f8fafc !important;
            }
            .page-inner {
                padding: 12px 12px !important;
            }
            .container-fluid {
                padding-left: 0 !important;
                padding-right: 0 !important;
            }
            .custom-template, .custom-toggle {
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

        /* Scoped page wrapper */
        .recon-page-wrapper {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--recon-text);
            padding-bottom: 30px;
        }

        /* Top Line Breadcrumb */
        .recon-topline {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 16px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--recon-border);
        }
        .recon-breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--recon-muted);
            font-size: 13.5px;
            font-weight: 500;
        }
        .recon-breadcrumb a {
            color: var(--recon-text);
            text-decoration: none;
            font-weight: 600;
        }
        .recon-breadcrumb a:hover {
            color: var(--recon-primary);
        }
        .recon-breadcrumb-separator {
            color: #cbd5e1;
        }
        .recon-title-wrapper h1 {
            font-size: 24px;
            font-weight: 800;
            color: var(--recon-text);
            margin: 0;
        }
        .recon-title-wrapper .subtitle {
            font-size: 13.5px;
            color: var(--recon-muted);
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* Card styles */
        .recon-card {
            background: #ffffff;
            border: 1px solid var(--recon-border);
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03), 0 2px 4px -1px rgba(0, 0, 0, 0.015);
            transition: all 0.2s ease;
            margin-bottom: 24px;
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
        .recon-card .card-header i {
            color: var(--recon-muted);
        }
        .recon-card .card-body {
            padding: 20px;
        }

        /* KPI grid styling */
        .recon-kpi-card {
            background: #ffffff;
            border: 1px solid var(--recon-border);
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);
            display: flex;
            align-items: center;
            gap: 14px;
            height: 100%;
        }
        .kpi-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .kpi-content {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }
        .kpi-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--recon-muted);
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .kpi-value {
            font-size: 18px;
            font-weight: 800;
            color: var(--recon-text);
            word-break: break-word;
        }

        .icon-box-blue {
            background-color: var(--recon-primary-light);
            color: var(--recon-primary);
        }
        .icon-box-purple {
            background-color: #f3e8ff;
            color: #6b21a8;
        }
        .icon-box-green {
            background-color: var(--recon-success-light);
            color: var(--recon-success);
        }
        .icon-box-orange {
            background-color: var(--recon-warning-light);
            color: var(--recon-warning);
        }

        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 700;
            line-height: 1.2;
            white-space: nowrap;
        }
        .status-badge-blue {
            background-color: var(--recon-primary-light);
            color: var(--recon-primary);
        }
        .status-badge-green {
            background-color: var(--recon-success-light);
            color: var(--recon-success);
        }
        .status-badge-gray {
            background-color: #f1f5f9;
            color: var(--recon-muted);
        }
        .status-badge-orange {
            background-color: var(--recon-warning-light);
            color: #b45309;
        }
        .status-badge-red {
            background-color: var(--recon-danger-light);
            color: var(--recon-danger);
        }

        /* User detail info grid */
        .detail-info-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .detail-info-icon {
            font-size: 14px;
            color: var(--recon-muted);
            margin-top: 3px;
        }
        .detail-info-content {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .detail-info-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--recon-muted);
            text-transform: uppercase;
        }
        .detail-info-val {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--recon-text);
        }
        .detail-info-sub {
            font-size: 11px;
            color: var(--recon-muted);
        }

        .note-box {
            background-color: #f8fafc;
            border: 1px solid var(--recon-border);
            border-radius: 8px;
            padding: 14px 16px;
            font-size: 13.5px;
            color: #334155;
            white-space: pre-wrap;
            min-height: 80px;
        }

        .alert-info-box {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 13px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .alert-info-box i {
            font-size: 16px;
            margin-top: 2px;
        }

        /* Table styles */
        .settlement-detail-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .settlement-detail-table th {
            background-color: #f8fafc !important;
            color: var(--recon-muted) !important;
            font-weight: 700 !important;
            text-transform: uppercase;
            font-size: 11px !important;
            letter-spacing: 0.05em;
            padding: 14px 16px !important;
            border-bottom: 2px solid var(--recon-border) !important;
            border-top: none !important;
            white-space: nowrap;
        }
        .settlement-detail-table td {
            padding: 14px 16px !important;
            border-bottom: 1px solid var(--recon-border) !important;
            font-size: 13px;
            color: #334155;
            vertical-align: middle;
        }
        .settlement-detail-table tr:hover td {
            background-color: var(--recon-primary-light) !important;
        }

        .dot-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
            flex-shrink: 0;
        }
        .dot-indicator-green {
            background-color: var(--recon-success);
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
        }
        .dot-indicator-red {
            background-color: var(--recon-danger);
            box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2);
        }
        .dot-indicator-gray {
            background-color: var(--recon-muted);
        }

        /* Mobile layout styling */
        .mobile-layout {
            padding: 4px 0;
        }
        .mobile-header {
            background: #ffffff;
            padding: 14px 12px;
            margin: -12px -12px 16px -12px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .mobile-back-btn {
            color: #0f172a !important;
            font-size: 18px;
            text-decoration: none !important;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .mobile-header-info {
            display: flex;
            flex-direction: column;
        }
        .mobile-header-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
            line-height: 1.2;
        }
        .mobile-header-subtitle {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }
        .mobile-metrics-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        .mobile-metric-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 12px;
            border: 1px solid #f1f5f9;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.02), 0 2px 4px rgba(0, 0, 0, 0.01);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .mobile-metric-icon-box {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .mobile-metric-content {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }
        .mobile-metric-label {
            font-size: 10px;
            color: #64748b;
            font-weight: 500;
        }
        .mobile-metric-value {
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
            word-break: break-all;
        }
        .mobile-section-card {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #f1f5f9;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.02), 0 2px 4px rgba(0, 0, 0, 0.01);
            margin-bottom: 16px;
            overflow: hidden;
        }
        .mobile-section-header {
            padding: 12px;
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .mobile-section-header i {
            color: #64748b;
        }
        .mobile-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
        }
        .mobile-info-cell {
            padding: 12px;
        }
        .mobile-info-cell:nth-child(odd) {
            border-right: 1px solid #f1f5f9;
        }
        .mobile-info-cell:nth-child(-n+2) {
            border-bottom: 1px solid #f1f5f9;
        }
        .mobile-info-cell.full-width {
            grid-column: span 2;
            border-top: 1px solid #f1f5f9;
        }
        .mobile-info-label {
            font-size: 10px;
            color: #64748b;
            margin-bottom: 4px;
        }
        .mobile-info-value {
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
        }
        .mobile-ticket-card {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #f1f5f9;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.02), 0 2px 4px rgba(0, 0, 0, 0.01);
            margin-bottom: 12px;
            padding: 12px;
        }
        .mobile-ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .mobile-ticket-title {
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .mobile-ticket-title i {
            color: #1a73e8;
        }
        .mobile-ticket-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 10px;
        }
        .mobile-ticket-cell {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .mobile-ticket-cell.full-width {
            grid-column: span 2;
        }
        .mobile-ticket-label {
            font-size: 9px;
            color: #64748b;
        }
        .mobile-ticket-value {
            font-size: 11px;
            font-weight: 600;
            color: #0f172a;
            word-break: break-all;
        }
        .mobile-ticket-footer-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            padding-top: 10px;
            border-top: 1px dashed #f1f5f9;
            text-align: center;
        }
        .mobile-ticket-footer-col {
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 0 2px;
        }
        .mobile-ticket-footer-col:not(:last-child) {
            border-right: 1px solid #f1f5f9;
        }
        .mobile-ticket-footer-label {
            font-size: 9px;
            color: #64748b;
        }
        .mobile-ticket-footer-value {
            font-size: 11px;
            font-weight: 700;
            color: #0f172a;
        }
    </style>

    <div class="container-fluid recon-page-wrapper">
        <!-- Desktop Layout -->
        <div class="desktop-layout">
            <div class="recon-topline">
                <div class="recon-breadcrumb">
                    <a href="#">Quản trị</a>
                    <span class="recon-breadcrumb-separator">/</span>
                    <a href="{{ route('admin.users.sales') }}">Nhân sự cửa hàng</a>
                    <span class="recon-breadcrumb-separator">/</span>
                    <a href="{{ route('admin.specialist-settlements.index') }}">Đối soát chuyên viên</a>
                    <span class="recon-breadcrumb-separator">/</span>
                    <span class="active">Chi tiết kỳ đối soát</span>
                </div>
                <div>
                    <a href="{{ route('admin.specialist-settlements.index') }}" class="btn btn-recon-outline btn-sm">
                        <i class="fas fa-arrow-left me-1"></i> Quay lại
                    </a>
                </div>
            </div>
            
            <div class="recon-title-wrapper mb-4">
                <h1>Chi tiết kỳ đối soát</h1>
                <div class="subtitle">
                    <span><i class="far fa-calendar-alt me-1 text-muted"></i> {{ $periodText }}</span>
                    <span><i class="far fa-user me-1 text-muted"></i> {{ $userName($settlement->specialist) }}</span>
                </div>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <!-- Desktop Grid Summary -->
        <div class="desktop-layout">
            <div class="row g-3 mb-4">
                <div class="col-xl-2 col-md-4 col-sm-6 col-6">
                    <div class="recon-kpi-card">
                        <div class="kpi-icon-box icon-box-blue">
                            <i class="far fa-file-alt"></i>
                        </div>
                        <div class="kpi-content">
                            <div class="kpi-label">Ticket hợp lệ</div>
                            <div class="kpi-value">{{ $number($settlement->total_eligible_tickets) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6 col-6">
                    <div class="recon-kpi-card">
                        <div class="kpi-icon-box icon-box-purple">
                            <i class="fas fa-calculator"></i>
                        </div>
                        <div class="kpi-content">
                            <div class="kpi-label">Phí bình quân</div>
                            <div class="kpi-value">{{ $money($settlement->fee_per_ticket) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6 col-6">
                    <div class="recon-kpi-card">
                        <div class="kpi-icon-box icon-box-green">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <div class="kpi-content">
                            <div class="kpi-label">Tổng thu nhập</div>
                            <div class="kpi-value">{{ $money($settlement->gross_income) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6 col-6">
                    <div class="recon-kpi-card">
                        <div class="kpi-icon-box icon-box-orange">
                            <i class="fas fa-sliders-h"></i>
                        </div>
                        <div class="kpi-content">
                            <div class="kpi-label">Điều chỉnh</div>
                            <div class="kpi-value {{ (float) $settlement->adjustment_amount < 0 ? 'text-danger' : ((float) $settlement->adjustment_amount > 0 ? 'text-success' : '') }}">
                                {{ $money($settlement->adjustment_amount) }}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6 col-6">
                    <div class="recon-kpi-card">
                        <div class="kpi-icon-box icon-box-blue">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="kpi-content">
                            <div class="kpi-label">Chốt cuối</div>
                            <div class="kpi-value text-primary">{{ $money($settlement->final_income) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6 col-6">
                    <div class="recon-kpi-card">
                        <div class="kpi-icon-box icon-box-green">
                            <i class="far fa-check-circle"></i>
                        </div>
                        <div class="kpi-content">
                            <div class="kpi-label">Trạng thái</div>
                            <div class="kpi-value">
                                <span class="status-badge status-badge-{{ strtolower($settlement->status) }}">
                                    {{ $statusLabels[$settlement->status] ?? $settlement->status }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-xl-8 col-lg-8 col-12">
                    <div class="card recon-card h-100 mb-0">
                        <div class="card-header bg-white fw-bold">
                            <i class="far fa-calendar-alt"></i> Thông tin kỳ
                        </div>
                        <div class="card-body">
                            <div class="row g-4">
                                <div class="col-md-4 col-12">
                                    <div class="detail-info-row">
                                        <div class="detail-info-icon"><i class="far fa-user-circle"></i></div>
                                        <div class="detail-info-content">
                                            <div class="detail-info-label">Người tạo</div>
                                            <div class="detail-info-val text-dark">{{ $userName($settlement->creator) }}</div>
                                            @if ($settlement->created_at)
                                                <div class="detail-info-sub"><i class="far fa-clock me-1"></i> {{ $settlement->created_at->format('d/m/Y H:i') }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 col-12">
                                    <div class="detail-info-row">
                                        <div class="detail-info-icon"><i class="far fa-check-circle"></i></div>
                                        <div class="detail-info-content">
                                            <div class="detail-info-label">Người duyệt</div>
                                            <div class="detail-info-val text-dark">{{ $userName($settlement->approver) }}</div>
                                            @if ($settlement->approved_at)
                                                <div class="detail-info-sub"><i class="far fa-clock me-1"></i> {{ $settlement->approved_at->format('d/m/Y H:i') }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 col-12">
                                    <div class="detail-info-row">
                                        <div class="detail-info-icon"><i class="fas fa-hand-holding-usd"></i></div>
                                        <div class="detail-info-content">
                                            <div class="detail-info-label">Người thanh toán</div>
                                            <div class="detail-info-val text-dark">{{ $userName($settlement->payer) }}</div>
                                            @if ($settlement->paid_at)
                                                <div class="detail-info-sub"><i class="far fa-clock me-1"></i> {{ $settlement->paid_at->format('d/m/Y H:i') }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 mt-4">
                                    <label class="form-label fw-bold text-dark" style="font-size: 13px;">Ghi chú / lý do điều chỉnh</label>
                                    <div class="note-box">{{ $settlement->note ?: ($settlement->notes ?: 'N/A') }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @if ($canManageSettlements)
                    <div class="col-xl-4 col-lg-4 col-12">
                        <div class="card recon-card h-100 mb-0">
                            <div class="card-header bg-white fw-bold">
                                <i class="fas fa-tools"></i> Thao tác đối soát
                            </div>
                            <div class="card-body">
                                <div class="d-flex flex-column gap-3">
                                    @if (in_array((string) $settlement->status, ['DRAFT', 'WAITING_APPROVAL'], true))
                                        <form method="POST" action="{{ route('admin.specialist-settlements.approve', $settlement->id) }}" class="w-100 m-0">
                                            @csrf
                                            @method('PATCH')
                                            <button class="btn btn-success w-100 fw-semibold" type="submit" onclick="return confirm('Duyệt kỳ đối soát này?')">
                                                <i class="fas fa-check me-1"></i> Duyệt đối soát
                                            </button>
                                        </form>
                                    @endif
                                    @if ((string) $settlement->status === 'APPROVED')
                                        <form method="POST" action="{{ route('admin.specialist-settlements.paid', $settlement->id) }}" class="w-100 m-0">
                                            @csrf
                                            @method('PATCH')
                                            <button class="btn btn-success w-100 fw-semibold" type="submit" onclick="return confirm('Đánh dấu kỳ này đã thanh toán?')">
                                                <i class="fas fa-money-bill-wave me-1"></i> Đã thanh toán
                                            </button>
                                        </form>
                                    @endif
                                </div>

                                @if (in_array((string) $settlement->status, ['DRAFT', 'WAITING_APPROVAL'], true))
                                    <form method="POST" action="{{ route('admin.specialist-settlements.adjust', $settlement->id) }}" class="row g-3 mt-2">
                                        @csrf
                                        <div class="col-12">
                                            <label class="form-label fw-semibold">Điều chỉnh cộng / trừ</label>
                                            <input type="number" name="adjustment_amount" class="form-control" step="1" placeholder="+1000 hoặc -1000" required>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fw-semibold">Lý do điều chỉnh</label>
                                            <textarea name="adjustment_note" class="form-control" rows="3" placeholder="Nhập lý do điều chỉnh..." required></textarea>
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-recon-primary w-100" type="submit">Lưu điều chỉnh</button>
                                        </div>
                                    </form>
                                @else
                                    <div class="alert-info-box mt-3">
                                        <i class="fas fa-info-circle"></i>
                                        <span>Kỳ đã duyệt/thanh toán không cho sửa điều chỉnh để giữ lịch sử đối soát.</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="card recon-card">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-ticket-alt"></i> Ticket và điều chỉnh trong kỳ
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover align-middle mb-0 settlement-detail-table">
                        <thead>
                            <tr>
                                <th>Ticket</th>
                                <th>Loại ticket</th>
                                <th>Đơn hàng</th>
                                <th>Cửa hàng</th>
                                <th>Trạng thái ticket</th>
                                <th>Trạng thái thu nhập</th>
                                <th class="text-end">Phí</th>
                                <th class="text-end">Thu nhập</th>
                                <th>Lý do</th>
                                <th>Ngày phát sinh</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($detailRows as $row)
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="dot-indicator @if (! empty($row->adjustment_source_earning_id)) dot-indicator-red @elseif ((bool) $row->is_eligible) dot-indicator-green @else dot-indicator-gray @endif"></span>
                                            <div class="fw-bold text-dark">{{ $row->ticket_code ?: ($row->ticket_id ?: 'N/A') }}</div>
                                        </div>
                                        @if (! empty($row->adjustment_source_earning_id))
                                            <small class="badge bg-danger mt-1">Adjustment âm</small>
                                        @elseif ((bool) $row->is_eligible)
                                            <small class="badge bg-success mt-1">Hợp lệ</small>
                                        @endif
                                    </td>
                                    <td>{{ $row->ticket_type_code ?: 'TICKET_DEFAULT' }}</td>
                                    <td>{{ $row->order_code ?: ($row->order_id ?: 'N/A') }}</td>
                                    <td>
                                        <div class="fw-semibold text-dark">{{ $row->store_name ?: 'N/A' }}</div>
                                        <small class="text-muted">{{ $row->store_code ?: '' }}</small>
                                    </td>
                                    <td>
                                        <span class="status-badge @if($row->ticket_status==='AVAILABLE') status-badge-blue @elseif($row->ticket_status==='USED') status-badge-green @else status-badge-gray @endif">
                                            {{ $translateTicketStatus($row->ticket_status) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge @if(strtolower((string)$row->earning_status ?: $row->status)==='paid'||strtolower((string)$row->earning_status ?: $row->status)==='eligible') status-badge-green @else status-badge-gray @endif">
                                            {{ $translateEarningStatus($row->earning_status ?: $row->status) }}
                                        </span>
                                    </td>
                                    <td class="text-end text-muted">{{ $money($row->fee_per_ticket) }}</td>
                                    <td class="text-end fw-bold {{ (float) $row->earning_amount < 0 ? 'text-danger' : 'text-success' }}">
                                        {{ $money($row->earning_amount) }}
                                    </td>
                                    <td style="max-width: 250px; font-size: 12.5px; color: var(--recon-muted);">{{ $row->reason_not_eligible ?: 'N/A' }}</td>
                                    <td>
                                        @php
                                            $rowDate = $row->generated_at ?: ($row->earned_at ?: null);
                                        @endphp
                                        <span class="text-muted" style="font-size: 12px;">{{ $rowDate ? \Carbon\Carbon::parse($rowDate)->format('d/m/Y H:i') : 'N/A' }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-5">
                                        <i class="far fa-folder-open d-block mb-2 text-muted" style="font-size: 24px;"></i>
                                        Kỳ này chưa có ticket hoặc adjustment nào.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Mobile Layout -->
        <div class="mobile-layout">
            <!-- Mobile Sticky Header -->
            <div class="mobile-header">
                <a href="{{ route('admin.specialist-settlements.index') }}" class="mobile-back-btn">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div class="mobile-header-info">
                    <h1 class="mobile-header-title">Chi tiết kỳ đối soát</h1>
                    <span class="mobile-header-subtitle">{{ $periodText }} - {{ $userName($settlement->specialist) }}</span>
                </div>
            </div>

            <!-- Overview Metrics Grid (2 columns) -->
            <div class="mobile-metrics-grid">
                <!-- Ticket hợp lệ -->
                <div class="mobile-metric-card">
                    <div class="mobile-metric-icon-box icon-box-blue">
                        <i class="far fa-file-alt"></i>
                    </div>
                    <div class="mobile-metric-content">
                        <span class="mobile-metric-label">Ticket hợp lệ</span>
                        <span class="mobile-metric-value">{{ $number($settlement->total_eligible_tickets) }}</span>
                    </div>
                </div>

                <!-- Phí bình quân / ticket -->
                <div class="mobile-metric-card">
                    <div class="mobile-metric-icon-box icon-box-purple">
                        <i class="fas fa-calculator"></i>
                    </div>
                    <div class="mobile-metric-content">
                        <span class="mobile-metric-label">Phí bình quân</span>
                        <span class="mobile-metric-value">{{ $money($settlement->fee_per_ticket) }}</span>
                    </div>
                </div>

                <!-- Gross income -->
                <div class="mobile-metric-card">
                    <div class="mobile-metric-icon-box icon-box-green">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="mobile-metric-content">
                        <span class="mobile-metric-label">Tổng thu nhập</span>
                        <span class="mobile-metric-value">{{ $money($settlement->gross_income) }}</span>
                    </div>
                </div>

                <!-- Điều chỉnh -->
                <div class="mobile-metric-card">
                    <div class="mobile-metric-icon-box icon-box-orange">
                        <i class="fas fa-sliders-h"></i>
                    </div>
                    <div class="mobile-metric-content">
                        <span class="mobile-metric-label">Điều chỉnh</span>
                        <span class="mobile-metric-value {{ (float) $settlement->adjustment_amount < 0 ? 'text-danger' : ((float) $settlement->adjustment_amount > 0 ? 'text-success' : '') }}">
                            {{ $money($settlement->adjustment_amount) }}
                        </span>
                    </div>
                </div>

                <!-- Chốt cuối -->
                <div class="mobile-metric-card">
                    <div class="mobile-metric-icon-box icon-box-blue">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="mobile-metric-content">
                        <span class="mobile-metric-label">Chốt cuối</span>
                        <span class="mobile-metric-value text-primary">{{ $money($settlement->final_income) }}</span>
                    </div>
                </div>

                <!-- Trạng thái -->
                <div class="mobile-metric-card">
                    <div class="mobile-metric-icon-box icon-box-green">
                        <i class="far fa-check-circle"></i>
                    </div>
                    <div class="mobile-metric-content">
                        <span class="mobile-metric-label">Trạng thái</span>
                        <span class="mobile-metric-value">
                            <span class="status-badge status-badge-{{ strtolower($settlement->status) }}" style="padding: 2px 8px; font-size: 10px;">
                                {{ $statusLabels[$settlement->status] ?? $settlement->status }}
                            </span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Thông tin kỳ Card -->
            <div class="mobile-section-card">
                <div class="mobile-section-header">
                    <i class="far fa-calendar-alt"></i>
                    <span>Thông tin kỳ</span>
                </div>
                <div class="mobile-info-grid">
                    <div class="mobile-info-cell">
                        <div class="mobile-info-label">Người tạo</div>
                        <div class="mobile-info-value">{{ $userName($settlement->creator) }}</div>
                        @if ($settlement->created_at)
                            <div class="text-muted" style="font-size: 9px; font-weight: 500;">{{ $settlement->created_at->format('d/m/Y H:i') }}</div>
                        @endif
                    </div>
                    <div class="mobile-info-cell">
                        <div class="mobile-info-label">Người duyệt</div>
                        <div class="mobile-info-value">{{ $userName($settlement->approver) }}</div>
                        @if ($settlement->approved_at)
                            <div class="text-muted" style="font-size: 9px; font-weight: 500;">{{ $settlement->approved_at->format('d/m/Y H:i') }}</div>
                        @endif
                    </div>
                    <div class="mobile-info-cell">
                        <div class="mobile-info-label">Người thanh toán</div>
                        <div class="mobile-info-value">{{ $userName($settlement->payer) }}</div>
                        @if ($settlement->paid_at)
                            <div class="text-muted" style="font-size: 9px; font-weight: 500;">{{ $settlement->paid_at->format('d/m/Y H:i') }}</div>
                        @endif
                    </div>
                    <div class="mobile-info-cell full-width">
                        <div class="mobile-info-label">Ghi chú / lý do điều chỉnh</div>
                        <div class="mobile-info-value" style="font-size: 11px; font-weight: 500; word-break: break-word; white-space: pre-wrap;">{{ $settlement->note ?: ($settlement->notes ?: 'N/A') }}</div>
                    </div>
                </div>
            </div>

            <!-- Thao tác đối soát Card (Admin Only) -->
            @if ($canManageSettlements)
                <div class="mobile-section-card">
                    <div class="mobile-section-header">
                        <i class="fas fa-tools"></i>
                        <span>Thao tác đối soát</span>
                    </div>
                    <div class="p-3">
                        <div class="d-flex flex-column gap-2 mb-3">
                            @if (in_array((string) $settlement->status, ['DRAFT', 'WAITING_APPROVAL'], true))
                                <form method="POST" action="{{ route('admin.specialist-settlements.approve', $settlement->id) }}" class="w-100 m-0">
                                    @csrf
                                    @method('PATCH')
                                    <button class="btn btn-success w-100 fw-semibold" type="submit" onclick="return confirm('Duyệt kỳ đối soát này?')">
                                        <i class="fas fa-check me-1"></i> Duyệt đối soát
                                    </button>
                                </form>
                            @endif
                            @if ((string) $settlement->status === 'APPROVED')
                                <form method="POST" action="{{ route('admin.specialist-settlements.paid', $settlement->id) }}" class="w-100 m-0">
                                    @csrf
                                    @method('PATCH')
                                    <button class="btn btn-success w-100 fw-semibold" type="submit" onclick="return confirm('Đánh dấu kỳ này đã thanh toán?')">
                                        <i class="fas fa-money-bill-wave me-1"></i> Đã thanh toán
                                    </button>
                                </form>
                            @endif
                        </div>

                        @if (in_array((string) $settlement->status, ['DRAFT', 'WAITING_APPROVAL'], true))
                            <form method="POST" action="{{ route('admin.specialist-settlements.adjust', $settlement->id) }}" class="row g-2 m-0">
                                @csrf
                                <div class="col-12 p-0">
                                    <label class="form-label font-weight-bold" style="font-size: 11px; margin-bottom: 4px;">Điều chỉnh cộng / trừ</label>
                                    <input type="number" name="adjustment_amount" class="form-control form-control-sm" step="1" placeholder="+1000 hoặc -1000" required>
                                </div>
                                <div class="col-12 p-0 mt-2">
                                    <label class="form-label font-weight-bold" style="font-size: 11px; margin-bottom: 4px;">Lý do điều chỉnh</label>
                                    <textarea name="adjustment_note" class="form-control form-control-sm" rows="2" required></textarea>
                                </div>
                                <div class="col-12 p-0 mt-3">
                                    <button class="btn btn-sm btn-primary w-100" type="submit">Lưu điều chỉnh</button>
                                </div>
                            </form>
                        @else
                            <div class="alert-info-box" style="font-size: 11px; padding: 10px;">
                                <i class="fas fa-info-circle"></i>
                                <span>Kỳ đã duyệt/thanh toán không cho sửa điều chỉnh để giữ lịch sử đối soát.</span>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <!-- Ticket List Cards Section -->
            <div class="mobile-section-card">
                <div class="mobile-section-header">
                    <i class="fas fa-ticket-alt"></i>
                    <span>Ticket và điều chỉnh trong kỳ</span>
                </div>
                <div class="p-2" style="background-color: #f8fafc;">
                    @forelse ($detailRows as $row)
                        <div class="mobile-ticket-card">
                            <!-- Ticket Header -->
                            <div class="mobile-ticket-header">
                                <div class="mobile-ticket-title">
                                    <span class="dot-indicator @if (! empty($row->adjustment_source_earning_id)) dot-indicator-red @elseif ((bool) $row->is_eligible) dot-indicator-green @else dot-indicator-gray @endif"></span>
                                    <span>{{ $row->ticket_code ?: ($row->ticket_id ?: 'N/A') }}</span>
                                </div>
                                <div>
                                    @if (! empty($row->adjustment_source_earning_id))
                                        <span class="status-badge status-badge-red" style="padding: 2px 8px; font-size: 9px;">Adjustment âm</span>
                                    @elseif ((bool) $row->is_eligible)
                                        <span class="status-badge status-badge-green" style="padding: 2px 8px; font-size: 9px;">Hợp lệ</span>
                                    @endif
                                </div>
                            </div>

                            <!-- Details Grid -->
                            <div class="mobile-ticket-grid">
                                <div class="mobile-ticket-cell">
                                    <span class="mobile-ticket-label">Loại ticket</span>
                                    <span class="mobile-ticket-value">{{ $row->ticket_type_code ?: 'TICKET_DEFAULT' }}</span>
                                </div>
                                <div class="mobile-ticket-cell">
                                    <span class="mobile-ticket-label">Đơn hàng</span>
                                    <span class="mobile-ticket-value">{{ $row->order_code ?: ($row->order_id ?: 'N/A') }}</span>
                                </div>
                                <div class="mobile-ticket-cell full-width">
                                    <span class="mobile-ticket-label">Cửa hàng</span>
                                    <span class="mobile-ticket-value">{{ $row->store_name ?: 'N/A' }} / <small class="text-muted">{{ $row->store_code ?: '' }}</small></span>
                                </div>
                                <div class="mobile-ticket-cell">
                                    <span class="mobile-ticket-label">Trạng thái ticket</span>
                                    <span class="mobile-ticket-value">{{ $translateTicketStatus($row->ticket_status) }}</span>
                                </div>
                                <div class="mobile-ticket-cell">
                                    <span class="mobile-ticket-label">Trạng thái thu nhập</span>
                                    <span class="mobile-ticket-value">{{ $translateEarningStatus($row->earning_status ?: $row->status) }}</span>
                                </div>
                            </div>

                            <!-- Footer Grid -->
                            <div class="mobile-ticket-footer-grid">
                                <div class="mobile-ticket-footer-col">
                                    <span class="mobile-ticket-footer-label">Phí</span>
                                    <span class="mobile-ticket-footer-value">{{ $money($row->fee_per_ticket) }}</span>
                                </div>
                                <div class="mobile-ticket-footer-col">
                                    <span class="mobile-ticket-footer-label">Thu nhập</span>
                                    <span class="mobile-ticket-footer-value {{ (float) $row->earning_amount < 0 ? 'text-danger' : 'text-success' }}">{{ $money($row->earning_amount) }}</span>
                                </div>
                                <div class="mobile-ticket-footer-col">
                                    <span class="mobile-ticket-footer-label">Lý do</span>
                                    <span class="mobile-ticket-footer-value">{{ $row->reason_not_eligible ?: 'N/A' }}</span>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-muted py-4 bg-white rounded-3 border">
                            Kỳ này chưa có ticket hoặc adjustment nào.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection

@extends('admin.layout')

@section('title', $isSaleUser ? 'Đối soát của tôi' : 'Đối soát chuyên viên')

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
        $periodLabel = fn ($settlement): string => optional($settlement->period_from)->format('d/m/Y') . ' - ' . optional($settlement->period_to)->format('d/m/Y');
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
        }
        .recon-card .card-body {
            padding: 20px;
        }

        /* Table styles */
        .settlement-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .settlement-table th {
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
        .settlement-table td {
            padding: 16px 16px !important;
            border-bottom: 1px solid var(--recon-border) !important;
            font-size: 13px;
            color: #334155;
            vertical-align: middle;
        }
        .settlement-table tr:hover td {
            background-color: var(--recon-primary-light) !important;
        }

        /* Status Pills */
        .status-pill {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 11.5px;
            font-weight: 700;
            line-height: 1.2;
            white-space: nowrap;
        }
        .status-pill-draft {
            background-color: #f3e8ff;
            color: #6b21a8;
        }
        .status-pill-waiting_approval {
            background-color: var(--recon-warning-light);
            color: #92400e;
        }
        .status-pill-approved {
            background-color: var(--recon-info-light);
            color: #1e40af;
        }
        .status-pill-paid {
            background-color: var(--recon-success-light);
            color: #065f46;
        }
        .status-pill-cancelled {
            background-color: var(--recon-danger-light);
            color: #991b1b;
        }

        /* Guide Card */
        .settlement-guide-card {
            background: linear-gradient(135deg, #ffffff 0%, #fcfdfe 100%);
            border: 1px solid var(--recon-border);
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03), 0 2px 4px -1px rgba(0, 0, 0, 0.015);
        }
        .guide-icon-wrapper {
            width: 32px;
            height: 32px;
            background-color: var(--recon-primary-light);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
        }
        .guide-list {
            list-style-type: none;
            padding-left: 0 !important;
        }
        .guide-list li {
            position: relative;
            padding-left: 20px;
            font-size: 13px;
            color: #475569;
            line-height: 1.5;
        }
        .guide-list li::before {
            content: "•";
            position: absolute;
            left: 0;
            top: 0;
            color: var(--recon-primary);
            font-size: 16px;
            font-weight: bold;
        }
        .guide-watermark {
            position: absolute;
            right: -10px;
            bottom: -10px;
            opacity: 0.04;
            pointer-events: none;
            color: var(--recon-primary);
        }

        /* Action Trigger Button */
        .btn-action-trigger {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--recon-muted);
            background: #f8fafc;
            border: 1px solid var(--recon-border);
            transition: all 0.2s ease;
        }
        .btn-action-trigger:hover, .btn-action-trigger[aria-expanded="true"] {
            background: var(--recon-primary-light);
            color: var(--recon-primary);
            border-color: var(--recon-primary-border);
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
            font-size: 13px;
            color: var(--recon-muted);
            margin-top: 4px;
        }

        .recon-record-info {
            font-size: 11px;
            line-height: 1.5;
            color: var(--recon-muted);
        }
        .recon-record-info .label {
            font-weight: 500;
        }
        .recon-record-info .val {
            color: #334155;
            font-weight: 600;
        }

        /* Mobile specific styles from original file */
        .mobile-layout {
            padding: 4px 0;
        }
        .mobile-title {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 16px;
            margin-top: 4px;
        }
        .mobile-tabs-container {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            scrollbar-width: none; /* Firefox */
            padding-bottom: 4px;
        }
        .mobile-tabs-container::-webkit-scrollbar {
            display: none; /* Chrome, Safari, Opera */
        }
        .mobile-tab-btn {
            white-space: nowrap;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #475569 !important;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none !important;
            transition: all 0.2s ease;
        }
        .mobile-tab-btn.active {
            background: #1a73e8 !important;
            color: #ffffff !important;
            border-color: #1a73e8 !important;
        }
        .mobile-tab-btn.active .tab-dot {
            display: none;
        }
        .tab-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
        }
        .dot-draft { background-color: #5e50f9; }
        .dot-waiting { background-color: #fd7e14; }
        .dot-approved { background-color: #1e88e5; }
        .dot-paid { background-color: #2ec866; }
        .dot-cancelled { background-color: #dc3545; }

        .mobile-filter-bar {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 8px;
        }
        .mobile-filter-toggle-btn {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 500;
            color: #475569;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            user-select: none;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        .mobile-date-display-btn {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 500;
            color: #475569;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            cursor: pointer;
            user-select: none;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        .mobile-date-display-btn span {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .mobile-date-display-btn i {
            color: #64748b;
            font-size: 14px;
        }
        .settlement-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 12px;
            margin-bottom: 12px;
            border: 1px solid #f1f5f9;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.02), 0 2px 4px rgba(0, 0, 0, 0.01);
        }
        .status-icon-box {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }
        .status-icon-draft {
            background-color: #e8e6ff;
            color: #5e50f9;
        }
        .status-icon-waiting_approval {
            background-color: #fff3e0;
            color: #ff9800;
        }
        .status-icon-approved {
            background-color: #e3f2fd;
            color: #1e88e5;
        }
        .status-icon-paid {
            background-color: #e8f5e9;
            color: #2ec866;
        }
        .status-icon-cancelled {
            background-color: #ffebee;
            color: #dc3545;
        }
        .card-date {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
        }
        .card-id {
            font-size: 11px;
            color: #64748b;
        }
        .badge-status {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            text-align: center;
            display: inline-block;
        }
        .badge-status-draft {
            background-color: #5e50f9;
            color: #ffffff;
        }
        .badge-status-waiting_approval {
            background-color: #ff9800;
            color: #ffffff;
        }
        .badge-status-approved {
            background-color: #1e88e5;
            color: #ffffff;
        }
        .badge-status-paid {
            background-color: #2ec866;
            color: #ffffff;
        }
        .badge-status-cancelled {
            background-color: #dc3545;
            color: #ffffff;
        }
        .card-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            margin: 12px 0;
            padding: 10px 0;
            border-top: 1px dashed #f1f5f9;
            border-bottom: 1px dashed #f1f5f9;
        }
        .stat-col {
            text-align: center;
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 2px;
        }
        .stat-col:not(:last-child) {
            border-right: 1px solid #f1f5f9;
        }
        .stat-label {
            font-size: 10px;
            color: #64748b;
            font-weight: 500;
        }
        .stat-value {
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
        }
        .card-info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            color: #475569;
        }
        .info-val {
            color: #0f172a;
            font-weight: 700;
        }
        .card-footer-row {
            border-top: 1px solid #f1f5f9;
        }
        .card-meta {
            font-size: 11px;
            color: #64748b;
            flex-grow: 1;
            padding-right: 8px;
        }
        .card-details-link {
            font-size: 12px;
            font-weight: 700;
            color: #1a73e8 !important;
            text-decoration: none !important;
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
        }
    </style>

    <div class="container-fluid recon-page-wrapper">
        <!-- Desktop Header and Title -->
        <div class="desktop-layout">
            <div class="recon-topline">
                <div class="recon-breadcrumb">
                    <a href="#">Quản trị</a>
                    <span class="recon-breadcrumb-separator">/</span>
                    <a href="{{ route('admin.users.sales') }}">Nhân sự cửa hàng</a>
                    <span class="recon-breadcrumb-separator">/</span>
                    <span class="active">Đối soát chuyên viên</span>
                </div>
            </div>
            
            <div class="recon-title-wrapper mb-4">
                <h1>{{ $isSaleUser ? 'Đối soát của tôi' : 'Đối soát chuyên viên' }}</h1>
                <div class="subtitle">
                    Chốt thu nhập từ Lucky Ticket hợp lệ. Ticket treo không tính, ticket hủy muộn được ghi nhận bằng điều chỉnh âm, không xóa kỳ cũ.
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

        <!-- Desktop Filters & Creation Form -->
        <div class="desktop-layout">
            <div class="row g-4 mb-4">
                @if ($canManageSettlements)
                    <div class="col-xl-6 col-lg-6 col-12">
                        <div class="card recon-card h-100">
                            <div class="card-header bg-white fw-bold">Tạo kỳ đối soát</div>
                            <div class="card-body">
                                <form method="POST" action="{{ route('admin.specialist-settlements.store') }}" class="row g-3">
                                    @csrf
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">Chuyên viên</label>
                                        <select class="form-select" name="specialist_id" required>
                                            <option value="">Chọn chuyên viên sale</option>
                                            @foreach ($specialistOptions as $specialist)
                                                <option value="{{ $specialist->id }}" @selected(old('specialist_id') == $specialist->id)>
                                                    {{ $userName($specialist) }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Từ ngày</label>
                                        <input type="date" class="form-control" name="period_from" value="{{ old('period_from', $defaultPeriodFrom) }}" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Đến ngày</label>
                                        <input type="date" class="form-control" name="period_to" value="{{ old('period_to', $defaultPeriodTo) }}" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Trạng thái tạo</label>
                                        <select class="form-select" name="status" required>
                                            <option value="WAITING_APPROVAL" @selected(old('status', 'WAITING_APPROVAL') === 'WAITING_APPROVAL')>Chờ duyệt</option>
                                            <option value="DRAFT" @selected(old('status') === 'DRAFT')>Nháp</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Điều chỉnh cộng / trừ</label>
                                        <input type="number" class="form-control" name="adjustment_amount" value="{{ old('adjustment_amount', 0) }}" step="1">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">Ghi chú</label>
                                        <textarea class="form-control" name="note" rows="2" placeholder="Bắt buộc nếu có điều chỉnh cộng/trừ">{{ old('note') }}</textarea>
                                    </div>
                                    <div class="col-12 text-end mt-4">
                                        <button class="btn btn-recon-primary w-100" type="submit">
                                            <i class="fas fa-plus me-1"></i> Tạo kỳ đối soát
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="{{ $canManageSettlements ? 'col-xl-6 col-lg-6 col-12' : 'col-12' }} d-flex flex-column gap-4">
                    <!-- Card Lọc danh sách đối soát -->
                    <div class="card recon-card mb-0">
                        <div class="card-header bg-white fw-bold">Lọc danh sách đối soát</div>
                        <div class="card-body">
                            <form method="GET" action="{{ route('admin.specialist-settlements.index') }}">
                                <div class="row g-3">
                                    @if (! $isSaleUser)
                                        <div class="col-md-6 col-12">
                                            <label class="form-label fw-semibold">Chuyên viên</label>
                                            <select class="form-select" name="specialist_id">
                                                <option value="">Tất cả</option>
                                                @foreach ($specialistOptions as $specialist)
                                                    <option value="{{ $specialist->id }}" @selected((string) $filters['specialist_id'] === (string) $specialist->id)>
                                                        {{ $userName($specialist) }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-6 col-12">
                                            <label class="form-label fw-semibold">Trạng thái</label>
                                            <select class="form-select" name="status">
                                                <option value="all" @selected($filters['status'] === 'all')>Tất cả</option>
                                                @foreach ($statusLabels as $status => $label)
                                                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @else
                                        <div class="col-md-4 col-12">
                                            <label class="form-label fw-semibold">Trạng thái</label>
                                            <select class="form-select" name="status">
                                                <option value="all" @selected($filters['status'] === 'all')>Tất cả</option>
                                                @foreach ($statusLabels as $status => $label)
                                                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endif
                                    <div class="{{ $isSaleUser ? 'col-md-4' : 'col-md-6' }} col-12">
                                        <label class="form-label fw-semibold">Từ ngày</label>
                                        <input type="date" class="form-control" name="period_from" value="{{ $filters['period_from'] }}">
                                    </div>
                                    <div class="{{ $isSaleUser ? 'col-md-4' : 'col-md-6' }} col-12">
                                        <label class="form-label fw-semibold">Đến ngày</label>
                                        <input type="date" class="form-control" name="period_to" value="{{ $filters['period_to'] }}">
                                    </div>
                                </div>
                                <div class="row mt-4">
                                    <div class="col-12 d-flex gap-2 justify-content-end">
                                        <a href="{{ route('admin.specialist-settlements.index') }}" class="btn btn-recon-outline">Reset</a>
                                        <button class="btn btn-recon-primary" type="submit">Lọc kết quả</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Card Hướng dẫn -->
                    <div class="card settlement-guide-card position-relative overflow-hidden flex-grow-1 mb-0">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-2 mb-3">
                                <div class="guide-icon-wrapper">
                                    <i class="fas fa-info-circle text-primary"></i>
                                </div>
                                <h5 class="mb-0 fw-bold" style="font-size: 15px; color: var(--recon-text);">Hướng dẫn đối soát</h5>
                            </div>
                            <ul class="guide-list mb-0">
                                <li class="mb-2"><strong>Gom ticket hợp lệ:</strong> Hệ thống tự động gom các ticket ở trạng thái hợp lệ và chưa thuộc kỳ đối soát nào vào tổng thu nhập.</li>
                                <li class="mb-2"><strong>Ticket loại trừ:</strong> Các ticket đang bị giữ (held), tranh chấp (disputed) hoặc chờ xử lý (pending) sẽ không được tính.</li>
                                <li class="mb-2"><strong>Điều chỉnh thu nhập:</strong> Sử dụng chức năng điều chỉnh (+ hoặc -) để xử lý ticket hủy muộn, thưởng thêm hoặc phạt vi phạm.</li>
                                <li><strong>Tính toàn vẹn:</strong> Không xóa kỳ đối soát cũ. Mọi thay đổi đều được ghi nhận qua lịch sử điều chỉnh rõ ràng.</li>
                            </ul>
                            <!-- Watermark Icon -->
                            <div class="guide-watermark">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="100" height="100" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
                                    <rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect>
                                    <path d="M9 14h6"></path>
                                    <path d="M9 18h6"></path>
                                    <path d="M9 10h3"></path>
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Desktop Table list -->
        <div class="desktop-layout">
            <div class="card recon-card">
                <div class="card-header bg-white fw-bold">Danh sách kỳ đối soát</div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover align-middle mb-0 settlement-table">
                        <thead>
                            <tr>
                                <th>Kỳ đối soát</th>
                                <th>Chuyên viên</th>
                                <th class="text-end">Ticket hợp lệ</th>
                                <th class="text-end">Phí bình quân</th>
                                <th class="text-end">Tổng thu nhập</th>
                                <th class="text-end">Điều chỉnh</th>
                                <th class="text-end">Chốt cuối</th>
                                <th>Trạng thái</th>
                                <th>Ghi nhận</th>
                                <th class="text-end">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($settlements as $settlement)
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark">{{ $periodLabel($settlement) }}</div>
                                        <div class="text-muted" style="font-size: 11px;">ID: {{ $settlement->id }}</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $userName($settlement->specialist) }}</div>
                                    </td>
                                    <td class="text-end fw-medium">{{ $number($settlement->total_eligible_tickets) }}</td>
                                    <td class="text-end text-muted">{{ $money($settlement->fee_per_ticket) }}</td>
                                    <td class="text-end fw-medium">{{ $money($settlement->gross_income) }}</td>
                                    <td class="text-end fw-semibold {{ (float) $settlement->adjustment_amount < 0 ? 'text-danger' : ((float) $settlement->adjustment_amount > 0 ? 'text-success' : '') }}">
                                        {{ $money($settlement->adjustment_amount) }}
                                    </td>
                                    <td class="text-end fw-bold text-primary">{{ $money($settlement->final_income) }}</td>
                                    <td>
                                        <span class="status-pill status-pill-{{ strtolower($settlement->status) }}">
                                            {{ $statusLabels[$settlement->status] ?? $settlement->status }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="recon-record-info">
                                            <div><span class="label">Tạo:</span> <span class="val">{{ $userName($settlement->creator) }}</span></div>
                                            @if($settlement->approver)
                                                <div><span class="label">Duyệt:</span> <span class="val">{{ $userName($settlement->approver) }}</span></div>
                                            @endif
                                            @if($settlement->payer)
                                                <div><span class="label">Trả:</span> <span class="val">{{ $userName($settlement->payer) }}</span></div>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-action-trigger" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-light">
                                                <li>
                                                    <a class="dropdown-item" href="{{ route('admin.specialist-settlements.show', $settlement->id) }}">
                                                        <i class="far fa-eye me-2 text-muted text-center" style="width: 16px;"></i> Chi tiết
                                                    </a>
                                                </li>
                                                @if ($canManageSettlements)
                                                    @if (in_array((string) $settlement->status, ['DRAFT', 'WAITING_APPROVAL'], true))
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <form method="POST" action="{{ route('admin.specialist-settlements.approve', $settlement->id) }}" class="m-0">
                                                                @csrf
                                                                @method('PATCH')
                                                                <button class="dropdown-item text-success" type="submit" onclick="return confirm('Duyệt kỳ đối soát này?')">
                                                                    <i class="far fa-check-circle me-2 text-center" style="width: 16px;"></i> Duyệt kỳ
                                                                </button>
                                                            </form>
                                                        </li>
                                                        <li>
                                                            <button class="dropdown-item text-primary" type="button" data-bs-toggle="modal" data-bs-target="#adjustModal-{{ $settlement->id }}">
                                                                <i class="fas fa-sliders-h me-2 text-center" style="width: 16px;"></i> Điều chỉnh
                                                            </button>
                                                        </li>
                                                    @endif
                                                    @if ((string) $settlement->status === 'APPROVED')
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <form method="POST" action="{{ route('admin.specialist-settlements.paid', $settlement->id) }}" class="m-0">
                                                                @csrf
                                                                @method('PATCH')
                                                                <button class="dropdown-item text-success" type="submit" onclick="return confirm('Đánh dấu đã thanh toán kỳ này?')">
                                                                    <i class="fas fa-money-bill-wave me-2 text-center" style="width: 16px;"></i> Đã thanh toán
                                                                </button>
                                                            </form>
                                                        </li>
                                                    @endif
                                                @endif
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-5">
                                        <i class="far fa-folder-open d-block mb-2 text-muted" style="font-size: 24px;"></i>
                                        Chưa có kỳ đối soát chuyên viên.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if (method_exists($settlements, 'links'))
                    <div class="card-footer bg-white border-top-0 d-flex justify-content-end py-3">
                        {{ $settlements->onEachSide(1)->links('pagination::bootstrap-5') }}
                    </div>
                @endif
            </div>
        </div>

        <!-- Mobile Layout -->
        <div class="mobile-layout">
            @php
                $currentStatus = $filters['status'] ?? 'all';
                $formattedFrom = !empty($filters['period_from']) ? date('d/m/Y', strtotime($filters['period_from'])) : null;
                $formattedTo = !empty($filters['period_to']) ? date('d/m/Y', strtotime($filters['period_to'])) : null;
                $dateLabel = 'Chọn thời gian';
                if ($formattedFrom && $formattedTo) {
                    $dateLabel = $formattedFrom . ' – ' . $formattedTo;
                } elseif ($formattedFrom) {
                    $dateLabel = 'Từ ' . $formattedFrom;
                } elseif ($formattedTo) {
                    $dateLabel = 'Đến ' . $formattedTo;
                }
            @endphp

            <!-- Mobile Title -->
            <h1 class="mobile-title">{{ $isSaleUser ? 'Đối soát của tôi' : 'Đối soát chuyên viên' }}</h1>

            <!-- Status Tabs -->
            <div class="mobile-tabs-container">
                <a href="#" class="mobile-tab-btn {{ $currentStatus === 'all' ? 'active' : '' }}" data-status="all">
                    Tất cả
                </a>
                <a href="#" class="mobile-tab-btn {{ $currentStatus === 'DRAFT' ? 'active' : '' }}" data-status="DRAFT">
                    Nháp <span class="tab-dot dot-draft"></span>
                </a>
                <a href="#" class="mobile-tab-btn {{ $currentStatus === 'WAITING_APPROVAL' ? 'active' : '' }}" data-status="WAITING_APPROVAL">
                    Chờ duyệt <span class="tab-dot dot-waiting"></span>
                </a>
                <a href="#" class="mobile-tab-btn {{ $currentStatus === 'APPROVED' ? 'active' : '' }}" data-status="APPROVED">
                    Đã duyệt <span class="tab-dot dot-approved"></span>
                </a>
                <a href="#" class="mobile-tab-btn {{ $currentStatus === 'PAID' ? 'active' : '' }}" data-status="PAID">
                    Đã thanh toán <span class="tab-dot dot-paid"></span>
                </a>
                <a href="#" class="mobile-tab-btn {{ $currentStatus === 'CANCELLED' ? 'active' : '' }}" data-status="CANCELLED">
                    Đã hủy <span class="tab-dot dot-cancelled"></span>
                </a>
            </div>

            <!-- Filter Buttons -->
            <div class="mobile-filter-bar mt-3">
                <div class="mobile-filter-toggle-btn" data-bs-toggle="collapse" data-bs-target="#mobileFilterCollapse" aria-expanded="false" aria-controls="mobileFilterCollapse">
                    <i class="fas fa-filter"></i>
                    <span>Bộ lọc</span>
                    <i class="fas fa-chevron-down ms-1" style="font-size: 10px;"></i>
                </div>
                <div class="mobile-date-display-btn" data-bs-toggle="collapse" data-bs-target="#mobileFilterCollapse" aria-expanded="false" aria-controls="mobileFilterCollapse">
                    <span>{{ $dateLabel }}</span>
                    <i class="far fa-calendar-alt"></i>
                </div>
            </div>

            <!-- Collapsible Filter Panel -->
            <div class="collapse" id="mobileFilterCollapse">
                <div class="card card-body p-3 mt-3 border rounded-3 bg-white">
                    <form method="GET" action="{{ route('admin.specialist-settlements.index') }}" id="mobile-filter-form">
                        <!-- Hidden status parameter to preserve active tab -->
                        <input type="hidden" name="status" value="{{ $currentStatus }}">

                        @if (! $isSaleUser)
                            <div class="mb-3">
                                <label class="form-label font-weight-bold">Chuyên viên</label>
                                <select class="form-select" name="specialist_id">
                                    <option value="">Tất cả</option>
                                    @foreach ($specialistOptions as $specialist)
                                        <option value="{{ $specialist->id }}" @selected((string) $filters['specialist_id'] === (string) $specialist->id)>
                                            {{ $userName($specialist) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label font-weight-bold">Từ ngày</label>
                                <input type="date" class="form-control" name="period_from" value="{{ $filters['period_from'] }}">
                            </div>
                            <div class="col-6">
                                <label class="form-label font-weight-bold">Đến ngày</label>
                                <input type="date" class="form-control" name="period_to" value="{{ $filters['period_to'] }}">
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button class="btn btn-primary w-100" type="submit">Lọc</button>
                            <a href="{{ route('admin.specialist-settlements.index') }}" class="btn btn-outline-secondary w-100 text-center pt-2">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Mobile Cards List -->
            <div class="mobile-cards-list mt-3">
                @forelse ($settlements as $settlement)
                    <div class="settlement-card">
                        <!-- Card Header -->
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="card-header-left d-flex align-items-center gap-2">
                                <div class="status-icon-box status-icon-{{ strtolower($settlement->status) }}">
                                    <i class="far fa-calendar-alt"></i>
                                </div>
                                <div class="d-flex flex-column">
                                    <span class="card-date">{{ $periodLabel($settlement) }}</span>
                                    <span class="card-id">ID: {{ $settlement->id }}</span>
                                </div>
                            </div>
                            <div>
                                <span class="badge-status badge-status-{{ strtolower($settlement->status) }}">
                                    {{ $statusLabels[$settlement->status] ?? $settlement->status }}
                                </span>
                            </div>
                        </div>

                        <!-- Stats Grid -->
                        <div class="card-stats-grid">
                            <div class="stat-col">
                                <div class="stat-label">Ticket hợp lệ</div>
                                <div class="stat-value">{{ $number($settlement->total_eligible_tickets) }}</div>
                            </div>
                            <div class="stat-col">
                                <div class="stat-label">Tổng thu nhập</div>
                                <div class="stat-value">{{ $money($settlement->gross_income) }}</div>
                            </div>
                            <div class="stat-col">
                                <div class="stat-label">Chốt cuối</div>
                                <div class="stat-value">{{ $money($settlement->final_income) }}</div>
                            </div>
                        </div>

                        <!-- Average fee & Adjustment row -->
                        <div class="card-info-row">
                            <span>Phí bình quân / ticket: <strong class="info-val">{{ $money($settlement->fee_per_ticket) }}</strong></span>
                            <span>Điều chỉnh: 
                                <strong class="info-val {{ (float) $settlement->adjustment_amount < 0 ? 'text-danger' : ((float) $settlement->adjustment_amount > 0 ? 'text-success' : '') }}">
                                    {{ $money($settlement->adjustment_amount) }}
                                </strong>
                            </span>
                        </div>

                        <!-- Metadata Row -->
                        <div class="card-footer-row d-flex justify-content-between align-items-center mt-3 pt-3">
                            <div class="card-meta">
                                Tạo: {{ $userName($settlement->creator) }} - Duyệt: {{ $userName($settlement->approver) }} - Trả: {{ $userName($settlement->payer) }}
                            </div>
                            <div>
                                <a href="{{ route('admin.specialist-settlements.show', $settlement->id) }}" class="card-details-link">
                                    Chi tiết <i class="fas fa-chevron-right ms-1" style="font-size: 10px;"></i>
                                </a>
                            </div>
                        </div>

                        <!-- Admin Action Buttons on Mobile -->
                        @if ($canManageSettlements)
                            <div class="card-admin-actions d-flex flex-wrap gap-2 mt-3 pt-3 border-top">
                                @if (in_array((string) $settlement->status, ['DRAFT', 'WAITING_APPROVAL'], true))
                                    <form method="POST" action="{{ route('admin.specialist-settlements.approve', $settlement->id) }}" class="m-0 flex-grow-1">
                                        @csrf
                                        @method('PATCH')
                                        <button class="btn btn-sm btn-success w-100" type="submit" onclick="return confirm('Duyệt kỳ đối soát này?')">Duyệt</button>
                                    </form>
                                    <button class="btn btn-sm btn-outline-secondary w-100 mt-1 text-center" type="button" data-bs-toggle="modal" data-bs-target="#adjustModal-{{ $settlement->id }}">
                                        Điều chỉnh
                                    </button>
                                @endif
                                @if ((string) $settlement->status === 'APPROVED')
                                    <form method="POST" action="{{ route('admin.specialist-settlements.paid', $settlement->id) }}" class="m-0 w-100">
                                        @csrf
                                        @method('PATCH')
                                        <button class="btn btn-sm btn-success w-100" type="submit" onclick="return confirm('Đánh dấu đã thanh toán kỳ này?')">Đã thanh toán</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="text-center text-muted py-5 bg-white rounded-3 border">
                        Chưa có kỳ đối soát chuyên viên.
                    </div>
                @endforelse

                <!-- Mobile Pagination -->
                @if (method_exists($settlements, 'links'))
                    <div class="mt-4 d-flex justify-content-center">
                        {{ $settlements->onEachSide(1)->links('pagination::bootstrap-5') }}
                    </div>
                @endif
            </div>
        </div>

        <!-- Modals for Adjustment -->
        @foreach ($settlements as $settlement)
            @if ($canManageSettlements && in_array((string) $settlement->status, ['DRAFT', 'WAITING_APPROVAL'], true))
                <div class="modal fade" id="adjustModal-{{ $settlement->id }}" tabindex="-1" aria-labelledby="adjustModalLabel-{{ $settlement->id }}" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title fw-bold" id="adjustModalLabel-{{ $settlement->id }}">Điều chỉnh kỳ đối soát #{{ $settlement->id }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form method="POST" action="{{ route('admin.specialist-settlements.adjust', $settlement->id) }}">
                                @csrf
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Số tiền điều chỉnh (đ)</label>
                                        <input type="number" name="adjustment_amount" class="form-control" step="1" placeholder="Ví dụ: +100000 hoặc -50000" required>
                                        <div class="form-text text-muted">Nhập số dương để cộng thêm, số âm để trừ bớt.</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Lý do điều chỉnh</label>
                                        <textarea name="adjustment_note" class="form-control" rows="3" placeholder="Nhập lý do điều chỉnh..." required></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button>
                                    <button type="submit" class="btn btn-primary">Lưu điều chỉnh</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Handle mobile status tab clicks
            document.querySelectorAll('.mobile-tab-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const status = this.getAttribute('data-status');
                    const statusInput = document.querySelector('#mobile-filter-form [name="status"]');
                    if (statusInput) {
                        statusInput.value = status;
                        document.querySelector('#mobile-filter-form').submit();
                    }
                });
            });
        });
    </script>
@endsection

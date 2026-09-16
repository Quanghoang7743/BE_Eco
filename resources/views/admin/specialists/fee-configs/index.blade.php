@extends('admin.layout')

@section('title', 'Cấu hình phí chuyên viên')

@section('content')
    @php
        $money = fn ($value) => number_format((float) $value, 0, ',', '.') . ' đ';
        $userName = function ($user): string {
            if (! $user) {
                return 'N/A';
            }

            return $user->full_name ?: ($user->name ?: ($user->email ?: 'N/A'));
        };
        $statusClass = fn (string $status): string => match ($status) {
            'ACTIVE' => 'success',
            'DRAFT' => 'warning text-dark',
            'EXPIRED' => 'secondary',
            'CANCELLED' => 'danger',
            default => 'light text-dark',
        };
        $scopeDetail = function ($config) use ($scopeLabels, $userName): string {
            return match ((string) $config->apply_scope) {
                'specialist' => 'Chuyên viên: ' . $userName($config->specialist),
                'region' => 'Khu vực ID: ' . ($config->region_id ?: 'N/A'),
                'store_type' => 'Loại cửa hàng: ' . ($config->store_type ?: 'N/A'),
                default => $scopeLabels[$config->apply_scope] ?? 'Toàn hệ thống',
            };
        };
        $statusBadgeClass = function ($status) {
            $statusLower = strtolower($status);
            if (str_contains($statusLower, 'draft') || str_contains($statusLower, 'nháp')) {
                return 'status-badge-pending';
            }
            if (str_contains($statusLower, 'active') || str_contains($statusLower, 'hoạt động')) {
                return 'status-badge-paid';
            }
            if (str_contains($statusLower, 'expired') || str_contains($statusLower, 'hết hạn')) {
                return 'status-badge-default';
            }
            if (str_contains($statusLower, 'cancelled') || str_contains($statusLower, 'hủy')) {
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

        .fee-config-wrapper {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--recon-text);
            padding-bottom: 30px;
        }

        /* Fallback active card header design */
        .icon-header-box {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        /* Card styles */
        .recon-card {
            background: #ffffff;
            border: 1px solid var(--recon-border) !important;
            border-radius: 12px !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03), 0 2px 4px -1px rgba(0, 0, 0, 0.015);
            transition: all 0.2s ease;
            overflow: hidden;
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
        .recon-card .card-body {
            padding: 20px;
        }

        /* Form elements */
        .form-label {
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 13px;
            color: #334155;
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

        /* Input Group suffix spacing */
        .input-group .form-control {
            border-top-right-radius: 0 !important;
            border-bottom-right-radius: 0 !important;
        }
        .input-group-text {
            border: 1px solid #cbd5e1;
            background-color: #f8fafc;
            border-left: none;
            border-top-right-radius: 6px !important;
            border-bottom-right-radius: 6px !important;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--recon-muted);
            padding: 0 16px;
            height: 40px;
            display: flex;
            align-items: center;
        }

        /* Active Fees Horizontal Row */
        .active-fees-scroll-container {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            flex-wrap: nowrap;
            padding: 4px 4px 10px 4px;
            scrollbar-width: thin;
        }
        .active-fees-scroll-container::-webkit-scrollbar {
            height: 6px;
        }
        .active-fees-scroll-container::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 3px;
        }
        .active-fees-scroll-container::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }

        .active-fee-kpi-card {
            background: #ffffff;
            border: 1px solid var(--recon-border);
            border-radius: 8px;
            padding: 8px 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.015);
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 125px;
            flex: 1 1 auto;
        }
        .active-fee-icon-box {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
        }

        /* Active Fee Themed Colors */
        .fee-theme-blue { --theme-color: #1a73e8; --theme-bg: #e8f0fe; }
        .fee-theme-purple { --theme-color: #7b1fa2; --theme-bg: #f3e5f5; }
        .fee-theme-green { --theme-color: #2ec866; --theme-bg: #ebfbee; }
        .fee-theme-orange { --theme-color: #ff9800; --theme-bg: #fff4e6; }
        .fee-theme-teal { --theme-color: #009688; --theme-bg: #e0f2f1; }
        .fee-theme-red { --theme-color: #ef4444; --theme-bg: #fee2e2; }
        .fee-theme-yellow { --theme-color: #f57c00; --theme-bg: #fff3e0; }

        .active-fee-kpi-card.fee-theme-blue { background-color: #ffffff; border-color: var(--recon-border); }
        .active-fee-icon-box {
            background-color: var(--theme-bg);
            color: var(--theme-color);
        }

        .active-fee-content {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .active-fee-label {
            font-size: 9.5px;
            font-weight: 500;
            color: var(--recon-muted);
            white-space: nowrap;
        }
        .active-fee-value {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--recon-primary);
            line-height: 1.2;
        }

        /* Soft Status Badges */
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
            padding: 8px 6px !important;
            background-color: #f8fafc !important;
            border-bottom: 1px solid var(--recon-border) !important;
            vertical-align: middle !important;
            letter-spacing: normal !important;
            line-height: 1.2 !important;
        }
        .table td {
            padding: 8px 6px !important;
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
            padding: 10px 6px !important;
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

        /* Button styles */
        .btn {
            font-weight: 600;
            font-size: 13px;
            border-radius: 6px;
            padding: 8px 16px;
            transition: all 0.2s;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-sm {
            height: 30px;
            padding: 4px 10px;
            font-size: 11.5px;
        }
        .btn-primary {
            background-color: var(--recon-primary);
            border-color: var(--recon-primary);
            color: #ffffff;
        }
        .btn-primary:hover {
            background-color: #0b5ed7;
            border-color: #0a58ca;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(31, 111, 255, 0.2);
        }
        .btn-success {
            background-color: var(--recon-success);
            border-color: var(--recon-success);
            color: #ffffff;
        }
        .btn-success:hover {
            background-color: #059669;
            border-color: #047857;
            transform: translateY(-1px);
        }
        .btn-outline-secondary {
            color: var(--recon-muted);
            border-color: #cbd5e1;
            background-color: transparent;
        }
        .btn-outline-secondary:hover {
            color: var(--recon-text);
            background-color: #f8fafc;
            border-color: #94a3b8;
        }
        .btn-outline-primary {
            color: var(--recon-primary);
            border-color: var(--recon-primary);
            background-color: transparent;
        }
        .btn-outline-primary:hover {
            color: #ffffff;
            background-color: var(--recon-primary);
            border-color: var(--recon-primary);
        }
        .btn-outline-danger {
            color: var(--recon-danger);
            border-color: var(--recon-danger);
            background-color: transparent;
        }
        .btn-outline-danger:hover {
            color: #ffffff;
            background-color: var(--recon-danger);
            border-color: var(--recon-danger);
        }
    </style>

    <div class="container-fluid fee-config-wrapper">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1 fw-bold text-dark">Cấu hình phí chuyên viên</h1>
                <p class="text-muted mb-0 small">Quản lý phí/ticket cho chuyên viên. Cấu hình đã duyệt không sửa đè, muốn đổi phí thì tạo cấu hình mới.</p>
            </div>
            <div class="card recon-card border-0 shadow-sm mb-0" style="min-width: 220px;">
                <div class="card-body p-3 d-flex align-items-center gap-3">
                    <div class="icon-header-box icon-box-blue" style="background-color: var(--recon-primary-light); color: var(--recon-primary);">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div>
                        <div class="text-muted small" style="font-size: 11px;">Phí fallback Active</div>
                        <div class="fw-bold text-primary" style="font-size: 20px; line-height: 1.2;">{{ $money($currentFee) }}</div>
                    </div>
                </div>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="row g-3 mb-3">
            <div class="col-lg-7 col-12">
                <div class="card recon-card h-100">
                    <div class="card-header"><i class="fas fa-plus-circle text-primary"></i> Tạo cấu hình phí</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.specialist-fee-configs.store') }}" class="row g-3">
                            @csrf
                            <div class="col-md-6 col-12">
                                <label class="form-label">Tên cấu hình</label>
                                <input type="text" name="config_name" class="form-control" value="{{ old('config_name') }}" required maxlength="255" placeholder="VD: Phí F&B giai đoạn 1">
                            </div>
                            <div class="col-md-3 col-6">
                                <label class="form-label">Loại ticket</label>
                                <select name="ticket_type_code" class="form-select" required>
                                    @foreach ($ticketTypeOptions as $ticketType)
                                        <option value="{{ $ticketType['code'] }}" @selected(old('ticket_type_code', 'TICKET_DEFAULT') === $ticketType['code'])>
                                            {{ $ticketType['name'] }} ({{ $ticketType['code'] }}){{ $ticketType['is_default'] ? ' - loại vé mặc định hệ thống' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3 col-6">
                                <label class="form-label">Phí / ticket</label>
                                <div class="input-group">
                                    <input type="number" name="fee_per_ticket" class="form-control text-end" value="{{ old('fee_per_ticket', 100) }}" min="1" step="1" required>
                                    <span class="input-group-text">đ</span>
                                </div>
                            </div>
                            <div class="col-md-4 col-12">
                                <label class="form-label">Phạm vi áp dụng</label>
                                <select name="apply_scope" class="form-select" required>
                                    @foreach ($scopeLabels as $value => $label)
                                        <option value="{{ $value }}" @selected(old('apply_scope', 'global') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4 col-12">
                                <label class="form-label">Chuyên viên</label>
                                <select name="specialist_id" class="form-select">
                                    <option value="">Không áp dụng</option>
                                    @foreach ($specialistOptions as $specialist)
                                        <option value="{{ $specialist->id }}" @selected(old('specialist_id') == $specialist->id)>{{ $userName($specialist) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4 col-12">
                                <label class="form-label">ID khu vực</label>
                                <input type="number" name="region_id" class="form-control" value="{{ old('region_id') }}" min="1" placeholder="Nhập ID khu vực">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Loại cửa hàng</label>
                                <input type="text" name="store_type" class="form-control" value="{{ old('store_type') }}" list="storeTypeOptions" placeholder="Chỉ nhập khi áp dụng theo loại cửa hàng">
                                <datalist id="storeTypeOptions">
                                    @foreach ($storeTypeOptions as $storeType)
                                        <option value="{{ $storeType }}"></option>
                                    @endforeach
                                </datalist>
                            </div>
                            <div class="col-md-6 col-12">
                                <label class="form-label">Hiệu lực từ</label>
                                <input type="datetime-local" name="effective_from" class="form-control" value="{{ old('effective_from', now('Asia/Ho_Chi_Minh')->format('Y-m-d\TH:i')) }}" required>
                            </div>
                            <div class="col-md-6 col-12">
                                <label class="form-label">Hiệu lực đến</label>
                                <input type="datetime-local" name="effective_to" class="form-control" value="{{ old('effective_to') }}">
                            </div>
                            <div class="col-12">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label mb-0">Ghi chú</label>
                                    <span class="text-muted small" style="font-size: 11px;" id="charCount">0/255</span>
                                </div>
                                <textarea name="notes" id="notesTextarea" class="form-control" rows="3" maxlength="255" placeholder="Nhập ghi chú cấu hình phí...">{{ old('notes') }}</textarea>
                            </div>
                            <div class="col-12 text-end">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fas fa-plus me-1"></i> Tạo nháp
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-5 col-12">
                <div class="card recon-card h-100">
                    <div class="card-header"><i class="fas fa-filter text-primary"></i> Lọc lịch sử cấu hình</div>
                    <div class="card-body">
                        <form method="GET" action="{{ route('admin.specialist-fee-configs.index') }}" class="row g-3">
                            <div class="col-md-6 col-12">
                                <label class="form-label">Trạng thái</label>
                                <select name="status" class="form-select">
                                    <option value="all" @selected($filters['status'] === 'all')>Tất cả</option>
                                    @foreach ($statusLabels as $status => $label)
                                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 col-12">
                                <label class="form-label">Phạm vi</label>
                                <select name="apply_scope" class="form-select" id="historyApplyScope">
                                    <option value="all" @selected($filters['apply_scope'] === 'all')>Tất cả</option>
                                    @foreach ($scopeLabels as $scope => $label)
                                        <option value="{{ $scope }}" @selected($filters['apply_scope'] === $scope)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 col-12">
                                <label class="form-label">Loại ticket</label>
                                <select name="ticket_type_code" class="form-select">
                                    <option value="all" @selected($filters['ticket_type_code'] === 'all')>Tất cả</option>
                                    @foreach ($ticketTypeOptions as $ticketType)
                                        <option value="{{ $ticketType['code'] }}" @selected($filters['ticket_type_code'] === $ticketType['code'])>
                                            {{ $ticketType['name'] }} ({{ $ticketType['code'] }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 col-12">
                                <label class="form-label">Chuyên viên</label>
                                <select name="specialist_id" class="form-select" id="historySpecialist">
                                    <option value="">Tất cả</option>
                                    @foreach ($specialistOptions as $specialist)
                                        <option value="{{ $specialist->id }}" @selected((string) $filters['specialist_id'] === (string) $specialist->id)>{{ $userName($specialist) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fas fa-filter me-1"></i> Lọc
                                </button>
                                <a href="{{ route('admin.specialist-fee-configs.index') }}" class="btn btn-outline-secondary">
                                    <i class="fas fa-redo me-1"></i> Reset
                                </a>
                            </div>
                        </form>

                        <div class="alert alert-info mt-3 mb-0 d-flex gap-2 align-items-start" style="background-color: var(--recon-primary-light); border-color: var(--recon-primary-border); color: var(--recon-primary); font-size: 12.5px;">
                            <i class="fas fa-info-circle mt-1 flex-shrink-0"></i>
                            <div>
                                Hệ thống ưu tiên cấu hình đúng loại ticket. Nếu không có, cấu hình <strong>TICKET_DEFAULT</strong> được dùng làm fallback. Ticket đã snapshot phí sẽ không bị thay đổi bởi cấu hình mới.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card recon-card mb-3">
            <div class="card-header">
                <i class="fas fa-receipt text-primary"></i> Phí Active hiện tại theo loại ticket
            </div>
            <div class="card-body">
                <div class="active-fees-scroll-container">
                    @php
                        $cardThemes = [
                            'TICKET_DEFAULT' => ['icon' => 'fas fa-file-alt', 'theme' => 'fee-theme-blue'],
                            'TICKET_B' => ['icon' => 'fas fa-file-invoice-dollar', 'theme' => 'fee-theme-purple'],
                            'TICKET_VIP' => ['icon' => 'fas fa-star', 'theme' => 'fee-theme-green'],
                            'TICKET_A' => ['icon' => 'fas fa-copy', 'theme' => 'fee-theme-orange'],
                            'TICKET_C' => ['icon' => 'fas fa-dollar-sign', 'theme' => 'fee-theme-teal'],
                            'TICKET_D' => ['icon' => 'fas fa-file-pdf', 'theme' => 'fee-theme-red'],
                            'TICKET_E' => ['icon' => 'fas fa-folder', 'theme' => 'fee-theme-yellow'],
                        ];
                    @endphp
                    @foreach ($currentFees as $ticketType)
                        @php
                            $code = $ticketType['code'];
                            $themeInfo = $cardThemes[$code] ?? $cardThemes['TICKET_DEFAULT'];
                        @endphp
                        <div class="active-fee-kpi-card {{ $themeInfo['theme'] }}">
                            <div class="active-fee-icon-box">
                                <i class="{{ $themeInfo['icon'] }}"></i>
                            </div>
                            <div class="active-fee-content">
                                <span class="active-fee-label">{{ $ticketType['name'] }} ({{ $code }})</span>
                                <span class="active-fee-value">{{ $money($ticketType['fee_per_ticket']) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card recon-card">
            <div class="card-header"><i class="fas fa-history text-primary"></i> Lịch sử cấu hình phí</div>
            <div class="card-body table-responsive p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Tên cấu hình</th>
                            <th>Loại ticket</th>
                            <th class="text-end">Phí / ticket</th>
                            <th>Phạm vi</th>
                            <th>Hiệu lực</th>
                            <th>Trạng thái</th>
                            <th>Người tạo / duyệt</th>
                            <th>Ghi chú</th>
                            <th class="text-end">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($configs as $config)
                            <tr>
                                <td>
                                    <div class="fw-semibold text-dark">{{ $config->config_name }}</div>
                                    <small class="text-muted">ID: {{ $config->id }}</small>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">{{ $config->ticket_type_code ?: 'TICKET_DEFAULT' }}</span>
                                </td>
                                <td class="text-end fw-semibold text-dark">{{ $money($config->fee_per_ticket) }}</td>
                                <td>
                                    <span class="badge bg-light text-dark border">{{ $scopeLabels[$config->apply_scope] ?? $config->apply_scope }}</span>
                                    <div class="small text-muted mt-1">{{ $scopeDetail($config) }}</div>
                                </td>
                                <td>
                                    <div>Từ: {{ optional($config->effective_from)->format('d/m/Y H:i') }}</div>
                                    <div>Đến: {{ optional($config->effective_to)->format('d/m/Y H:i') ?: 'Không giới hạn' }}</div>
                                </td>
                                <td>
                                    <span class="status-badge-custom {{ $statusBadgeClass((string) $config->status) }}">{{ $statusLabels[$config->status] ?? $config->status }}</span>
                                    @if ($config->cancelled_at)
                                        <div><small class="text-muted">Hủy: {{ optional($config->cancelled_at)->format('d/m/Y H:i') }}</small></div>
                                    @elseif ($config->approved_at)
                                        <div><small class="text-muted">Duyệt: {{ optional($config->approved_at)->format('d/m/Y H:i') }}</small></div>
                                    @endif
                                </td>
                                <td>
                                    <div>Tạo: {{ $userName($config->creator) }}</div>
                                    <div>Duyệt: {{ $userName($config->approver) }}</div>
                                </td>
                                <td style="min-width: 180px;">{{ $config->notes ?: 'N/A' }}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                                        @if ($config->status === 'DRAFT')
                                            <a href="{{ route('admin.specialist-fee-configs.edit', $config->id) }}" class="btn btn-sm btn-outline-primary">Sửa</a>
                                            <form method="POST" action="{{ route('admin.specialist-fee-configs.approve', $config->id) }}" class="d-inline">
                                                @csrf
                                                @method('PATCH')
                                                <button class="btn btn-sm btn-success" type="submit" onclick="return confirm('Duyệt cấu hình phí này?')">Duyệt</button>
                                            </form>
                                        @endif
                                        @if (! in_array($config->status, ['CANCELLED', 'EXPIRED'], true))
                                            <form method="POST" action="{{ route('admin.specialist-fee-configs.cancel', $config->id) }}" class="d-inline">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="cancel_reason" value="Hủy từ màn cấu hình phí">
                                                <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Hủy cấu hình phí này?')">Hủy</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">Chưa có cấu hình phí chuyên viên.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if (method_exists($configs, 'links'))
                <div class="card-footer bg-white border-top">{{ $configs->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
            @endif
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const scopeSelect = document.getElementById('historyApplyScope');
            const specialistSelect = document.getElementById('historySpecialist');

            if (scopeSelect && specialistSelect) {
                const syncSpecialistFilter = function () {
                    const supportsSpecialist = scopeSelect.value === 'specialist';
                    specialistSelect.disabled = !supportsSpecialist;

                    if (!supportsSpecialist) {
                        specialistSelect.value = '';
                    }
                };

                scopeSelect.addEventListener('change', syncSpecialistFilter);
                syncSpecialistFilter();
            }

            // Character counter for notes
            const notesTextarea = document.getElementById('notesTextarea');
            const charCount = document.getElementById('charCount');
            if (notesTextarea && charCount) {
                const updateCharCount = () => {
                    charCount.textContent = `${notesTextarea.value.length}/255`;
                };
                notesTextarea.addEventListener('input', updateCharCount);
                updateCharCount();
            }
        });
    </script>
@endsection

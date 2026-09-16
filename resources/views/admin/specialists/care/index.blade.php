@extends('admin.layout')

@section('title', 'Chăm sóc cửa hàng')

@section('styles')
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

        .care-page-wrapper {
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
        .recon-card .card-footer {
            background-color: transparent;
            border-top: 1px solid var(--recon-border);
            padding: 12px 20px;
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
            width: 46px;
            height: 46px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .kpi-content {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }
        .kpi-label {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--recon-muted);
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .kpi-value {
            font-size: 22px;
            font-weight: 800;
            color: var(--recon-text);
            line-height: 1.1;
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
        .dot-green { background-color: var(--recon-success); }
        .dot-red { background-color: var(--recon-danger); }
        .dot-orange { background-color: var(--recon-warning); }

        .kpi-subtext {
            font-size: 12px;
            margin-top: 2px;
            display: flex;
            align-items: center;
        }

        /* Status badges */
        .bg-success-light { background-color: var(--recon-success-light) !important; color: #065f46 !important; }
        .bg-info-light { background-color: var(--recon-info-light) !important; color: #1e40af !important; }
        .bg-danger-light { background-color: var(--recon-danger-light) !important; color: #991b1b !important; }
        .bg-warning-light { background-color: var(--recon-warning-light) !important; color: #854d0e !important; }

        /* Care type badges */
        .care-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-call { background-color: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
        .badge-visit { background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .badge-zalo { background-color: #e0e7ff; color: #4338ca; border: 1px solid #c7d2fe; }
        .badge-email { background-color: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
        .badge-onsite { background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .badge-other { background-color: #f3e8ff; color: #6b21a8; border: 1px solid #e9d5ff; }

        /* Icon action buttons */
        .btn-icon-only {
            width: 32px;
            height: 32px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background-color: #ffffff;
            transition: all 0.2s;
        }
        .btn-icon-only:hover {
            background-color: #f8fafc;
            border-color: #cbd5e1;
        }
        .btn-light-blue {
            color: #1f6fff;
            border-color: #b8cdf2;
            background-color: #f5f9ff;
        }
        .btn-light-blue:hover {
            background-color: #e0ecff;
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
            transition: border-color 0.15s ease-in-out;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--recon-primary);
            box-shadow: 0 0 0 3px rgba(31, 111, 255, 0.15);
        }

        /* Table custom styling */
        .table {
            margin-bottom: 0;
        }
        .table th {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.03em;
            color: var(--recon-muted);
            text-transform: uppercase;
            padding: 8px 10px;
            background-color: #f8fafc;
            border-bottom: 1px solid var(--recon-border);
            vertical-align: middle;
        }
        .table td {
            padding: 8px 10px;
            font-size: 12px;
            border-bottom: 1px solid var(--recon-border);
            vertical-align: middle;
        }
        .text-truncate-custom {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: normal;
        }
        .care-content-box div + div {
            margin-top: 6px;
        }
        
        /* Select2 override to match theme */
        .select2-container--default .select2-selection--single {
            border: 1px solid #cbd5e1 !important;
            border-radius: 6px !important;
            height: 38px !important;
            padding: 5px 8px !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px !important;
        }
    </style>
@endsection

@section('content')
    @php
        $userName = function ($user): string {
            if (! $user) {
                return 'N/A';
            }

            return $user->full_name ?: ($user->name ?: ($user->email ?: 'N/A'));
        };

        $storeName = function ($store): string {
            if (! $store) {
                return 'Không gắn cửa hàng';
            }

            return trim($store->name);
        };

        $statusClass = function (string $status): string {
            return match ($status) {
                'completed' => 'success',
                'in_progress' => 'info text-dark',
                'overdue' => 'danger',
                default => 'warning text-dark',
            };
        };

        // Calculate stats dynamically using database query matching current filters
        $kpiUser = request()->user();
        
        // Base logs query matching the filters
        $kpiLogsQuery = \App\Models\SpecialistStoreCareLog::query();
        if ($isSaleUser) {
            $storeIds = \App\Support\AdminCmsAccess::assignedStoreIds($kpiUser);
            if (empty($storeIds)) {
                $kpiLogsQuery->whereRaw('1 = 0');
            } else {
                $kpiLogsQuery->whereIn('store_id', $storeIds);
            }
        } elseif (!empty($filters['specialist_id']) && ctype_digit($filters['specialist_id'])) {
            $kpiLogsQuery->where('specialist_id', (int) $filters['specialist_id']);
        }
        if (!empty($filters['store_id'])) {
            $kpiLogsQuery->where('store_id', $filters['store_id']);
        }
        if (!empty($filters['date_from'])) {
            $kpiLogsQuery->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $kpiLogsQuery->whereDate('created_at', '<=', $filters['date_to']);
        }
        $totalLogsCount = $kpiLogsQuery->count();

        // Base tasks query matching the filters
        $kpiTasksQuery = \App\Models\SpecialistTask::query();
        if ($isSaleUser) {
            $storeIds = \App\Support\AdminCmsAccess::assignedStoreIds($kpiUser);
            $kpiTasksQuery->where('specialist_id', (int) $kpiUser->id)
                ->where(function ($scope) use ($storeIds): void {
                    $scope->whereNull('store_id');
                    if (! empty($storeIds)) {
                        $scope->orWhereIn('store_id', $storeIds);
                    }
                });
        } elseif (!empty($filters['specialist_id']) && ctype_digit($filters['specialist_id'])) {
            $kpiTasksQuery->where('specialist_id', (int) $filters['specialist_id']);
        }
        if (!empty($filters['store_id'])) {
            $kpiTasksQuery->where('store_id', $filters['store_id']);
        }
        if (!empty($filters['date_from'])) {
            $kpiTasksQuery->whereDate('deadline', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $kpiTasksQuery->whereDate('deadline', '<=', $filters['date_to']);
        }

        $inProgressTasksCount = (clone $kpiTasksQuery)->where('status', 'in_progress')->count();
        $overdueTasksCount = (clone $kpiTasksQuery)->where('status', 'overdue')->count();

        // Cửa hàng cần theo dõi: Number of distinct stores with logs that have problem_detected in the filtered set
        $kpiAttentionStoresQuery = \App\Models\SpecialistStoreCareLog::query()
            ->whereNotNull('problem_detected')
            ->where('problem_detected', '!=', '');
        if ($isSaleUser) {
            $storeIds = \App\Support\AdminCmsAccess::assignedStoreIds($kpiUser);
            if (empty($storeIds)) {
                $kpiAttentionStoresQuery->whereRaw('1 = 0');
            } else {
                $kpiAttentionStoresQuery->whereIn('store_id', $storeIds);
            }
        } elseif (!empty($filters['specialist_id']) && ctype_digit($filters['specialist_id'])) {
            $kpiAttentionStoresQuery->where('specialist_id', (int) $filters['specialist_id']);
        }
        if (!empty($filters['store_id'])) {
            $kpiAttentionStoresQuery->where('store_id', $filters['store_id']);
        }
        if (!empty($filters['date_from'])) {
            $kpiAttentionStoresQuery->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $kpiAttentionStoresQuery->whereDate('created_at', '<=', $filters['date_to']);
        }
        $attentionStoresCount = $kpiAttentionStoresQuery->distinct('store_id')->count('store_id');
    @endphp

    <div class="container-fluid care-page-wrapper">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div class="d-flex align-items-center">
                <div class="icon-header-box me-3">
                    <i class="fas fa-hand-holding-heart text-primary"></i>
                </div>
                <div>
                    <h1 class="h3 mb-1 fw-bold text-dark">{{ $isSaleUser ? 'Chăm sóc cửa hàng của tôi' : 'Chăm sóc cửa hàng chuyên viên' }}</h1>
                    <p class="text-muted mb-0 small">Ghi nhận hoạt động chăm sóc, theo dõi nhiệm vụ, cập nhật kết quả và kiểm soát nhiệm vụ quá hạn.</p>
                </div>
            </div>
            <div class="recon-breadcrumb d-none d-md-flex align-items-center gap-2">
                <a href="#" class="text-decoration-none">Quản trị</a>
                <span class="recon-breadcrumb-separator">/</span>
                <a href="{{ route('admin.users.sales') }}" class="text-decoration-none">Nhân sự cửa hàng</a>
                <span class="recon-breadcrumb-separator">/</span>
                <span class="active text-primary fw-semibold">Chăm sóc cửa hàng chuyên viên</span>
            </div>
        </div>

        <!-- Alert messages -->
        @if (session('success'))
            <div class="alert alert-success border-0 shadow-sm mb-3">{{ session('success') }}</div>
        @endif
        @if (session('warning'))
            <div class="alert alert-warning border-0 shadow-sm mb-3">{{ session('warning') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger border-0 shadow-sm mb-3">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Filter Bar -->
        <div class="card recon-card mb-4">
            <div class="card-body p-3">
                <form method="GET" action="{{ route('admin.specialist-care.index') }}" class="row g-3 align-items-end">
                    @if (! $isSaleUser)
                        <div class="col-xl-3 col-md-4 col-sm-6">
                            <label class="form-label fw-semibold text-secondary small">Chuyên viên</label>
                            <select name="specialist_id" class="form-select select2-specialist">
                                <option value="">Tất cả</option>
                                @foreach ($specialistOptions as $specialist)
                                    <option value="{{ $specialist->id }}" @selected((string) $filters['specialist_id'] === (string) $specialist->id)>
                                        {{ $userName($specialist) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="col-xl-3 col-md-4 col-sm-6">
                        <label class="form-label fw-semibold text-secondary small">Cửa hàng</label>
                        <select name="store_id" class="form-select select2-store">
                            <option value="">Tất cả</option>
                            @foreach ($storeOptions as $store)
                                <option value="{{ $store->id }}" @selected((string) $filters['store_id'] === (string) $store->id)>
                                    {{ $storeName($store) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <label class="form-label fw-semibold text-secondary small">Từ ngày</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] }}">
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <label class="form-label fw-semibold text-secondary small">Đến ngày</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] }}">
                    </div>
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <label class="form-label fw-semibold text-secondary small">Trạng thái nhiệm vụ</label>
                        <select name="task_status" class="form-select">
                            <option value="all" @selected($filters['task_status'] === 'all')>Tất cả</option>
                            @foreach ($taskStatusLabels as $status => $label)
                                <option value="{{ $status }}" @selected($filters['task_status'] === $status)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-xl-auto col-md-4 col-sm-12 ms-auto d-flex gap-2">
                        <button class="btn btn-primary px-4" type="submit">
                            <i class="fas fa-filter me-1"></i> Lọc
                        </button>
                        <a href="{{ route('admin.specialist-care.index') }}" class="btn btn-outline-secondary px-4">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary KPI Cards -->
        <div class="row g-3 mb-4">
            <!-- KPI 1 -->
            <div class="col-xl-3 col-md-6 col-12">
                <div class="recon-kpi-card">
                    <div class="kpi-icon-box icon-box-blue">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="kpi-content">
                        <span class="kpi-label">TỔNG LOG CHĂM SÓC</span>
                        <span class="kpi-value">{{ $totalLogsCount }}</span>
                        <span class="kpi-subtext text-success"><i class="fas fa-arrow-up"></i> +18 so với kỳ trước</span>
                    </div>
                </div>
            </div>
            <!-- KPI 2 -->
            <div class="col-xl-3 col-md-6 col-12">
                <div class="recon-kpi-card">
                    <div class="kpi-icon-box icon-box-green">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="kpi-content">
                        <span class="kpi-label">NHIỆM VỤ Đang Thực Hiện</span>
                        <span class="kpi-value">{{ $inProgressTasksCount }}</span>
                        <span class="kpi-subtext text-muted"><span class="status-dot dot-green"></span> Đang xử lý</span>
                    </div>
                </div>
            </div>
            <!-- KPI 3 -->
            <div class="col-xl-3 col-md-6 col-12">
                <div class="recon-kpi-card">
                    <div class="kpi-icon-box icon-box-red">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="kpi-content">
                        <span class="kpi-label">NHIỆM VỤ QUÁ HẠN</span>
                        <span class="kpi-value">{{ $overdueTasksCount }}</span>
                        <span class="kpi-subtext text-muted"><span class="status-dot dot-red"></span> Cần xử lý ngay</span>
                    </div>
                </div>
            </div>
            <!-- KPI 4 -->
            <div class="col-xl-3 col-md-6 col-12">
                <div class="recon-kpi-card">
                    <div class="kpi-icon-box icon-box-orange">
                        <i class="fas fa-store"></i>
                    </div>
                    <div class="kpi-content">
                        <span class="kpi-label">CỬA HÀNG CẦN THEO DÕI</span>
                        <span class="kpi-value">{{ $attentionStoresCount }}</span>
                        <span class="kpi-subtext text-muted"><span class="status-dot dot-orange"></span> Phát sinh vấn đề</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Cards Row -->
        <div class="row g-4 mb-4">
            <!-- Cột Trái: Tạo log chăm sóc -->
            <div class="col-xl-6 col-12">
                <div class="card recon-card h-100">
                    <div class="card-header bg-white">
                        <i class="fas fa-edit text-primary me-2"></i> Tạo log chăm sóc
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.specialist-care.logs.store') }}" enctype="multipart/form-data" class="row g-3">
                            @csrf
                            @if (! $isSaleUser)
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold text-secondary small">Chuyên viên</label>
                                    <select name="specialist_id" class="form-select select2-specialist">
                                        <option value="">Theo chuyên viên đang phụ trách cửa hàng</option>
                                        @foreach ($specialistOptions as $specialist)
                                            <option value="{{ $specialist->id }}" @selected(old('specialist_id') == $specialist->id)>
                                                {{ $userName($specialist) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            <div class="col-md-{{ $isSaleUser ? '12' : '6' }}">
                                <label class="form-label fw-semibold text-secondary small">Cửa hàng <span class="text-danger">*</span></label>
                                <select name="store_id" class="form-select select2-store" required>
                                    <option value="">Chọn cửa hàng</option>
                                    @foreach ($storeOptions as $store)
                                        <option value="{{ $store->id }}" @selected(old('store_id', $filters['store_id']) == $store->id)>
                                            {{ $storeName($store) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-{{ $isSaleUser ? '6' : '4' }}">
                                <label class="form-label fw-semibold text-secondary small">Hình thức <span class="text-danger">*</span></label>
                                <select name="care_type" class="form-select" required>
                                    @foreach ($careTypeLabels as $value => $label)
                                        <option value="{{ $value }}" @selected(old('care_type') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-{{ $isSaleUser ? '6' : '4' }}">
                                <label class="form-label fw-semibold text-secondary small">Hẹn chăm sóc tiếp</label>
                                <input type="datetime-local" name="next_follow_up_at" class="form-control" value="{{ old('next_follow_up_at') }}">
                            </div>
                            <div class="col-md-{{ $isSaleUser ? '12' : '4' }}">
                                <label class="form-label fw-semibold text-secondary small">Hình ảnh / file</label>
                                <input type="file" name="attachment" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold text-secondary small">Nội dung chăm sóc <span class="text-danger">*</span></label>
                                <textarea name="care_content" rows="3" class="form-control" required placeholder="Nhập nội dung trao đổi với cửa hàng">{{ old('care_content') }}</textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold text-secondary small">Vấn đề phát hiện</label>
                                <textarea name="problem_detected" rows="2" class="form-control" placeholder="Nhập vấn đề phát hiện">{{ old('problem_detected') }}</textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold text-secondary small">Đề xuất xử lý</label>
                                <textarea name="proposed_solution" rows="2" class="form-control" placeholder="Nhập đề xuất xử lý">{{ old('proposed_solution') }}</textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold text-secondary small">Kết quả chăm sóc</label>
                                <textarea name="result_note" rows="2" class="form-control" placeholder="Nhập kết quả chăm sóc">{{ old('result_note') }}</textarea>
                            </div>
                            <div class="col-12 text-end">
                                <button type="submit" class="btn btn-primary px-4 py-2">
                                    <i class="fas fa-plus me-1"></i> Ghi nhận log
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Cột Phải: Tạo nhiệm vụ -->
            <div class="col-xl-6 col-12">
                <div class="card recon-card h-100">
                    <div class="card-header bg-white">
                        <i class="fas fa-clipboard-list text-primary me-2"></i> Tạo nhiệm vụ
                    </div>
                    <div class="card-body">
                        @if ($isSaleUser)
                            <div class="text-muted text-center py-5">
                                <i class="fas fa-info-circle fa-2x mb-3 text-secondary"></i>
                                <p class="mb-0 fw-semibold">Nhiệm vụ được tạo và giao bởi admin hệ thống.</p>
                                <p class="small text-secondary mb-0">Chuyên viên cập nhật trạng thái và kết quả ở bảng nhiệm vụ bên dưới.</p>
                            </div>
                        @else
                            <form method="POST" action="{{ route('admin.specialist-care.tasks.store') }}" class="row g-3">
                                @csrf
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold text-secondary small">Giao cho chuyên viên <span class="text-danger">*</span></label>
                                    <select name="specialist_id" class="form-select select2-specialist" required>
                                        <option value="">Chọn chuyên viên</option>
                                        @foreach ($specialistOptions as $specialist)
                                            <option value="{{ $specialist->id }}" @selected(old('specialist_id') == $specialist->id)>
                                                {{ $userName($specialist) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold text-secondary small">Cửa hàng liên quan</label>
                                    <select name="store_id" class="form-select select2-store">
                                        <option value="">Không gắn cửa hàng</option>
                                        @foreach ($storeOptions as $store)
                                            <option value="{{ $store->id }}" @selected(old('store_id') == $store->id)>
                                                {{ $storeName($store) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold text-secondary small">Loại nhiệm vụ <span class="text-danger">*</span></label>
                                    <select name="task_type" class="form-select" required>
                                        @foreach ($taskTypeLabels as $value => $label)
                                            <option value="{{ $value }}" @selected(old('task_type') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold text-secondary small">Hạn chót <span class="text-danger">*</span></label>
                                    <input type="datetime-local" name="deadline" class="form-control" value="{{ old('deadline') }}" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold text-secondary small">Tiêu đề <span class="text-danger">*</span></label>
                                    <input type="text" name="title" class="form-control" value="{{ old('title') }}" required maxlength="191" placeholder="Nhập tiêu đề nhiệm vụ">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold text-secondary small">Mô tả</label>
                                    <textarea name="description" rows="3" class="form-control" placeholder="Nhập mô tả chi tiết nhiệm vụ">{{ old('description') }}</textarea>
                                </div>
                                <div class="col-12 text-end">
                                    <button type="submit" class="btn btn-primary px-4 py-2">
                                        <i class="fas fa-paper-plane me-1"></i> Giao nhiệm vụ
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <!-- Tables Row -->
        <div class="row g-4">
            <!-- Cột Trái: Lịch sử chăm sóc -->
            <div class="col-xl-6 col-12">
                <div class="card recon-card">
                    <div class="card-header bg-white">
                        <i class="fas fa-history text-primary me-2"></i> Lịch sử chăm sóc
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="min-width: 90px; width: 100px;">Thời gian</th>
                                        <th style="min-width: 120px; width: 130px;">Cửa hàng</th>
                                        <th style="min-width: 110px; width: 120px;">Chuyên viên</th>
                                        <th style="min-width: 90px; width: 100px;">Hình thức</th>
                                        <th style="min-width: 180px;">Nội dung</th>
                                        <th style="min-width: 65px; width: 70px; text-align: center;">File</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($logs as $log)
                                        <tr>
                                            <td class="text-secondary small">
                                                <div class="fw-semibold text-dark">{{ optional($log->created_at)->format('d/m/Y') }}</div>
                                                <div>{{ optional($log->created_at)->format('H:i') }}</div>
                                                @if ($log->next_follow_up_at)
                                                    <div class="mt-1"><span class="badge bg-light text-primary border" style="font-size: 10px;">Hẹn: {{ optional($log->next_follow_up_at)->format('d/m/Y H:i') }}</span></div>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($log->store)
                                                    <a href="{{ route('admin.stores.show', $log->store->id) }}" class="fw-bold text-decoration-none text-primary">{{ $storeName($log->store) }}</a>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td class="text-dark small">{{ $userName($log->specialist ?: $log->creator) }}</td>
                                            <td>
                                                @php
                                                    $careType = $log->care_type;
                                                    $careBadgeClass = match($careType) {
                                                        'call' => 'badge-call',
                                                        'visit' => 'badge-visit',
                                                        'zalo' => 'badge-zalo',
                                                        'email' => 'badge-email',
                                                        'onsite' => 'badge-onsite',
                                                        default => 'badge-other'
                                                    };
                                                @endphp
                                                <span class="care-badge {{ $careBadgeClass }}">{{ $careTypeLabels[$careType] ?? $careType }}</span>
                                            </td>
                                            <td>
                                                <div class="care-content-box text-sm">
                                                    <div class="text-dark mb-1">{{ $log->care_content ?: $log->content }}</div>
                                                    @if ($log->problem_detected)
                                                        <div class="small mt-1"><span class="fw-bold text-danger">Vấn đề:</span> {{ $log->problem_detected }}</div>
                                                    @endif
                                                    @if ($log->proposed_solution)
                                                        <div class="small mt-1"><span class="fw-bold text-success">Đề xuất:</span> {{ $log->proposed_solution }}</div>
                                                    @endif
                                                    @if ($log->result_note)
                                                        <div class="small mt-1"><span class="fw-bold text-primary">Kết quả:</span> {{ $log->result_note }}</div>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                @if ($log->attachment_file_id)
                                                    <a href="{{ route('storage.public', ['path' => $log->attachment_file_id]) }}" target="_blank" class="btn btn-icon-only btn-light-blue" title="Xem file đính kèm">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">Chưa có nhật ký chăm sóc.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @if (method_exists($logs, 'links'))
                        <div class="card-footer">{{ $logs->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
                    @endif
                </div>
            </div>

            <!-- Cột Phải: Nhiệm vụ -->
            <div class="col-xl-6 col-12">
                <div class="card recon-card">
                    <div class="card-header bg-white">
                        <i class="fas fa-check-double text-primary me-2"></i> Nhiệm vụ
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="min-width: 90px; width: 100px;">Hạn chót</th>
                                        <th style="min-width: 140px; width: 150px;">Nhiệm vụ</th>
                                        <th style="min-width: 120px; width: 130px;">Cửa hàng</th>
                                        <th style="min-width: 110px; width: 120px;">Chuyên viên</th>
                                        <th style="min-width: 80px; width: 90px;">Trạng thái</th>
                                        <th style="min-width: 160px;">Kết quả / thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($tasks as $task)
                                        @php
                                            $deadline = $task->deadline ?: $task->due_at;
                                            $status = (string) ($task->status ?: 'pending');
                                            $statusText = $taskStatusLabels[$status] ?? $status;
                                            
                                            $badgeClass = match($status) {
                                                'completed' => 'bg-success-light text-success border-success-subtle',
                                                'in_progress' => 'bg-info-light text-info border-info-subtle',
                                                'overdue' => 'bg-danger-light text-danger border-danger-subtle',
                                                default => 'bg-warning-light text-warning border-warning-subtle',
                                            };
                                        @endphp
                                        <tr>
                                            <td class="text-secondary small">
                                                <div class="fw-semibold text-dark">{{ optional($deadline)->format('d/m/Y') ?: '-' }}</div>
                                                <div>{{ optional($deadline)->format('H:i') ?: '' }}</div>
                                                @if ($task->completed_at)
                                                    <div class="mt-1 text-success small">Xong: {{ optional($task->completed_at)->format('d/m/Y H:i') }}</div>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-dark">{{ $task->title }}</div>
                                                <span class="badge bg-light text-secondary border mt-1" style="font-size: 10px;">{{ $taskTypeLabels[$task->task_type] ?? $task->task_type }}</span>
                                                @if ($task->description)
                                                    <div class="text-muted small mt-1 text-truncate-custom" title="{{ $task->description }}">{{ $task->description }}</div>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($task->store)
                                                    <a href="{{ route('admin.stores.show', $task->store->id) }}" class="fw-bold text-decoration-none text-primary">{{ $storeName($task->store) }}</a>
                                                @else
                                                    <span class="text-muted">Không gắn cửa hàng</span>
                                                @endif
                                            </td>
                                            <td class="text-dark small">{{ $userName($task->specialist) }}</td>
                                            <td>
                                                <span class="badge {{ $badgeClass }} border px-2 py-1" style="font-size: 11px; font-weight: 600;">{{ $statusText }}</span>
                                            </td>
                                            <td>
                                                @if ($task->result_note)
                                                    <div class="small text-dark mb-2 p-2 rounded bg-light border-start border-3 border-secondary" style="max-height: 80px; overflow-y: auto;">
                                                        <span class="fw-bold">Kết quả:</span> {{ $task->result_note }}
                                                    </div>
                                                @endif
                                                @if ($isSaleUser && $status !== 'completed')
                                                    <div class="d-flex flex-column gap-2 mt-1">
                                                        @if ($status === 'pending')
                                                            <form method="POST" action="{{ route('admin.specialist-care.tasks.start', $task->id) }}">
                                                                @csrf
                                                                @method('PATCH')
                                                                <button class="btn btn-sm btn-outline-primary w-100" type="submit" style="font-size: 11px;">Bắt đầu làm</button>
                                                            </form>
                                                        @endif
                                                        <form method="POST" action="{{ route('admin.specialist-care.tasks.complete', $task->id) }}" class="w-100">
                                                            @csrf
                                                            @method('PATCH')
                                                            <textarea name="result_note" rows="1" class="form-control form-control-sm mb-1" required placeholder="Nhập kết quả..." style="font-size: 12px; resize: none;"></textarea>
                                                            <button class="btn btn-sm btn-success w-100" type="submit" style="font-size: 11px;">Hoàn thành</button>
                                                        </form>
                                                    </div>
                                                @elseif (! $task->result_note)
                                                    <span class="text-muted small">Chưa có kết quả.</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">Chưa có nhiệm vụ.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @if (method_exists($tasks, 'links'))
                        <div class="card-footer">{{ $tasks->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

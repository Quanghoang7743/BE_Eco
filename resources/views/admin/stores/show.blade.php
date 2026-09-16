@extends('admin.layout')

@section('title', 'Chi tiết cửa hàng')

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

        .store-detail-wrapper {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--recon-text);
            padding-bottom: 30px;
        }

        .btn-icon-only {
            width: 38px;
            height: 38px;
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

        /* KPI horizontal row layout */
        .kpi-horizontal-row {
            display: flex;
            flex-wrap: nowrap;
            gap: 10px;
            overflow-x: auto;
            padding-bottom: 8px;
            margin-bottom: 24px;
        }
        .kpi-col-item {
            flex: 1 0 150px;
            min-width: 155px;
        }
        @media (max-width: 1400px) {
            .kpi-col-item {
                flex: 0 0 175px;
            }
        }

        /* KPI grid styling */
        .recon-kpi-card {
            background: #ffffff;
            border: 1px solid var(--recon-border);
            border-radius: 12px;
            padding: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);
            display: flex;
            align-items: center;
            gap: 10px;
            height: 100%;
        }
        .kpi-icon-box {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .kpi-content {
            display: flex;
            flex-direction: column;
            gap: 1px;
            min-width: 0;
        }
        .kpi-label {
            font-size: 9.5px;
            font-weight: 600;
            color: var(--recon-muted);
            text-transform: uppercase;
            letter-spacing: 0.02em;
            line-height: 1.2;
        }
        .kpi-value {
            font-size: 16px;
            font-weight: 800;
            color: var(--recon-text);
            line-height: 1.1;
            word-break: break-word;
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
        .icon-box-purple {
            background-color: #f3e8ff;
            color: #6b21a8;
            border: 1px solid rgba(107, 33, 168, 0.2);
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
        
        .care-content-box div + div {
            margin-top: 6px;
        }
    </style>
@endsection

@section('content')
    @php
        $number = fn ($value) => number_format((int) $value, 0, ',', '.');
        $money = fn ($value) => number_format((float) $value, 0, ',', '.') . ' đ';
        $percent = fn ($value) => number_format((float) $value, 2, ',', '.') . '%';

        $storeStatus = strtolower((string) ($store->status ?? ''));
        $isOpen = (bool) ($store->is_open ?? true);
        $statusLabel = $isOpen && in_array($storeStatus, ['active', 'approved'], true)
            ? 'Hoạt động'
            : (!$isOpen ? 'Tạm ngưng' : ($store->status ?: 'N/A'));
        $statusClass = $isOpen && in_array($storeStatus, ['active', 'approved'], true)
            ? 'success'
            : (!$isOpen ? 'warning text-dark' : 'secondary');

        $careTypeLabels = [
            'call' => 'Gọi điện',
            'visit' => 'Gặp trực tiếp',
            'zalo' => 'Zalo',
            'email' => 'Email',
            'onsite' => 'Hỗ trợ tại quán',
            'other' => 'Khác',
        ];
        $complaintStatusLabels = [
            'pending' => 'Mới tiếp nhận',
            'processing' => 'Đang xử lý',
            'resolved' => 'Đã giải quyết',
            'closed' => 'Đã đóng',
        ];

        $assignmentNoteLabel = function ($note): string {
            $note = trim((string) $note);
            if ($note === '') {
                return 'N/A';
            }

            $lowerNote = strtolower($note);
            if ($lowerNote === 'backfilled from stores.sale_id') {
                return 'Tự động đồng bộ từ phân công cửa hàng.';
            }

            if ($lowerNote === 'updated from cms store assignment') {
                return 'Cập nhật từ phân công trên CMS.';
            }

            return $note;
        };
    @endphp

    <div class="container-fluid store-detail-wrapper">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div class="d-flex align-items-center">
                <a href="{{ route('admin.stores.index') }}" class="btn btn-icon-only btn-light me-3" title="Quay lại">
                    <i class="fas fa-arrow-left text-secondary"></i>
                </a>
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <h1 class="h3 mb-0 fw-bold text-dark">{{ $store->name }}</h1>
                        <span class="badge bg-{{ $statusClass }} rounded-pill px-2 py-1" style="font-size: 11px;">{{ $statusLabel }}</span>
                    </div>
                    <p class="text-muted mb-0 small mt-1">{{ $store->store_code ?: $store->id }}</p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.stores.index') }}" class="btn btn-outline-secondary d-flex align-items-center gap-1 px-3 py-2">
                    <i class="fas fa-arrow-left small"></i> Danh sách
                </a>
                @if (!\App\Support\AdminCmsAccess::isSale())
                    <a href="{{ route('admin.stores.edit', $store->id) }}" class="btn btn-primary d-flex align-items-center gap-1 px-3 py-2">
                        <i class="fas fa-pen small"></i> Sửa cửa hàng
                    </a>
                @endif
            </div>
        </div>

        <!-- Alerts -->
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

        <!-- Row 1: Info Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-6 col-12">
                <div class="card recon-card h-100">
                    <div class="card-header bg-white">
                        <i class="fas fa-store text-primary me-2"></i> Thông tin cửa hàng
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-md-6 col-12">
                                <div class="mb-2">
                                    <i class="fas fa-info-circle text-muted me-1" style="width: 14px;"></i>
                                    <strong>Trạng thái:</strong>
                                    <span class="badge bg-{{ $statusClass }} ms-1">{{ $statusLabel }}</span>
                                </div>
                                <div class="mb-2">
                                    <i class="fas fa-envelope text-muted me-1" style="width: 14px;"></i>
                                    <strong>Email:</strong>
                                    <span class="text-dark ms-1">{{ $store->email ?: 'N/A' }}</span>
                                </div>
                                <div class="mb-2">
                                    <i class="fas fa-briefcase text-muted me-1" style="width: 14px;"></i>
                                    <strong>Loại hình:</strong>
                                    <span class="text-dark ms-1">{{ $store->business_type ?: 'N/A' }}</span>
                                </div>
                            </div>
                            <div class="col-md-6 col-12">
                                <div class="mb-2">
                                    <i class="fas fa-phone text-muted me-1" style="width: 14px;"></i>
                                    <strong>Số điện thoại:</strong>
                                    <span class="text-dark ms-1">{{ $store->phone ?: 'N/A' }}</span>
                                </div>
                                <div class="mb-2">
                                    <i class="fas fa-user text-muted me-1" style="width: 14px;"></i>
                                    <strong>Người đại diện:</strong>
                                    <span class="text-dark ms-1">{{ $store->representative ?: 'N/A' }}</span>
                                </div>
                            </div>
                            <div class="col-12 border-top pt-2 mt-2">
                                <div>
                                    <i class="fas fa-map-marker-alt text-muted me-1" style="width: 14px;"></i>
                                    <strong>Địa chỉ:</strong>
                                    <span class="text-dark ms-1">{{ $store->address ?: 'N/A' }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-6 col-12">
                <div class="card recon-card h-100">
                    <div class="card-header bg-white">
                        <i class="fas fa-user-shield text-primary me-2"></i> Thông tin chuyên viên phụ trách
                    </div>
                    <div class="card-body">
                        @if ($store->salePerson)
                            <div class="row g-2">
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <i class="fas fa-user-tie text-muted me-1" style="width: 14px;"></i>
                                        <strong>Họ tên:</strong>
                                        <span class="text-dark ms-1">{{ $store->salePerson->full_name ?: $store->salePerson->name }}</span>
                                    </div>
                                    <div class="mb-2">
                                        <i class="fas fa-envelope text-muted me-1" style="width: 14px;"></i>
                                        <strong>Email:</strong>
                                        <span class="text-dark ms-1">{{ $store->salePerson->email ?: 'N/A' }}</span>
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="mb-2">
                                        <i class="fas fa-phone text-muted me-1" style="width: 14px;"></i>
                                        <strong>Số điện thoại:</strong>
                                        <span class="text-dark ms-1">{{ $store->salePerson->phone ?: 'N/A' }}</span>
                                    </div>
                                    <div class="mb-2">
                                        <i class="fas fa-percentage text-muted me-1" style="width: 14px;"></i>
                                        <strong>Tỷ lệ hoa hồng:</strong>
                                        <span class="text-dark ms-1">{{ $percent($store->sale_commission_rate ?? 0) }}</span>
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="text-muted text-center py-4">
                                <i class="fas fa-info-circle mb-2 fa-lg text-secondary"></i>
                                <p class="mb-0">Cửa hàng chưa được gán chuyên viên.</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 2: KPI Metrics (Always in 1 Row) -->
        <div class="kpi-horizontal-row">
            <!-- Card 1 -->
            <div class="kpi-col-item">
                <div class="recon-kpi-card">
                    <div class="kpi-icon-box icon-box-blue">
                        <i class="fas fa-shopping-basket"></i>
                    </div>
                    <div class="kpi-content">
                        <span class="kpi-label">Tổng đơn thành công</span>
                        <span class="kpi-value">{{ $number($insights['orders']['successful']) }}</span>
                    </div>
                </div>
            </div>
            <!-- Card 2 -->
            <div class="kpi-col-item">
                <div class="recon-kpi-card">
                    <div class="kpi-icon-box icon-box-green">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                    <div class="kpi-content">
                        <span class="kpi-label">Tổng doanh số</span>
                        <span class="kpi-value">{{ $number($insights['orders']['revenue']) }}</span>
                    </div>
                </div>
            </div>
            <!-- Card 3 -->
            <div class="kpi-col-item">
                <div class="recon-kpi-card">
                    <div class="kpi-icon-box icon-box-purple">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="kpi-content">
                        <span class="kpi-label">Tổng ticket</span>
                        <span class="kpi-value">{{ $number($insights['tickets']['total']) }}</span>
                    </div>
                </div>
            </div>
            <!-- Card 4 -->
            <div class="kpi-col-item">
                <div class="recon-kpi-card">
                    <div class="kpi-icon-box icon-box-orange">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="kpi-content">
                        <span class="kpi-label">Thu nhập từ cửa hàng</span>
                        <span class="kpi-value">{{ $number($insights['income']['amount']) }}</span>
                    </div>
                </div>
            </div>
            <!-- Card 5 -->
            <div class="kpi-col-item">
                <div class="recon-kpi-card">
                    <div class="kpi-icon-box icon-box-green">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="kpi-content">
                        <span class="kpi-label">Ticket hợp lệ</span>
                        <span class="kpi-value text-success">{{ $number($insights['tickets']['eligible']) }}</span>
                    </div>
                </div>
            </div>
            <!-- Card 6 -->
            <div class="kpi-col-item">
                <div class="recon-kpi-card">
                    <div class="kpi-icon-box icon-box-orange">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="kpi-content">
                        <span class="kpi-label">Ticket treo</span>
                        <span class="kpi-value text-warning">{{ $number($insights['tickets']['held']) }}</span>
                    </div>
                </div>
            </div>
            <!-- Card 7 -->
            <div class="kpi-col-item">
                <div class="recon-kpi-card">
                    <div class="kpi-icon-box icon-box-red">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="kpi-content">
                        <span class="kpi-label">Ticket hủy</span>
                        <span class="kpi-value text-danger">{{ $number($insights['tickets']['cancelled']) }}</span>
                    </div>
                </div>
            </div>
            <!-- Card 8 -->
            <div class="kpi-col-item">
                <div class="recon-kpi-card">
                    <div class="kpi-icon-box icon-box-blue">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="kpi-content">
                        <span class="kpi-label">Tỷ lệ hủy / treo</span>
                        <span class="kpi-value" style="font-size: 12px; font-weight: 800;">{{ $percent($insights['rates']['cancel_rate']) }} / {{ $percent($insights['rates']['held_ticket_rate']) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 3: Forms and Tables -->
        <div class="row g-4">
            <!-- Cột Trái: Khiếu nại cửa hàng & Lịch sử phân công chuyên viên -->
            <div class="col-xl-6 col-12 d-flex flex-column gap-4">
                <!-- Khiếu nại cửa hàng -->
                <div class="card recon-card">
                    <div class="card-header bg-white">
                        <i class="fas fa-exclamation-triangle text-primary me-2"></i> Khiếu nại cửa hàng
                    </div>
                    <div class="card-body p-0">
                        @if ($complaints->isEmpty())
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-inbox mb-2" style="font-size: 36px; color: #cbd5e1;"></i>
                                <div class="small">Chưa có khiếu nại.</div>
                            </div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th style="width: 100px;">Mã</th>
                                            <th>Nội dung</th>
                                            <th style="width: 120px;">Trạng thái</th>
                                            <th style="width: 140px;">Ngày tạo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($complaints as $complaint)
                                            @php
                                                $complaintStatusClass = match($complaint->status) {
                                                    'resolved' => 'bg-success-light text-success border-success-subtle',
                                                    'closed' => 'bg-secondary text-dark border-secondary-subtle',
                                                    'processing' => 'bg-info-light text-info border-info-subtle',
                                                    default => 'bg-warning-light text-warning border-warning-subtle',
                                                };
                                            @endphp
                                            <tr>
                                                <td>
                                                    <a href="{{ route('admin.complaints.show', $complaint->id) }}" class="fw-bold text-decoration-none text-primary">
                                                        {{ $complaint->complaint_code ?: substr($complaint->id, 0, 8) . '...' }}
                                                    </a>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold text-dark">{{ $complaint->title ?: 'N/A' }}</div>
                                                    <small class="text-muted">{{ $complaint->severity ?: 'normal' }}</small>
                                                </td>
                                                <td>
                                                    <span class="badge {{ $complaintStatusClass }} border px-2 py-1" style="font-size: 11px; font-weight: 600;">
                                                        {{ $complaintStatusLabels[$complaint->status] ?? $complaint->status }}
                                                    </span>
                                                </td>
                                                <td class="text-secondary small">
                                                    {{ $complaint->created_at ? \Carbon\Carbon::parse($complaint->created_at)->format('d/m/Y H:i') : 'N/A' }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Lịch sử phân công chuyên viên -->
                <div class="card recon-card">
                    <div class="card-header bg-white">
                        <i class="fas fa-history text-primary me-2"></i> Lịch sử phân công chuyên viên
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Chuyên viên</th>
                                        <th style="width: 140px;">Từ ngày</th>
                                        <th style="width: 140px;">Đến ngày</th>
                                        <th>Ghi chú</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($assignmentHistory as $assignment)
                                        <tr>
                                            <td>
                                                <div class="fw-semibold text-dark">{{ $assignment->specialist_full_name ?: ($assignment->specialist_name ?: $assignment->specialist_id) }}</div>
                                                <small class="text-muted">{{ $assignment->specialist_email ?: '' }}</small>
                                            </td>
                                            <td class="text-secondary small">
                                                {{ $assignment->assigned_from ? \Carbon\Carbon::parse($assignment->assigned_from)->format('d/m/Y H:i') : 'N/A' }}
                                            </td>
                                            <td class="text-secondary small">
                                                {{ $assignment->assigned_to ? \Carbon\Carbon::parse($assignment->assigned_to)->format('d/m/Y H:i') : 'Đang phụ trách' }}
                                            </td>
                                            <td class="text-secondary small">
                                                {{ $assignmentNoteLabel($assignment->notes ?? null) }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center text-muted py-4">Chưa có lịch sử phân công.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cột Phải: Lịch sử chăm sóc (Form & Table) -->
            <div class="col-xl-6 col-12">
                <div class="card recon-card h-100">
                    <div class="card-header bg-white">
                        <i class="fas fa-heart text-primary me-2"></i> Lịch sử chăm sóc
                    </div>
                    <div class="card-body">
                        <!-- Form -->
                        <form method="POST" action="{{ route('admin.stores.care-logs.store', $store->id) }}" enctype="multipart/form-data" class="mb-4 pb-4 border-bottom">
                            @csrf
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold text-secondary small">Hình thức <span class="text-danger">*</span></label>
                                    <select name="care_type" class="form-select" required>
                                        @foreach ($careTypeLabels as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold text-secondary small">Hẹn chăm sóc tiếp</label>
                                    <input type="datetime-local" name="next_follow_up_at" class="form-control">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold text-secondary small">Hình ảnh / file</label>
                                    <input type="file" name="attachment" class="form-control">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold text-secondary small">Nội dung chăm sóc <span class="text-danger">*</span></label>
                                    <textarea name="care_content" rows="3" class="form-control" required placeholder="Nhập nội dung trao đổi với cửa hàng...">{{ old('care_content', old('content')) }}</textarea>
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
                                    <button class="btn btn-primary px-4 py-2" type="submit">
                                        <i class="fas fa-plus me-1"></i> Ghi nhận
                                    </button>
                                </div>
                            </div>
                        </form>

                        <!-- Logs Table -->
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 110px;">Thời gian</th>
                                        <th style="width: 120px;">Người phụ trách</th>
                                        <th>Nội dung</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($careLogs as $log)
                                        <tr>
                                            <td class="text-secondary small">
                                                <div class="fw-semibold text-dark">{{ $log->created_at ? \Carbon\Carbon::parse($log->created_at)->format('d/m/Y') : 'N/A' }}</div>
                                                <div>{{ $log->created_at ? \Carbon\Carbon::parse($log->created_at)->format('H:i') : '' }}</div>
                                                @if ($log->next_follow_up_at)
                                                    <div class="mt-1">
                                                        <span class="badge bg-light text-primary border" style="font-size: 10px;">
                                                            Hẹn: {{ \Carbon\Carbon::parse($log->next_follow_up_at)->format('d/m/Y H:i') }}
                                                        </span>
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="text-dark small">
                                                {{ $log->specialist_full_name ?: ($log->specialist_name ?: ($log->creator_full_name ?: $log->creator_name)) }}
                                            </td>
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
                                                <div class="mt-2 text-dark">{{ data_get($log, 'care_content') ?: $log->content }}</div>
                                                
                                                @if (data_get($log, 'problem_detected'))
                                                    <div class="small mt-1"><span class="fw-bold text-danger">Vấn đề:</span> {{ data_get($log, 'problem_detected') }}</div>
                                                @endif
                                                @if (data_get($log, 'proposed_solution'))
                                                    <div class="small mt-1"><span class="fw-bold text-success">Đề xuất:</span> {{ data_get($log, 'proposed_solution') }}</div>
                                                @endif
                                                @if (data_get($log, 'result_note'))
                                                    <div class="small mt-1"><span class="fw-bold text-primary">Kết quả:</span> {{ data_get($log, 'result_note') }}</div>
                                                @endif
                                                @if (data_get($log, 'attachment_file_id'))
                                                    <div class="mt-2">
                                                        <a href="{{ route('storage.public', ['path' => data_get($log, 'attachment_file_id')]) }}" target="_blank" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 py-1 px-2" style="font-size: 11px;">
                                                            <i class="fas fa-paperclip"></i> File đính kèm
                                                        </a>
                                                    </div>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="text-center text-muted py-4">Chưa có lịch sử chăm sóc.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@extends('admin.layout')

@section('title', 'Chi tiết điểm tín dụng chuyên viên')

@section('content')
    @php
        $displayName = $profile->user
            ? ($profile->user->full_name ?: ($profile->user->name ?: ($profile->user->email ?: 'N/A')))
            : 'N/A';
        $changeText = fn ($value): string => ((int) $value > 0 ? '+' : '') . (int) $value;
        $actionClass = fn ($action): string => match ((string) $action) {
            'PLUS' => 'text-success',
            'MINUS' => 'text-danger',
            'ADJUST' => 'text-primary',
            'CANCEL' => 'text-warning',
            default => 'text-muted',
        };
        $actorName = fn ($user): string => $user ? ($user->full_name ?: ($user->name ?: ($user->email ?: 'N/A'))) : 'Hệ thống';
        $statusBadgeClass = fn ($status) => match ($status) {
            'ACTIVE' => 'status-badge-paid',
            'CANCELLED' => 'status-badge-draft',
            default => 'status-badge-default',
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

        .credit-detail-wrapper {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--recon-text);
            padding-bottom: 30px;
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

        /* KPI styling */
        .kpi-title {
            font-size: 11px;
            font-weight: 600;
            color: var(--recon-muted);
            letter-spacing: 0.05em;
        }
        .kpi-subtext {
            font-size: 11.5px;
            color: var(--recon-muted);
        }
        .kpi-progress-track {
            width: 140px;
            height: 6px;
            background-color: #f1f5f9;
            border-radius: 3px;
            overflow: hidden;
        }
        .kpi-progress-bar {
            height: 100%;
            background-color: var(--recon-primary);
            border-radius: 3px;
        }

        /* Override badge rank colors inside view scope */
        .badge.bg-success {
            background-color: var(--recon-success-light) !important;
            color: #065f46 !important;
            border: 1px solid rgba(16, 185, 129, 0.2) !important;
            font-weight: 700 !important;
        }
        .badge.bg-primary {
            background-color: var(--recon-primary-light) !important;
            color: var(--recon-primary) !important;
            border: 1px solid var(--recon-primary-border) !important;
            font-weight: 700 !important;
        }
        .badge.bg-warning {
            background-color: var(--recon-warning-light) !important;
            color: #854d0e !important;
            border: 1px solid rgba(245, 158, 11, 0.2) !important;
            font-weight: 700 !important;
        }
        .badge.bg-danger {
            background-color: var(--recon-danger-light) !important;
            color: #991b1b !important;
            border: 1px solid rgba(239, 68, 68, 0.2) !important;
            font-weight: 700 !important;
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
        .status-badge-draft {
            background-color: #f3e5f5;
            color: #7b1fa2;
            border: 1px solid #e1bee7;
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
            padding: 10px 8px !important;
            background-color: #f8fafc !important;
            border-bottom: 1px solid var(--recon-border) !important;
            vertical-align: middle !important;
            letter-spacing: normal !important;
            line-height: 1.2 !important;
        }
        .table td {
            padding: 10px 8px !important;
            font-size: 11.5px !important;
            color: #0f172a !important;
            border-bottom: 1px solid #f1f5f9 !important;
            vertical-align: middle !important;
            line-height: 1.3 !important;
        }
        .table tbody tr:last-child td {
            border-bottom: none;
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
        .btn-danger {
            background-color: var(--recon-danger);
            border-color: var(--recon-danger);
            color: #ffffff;
        }
        .btn-danger:hover {
            background-color: #dc3545;
            border-color: #bd2130;
        }
    </style>

    <div class="container-fluid credit-detail-wrapper">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1" style="font-size: 13.5px; color: var(--recon-muted);">
                    <span>Trang chủ</span>
                    <span style="color: #cbd5e1;">/</span>
                    <span>Chuyên viên</span>
                    <span style="color: #cbd5e1;">/</span>
                    <span class="text-primary fw-semibold">Chi tiết điểm tín dụng</span>
                </div>
                <h1 class="h3 mb-1 fw-bold text-dark">Chi tiết điểm tín dụng</h1>
                <div class="text-muted small">
                    <i class="fas fa-user-circle me-1"></i> <strong class="text-dark">{{ $displayName }}</strong> · {{ $profile->user?->email ?: $profile->user?->phone }}
                </div>
            </div>
            <a href="{{ route('admin.specialist-credit.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Quay lại
            </a>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4 col-12">
                <div class="card recon-card h-100 text-center">
                    <div class="card-body d-flex flex-column align-items-center justify-content-center py-4">
                        <div class="kpi-title text-uppercase mb-2">Điểm hiện tại</div>
                        <div class="kpi-value text-primary display-6 fw-bold mb-2">{{ (int) $profile->credit_points }}/100</div>
                        <div class="kpi-progress-track mb-3">
                            <div class="kpi-progress-bar" style="width: {{ (int) $profile->credit_points }}%"></div>
                        </div>
                        <div class="kpi-subtext text-muted">Điểm tối đa đạt được</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="card recon-card h-100 text-center">
                    <div class="card-body d-flex flex-column align-items-center justify-content-center py-4">
                        <div class="kpi-title text-uppercase mb-3">Xếp loại</div>
                        <div class="mb-3">
                            <span class="badge {{ $rankBadgeClass((int) $profile->credit_points) }} px-3 py-2 fs-6 rounded" style="letter-spacing: 0.5px;">
                                {{ $profile->credit_rank_label }}
                            </span>
                        </div>
                        <div class="kpi-subtext text-muted mt-2">Xếp loại tín dụng hiện tại</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="card recon-card h-100 text-center">
                    <div class="card-body d-flex flex-column align-items-center justify-content-center py-4">
                        <div class="kpi-title text-uppercase mb-2">Trạng thái tài khoản</div>
                        <div class="mb-3">
                            @php
                                $statusVal = strtolower($profile->user?->status ?? '');
                                $statusText = match ($statusVal) {
                                    'active' => 'Hoạt động',
                                    'inactive' => 'Không hoạt động',
                                    'suspended' => 'Tạm khóa',
                                    'blocked' => 'Đã khóa',
                                    default => $profile->user?->status ?: 'N/A',
                                };
                            @endphp
                            <span class="fw-bold @if($statusVal === 'active') text-success @else text-danger @endif" style="font-size: 22px; letter-spacing: 0.5px;">
                                {{ $statusText }}
                            </span>
                        </div>
                        <div class="kpi-subtext text-muted">Chuyên viên không thể tự chỉnh sửa điểm tín dụng.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-8 col-12">
                <div class="card recon-card mb-3">
                    <div class="card-header"><i class="fas fa-filter text-primary"></i> Lọc lịch sử điểm tín dụng</div>
                    <div class="card-body">
                        <form method="GET" action="{{ route('admin.specialist-credit.show', $profile->id) }}" class="row g-3 align-items-end">
                            <div class="col-md-5 col-12">
                                <label class="form-label">Trạng thái log</label>
                                <select name="log_status" class="form-select">
                                    <option value="all" @selected($filters['log_status'] === 'all')>Tất cả</option>
                                    @foreach ($statusLabels as $status => $label)
                                        <option value="{{ $status }}" @selected($filters['log_status'] === $status)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-5 col-12">
                                <label class="form-label">Loại thay đổi</label>
                                <select name="action_type" class="form-select">
                                    <option value="all" @selected($filters['action_type'] === 'all')>Tất cả</option>
                                    @foreach ($actionLabels as $action => $label)
                                        <option value="{{ $action }}" @selected($filters['action_type'] === $action)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2 col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter me-1"></i> Lọc
                                </button>
                                <a href="{{ route('admin.specialist-credit.show', $profile->id) }}" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-redo me-1"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card recon-card">
                    <div class="card-header"><i class="fas fa-history text-primary"></i> Lịch sử cộng / trừ / điều chỉnh điểm</div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Thời gian</th>
                                    <th>Loại</th>
                                    <th class="text-end">Thay đổi</th>
                                    <th class="text-end">Trước</th>
                                    <th class="text-end">Sau</th>
                                    <th>Người đánh giá</th>
                                    <th>Lý do</th>
                                    <th>Trạng thái</th>
                                    @if ($canManageCredit)
                                        <th class="text-end">Thao tác</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($history as $log)
                                    <tr>
                                        <td class="nowrap">{{ optional($log->created_at)->format('d/m/Y H:i') }}</td>
                                        <td>{{ $actionLabels[$log->action_type] ?? $log->action_type }}</td>
                                        <td class="text-end fw-semibold {{ $actionClass($log->action_type) }}">{{ $changeText($log->points_change) }}</td>
                                        <td class="text-end fw-medium text-dark">{{ (int) $log->points_before }}</td>
                                        <td class="text-end fw-medium text-dark">{{ (int) $log->points_after }}</td>
                                        <td>{{ $actorName($log->adminUser) }}</td>
                                        <td style="min-width: 240px;">
                                            <div class="text-dark fw-medium">{{ $log->reason }}</div>
                                            @if ($log->cancel_reason)
                                                <div class="mt-1"><small class="text-danger">Lý do hủy: {{ $log->cancel_reason }}</small></div>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="status-badge-custom {{ $statusBadgeClass((string) $log->status) }}">
                                                {{ $statusLabels[$log->status] ?? $log->status }}
                                            </span>
                                        </td>
                                        @if ($canManageCredit)
                                            <td class="text-end">
                                                @if ($log->status === 'ACTIVE' && $log->action_type !== 'CANCEL')
                                                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal-{{ $log->id }}">
                                                        Hủy
                                                    </button>

                                                    <!-- Modal for Hủy -->
                                                    <div class="modal fade text-start" id="cancelModal-{{ $log->id }}" tabindex="-1" aria-labelledby="cancelModalLabel-{{ $log->id }}" aria-hidden="true">
                                                        <div class="modal-dialog modal-dialog-centered">
                                                            <div class="modal-content" style="border-radius: 12px; border: none; box-shadow: 0 10px 25px rgba(0,0,0,0.15);">
                                                                <div class="modal-header border-bottom-0 pt-4 px-4 pb-2">
                                                                    <h5 class="modal-title fw-bold text-dark" id="cancelModalLabel-{{ $log->id }}" style="font-size: 16px;">
                                                                        <i class="fas fa-exclamation-triangle text-warning me-2"></i> Hủy đánh giá điểm tín dụng
                                                                    </h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                </div>
                                                                <form method="POST" action="{{ route('admin.specialist-credit.logs.cancel', $log->id) }}">
                                                                    @csrf
                                                                    @method('PATCH')
                                                                    <div class="modal-body px-4 py-3">
                                                                        <p class="text-muted small mb-3">Bạn có chắc chắn muốn hủy đánh giá này? Điểm tín dụng của chuyên viên sẽ được hoàn lại tương ứng.</p>
                                                                        <div class="mb-3">
                                                                            <label class="form-label fw-semibold text-secondary small">Lý do hủy <span class="text-danger">*</span></label>
                                                                            <textarea name="cancel_reason" class="form-control" rows="3" required placeholder="Bắt buộc nhập lý do hủy đánh giá..."></textarea>
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer border-top-0 px-4 pb-4 pt-2 d-flex gap-2 justify-content-end">
                                                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" style="height: 36px;">Đóng</button>
                                                                        <button type="submit" class="btn btn-danger btn-sm" style="height: 36px; padding: 0 16px;">Xác nhận hủy</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @else
                                                    <span class="text-muted small">N/A</span>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ $canManageCredit ? 9 : 8 }}" class="text-center text-muted py-4">Chưa có lịch sử điểm tín dụng.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if (method_exists($history, 'links'))
                        <div class="card-footer bg-white border-top">{{ $history->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
                    @endif
                </div>
            </div>

            <div class="col-xl-4 col-12">
                @if ($canManageCredit)
                    <div class="card recon-card">
                        <div class="card-header"><i class="fas fa-edit text-primary"></i> Tạo đánh giá điểm tín dụng</div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.specialist-credit.evaluate', $profile->id) }}" class="row g-3">
                                @csrf
                                <div class="col-12">
                                    <label class="form-label">Loại đánh giá</label>
                                    <select name="action_type" class="form-select" required>
                                        <option value="PLUS">Cộng điểm</option>
                                        <option value="MINUS">Trừ điểm</option>
                                        <option value="ADJUST">Điều chỉnh về điểm cụ thể</option>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Số điểm</label>
                                    <input type="number" name="points" class="form-control text-end font-monospace" min="0" max="100" step="1" required placeholder="Nhập số điểm">
                                    <div class="text-muted small mt-1" style="font-size: 11.5px;">
                                        Ví dụ: 10, -5, 0. Với Cộng/Trừ: nhập số điểm thay đổi. Với Điều chỉnh: nhập điểm cuối cùng cần đặt.
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Lý do</label>
                                    <textarea name="reason" class="form-control" rows="4" required placeholder="Bắt buộc nhập lý do đánh giá..."></textarea>
                                    <div class="text-muted small mt-1" style="font-size: 11.5px;">
                                        Vui lòng nhập lý do chi tiết cho việc đánh giá điểm.
                                    </div>
                                </div>

                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-save me-1"></i> Lưu đánh giá
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                @else
                    <div class="card recon-card">
                        <div class="card-header"><i class="fas fa-lock text-muted"></i> Quyền truy cập</div>
                        <div class="card-body text-muted small">
                            Chuyên viên chỉ được xem điểm tín dụng và lịch sử của chính mình.
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

@extends('admin.layout')

@section('content')
    <style>
        .staff-header-card {
            border: 0;
            background: linear-gradient(120deg, #0d6efd 0%, #4b6cb7 100%);
            color: #fff;
        }

        .staff-avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            border: 3px solid rgba(255, 255, 255, 0.35);
            object-fit: cover;
            background: rgba(255, 255, 255, 0.15);
        }

        .kpi-box {
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.14);
            padding: 12px;
            min-height: 94px;
        }

        .kpi-value {
            font-size: 1.45rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .revenue-badge-high {
            background: #198754;
            color: #fff;
        }

        .revenue-badge-mid {
            background: #fd7e14;
            color: #fff;
        }

        .revenue-badge-low {
            background: #6c757d;
            color: #fff;
        }

        .commission-input {
            min-width: 120px;
        }

        .commission-preview {
            font-weight: 600;
        }
    </style>

    <div class="container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <h2 class="mb-0">Hiệu suất chuyên viên cửa hàng</h2>
            <a href="{{ route('admin.stores.index') }}" class="btn btn-outline-secondary">Quay lại danh sách cửa hàng</a>
        </div>

        <div class="card staff-header-card mb-3">
            <div class="card-body">
                <div class="row g-3 align-items-center">
                    <div class="col-lg-4">
                        <div class="d-flex align-items-center gap-3">
                            @php
                                $avatar = !empty($staff->avatar_path) ? asset('storage/' . ltrim((string) $staff->avatar_path, '/')) : null;
                            @endphp
                            @if ($avatar)
                                <img src="{{ $avatar }}" alt="{{ $staff->full_name ?: $staff->name }}" class="staff-avatar">
                            @else
                                <div class="staff-avatar d-flex align-items-center justify-content-center fw-bold fs-4">
                                    {{ strtoupper(mb_substr($staff->full_name ?: $staff->name, 0, 1)) }}
                                </div>
                            @endif

                            <div>
                                <div class="h4 mb-1">{{ $staff->full_name ?: $staff->name }}</div>
                                <div class="opacity-75">SĐT: {{ $staff->phone ?: 'N/A' }}</div>
                                <div class="opacity-75">Email: {{ $staff->email ?: 'N/A' }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-8">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="kpi-box">
                                    <div class="text-uppercase small opacity-75">Số cửa hàng phụ trách</div>
                                    <div class="kpi-value">{{ number_format($summary['total_stores']) }}</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="kpi-box">
                                    <div class="text-uppercase small opacity-75">Tổng đơn thành công</div>
                                    <div class="kpi-value">{{ number_format($summary['total_orders']) }}</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="kpi-box">
                                    <div class="text-uppercase small opacity-75">Tổng doanh thu</div>
                                    <div class="kpi-value">{{ number_format($summary['total_revenue'], 0, ',', '.') }} đ</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="kpi-box">
                                    <div class="text-uppercase small opacity-75">Tổng giá trị Ticket</div>
                                    <div class="kpi-value">{{ number_format($summary['total_ticket_value'] ?? 0, 0, ',', '.') }} đ</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="kpi-box">
                                    <div class="text-uppercase small opacity-75">Hoa hồng dự tính theo Ticket</div>
                                    <div class="kpi-value">{{ number_format($summary['total_estimated_commission'] ?? 0, 0, ',', '.') }} đ</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.sales.performance.show', $staff->id) }}" class="row g-3 align-items-end">
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label">Khoảng thời gian</label>
                        <select name="range" id="rangeSelect" class="form-select">
                            <option value="this_month" {{ ($filters['range'] ?? 'this_month') === 'this_month' ? 'selected' : '' }}>Tháng này</option>
                            <option value="last_month" {{ ($filters['range'] ?? '') === 'last_month' ? 'selected' : '' }}>Tháng trước</option>
                            <option value="custom" {{ ($filters['range'] ?? '') === 'custom' ? 'selected' : '' }}>Tùy chọn</option>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label">Từ ngày</label>
                        <input type="date" name="from_date" id="fromDate" class="form-control"
                            value="{{ $fromDate }}">
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label">Đến ngày</label>
                        <input type="date" name="to_date" id="toDate" class="form-control"
                            value="{{ $toDate }}">
                    </div>
                    <div class="col-lg-3 col-md-6 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-50">Lọc</button>
                        <a href="{{ route('admin.sales.performance.show', $staff->id) }}" class="btn btn-outline-secondary w-50">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Danh sách cửa hàng phụ trách</h5>
                <a
                    href="{{ route('admin.sales.performance.export', array_merge(['staff' => $staff->id], request()->only(['range', 'from_date', 'to_date']))) }}"
                    class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel me-1"></i> Xuất Excel
                </a>
            </div>
            <div class="card-body p-0">
                <form method="POST"
                    action="{{ route('admin.sales.performance.update-commission', array_merge(['staff' => $staff->id], request()->only(['range', 'from_date', 'to_date']))) }}">
                    @csrf
                    @method('PATCH')
                    <div>
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Mã CH</th>
                                    <th>Tên cửa hàng</th>
                                    <th>Trạng thái</th>
                                    <th class="text-end">Tổng đơn thành công</th>
                                    <th class="text-end">Doanh thu</th>
                                    <th class="text-end">Tổng giá trị Ticket</th>
                                    <th>Tỉ lệ hoa hồng</th>
                                    <th class="text-end">Hoa hồng dự tính theo Ticket</th>
                                    <th>Hành động</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($stores as $store)
                                    @php
                                        $revenue = (float) ($store->delivered_revenue_sum ?? 0);
                                        $ticketValue = (float) ($store->delivered_ticket_value_sum ?? 0);
                                        $revenueClass = $revenue >= 20000000 ? 'revenue-badge-high' : ($revenue > 0 ? 'revenue-badge-mid' : 'revenue-badge-low');
                                        $commissionRate = (float) ($store->sale_commission_rate ?? 0);
                                        $commissionPreview = (float) ($store->estimated_commission ?? ($ticketValue * ($commissionRate / 100)));
                                    @endphp
                                    <tr>
                                        <td>{{ $store->store_code ?? 'N/A' }}</td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                @if ($store->logo_url)
                                                    <img src="{{ asset('storage/' . ltrim($store->logo_url, '/')) }}" alt="{{ $store->name }}"
                                                        style="width: 28px; height: 28px; object-fit: cover; border-radius: 6px;">
                                                @else
                                                    <span class="badge bg-light text-secondary">No logo</span>
                                                @endif
                                                <span class="fw-semibold">{{ $store->name }}</span>
                                            </div>
                                        </td>
                                        <td>
                                            @if ($store->is_open)
                                                <span class="badge bg-success">Đang hoạt động</span>
                                            @else
                                                <span class="badge bg-secondary">Tạm nghỉ</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ number_format((int) ($store->delivered_orders_count ?? 0)) }}</td>
                                        <td class="text-end">
                                            <span class="badge {{ $revenueClass }}">
                                                {{ number_format($revenue, 0, ',', '.') }} đ
                                            </span>
                                        </td>
                                        <td class="text-end">{{ number_format($ticketValue, 0, ',', '.') }} đ</td>
                                        <td>
                                            <div class="input-group input-group-sm commission-input">
                                                <input type="number"
                                                    min="0"
                                                    max="100"
                                                    step="0.01"
                                                    name="commission_rates[{{ $store->id }}]"
                                                    class="form-control commission-rate-input"
                                                    data-ticket-value="{{ $ticketValue }}"
                                                    data-target="commission-preview-{{ $store->id }}"
                                                    value="{{ number_format($commissionRate, 2, '.', '') }}">
                                                <span class="input-group-text">%</span>
                                            </div>
                                            @if ($commissionRate > 0)
                                                <span class="badge bg-success mt-1">Đã cấu hình</span>
                                            @else
                                                <span class="badge bg-danger mt-1">0% - Chưa cấu hình</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <span class="commission-preview" id="commission-preview-{{ $store->id }}">
                                                {{ number_format($commissionPreview, 0, ',', '.') }} đ
                                            </span>
                                        </td>
                                        <td>
                                            <a href="{{ route('admin.stores.edit', $store->id) }}" class="btn btn-sm btn-outline-primary">
                                                Xem chi tiết cửa hàng
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="text-center py-4">Nhân viên này chưa được gán cửa hàng nào.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($stores->count() > 0)
                        <div class="border-top p-3 d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary">
                                Cập nhật cấu hình
                            </button>
                        </div>
                    @endif
                </form>
            </div>
            <div class="card-footer">
                {{ $stores->onEachSide(1)->links('pagination::bootstrap-5') }}
            </div>
        </div>
    </div>

    <script>
        (function() {
            const rangeSelect = document.getElementById('rangeSelect');
            const fromDate = document.getElementById('fromDate');
            const toDate = document.getElementById('toDate');
            const commissionInputs = document.querySelectorAll('.commission-rate-input');

            const toggleDateInputs = () => {
                const custom = rangeSelect.value === 'custom';
                fromDate.disabled = !custom;
                toDate.disabled = !custom;
            };

            const formatVnd = (value) => {
                return new Intl.NumberFormat('vi-VN').format(Math.max(0, value || 0)) + ' đ';
            };

            const updatePreview = (input) => {
                const ticketValue = parseFloat(input.dataset.ticketValue || '0');
                const rate = parseFloat(input.value || '0');
                const targetId = input.dataset.target;
                const preview = ticketValue * (isNaN(rate) ? 0 : rate / 100);
                const target = document.getElementById(targetId);
                if (target) {
                    target.textContent = formatVnd(preview);
                }
            };

            rangeSelect.addEventListener('change', toggleDateInputs);
            toggleDateInputs();

            commissionInputs.forEach((input) => {
                input.addEventListener('input', () => updatePreview(input));
            });
        })();
    </script>
@endsection

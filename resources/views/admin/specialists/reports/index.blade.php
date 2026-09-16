@extends('admin.layout')

@section('title', $isSaleUser ? 'Báo cáo của tôi' : 'Báo cáo chuyên viên')

@section('content')
    @php
        $money = fn ($value) => number_format((float) $value, 0, ',', '.') . ' đ';
        $number = fn ($value) => number_format((int) $value, 0, ',', '.');
        $percent = fn ($value) => number_format((float) $value, 2, ',', '.') . '%';
        $userName = function ($user): string {
            if (! $user) {
                return 'Tất cả chuyên viên';
            }

            return $user->full_name ?: ($user->name ?: ($user->email ?: 'N/A'));
        };
        $dateText = function ($value): string {
            if (empty($value)) {
                return 'N/A';
            }

            return \Carbon\Carbon::parse($value)->format('d/m/Y');
        };
        $statusBadge = fn (string $status): string => match (strtolower($status)) {
            'paid' => 'success',
            'approved' => 'info',
            'waiting_approval', 'pending', 'pending_settlement', 'draft' => 'warning text-dark',
            'active', 'approved' => 'success',
            'suspended', 'inactive', 'blocked', 'closed', 'cancelled' => 'danger',
            default => 'secondary',
        };
        $statusBadgeClass = fn ($status) => match (strtolower((string) $status)) {
            'paid', 'active', 'approved' => 'status-badge-paid',
            'waiting_approval', 'pending', 'pending_settlement', 'draft', 'waiting approval' => 'status-badge-pending',
            'suspended', 'inactive', 'blocked', 'closed', 'cancelled' => 'status-badge-cancelled',
            default => 'status-badge-default',
        };
        $translateStatus = fn ($status) => match (strtolower((string) $status)) {
            'paid' => 'Đã thanh toán',
            'approved' => 'Đã duyệt',
            'waiting_approval', 'waiting approval' => 'Chờ duyệt',
            'pending', 'pending_settlement' => 'Chờ xử lý',
            'draft' => 'Nháp',
            'active' => 'Hoạt động',
            'suspended' => 'Tạm ngưng',
            'inactive' => 'Không hoạt động',
            'blocked' => 'Đã khóa',
            'closed' => 'Đã đóng',
            'cancelled' => 'Đã hủy',
            'available' => 'Sẵn có',
            'used_in_room' => 'Đã sử dụng',
            default => $status,
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

        .specialist-report-wrapper {
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
            padding: 14px 20px;
            font-weight: 700;
            color: var(--recon-text);
            font-size: 14.5px;
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
            font-size: 13px;
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
        .kpi-flex-container {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 24px;
        }
        .kpi-card-custom {
            flex: 1 1 calc(20% - 10px);
            min-width: 195px;
            background: #ffffff;
            border: 1px solid var(--recon-border);
            border-radius: 10px;
            padding: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
            transition: all 0.2s ease;
        }
        @media (max-width: 1400px) {
            .kpi-card-custom {
                flex: 1 1 calc(25% - 9px);
            }
        }
        @media (max-width: 1100px) {
            .kpi-card-custom {
                flex: 1 1 calc(33.333% - 8px);
            }
        }
        @media (max-width: 768px) {
            .kpi-card-custom {
                flex: 1 1 calc(50% - 6px);
            }
        }
        @media (max-width: 480px) {
            .kpi-card-custom {
                flex: 1 1 100%;
            }
        }
        .kpi-card-custom:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.04);
        }
        .kpi-icon-wrapper {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
        }
        .kpi-info {
            flex-grow: 1;
            min-width: 0;
        }
        .kpi-label-text {
            font-size: 11px;
            color: var(--recon-muted);
            font-weight: 600;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .kpi-value-text {
            font-size: 17px;
            font-weight: 700;
            color: var(--recon-text);
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .kpi-sub-text {
            font-size: 10px;
            color: var(--recon-muted);
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .kpi-bg-blue { background-color: #e8f0fe; color: #1a73e8; }
        .kpi-bg-green { background-color: #e6f4ea; color: #137333; }
        .kpi-bg-purple { background-color: #f3e8fd; color: #a142f4; }
        .kpi-bg-green-success { background-color: #e6f4ea; color: #137333; }
        .kpi-bg-blue-indigo { background-color: #e8eaf6; color: #3f51b5; }
        .kpi-bg-orange { background-color: #fef7e0; color: #b06000; }
        .kpi-bg-red { background-color: #fce8e6; color: #c5221f; }

        /* Soft Status Badges */
        .status-badge-custom {
            display: inline-block;
            padding: 3px 8px;
            font-size: 10.5px;
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
            background-color: #fff4e6;
            color: #ff9200;
            border: 1px solid #ffe8cc;
        }
        .status-badge-default {
            background-color: #f1f3f5;
            color: #495057;
            border: 1px solid #e9ecef;
        }

        /* Table custom styling */
        .report-table th {
            font-size: 11px !important;
            font-weight: 600 !important;
            color: #475569 !important;
            white-space: nowrap !important;
            padding: 8px 6px !important;
            background-color: #f8fafc !important;
            border-bottom: 1px solid var(--recon-border) !important;
            vertical-align: middle !important;
            letter-spacing: normal !important;
            line-height: 1.2 !important;
        }
        .report-table td {
            padding: 8px 6px !important;
            font-size: 11.5px !important;
            color: #0f172a !important;
            border-bottom: 1px solid #f1f5f9 !important;
            vertical-align: middle !important;
            line-height: 1.3 !important;
        }
        .report-table tbody tr:last-child td {
            border-bottom: none !important;
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
            background-color: #ffffff;
        }
        .btn-outline-secondary:hover {
            background-color: #f8fafc;
            color: var(--recon-text);
            border-color: #cbd5e1;
        }
    </style>

    <div class="specialist-report-wrapper container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h1 class="mb-1 fw-bold text-dark" style="font-size: 24px; letter-spacing: -0.02em;">{{ $isSaleUser ? 'Báo cáo của tôi' : 'Báo cáo chuyên viên' }}</h1>
                <div class="text-muted d-flex align-items-center gap-2" style="font-size: 13.5px; font-weight: 500;">
                    <span>{{ $isSaleUser ? 'dữ liệu trong phạm vi phụ trách của bạn' : $userName($selectedSpecialist) }}</span>
                    <span>•</span>
                    <span>{{ $dateRangeLabel }}</span>
                </div>
            </div>
        </div>

        <div class="recon-card mb-4">
            <div class="card-body p-3">
                <form method="GET" action="{{ route('admin.reports.specialists') }}" class="row g-3 align-items-end">
                    @if (! $isSaleUser)
                        <div class="col-xl-3 col-md-4">
                            <label class="form-label">Chuyên viên</label>
                            <select class="form-select" name="specialist_id">
                                <option value="">Tất cả chuyên viên</option>
                                @foreach ($specialistOptions as $specialist)
                                    <option value="{{ $specialist->id }}" @selected((string) $filters['specialist_id'] === (string) $specialist->id)>
                                        {{ $userName($specialist) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="col-xl-2 col-md-4">
                        <label class="form-label">Từ ngày</label>
                        <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] }}">
                    </div>
                    <div class="col-xl-2 col-md-4">
                        <label class="form-label">Đến ngày</label>
                        <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] }}">
                    </div>
                    <div class="col-xl-2 col-md-4">
                        <label class="form-label">Không hoạt động sau</label>
                        <div class="d-flex align-items-center gap-2">
                            <input type="number" name="inactive_days" class="form-control" min="1" max="365" value="{{ $filters['inactive_days'] }}">
                            <span class="text-muted fw-semibold" style="font-size: 13px;">ngày</span>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-8 d-flex gap-2">
                        <button class="btn btn-primary px-3 flex-grow-1" type="submit">
                            <i class="fa-solid fa-filter me-2"></i> Lọc
                        </button>
                        <a href="{{ route('admin.reports.specialists') }}" class="btn btn-outline-secondary px-3 flex-grow-1">
                            <i class="fa-solid fa-arrow-rotate-left me-2"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="kpi-flex-container mb-4">
            <!-- Card 1: Cửa hàng quản lý -->
            <div class="kpi-card-custom">
                <div class="kpi-icon-wrapper kpi-bg-blue">
                    <i class="fa-solid fa-store"></i>
                </div>
                <div class="kpi-info">
                    <div class="kpi-label-text">Cửa hàng quản lý</div>
                    <div class="kpi-value-text">{{ $number($personal['storeSummary']['total']) }}</div>
                    <div class="kpi-sub-text text-muted">Hoạt động: {{ $number($personal['storeSummary']['active']) }} / Tạm ngưng: {{ $number($personal['storeSummary']['suspended']) }}</div>
                </div>
            </div>

            <!-- Card 2: Đơn hàng thành công -->
            <div class="kpi-card-custom">
                <div class="kpi-icon-wrapper kpi-bg-green">
                    <i class="fa-solid fa-basket-shopping"></i>
                </div>
                <div class="kpi-info">
                    <div class="kpi-label-text">Đơn hàng thành công</div>
                    <div class="kpi-value-text">{{ $number($personal['orderSummary']['total']) }}</div>
                    <div class="kpi-sub-text text-muted">GMV: {{ $money($personal['orderSummary']['gmv']) }}</div>
                </div>
            </div>

            <!-- Card 3: Ticket -->
            <div class="kpi-card-custom">
                <div class="kpi-icon-wrapper kpi-bg-purple">
                    <i class="fa-solid fa-ticket"></i>
                </div>
                <div class="kpi-info">
                    <div class="kpi-label-text">Ticket</div>
                    <div class="kpi-value-text">{{ $number($personal['ticketSummary']['total']) }}</div>
                    <div class="kpi-sub-text text-muted">Eligible: {{ $number($personal['ticketSummary']['eligible']) }} / Treo: {{ $number($personal['ticketSummary']['held']) }}</div>
                </div>
            </div>

            <!-- Card 4: Thu nhập / đối soát -->
            <div class="kpi-card-custom">
                <div class="kpi-icon-wrapper kpi-bg-green-success">
                    <i class="fa-solid fa-circle-dollar-to-slot"></i>
                </div>
                <div class="kpi-info">
                    <div class="kpi-label-text">Thu nhập / đối soát</div>
                    <div class="kpi-value-text text-success">{{ $money($personal['settlementSummary']['paid']) }}</div>
                    <div class="kpi-sub-text text-muted">Tạm tính: {{ $money($personal['incomeSummary']['estimated']) }}</div>
                </div>
            </div>

            @if (! $isSaleUser && $admin)
                <!-- Card 5: Chuyên viên -->
                <div class="kpi-card-custom">
                    <div class="kpi-icon-wrapper kpi-bg-purple">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                    <div class="kpi-info">
                        <div class="kpi-label-text">Chuyên viên</div>
                        <div class="kpi-value-text">{{ $number($admin['summary']['specialists']) }}</div>
                        <div class="kpi-sub-text text-muted">Tổng chuyên viên</div>
                    </div>
                </div>

                <!-- Card 6: Tổng ticket -->
                <div class="kpi-card-custom">
                    <div class="kpi-icon-wrapper kpi-bg-blue">
                        <i class="fa-solid fa-ticket-simple"></i>
                    </div>
                    <div class="kpi-info">
                        <div class="kpi-label-text">Tổng ticket</div>
                        <div class="kpi-value-text">{{ $number($admin['summary']['total_tickets']) }}</div>
                        <div class="kpi-sub-text text-muted">Toàn hệ thống</div>
                    </div>
                </div>

                <!-- Card 7: Ticket bất thường -->
                <div class="kpi-card-custom">
                    <div class="kpi-icon-wrapper kpi-bg-orange">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div class="kpi-info">
                        <div class="kpi-label-text">Ticket bất thường</div>
                        <div class="kpi-value-text text-danger">{{ $number($admin['summary']['abnormal_tickets']) }}</div>
                        <div class="kpi-sub-text text-muted">Cần rà soát</div>
                    </div>
                </div>

                <!-- Card 8: Chi phí chuyên viên -->
                <div class="kpi-card-custom">
                    <div class="kpi-icon-wrapper kpi-bg-blue-indigo">
                        <i class="fa-solid fa-wallet"></i>
                    </div>
                    <div class="kpi-info">
                        <div class="kpi-label-text">Chi phí chuyên viên</div>
                        <div class="kpi-value-text">{{ $money($admin['summary']['specialist_cost']) }}</div>
                        <div class="kpi-sub-text text-muted">Tổng chi trả</div>
                    </div>
                </div>

                <!-- Card 9: Hiệu suất cao -->
                <div class="kpi-card-custom">
                    <div class="kpi-icon-wrapper kpi-bg-orange">
                        <i class="fa-solid fa-star"></i>
                    </div>
                    <div class="kpi-info">
                        <div class="kpi-label-text">Hiệu suất cao</div>
                        <div class="kpi-value-text text-warning">{{ $number($admin['summary']['high_performers']) }}</div>
                        <div class="kpi-sub-text text-muted">Chuyên viên xuất sắc</div>
                    </div>
                </div>

                <!-- Card 10: Shop không hoạt động -->
                <div class="kpi-card-custom">
                    <div class="kpi-icon-wrapper kpi-bg-red">
                        <i class="fa-solid fa-store-slash"></i>
                    </div>
                    <div class="kpi-info">
                        <div class="kpi-label-text">Shop không hoạt động</div>
                        <div class="kpi-value-text text-danger">{{ $number($admin['summary']['inactive_stores']) }}</div>
                        <div class="kpi-sub-text text-muted">Ngưỡng lọc</div>
                    </div>
                </div>
            @endif
        </div>

        <div class="row g-3 mb-4">
            <div class="col-xl-7 col-lg-7">
                <div class="recon-card h-100 mb-0">
                    <div class="card-header">
                        <i class="fa-solid fa-chart-line text-primary me-2"></i> A. Hiệu quả cửa hàng quản lý
                    </div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-hover align-middle mb-0 report-table">
                            <thead>
                                <tr>
                                    <th>Cửa hàng</th>
                                    <th>Trạng thái</th>
                                    <th>Phân công</th>
                                    <th class="text-end">Đơn</th>
                                    <th class="text-end">GMV</th>
                                    <th class="text-end">Ticket</th>
                                    <th class="text-end">Thu nhập</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($personal['storeRows'] as $row)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $row['name'] }}</div>
                                            <small class="text-muted">{{ $row['code'] ?: $row['id'] }}</small>
                                        </td>
                                        <td><span class="status-badge-custom {{ $statusBadgeClass($row['status']) }}">{{ $translateStatus($row['status']) }}</span></td>
                                        <td>
                                            <div>{{ $dateText($row['assigned_from']) }}</div>
                                            <small class="text-muted">{{ $row['assigned_to'] ? $dateText($row['assigned_to']) : 'Đang phụ trách' }}</small>
                                        </td>
                                        <td class="text-end fw-semibold">{{ $number($row['orders']) }}</td>
                                        <td class="text-end text-dark">{{ $money($row['gmv']) }}</td>
                                        <td class="text-end">{{ $number($row['tickets']) }}</td>
                                        <td class="text-end text-success fw-semibold">{{ $money($row['income']) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">Chưa có cửa hàng trong phạm vi báo cáo.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
 
            <div class="col-xl-5 col-lg-5">
                <div class="recon-card h-100 mb-0">
                    <div class="card-header">
                        <i class="fa-solid fa-basket-shopping text-success me-2"></i> Đơn hàng thành công
                    </div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-hover align-middle mb-0 report-table">
                            <thead>
                                <tr>
                                    <th>Đơn hàng</th>
                                    <th>Cửa hàng</th>
                                    <th class="text-end">Giá trị</th>
                                    <th>Hoàn tất</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($personal['orderRows'] as $order)
                                    <tr>
                                        <td class="fw-semibold text-primary">{{ $order->order_code ?: $order->id }}</td>
                                        <td>
                                            <div class="fw-semibold">{{ $order->store_name ?: 'N/A' }}</div>
                                            <small class="text-muted">{{ $order->store_code }}</small>
                                        </td>
                                        <td class="text-end text-success fw-semibold">{{ $money($order->final_amount) }}</td>
                                        <td>{{ $dateText($order->delivered_at ?: $order->created_at) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">Chưa có đơn thành công trong khoảng lọc.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <!-- Left side: C. Thu nhập và đối soát -->
            <div class="col-xl-7 col-lg-7">
                <div class="recon-card h-100 mb-0">
                    <div class="card-header">
                        <i class="fa-solid fa-file-invoice-dollar text-success me-2"></i> C. Thu nhập và đối soát
                    </div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-hover align-middle mb-0 report-table">
                            <thead>
                                <tr>
                                    <th>Kỳ</th>
                                    <th>Trạng thái</th>
                                    <th class="text-end">Ticket</th>
                                    <th class="text-end">Gross</th>
                                    <th class="text-end">Điều chỉnh</th>
                                    <th class="text-end">Chốt cuối</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($personal['settlementRows'] as $row)
                                    <tr>
                                        <td class="text-nowrap">{{ $dateText($row->period_from) }} - {{ $dateText($row->period_to) }}</td>
                                        <td><span class="status-badge-custom {{ $statusBadgeClass($row->status) }}">{{ $translateStatus($row->status) }}</span></td>
                                        <td class="text-end fw-semibold">{{ $number($row->total_eligible_tickets) }}</td>
                                        <td class="text-end">{{ $money($row->gross_income) }}</td>
                                        <td class="text-end {{ (float) $row->adjustment_amount < 0 ? 'text-danger' : 'text-success' }}">{{ $money($row->adjustment_amount) }}</td>
                                        <td class="text-end fw-bold text-success">{{ $money($row->final_income) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">Chưa có kỳ đối soát trong khoảng lọc.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right side: B1 and B2 stacked vertically -->
            <div class="col-xl-5 col-lg-5">
                <div class="d-flex flex-column gap-3 h-100">
                    <div class="recon-card mb-0">
                        <div class="card-header">
                            <i class="fa-solid fa-tags text-purple me-2"></i> B1. Lucky Ticket
                        </div>
                        <div class="card-body table-responsive p-0">
                            <table class="table table-hover align-middle mb-0 report-table">
                                <thead>
                                    <tr>
                                        <th>Trạng thái</th>
                                        <th class="text-end">Tổng ticket</th>
                                        <th class="text-end">Ticket hợp lệ</th>
                                        <th class="text-end">Thu nhập</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($personal['ticketStatusRows'] as $row)
                                        <tr>
                                            <td class="fw-semibold text-slate-700">{{ $translateStatus($row->status) }}</td>
                                            <td class="text-end fw-semibold">{{ $number($row->total_tickets) }}</td>
                                            <td class="text-end text-success fw-semibold">{{ $number($row->eligible_tickets) }}</td>
                                            <td class="text-end text-dark fw-bold">{{ $money($row->income) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">Chưa có ticket trong khoảng lọc.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="recon-card mb-0">
                        <div class="card-header">
                            <i class="fa-solid fa-list-check text-primary me-2"></i> B2. Thu nhập theo loại ticket
                        </div>
                        <div class="card-body table-responsive p-0">
                            <table class="table table-hover align-middle mb-0 report-table">
                                <thead>
                                    <tr>
                                        <th>Loại ticket</th>
                                        <th class="text-end">Tổng ticket</th>
                                        <th class="text-end">Ticket hợp lệ</th>
                                        <th class="text-end">Thu nhập</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($personal['ticketTypeIncomeRows'] as $row)
                                        <tr>
                                            <td class="fw-semibold text-primary">{{ $row->ticket_type_code }}</td>
                                            <td class="text-end fw-semibold">{{ $number($row->total_tickets) }}</td>
                                            <td class="text-end text-success fw-semibold">{{ $number($row->eligible_tickets) }}</td>
                                            <td class="text-end text-dark fw-bold">{{ $money($row->income) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">Chưa có thu nhập theo loại ticket trong khoảng lọc.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if (! $isSaleUser && $admin)
            <div class="row g-3 mb-4">
                <div class="col-xl-7 col-lg-7">
                    <div class="recon-card h-100 mb-0">
                        <div class="card-header">
                            <i class="fa-solid fa-users text-primary me-2"></i> D1. Hiệu suất chuyên viên
                        </div>
                        <div class="card-body table-responsive p-0">
                            <table class="table table-hover align-middle mb-0 report-table">
                                <thead>
                                    <tr>
                                        <th>Chuyên viên</th>
                                        <th class="text-end">Sao</th>
                                        <th class="text-end">Điểm</th>
                                        <th class="text-end">Cửa hàng</th>
                                        <th class="text-end">Đơn</th>
                                        <th class="text-end">Ticket</th>
                                        <th class="text-end">Bất thường</th>
                                        <th class="text-end">Chi phí</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($admin['performanceRows'] as $row)
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">{{ $row['name'] }}</div>
                                                <small class="text-muted">{{ $row['email'] }}</small>
                                            </td>
                                            <td class="text-end text-warning">{{ $row['stars'] ? str_repeat('★', (int) $row['stars']) : 'N/A' }}</td>
                                            <td class="text-end fw-semibold">{{ $row['score'] !== null ? number_format($row['score'], 2, ',', '.') : 'N/A' }}</td>
                                            <td class="text-end">{{ $number($row['stores']) }}</td>
                                            <td class="text-end">{{ $number($row['orders']) }}</td>
                                            <td class="text-end">{{ $number($row['tickets']) }}</td>
                                            <td class="text-end text-danger fw-semibold">{{ $number($row['abnormal_tickets']) }}</td>
                                            <td class="text-end text-success fw-semibold">{{ $money($row['income']) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">Chưa có dữ liệu hiệu suất chuyên viên.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-xl-5 col-lg-5">
                    <div class="recon-card h-100 mb-0">
                        <div class="card-header">
                            <i class="fa-solid fa-triangle-exclamation text-danger me-2"></i> D2. Ticket bất thường
                        </div>
                        <div class="card-body table-responsive p-0">
                            <table class="table table-hover align-middle mb-0 report-table">
                                <thead>
                                    <tr>
                                        <th>Chuyên viên</th>
                                        <th>Cửa hàng</th>
                                        <th>Trạng thái</th>
                                        <th>Lý do</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($admin['abnormalTicketRows'] as $row)
                                        <tr>
                                            <td class="fw-semibold text-slate-700">{{ $row->full_name ?: ($row->name ?: $row->email) }}</td>
                                            <td>
                                                <div class="fw-semibold">{{ $row->store_name ?: 'N/A' }}</div>
                                                <small class="text-muted">{{ $row->store_code }}</small>
                                            </td>
                                            <td><span class="status-badge-custom status-badge-cancelled">{{ $row->ticket_status ? $translateStatus($row->ticket_status) : 'N/A' }}</span></td>
                                            <td><small class="text-muted">{{ $row->reason_not_eligible ?: 'N/A' }}</small></td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">Không có ticket bất thường trong khoảng lọc.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-12">
                    <div class="recon-card mb-0">
                        <div class="card-header">
                            <i class="fa-solid fa-store-slash text-danger me-2"></i> E. Cửa hàng không hoạt động
                        </div>
                        <div class="card-body table-responsive p-0">
                            <table class="table table-hover align-middle mb-0 report-table">
                                <thead>
                                    <tr>
                                        <th>Cửa hàng</th>
                                        <th>Chuyên viên</th>
                                        <th>Trạng thái</th>
                                        <th>Ngày tạo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($admin['inactiveStoreRows'] as $store)
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">{{ $store->name }}</div>
                                                <small class="text-muted">{{ $store->store_code ?: $store->id }}</small>
                                            </td>
                                            <td>{{ $store->specialist_full_name ?: ($store->specialist_name ?: ($store->specialist_email ?: 'N/A')) }}</td>
                                            <td><span class="status-badge-custom {{ $statusBadgeClass($store->status) }}">{{ $translateStatus($store->status) }}</span></td>
                                            <td>{{ $dateText($store->created_at) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">Không có cửa hàng không hoạt động theo ngưỡng lọc.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection

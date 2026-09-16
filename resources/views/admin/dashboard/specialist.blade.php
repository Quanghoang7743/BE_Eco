@extends('admin.layout')

@section('title', 'Dashboard chuyên viên')

@section('styles')
    <style>
        .specialist-dashboard .filter-card,
        .specialist-dashboard .metric-card,
        .specialist-dashboard .table-card {
            border-radius: 8px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
        }

        .specialist-dashboard .metric-card {
            min-height: 122px;
        }

        .specialist-dashboard .metric-icon {
            width: 42px;
            height: 42px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            flex: 0 0 auto;
        }

        .specialist-dashboard .metric-value {
            font-size: 1.35rem;
            line-height: 1.2;
            font-weight: 700;
            color: #111827;
            word-break: break-word;
        }

        .specialist-dashboard .metric-label {
            color: #64748b;
            font-size: 0.86rem;
        }

        .specialist-dashboard .metric-section-title {
            font-weight: 700;
            color: #334155;
            margin: 10px 0 12px;
        }

        .specialist-dashboard .tone-blue { background: #2563eb; }
        .specialist-dashboard .tone-green { background: #16a34a; }
        .specialist-dashboard .tone-amber { background: #d97706; }
        .specialist-dashboard .tone-red { background: #dc2626; }
        .specialist-dashboard .tone-slate { background: #475569; }
        .specialist-dashboard .tone-teal { background: #0f766e; }
        .specialist-dashboard .tone-indigo { background: #4f46e5; }
        .specialist-dashboard .tone-violet { background: #7c3aed; }

        .specialist-dashboard .table th,
        .specialist-dashboard .table td {
            vertical-align: middle;
        }
    </style>
@endsection

@section('content')
    @php
        $number = fn ($value) => number_format((int) $value, 0, ',', '.');
        $money = fn ($value) => number_format((float) $value, 0, ',', '.') . ' đ';

        $metricGroups = [
            [
                'title' => 'Cửa hàng',
                'items' => [
                    ['label' => 'Tổng cửa hàng đang quản lý', 'value' => $number($metrics['stores']['managed']), 'icon' => 'fa-store', 'tone' => 'tone-blue'],
                    ['label' => 'Số cửa hàng hoạt động', 'value' => $number($metrics['stores']['active']), 'icon' => 'fa-circle-check', 'tone' => 'tone-green'],
                    ['label' => 'Số cửa hàng tạm ngưng', 'value' => $number($metrics['stores']['suspended']), 'icon' => 'fa-circle-pause', 'tone' => 'tone-amber'],
                    ['label' => 'Cửa hàng chưa có đơn', 'value' => $number($metrics['stores']['without_orders']), 'icon' => 'fa-cart-arrow-down', 'tone' => 'tone-slate'],
                ],
            ],
            [
                'title' => 'Đơn hàng và doanh số',
                'items' => [
                    ['label' => 'Tổng đơn thành công toàn hệ thống', 'value' => $number($metrics['orders']['global_successful']), 'icon' => 'fa-globe', 'tone' => 'tone-indigo'],
                    ['label' => 'Tổng đơn thành công của chuyên viên', 'value' => $number($metrics['orders']['specialist_successful']), 'icon' => 'fa-receipt', 'tone' => 'tone-green'],
                    ['label' => 'Tổng đơn thành công hôm nay', 'value' => $number($metrics['orders']['today_successful']), 'icon' => 'fa-calendar-day', 'tone' => 'tone-teal'],
                    ['label' => 'Tổng doanh số cửa hàng quản lý', 'value' => $money($metrics['orders']['managed_revenue']), 'icon' => 'fa-chart-line', 'tone' => 'tone-blue'],
                ],
            ],
            [
                'title' => 'Lucky Ticket',
                'items' => [
                    ['label' => 'Tổng ticket phát sinh', 'value' => $number($metrics['tickets']['total']), 'icon' => 'fa-ticket', 'tone' => 'tone-indigo'],
                    ['label' => 'Ticket hợp lệ', 'value' => $number($metrics['tickets']['eligible']), 'icon' => 'fa-check-circle', 'tone' => 'tone-green'],
                    ['label' => 'Ticket treo', 'value' => $number($metrics['tickets']['held']), 'icon' => 'fa-lock', 'tone' => 'tone-amber'],
                    ['label' => 'Ticket hủy', 'value' => $number($metrics['tickets']['cancelled']), 'icon' => 'fa-ban', 'tone' => 'tone-red'],
                ],
            ],
            [
                'title' => 'Thu nhập',
                'items' => [
                    ['label' => 'Thu nhập tạm tính', 'value' => $money($metrics['income']['tentative']), 'icon' => 'fa-coins', 'tone' => 'tone-violet'],
                    ['label' => 'Thu nhập chờ duyệt', 'value' => $money($metrics['income']['pending']), 'icon' => 'fa-hourglass-half', 'tone' => 'tone-amber'],
                    ['label' => 'Thu nhập đã duyệt', 'value' => $money($metrics['income']['approved']), 'icon' => 'fa-circle-check', 'tone' => 'tone-green'],
                    ['label' => 'Thu nhập đã thanh toán', 'value' => $money($metrics['income']['paid']), 'icon' => 'fa-money-bill-transfer', 'tone' => 'tone-teal'],
                ],
            ],
        ];
    @endphp

    <div class="specialist-dashboard">
        <div class="d-flex align-items-start align-items-md-center flex-column flex-md-row pt-2 pb-3">
            <div>
                <h3 class="fw-bold mb-1">Dashboard chuyên viên</h3>
                <div class="text-muted">
                    {{ $specialist->full_name ?: ($specialist->name ?: $specialist->email) }} · {{ $dateRange['label'] }}
                </div>
            </div>
            <div class="ms-md-auto mt-3 mt-md-0">
                <span class="badge bg-primary fs-6">
                    {{ $number($metrics['stores']['managed']) }} cửa hàng
                </span>
            </div>
        </div>

        @php
            $specialistProfile = $specialist->specialistProfile;
            $specialistOtherDocuments = array_values((array) ($specialistProfile?->other_documents_json ?? []));
        @endphp
        <div class="card table-card mb-4">
            <div class="card-header fw-bold">Hồ sơ pháp lý của tôi</div>
            <div class="card-body">
                @if($specialistProfile)
                    <div class="d-flex flex-wrap gap-2">
                        @if($specialistProfile->contract_file_url)
                            <a class="btn btn-outline-primary btn-sm"
                                href="{{ route('admin.users.documents.download', [$specialist, 'contract']) }}">
                                <i class="fa fa-file-contract me-1"></i> Hợp đồng
                            </a>
                        @endif
                        @if($specialistProfile->id_card_front_url)
                            <a class="btn btn-outline-primary btn-sm"
                                href="{{ route('admin.users.documents.download', [$specialist, 'id-card-front']) }}">
                                <i class="fa fa-id-card me-1"></i> CCCD mặt trước
                            </a>
                        @endif
                        @if($specialistProfile->id_card_back_url)
                            <a class="btn btn-outline-primary btn-sm"
                                href="{{ route('admin.users.documents.download', [$specialist, 'id-card-back']) }}">
                                <i class="fa fa-id-card me-1"></i> CCCD mặt sau
                            </a>
                        @endif
                        @foreach($specialistOtherDocuments as $index => $document)
                            <a class="btn btn-outline-secondary btn-sm"
                                href="{{ route('admin.users.documents.download', [$specialist, 'other', $index]) }}">
                                <i class="fa fa-file me-1"></i> Giấy tờ khác #{{ $index + 1 }}
                            </a>
                        @endforeach
                    </div>
                    @if(
                        ! $specialistProfile->contract_file_url
                        && ! $specialistProfile->id_card_front_url
                        && ! $specialistProfile->id_card_back_url
                        && $specialistOtherDocuments === []
                    )
                        <div class="text-muted">Hồ sơ chưa có tài liệu pháp lý.</div>
                    @endif
                @else
                    <div class="text-muted">Hồ sơ chưa được khởi tạo.</div>
                @endif
                <div class="form-text mt-2">Bạn chỉ có quyền xem. Việc cập nhật tài liệu do quản trị viên thực hiện.</div>
            </div>
        </div>

        <form method="GET" action="{{ route('admin') }}" class="card filter-card mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label">Thời gian</label>
                        <select name="range" id="dashboardRange" class="form-control">
                            <option value="today" @selected($filters['range'] === 'today')>Hôm nay</option>
                            <option value="yesterday" @selected($filters['range'] === 'yesterday')>Hôm qua</option>
                            <option value="last_7_days" @selected($filters['range'] === 'last_7_days')>7 ngày gần nhất</option>
                            <option value="this_month" @selected($filters['range'] === 'this_month')>Tháng này</option>
                            <option value="last_month" @selected($filters['range'] === 'last_month')>Tháng trước</option>
                            <option value="custom" @selected($filters['range'] === 'custom')>Chọn khoảng ngày</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Từ ngày</label>
                        <input type="date" name="from_date" id="dashboardFromDate" class="form-control"
                            value="{{ $filters['from_date'] ?: $dateRange['from_date'] }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Đến ngày</label>
                        <input type="date" name="to_date" id="dashboardToDate" class="form-control"
                            value="{{ $filters['to_date'] ?: $dateRange['to_date'] }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Cửa hàng</label>
                        <select name="store_id" class="form-control">
                            <option value="">Tất cả</option>
                            @foreach ($storeOptions as $store)
                                <option value="{{ $store->id }}" @selected((string) $filters['store_id'] === (string) $store->id)>
                                    {{ trim(($store->store_code ?? '') . ' - ' . ($store->name ?? 'Cửa hàng')) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Khu vực</label>
                        <input type="text" name="region" class="form-control"
                            placeholder="{{ $hasRegionColumn ? 'Mã khu vực' : 'Tên / địa chỉ' }}"
                            value="{{ $filters['region'] }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Trạng thái ticket</label>
                        <select name="ticket_status" class="form-control">
                            <option value="all" @selected($filters['ticket_status'] === 'all')>Tất cả</option>
                            <option value="eligible" @selected($filters['ticket_status'] === 'eligible')>Hợp lệ</option>
                            <option value="held" @selected($filters['ticket_status'] === 'held')>Treo</option>
                            <option value="cancelled" @selected($filters['ticket_status'] === 'cancelled')>Hủy</option>
                        </select>
                    </div>
                    <div class="col-md-12 d-flex gap-2 justify-content-end">
                        <a href="{{ route('admin') }}" class="btn btn-light">
                            <i class="fa fa-rotate-left me-1"></i> Đặt lại
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-filter me-1"></i> Lọc
                        </button>
                    </div>
                </div>
            </div>
        </form>

        @foreach ($metricGroups as $group)
            <div class="metric-section-title">{{ $group['title'] }}</div>
            <div class="row g-3 mb-3">
                @foreach ($group['items'] as $item)
                    <div class="col-sm-6 col-xl-3">
                        <div class="card metric-card h-100">
                            <div class="card-body d-flex gap-3 align-items-start">
                                <div class="metric-icon {{ $item['tone'] }}">
                                    <i class="fa {{ $item['icon'] }}"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="metric-label">{{ $item['label'] }}</div>
                                    <div class="metric-value mt-1">{{ $item['value'] }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach

        <div class="card table-card mt-4">
            <div class="card-header d-flex align-items-center">
                <div class="card-title mb-0">Hiệu suất cửa hàng quản lý</div>
                <div class="ms-auto text-muted small">{{ $dateRange['label'] }}</div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Cửa hàng</th>
                                <th>Trạng thái</th>
                                <th class="text-end">Đơn thành công</th>
                                <th class="text-end">Doanh số</th>
                                <th class="text-end">Hoa hồng</th>
                                <th class="text-end">Thu nhập tạm tính</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($storeRows as $row)
                                @php
                                    $status = strtolower($row['status']);
                                    $isOpen = (bool) $row['is_open'];
                                    $statusLabel = $isOpen && in_array($status, ['active', 'approved'], true)
                                        ? 'Hoạt động'
                                        : (!$isOpen ? 'Tạm ngưng' : ($row['status'] ?: 'N/A'));
                                    $statusClass = $isOpen && in_array($status, ['active', 'approved'], true)
                                        ? 'success'
                                        : (!$isOpen ? 'warning text-dark' : 'secondary');
                                @endphp
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $row['name'] }}</div>
                                        <div class="text-muted small">{{ $row['store_code'] ?: $row['id'] }}</div>
                                    </td>
                                    <td><span class="badge bg-{{ $statusClass }}">{{ $statusLabel }}</span></td>
                                    <td class="text-end">{{ $number($row['orders_count']) }}</td>
                                    <td class="text-end">{{ $money($row['revenue']) }}</td>
                                    <td class="text-end">{{ number_format((float) $row['commission_rate'], 2, ',', '.') }}%</td>
                                    <td class="text-end fw-semibold">{{ $money($row['estimated_income']) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        Chưa có cửa hàng nào thuộc phạm vi quản lý trong bộ lọc hiện tại.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const range = document.getElementById('dashboardRange');
            const fromDate = document.getElementById('dashboardFromDate');
            const toDate = document.getElementById('dashboardToDate');

            function syncDateInputs() {
                const isCustom = range.value === 'custom';
                fromDate.disabled = !isCustom;
                toDate.disabled = !isCustom;
            }

            range.addEventListener('change', syncDateInputs);
            syncDateInputs();
        });
    </script>
@endsection

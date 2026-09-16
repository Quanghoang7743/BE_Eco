@extends('admin.layout')

@section('content')
    @php
        $statusLabels = [
            'AVAILABLE' => 'Sẵn sàng sử dụng',
            'USED_IN_ROOM' => 'Đã tham gia phòng quay',
            'USED_FOR_ORDER' => 'Đã dùng gọi món',
            'EXPIRED' => 'Đã hết hạn',
            'LOCKED' => 'Đang tạm khóa',
            'REFUNDED' => 'Đã hoàn Ticket',
        ];

        $statusBadgeClasses = [
            'AVAILABLE' => 'bg-success',
            'USED_IN_ROOM' => 'bg-primary',
            'USED_FOR_ORDER' => 'bg-info text-dark',
            'EXPIRED' => 'bg-secondary',
            'LOCKED' => 'bg-warning text-dark',
            'REFUNDED' => 'bg-dark',
        ];
    @endphp

    <div class="container-fluid">
        <h2 class="mb-4">Tổng số Ticket đã phát (S09)</h2>

        <div class="alert alert-info">
            Dữ liệu theo cửa hàng được tổng hợp từ Ticket có <strong>source_type=store</strong>. Ticket không map được cửa hàng sẽ nằm trong nhóm <strong>UNKNOWN_STORE</strong>.
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.ticket-stats.index') }}" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Từ ngày</label>
                        <input type="date" class="form-control" name="date_from" value="{{ request('date_from') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Đến ngày</label>
                        <input type="date" class="form-control" name="date_to" value="{{ request('date_to') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Mã cửa hàng</label>
                        <input type="text" class="form-control" name="store_code" value="{{ request('store_code') }}" placeholder="VD: STORE001">
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button class="btn btn-primary" type="submit">Lọc dữ liệu</button>
                        <a href="{{ route('admin.ticket-stats.index') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="text-muted mb-2">Tổng số Ticket đã phát hành</h6>
                        <h3 class="mb-0">{{ number_format((int) ($summary->total_tickets ?? 0)) }}</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="text-muted mb-2">Tổng doanh thu Ticket</h6>
                        <h3 class="mb-0">{{ number_format((float) ($summary->total_revenue ?? 0), 0, ',', '.') }} VND</h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header">Trạng thái Ticket</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Trạng thái</th>
                                        <th class="text-end">Số lượng</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($statusRows as $row)
                                        @php
                                            $statusCode = strtoupper((string) ($row->status ?? 'UNKNOWN'));
                                            $statusLabel = $statusLabels[$statusCode] ?? 'Trạng thái khác';
                                            $badgeClass = $statusBadgeClasses[$statusCode] ?? 'bg-secondary';
                                        @endphp
                                        <tr>
                                            <td>
                                                <span class="badge {{ $badgeClass }}">{{ $statusLabel }}</span>
                                                <div><small class="text-muted">Mã: {{ $statusCode }}</small></div>
                                            </td>
                                            <td class="text-end">{{ number_format((int) $row->total_tickets) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="2" class="text-center py-3">Không có dữ liệu.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header">Thống kê Ticket theo ngày</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Ngày</th>
                                        <th class="text-end">Số Ticket</th>
                                        <th class="text-end">Doanh thu</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($dailyRows as $row)
                                        <tr>
                                            <td>{{ $row->stat_date ?? 'N/A' }}</td>
                                            <td class="text-end">{{ number_format((int) $row->total_tickets) }}</td>
                                            <td class="text-end">{{ number_format((float) $row->total_revenue, 0, ',', '.') }} VND</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="text-center py-3">Không có dữ liệu.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer">{{ $dailyRows->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">Thống kê Ticket theo cửa hàng</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Mã cửa hàng</th>
                                <th>Tên cửa hàng</th>
                                <th class="text-end">Số Ticket</th>
                                <th class="text-end">Doanh thu</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($storeRows as $row)
                                @php
                                    $isUnknownStore = strtoupper((string) ($row->store_code ?? '')) === 'UNKNOWN_STORE';
                                @endphp
                                <tr>
                                    <td>
                                        {{ $isUnknownStore ? 'Chưa xác định cửa hàng' : $row->store_code }}
                                        @if ($isUnknownStore)
                                            <div><small class="text-muted">Mã kỹ thuật: UNKNOWN_STORE</small></div>
                                        @endif
                                    </td>
                                    <td>{{ $isUnknownStore ? 'Không xác định được cửa hàng phát Ticket' : $row->store_name }}</td>
                                    <td class="text-end">{{ number_format((int) $row->total_tickets) }}</td>
                                    <td class="text-end">{{ number_format((float) $row->total_revenue, 0, ',', '.') }} VND</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center py-3">Không có dữ liệu.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer">{{ $storeRows->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
        </div>
    </div>
@endsection

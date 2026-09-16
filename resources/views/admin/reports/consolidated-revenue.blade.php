@extends('admin.layout')

@section('content')
    @php
        $summary = $summary ?? [];
        $filters = $filters ?? [];
        $costCoverage = $costCoverage ?? [];
        $reconciliation = $reconciliation ?? [];
        $storeRows = $storeRows ?? collect();
        $trends = $trends ?? [];

        $maxTrendValue = collect($trends)->map(function ($row) {
            return max((float) ($row['ticket_revenue'] ?? 0), (float) ($row['order_revenue'] ?? 0));
        })->max() ?: 1;

        $ratio = $costCoverage['ratio'] ?? null;
        $coverageClass = 'bg-secondary';
        $coverageText = 'Chưa xác định';

        if ($ratio !== null) {
            if ($ratio > 1) {
                $coverageClass = 'bg-success';
                $coverageText = 'Đạt mức bao phủ chi phí';
            } elseif ((float) $ratio === 1.0) {
                $coverageClass = 'bg-warning text-dark';
                $coverageText = 'Hòa vốn theo chi phí giải thưởng';
            } else {
                $coverageClass = 'bg-danger';
                $coverageText = 'Chưa bao phủ đủ chi phí giải thưởng';
            }
        }
    @endphp

    <div class="container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <h2 class="mb-0">Báo cáo doanh thu tổng hợp hệ thống (S14)</h2>
            <span class="badge {{ $coverageClass }} px-3 py-2">Mức bao phủ chi phí giải thưởng: {{ $ratio !== null ? number_format((float) $ratio, 2) . 'x' : 'N/A' }} - {{ $coverageText }}</span>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.reports.consolidated-revenue') }}" class="row g-3" id="consolidatedRevenueFilters">
                    <div class="col-md-2">
                        <label class="form-label">Từ ngày</label>
                        <input type="date" class="form-control" name="from_date" value="{{ $filters['from_date'] ?? '' }}">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Đến ngày</label>
                        <input type="date" class="form-control" name="to_date" value="{{ $filters['to_date'] ?? '' }}">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Mã cửa hàng</label>
                        <input type="text" class="form-control" name="store_id" value="{{ $filters['store_id'] ?? '' }}" placeholder="Nhập mã cửa hàng để lọc">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Vùng miền</label>
                        <input type="text" class="form-control" name="region" value="{{ $filters['region'] ?? '' }}" placeholder="Ví dụ: Quận 1">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Xu hướng theo</label>
                        <select name="granularity" class="form-control">
                            <option value="day" {{ ($filters['granularity'] ?? 'day') === 'day' ? 'selected' : '' }}>Ngày</option>
                            <option value="week" {{ ($filters['granularity'] ?? '') === 'week' ? 'selected' : '' }}>Tuần</option>
                            <option value="month" {{ ($filters['granularity'] ?? '') === 'month' ? 'selected' : '' }}>Tháng</option>
                        </select>
                    </div>

                    <div class="col-md-1">
                        <label class="form-label">Giá trị 1 Ticket</label>
                        <input type="number" min="0" step="100" class="form-control" name="ticket_unit_value" value="{{ (int) ($filters['ticket_unit_value'] ?? 5000) }}">
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Lọc dữ liệu</button>
                        <a href="{{ route('admin.reports.consolidated-revenue') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-3 col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <small class="text-muted d-block mb-1">Tổng doanh thu hệ thống</small>
                        <div class="h4 mb-0">{{ number_format((float) ($summary['total_revenue'] ?? 0), 0, ',', '.') }} VND</div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <small class="text-muted d-block mb-1">Doanh thu từ bán Ticket</small>
                        <div class="h4 mb-0 text-primary">{{ number_format((float) ($summary['ticket_revenue'] ?? 0), 0, ',', '.') }} VND</div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <small class="text-muted d-block mb-1">Doanh thu từ đơn hàng thành công</small>
                        <div class="h4 mb-0 text-success">{{ number_format((float) ($summary['order_revenue'] ?? 0), 0, ',', '.') }} VND</div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <small class="text-muted d-block mb-1">Giá trị Ticket hiện còn trong hệ thống</small>
                        <div class="h4 mb-0 text-warning">{{ number_format((float) ($summary['circulating_value'] ?? 0), 0, ',', '.') }} VND</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">Tổng quan tình trạng Ticket</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <tbody>
                                    <tr>
                                        <th>Số Ticket đã bán cho khách</th>
                                        <td class="text-end">{{ number_format((int) ($summary['tickets_sold'] ?? 0)) }}</td>
                                    </tr>
                                    <tr>
                                        <th>Tổng số Ticket đã tạo trong hệ thống</th>
                                        <td class="text-end">{{ number_format((int) ($summary['total_tickets_issued'] ?? 0)) }}</td>
                                    </tr>
                                    <tr>
                                        <th>Ticket đã dùng để gọi món</th>
                                        <td class="text-end">{{ number_format((int) ($summary['tickets_used_for_orders'] ?? 0)) }}</td>
                                    </tr>
                                    <tr>
                                        <th>Ticket đã tham gia phòng quay</th>
                                        <td class="text-end">{{ number_format((int) ($summary['tickets_used_for_rooms'] ?? 0)) }}</td>
                                    </tr>
                                    <tr>
                                        <th>Ticket đã hết hạn sử dụng</th>
                                        <td class="text-end">{{ number_format((int) ($summary['tickets_expired'] ?? 0)) }}</td>
                                    </tr>
                                    <tr>
                                        <th>Ticket đang tạm khóa xử lý</th>
                                        <td class="text-end">{{ number_format((int) ($summary['tickets_locked'] ?? 0)) }}</td>
                                    </tr>
                                    <tr>
                                        <th>Ticket có thể sử dụng ngay</th>
                                        <td class="text-end">{{ number_format((int) ($summary['tickets_available'] ?? 0)) }}</td>
                                    </tr>
                                    <tr>
                                        <th>Số đơn hàng thành công</th>
                                        <td class="text-end">{{ number_format((int) ($summary['delivered_orders'] ?? 0)) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">Bao phủ chi phí giải thưởng và đối soát giao dịch Ticket</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <tbody>
                                    <tr>
                                        <th>Tổng tiền thu được từ bán Ticket</th>
                                        <td class="text-end">{{ number_format((float) ($costCoverage['ticket_revenue'] ?? 0), 0, ',', '.') }} VND</td>
                                    </tr>
                                    <tr>
                                        <th>Tổng chi phí giải thưởng dự kiến</th>
                                        <td class="text-end">{{ number_format((float) ($costCoverage['prize_cost'] ?? 0), 0, ',', '.') }} VND</td>
                                    </tr>
                                    <tr>
                                        <th>Tỷ lệ bao phủ chi phí</th>
                                        <td class="text-end fw-bold">{{ isset($costCoverage['ratio']) && $costCoverage['ratio'] !== null ? number_format((float) $costCoverage['ratio'], 2) . 'x' : 'N/A' }}</td>
                                    </tr>
                                    <tr>
                                        <th>Tổng Ticket cộng vào hệ thống</th>
                                        <td class="text-end">{{ number_format((int) ($reconciliation['ledger_in'] ?? 0)) }}</td>
                                    </tr>
                                    <tr>
                                        <th>Tổng Ticket đã trừ ra khỏi hệ thống</th>
                                        <td class="text-end">{{ number_format((int) ($reconciliation['ledger_out'] ?? 0)) }}</td>
                                    </tr>
                                    <tr>
                                        <th>Chênh lệch Ticket thuần</th>
                                        <td class="text-end">{{ number_format((int) ($reconciliation['ledger_net'] ?? 0)) }}</td>
                                    </tr>
                                    <tr>
                                        <th>Kết quả đối soát dữ liệu</th>
                                        <td class="text-end">
                                            @if (!empty($reconciliation['mismatch_flag']))
                                                <span class="badge bg-danger">Cần kiểm tra lại số liệu</span>
                                            @else
                                                <span class="badge bg-success">Số liệu ổn định</span>
                                            @endif
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Doanh thu chi tiết theo từng cửa hàng</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="consolidatedStoreTable" class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Thông tin cửa hàng</th>
                                <th class="text-end">Số đơn thành công</th>
                                <th class="text-end">Số Ticket đã dùng</th>
                                <th class="text-end">Giá trị quy đổi theo Ticket</th>
                                <th class="text-end">Tổng doanh thu cửa hàng</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Xu hướng doanh thu theo {{ ($filters['granularity'] ?? 'day') === 'day' ? 'ngày' : (($filters['granularity'] ?? 'day') === 'week' ? 'tuần' : 'tháng') }}</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 130px;">Mốc thời gian</th>
                                <th>Doanh thu từ bán Ticket</th>
                                <th>Doanh thu từ đơn hàng</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($trends as $row)
                                @php
                                    $ticketValue = (float) ($row['ticket_revenue'] ?? 0);
                                    $orderValue = (float) ($row['order_revenue'] ?? 0);
                                    $ticketPercent = min(100, ($ticketValue / $maxTrendValue) * 100);
                                    $orderPercent = min(100, ($orderValue / $maxTrendValue) * 100);
                                @endphp
                                <tr>
                                    <td class="fw-semibold">{{ $row['period'] ?? 'N/A' }}</td>
                                    <td>
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span>Bán Ticket</span>
                                            <span>{{ number_format($ticketValue, 0, ',', '.') }} VND</span>
                                        </div>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar bg-primary" style="width: {{ $ticketPercent }}%"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span>Đơn hàng</span>
                                            <span>{{ number_format($orderValue, 0, ',', '.') }} VND</span>
                                        </div>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar bg-success" style="width: {{ $orderPercent }}%"></div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center py-3">Chưa có dữ liệu xu hướng trong khoảng lọc này.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        $(function () {
            var $filters = $('#consolidatedRevenueFilters');

            var storeTable = $('#consolidatedStoreTable').DataTable({
                dom: 'rt<"row mt-3 align-items-center"<"col-sm-6"l><"col-sm-6 text-end"p>>',
                processing: true,
                serverSide: true,
                autoWidth: false,
                searchDelay: 300,
                order: [[4, 'desc']],
                pageLength: 10,
                ajax: {
                    url: '{{ route('admin.reports.consolidated-revenue.datatable') }}',
                    type: 'GET',
                    data: function (d) {
                        d.from_date = $filters.find('[name="from_date"]').val();
                        d.to_date = $filters.find('[name="to_date"]').val();
                        d.store_id = $filters.find('[name="store_id"]').val();
                        d.region = $filters.find('[name="region"]').val();
                        d.ticket_unit_value = $filters.find('[name="ticket_unit_value"]').val();
                    },
                    dataSrc: function (json) {
                        return json.data || [];
                    }
                },
                language: {
                    processing: 'Đang xử lý...',
                    lengthMenu: '_MENU_ mục',
                    info: 'Hiển thị _START_ đến _END_ của _TOTAL_ mục',
                    infoEmpty: 'Hiển thị 0 đến 0 của 0 mục',
                    infoFiltered: '(lọc từ _MAX_ mục)',
                    loadingRecords: 'Đang tải...',
                    zeroRecords: 'Không tìm thấy dữ liệu phù hợp',
                    emptyTable: 'Chưa có dữ liệu',
                    paginate: {
                        first: 'Đầu',
                        previous: 'Trước',
                        next: 'Tiếp',
                        last: 'Cuối'
                    }
                },
                lengthMenu: [[10, 20, 50], [10, 20, 50]],
                columns: [
                    { data: 'store_info', orderable: true, searchable: false },
                    { data: 'total_orders', orderable: true, searchable: false, className: 'text-end' },
                    { data: 'total_tickets_used', orderable: true, searchable: false, className: 'text-end' },
                    { data: 'converted_value', orderable: true, searchable: false, className: 'text-end' },
                    { data: 'total_revenue', orderable: true, searchable: false, className: 'text-end fw-semibold' }
                ],
                columnDefs: [
                    { targets: [0, 1, 2, 3, 4], className: 'align-top' }
                ]
            });

            $filters.on('submit', function (e) {
                e.preventDefault();
                storeTable.page('first').ajax.reload();
            });
        });
    </script>
@endsection

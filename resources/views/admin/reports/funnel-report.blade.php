@extends('admin.layout')

@section('content')
    @php
        $summary = $summary ?? [];
        $filters = $filters ?? [];
        $dailyRows = $dailyRows ?? [];
        $chart = $chart ?? ['labels' => [], 'values' => []];
    @endphp

    <div class="container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <h2 class="mb-0">S41 - Funnel báo cáo (Phễu chuyển đổi)</h2>
            <a class="btn btn-success"
                href="{{ route('admin.reports.funnel', ['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null, 'export' => 'csv']) }}">
                Xuất báo cáo CSV
            </a>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.reports.funnel') }}" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Từ ngày</label>
                        <input type="date" class="form-control" name="from" value="{{ $filters['from'] ?? '' }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Đến ngày</label>
                        <input type="date" class="form-control" name="to" value="{{ $filters['to'] ?? '' }}">
                    </div>
                    <div class="col-md-6 d-flex align-items-end gap-2">
                        <button class="btn btn-primary" type="submit">Lọc dữ liệu</button>
                        <a href="{{ route('admin.reports.funnel') }}" class="btn btn-outline-secondary">30 ngày gần nhất</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-xl-3 col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <small class="text-muted d-block mb-1">Tổng đơn hàng thành công</small>
                        <div class="h4 mb-0">{{ number_format((int) ($summary['orders_success'] ?? 0)) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <small class="text-muted d-block mb-1">Tổng Ticket đã phát hành</small>
                        <div class="h4 mb-0 text-primary">{{ number_format((int) ($summary['tickets_issued'] ?? 0)) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <small class="text-muted d-block mb-1">Tổng Ticket đã vào phòng quay</small>
                        <div class="h4 mb-0 text-warning">{{ number_format((int) ($summary['room_joined'] ?? 0)) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <small class="text-muted d-block mb-1">Tổng số giải thưởng đã trao</small>
                        <div class="h4 mb-0 text-success">{{ number_format((int) ($summary['winners'] ?? 0)) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header">Funnel chuyển đổi</div>
                    <div class="card-body">
                        <div style="height: 340px;">
                            <canvas id="funnelChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header">Tỷ lệ chuyển đổi</div>
                    <div class="card-body p-0">
                        <table class="table table-striped mb-0">
                            <tbody>
                                <tr>
                                    <th>CR1: Ticket phát hành / Đơn thành công</th>
                                    <td class="text-end fw-semibold">{{ number_format((float) ($summary['cr1'] ?? 0), 2) }}%</td>
                                </tr>
                                <tr>
                                    <th>CR2: Ticket vào phòng / Ticket phát hành</th>
                                    <td class="text-end fw-semibold">{{ number_format((float) ($summary['cr2'] ?? 0), 2) }}%</td>
                                </tr>
                                <tr>
                                    <th>CR3: Trúng thưởng / Ticket vào phòng</th>
                                    <td class="text-end fw-semibold">{{ number_format((float) ($summary['cr3'] ?? 0), 2) }}%</td>
                                </tr>
                                <tr>
                                    <th>Người dùng mua thành công (unique)</th>
                                    <td class="text-end">{{ number_format((int) ($summary['orders_users'] ?? 0)) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Chi tiết theo ngày (Drill-down)</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Ngày</th>
                                <th class="text-end">Đơn thành công</th>
                                <th class="text-end">Ticket phát hành</th>
                                <th class="text-end">Ticket vào phòng</th>
                                <th class="text-end">Trúng thưởng</th>
                                <th class="text-end">CR1</th>
                                <th class="text-end">CR2</th>
                                <th class="text-end">CR3</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($dailyRows as $row)
                                <tr>
                                    <td>{{ $row['date'] }}</td>
                                    <td class="text-end">{{ number_format((int) ($row['orders_success'] ?? 0)) }}</td>
                                    <td class="text-end">{{ number_format((int) ($row['tickets_issued'] ?? 0)) }}</td>
                                    <td class="text-end">{{ number_format((int) ($row['room_joined'] ?? 0)) }}</td>
                                    <td class="text-end">{{ number_format((int) ($row['winners'] ?? 0)) }}</td>
                                    <td class="text-end">{{ number_format((float) ($row['cr1'] ?? 0), 2) }}%</td>
                                    <td class="text-end">{{ number_format((float) ($row['cr2'] ?? 0), 2) }}%</td>
                                    <td class="text-end">{{ number_format((float) ($row['cr3'] ?? 0), 2) }}%</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center py-3">Không có dữ liệu trong khoảng thời gian đã chọn.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const canvas = document.getElementById('funnelChart');
            if (!canvas) return;

            if (window.funnelChartInstance) {
                window.funnelChartInstance.destroy();
                window.funnelChartInstance = null;
            }

            const labels = @json($chart['labels'] ?? []);
            const values = @json($chart['values'] ?? []);

            if (!Array.isArray(labels) || !Array.isArray(values) || labels.length === 0 || values.length === 0) {
                return;
            }

            window.funnelChartInstance = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: 'Số lượng',
                        data: values,
                        backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#dc3545'],
                        borderRadius: 8,
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
        });
    </script>
@endsection

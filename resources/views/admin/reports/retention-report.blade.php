@extends('admin.layout')

@section('content')
    @php
        $summary = $summary ?? [];
        $filters = $filters ?? [];
        $rows = $rows ?? [];
        $chart = $chart ?? ['labels' => [], 'values' => []];
        $dayOffsets = $dayOffsets ?? [1, 3, 7, 14, 30];

        $heatColor = function ($rate) {
            if ($rate === null) {
                return '#f8f9fa';
            }

            $alpha = min(0.9, max(0.08, ((float) $rate / 100) * 0.9));
            return 'rgba(25, 135, 84, ' . number_format($alpha, 2, '.', '') . ')';
        };

        $growthClass = function ($value) {
            if ($value > 0) {
                return 'text-success';
            }

            if ($value < 0) {
                return 'text-danger';
            }

            return 'text-muted';
        };

        $growthPrefix = function ($value) {
            if ($value > 0) {
                return '+';
            }

            return '';
        };
    @endphp

    <div class="container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <h2 class="mb-0">S42 - Retention User (Tỷ lệ giữ chân)</h2>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.reports.retention') }}" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Từ ngày đăng ký</label>
                        <input type="date" class="form-control" name="from" value="{{ $filters['from'] ?? '' }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Đến ngày đăng ký</label>
                        <input type="date" class="form-control" name="to" value="{{ $filters['to'] ?? '' }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Kiểu Cohort</label>
                        <select class="form-select" name="cohort_by">
                            <option value="day" {{ ($filters['cohort_by'] ?? 'day') === 'day' ? 'selected' : '' }}>Theo ngày</option>
                            <option value="week" {{ ($filters['cohort_by'] ?? 'day') === 'week' ? 'selected' : '' }}>Theo tuần</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button class="btn btn-primary" type="submit">Lọc dữ liệu</button>
                        <a href="{{ route('admin.reports.retention') }}" class="btn btn-outline-secondary">Mặc định</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-body">
                        <small class="text-muted d-block mb-1">Trung bình Retention Day 7</small>
                        <div class="d-flex align-items-end justify-content-between">
                            <div class="display-6 mb-0">{{ number_format((float) ($summary['avg_day7'] ?? 0), 2) }}%</div>
                            <div class="fw-semibold {{ $growthClass((float) ($summary['growth_day7'] ?? 0)) }}">
                                {{ $growthPrefix((float) ($summary['growth_day7'] ?? 0)) }}{{ number_format((float) ($summary['growth_day7'] ?? 0), 2) }}%
                                <small class="text-muted">so với kỳ trước</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-body">
                        <small class="text-muted d-block mb-1">Trung bình Retention Day 30</small>
                        <div class="d-flex align-items-end justify-content-between">
                            <div class="display-6 mb-0">{{ number_format((float) ($summary['avg_day30'] ?? 0), 2) }}%</div>
                            <div class="fw-semibold {{ $growthClass((float) ($summary['growth_day30'] ?? 0)) }}">
                                {{ $growthPrefix((float) ($summary['growth_day30'] ?? 0)) }}{{ number_format((float) ($summary['growth_day30'] ?? 0), 2) }}%
                                <small class="text-muted">so với kỳ trước</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Xu hướng Retention Day 7 theo tháng</div>
            <div class="card-body">
                <div style="height: 320px;">
                    <canvas id="retentionLineChart"></canvas>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Bảng Cohort Retention</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Cohort</th>
                                <th class="text-end">User mới</th>
                                @foreach ($dayOffsets as $day)
                                    <th class="text-end">Day {{ $day }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr>
                                    <td>{{ $row['cohort_label'] }}</td>
                                    <td class="text-end fw-semibold">{{ number_format((int) $row['new_users']) }}</td>
                                    @foreach ($dayOffsets as $day)
                                        @php
                                            $cell = $row['retention'][$day] ?? ['rate' => null, 'count' => null];
                                            $rate = $cell['rate'];
                                            $count = $cell['count'];
                                        @endphp
                                        <td class="text-end" style="background-color: {{ $heatColor($rate) }}; color: {{ $rate !== null && $rate >= 35 ? '#fff' : '#212529' }};">
                                            @if ($rate === null)
                                                -
                                            @else
                                                {{ number_format((float) $rate, 2) }}%
                                                <small class="d-block" style="opacity: .9;">{{ number_format((int) $count) }}/{{ number_format((int) $row['new_users']) }}</small>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ 2 + count($dayOffsets) }}" class="text-center py-3">Không có dữ liệu retention trong khoảng thời gian đã chọn.</td>
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
            const canvas = document.getElementById('retentionLineChart');
            if (!canvas) return;

            if (window.retentionLineChartInstance) {
                window.retentionLineChartInstance.destroy();
                window.retentionLineChartInstance = null;
            }

            const labels = @json($chart['labels'] ?? []);
            const values = @json($chart['values'] ?? []);

            if (!Array.isArray(labels) || !Array.isArray(values) || labels.length === 0) {
                return;
            }

            window.retentionLineChartInstance = new Chart(canvas, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: 'Tỷ lệ giữ chân Day 7 (%)',
                        data: values,
                        borderColor: '#198754',
                        backgroundColor: 'rgba(25, 135, 84, 0.15)',
                        borderWidth: 2,
                        tension: 0.25,
                        fill: true,
                        pointRadius: 3,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: {
                            display: true
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
@endsection

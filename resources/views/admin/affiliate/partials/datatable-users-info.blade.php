<div><strong>F2:</strong> {{ $report['f2_user']['id'] ?? 'N/A' }}</div>
<div>{{ $report['f2_user']['name'] ?? 'N/A' }}</div>
<div><small class="text-muted">SĐT: {{ $report['f2_user']['phone'] ?? 'N/A' }}</small></div>
<hr class="my-2">
<div><strong>F1 bị báo cáo:</strong> {{ $report['reported_upline']['id'] ?? 'N/A' }}</div>
<div>{{ $report['reported_upline']['name'] ?? 'Chưa xác định' }}</div>

<div class="fw-semibold">{{ $report['complaint_code'] }}</div>
<div>{{ $report['title'] }}</div>
<small class="text-muted">Ngày tạo: {{ $report['created_at'] ?? 'N/A' }}</small>
<div class="mt-1">
    <span class="badge bg-warning text-dark">{{ $severityLabels[$report['severity']] ?? $report['severity'] }}</span>
</div>

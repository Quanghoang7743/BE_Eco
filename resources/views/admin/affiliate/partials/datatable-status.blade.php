<span class="badge bg-info text-dark">{{ $statusLabels[$report['status']] ?? $report['status'] }}</span>
@if (!empty($report['resolved_at']))
    <div class="mt-2"><small class="text-muted">Đã xử lý lúc: {{ $report['resolved_at'] }}</small></div>
@endif
@if (!empty($report['resolution_note']))
    <div class="mt-2"><small class="text-muted">{{ \Illuminate\Support\Str::limit($report['resolution_note'], 120) }}</small></div>
@endif

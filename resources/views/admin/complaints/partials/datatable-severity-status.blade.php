<div><span class="badge bg-warning text-dark">{{ $severityLabels[$complaint->severity] ?? $complaint->severity }}</span></div>
<div class="mt-1"><span class="badge bg-info text-dark">{{ $statusLabels[$complaint->status] ?? $complaint->status }}</span></div>
<div class="mt-1"><small class="text-muted">Yêu cầu hoàn vé: {{ $complaint->refund_requested ? 'Có' : 'Không' }}</small></div>

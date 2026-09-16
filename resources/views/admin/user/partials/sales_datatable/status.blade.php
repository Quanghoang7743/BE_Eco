@php
    $tone = $status['tone'] ?? 'green';
@endphp

<span class="sales-status sales-status--{{ $tone }}">
    <span class="sales-status-dot"></span>
    {{ $status['label'] ?? '-' }}
</span>

@if(is_null($credit['points'] ?? null))
    <span class="text-muted">---</span>
@else
    <span class="sales-credit-wrap sales-credit--{{ $credit['tone'] ?? 'muted' }}">
        <span class="sales-credit-value">{{ $credit['points'] }}</span>
        <span class="sales-credit-label">{{ $credit['label'] }}</span>
    </span>
@endif

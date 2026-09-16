@if($starLevel)
    <span class="sales-star-value">
        <i class="fas fa-star"></i>
        {{ number_format((float) $starLevel, 1) }}
    </span>
@else
    <span class="text-muted">-</span>
@endif

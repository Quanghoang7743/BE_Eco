<div><small class="text-muted">Khách hàng: {{ $complaint->user_id ?? 'N/A' }}</small></div>
<div><small class="text-muted">Đơn hàng: {{ $complaint->order_id ?? 'N/A' }}</small></div>
<div><small class="text-muted">Cửa hàng: {{ optional($complaint->store)->store_code }} {{ optional($complaint->store)->name ?: 'N/A' }}</small></div>

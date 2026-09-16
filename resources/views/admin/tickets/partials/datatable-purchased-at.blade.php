@php
	$purchasedAt = $ticket->purchased_at;
@endphp
{{ $purchasedAt ? \Illuminate\Support\Carbon::parse($purchasedAt)->format('Y-m-d H:i:s') : 'N/A' }}

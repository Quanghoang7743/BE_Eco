@php
	$expiredAt = $ticket->expired_at;
@endphp
@if ($expiredAt){{ \Illuminate\Support\Carbon::parse($expiredAt)->format('Y-m-d H:i:s') }}@else{{ 'N/A' }}@endif

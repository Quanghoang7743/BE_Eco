{{ number_format((float) ($ticket->purchased_price ?? $ticket->value_amount ?? 0), 0, ',', '.') }} VND

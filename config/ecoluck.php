<?php

return [
    'ticket_unit_price' => (int) env('ECOLUCK_TICKET_UNIT_PRICE', 25000),
    'order_sla_timeout_seconds' => (int) env('ECOLUCK_ORDER_SLA_TIMEOUT_SECONDS', 150),
    'order_sla_timeout_minutes' => (int) env('ECOLUCK_ORDER_SLA_TIMEOUT_MINUTES', 3),
];

<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TicketRewardService
{
    public function __construct(
        private readonly TicketPricingService $ticketPricingService,
        private readonly TicketLedgerService $ticketLedgerService,
    ) {
    }

    public function awardForCompletedOrder(?User $user, Order $order, ?float $fallbackFinalTotal = null): int
    {
        if (!$user) {
            return 0;
        }

        $status = mb_strtolower(trim((string) ($order->status ?? '')));
        $statusPayment = mb_strtolower(trim((string) ($order->status_payment ?? '')));

        // Allow awarding if order is delivered/completed OR if it's already paid
        // But never if it's canceled
        if ($status === 'canceled') {
            return 0;
        }

        if (!in_array($status, ['delivered', 'completed'], true) && $statusPayment !== 'paid') {
            return 0;
        }

        $alreadyAwarded = Ticket::query()
            ->where('user_id', $user->id)
            ->where('source_type', 'ORDER_REWARD')
            ->where('source_id', (string) $order->id)
            ->exists();

        if ($alreadyAwarded) {
            return 0;
        }

        $rewardRows = $this->resolveTicketRewardRows($order);
        $ticketCount = array_sum(array_column($rewardRows, 'ticket_count'));
        $estimatedRecovery = array_sum(array_column($rewardRows, 'estimated_recovery'));

        if ($ticketCount < 1) {
            return 0;
        }

        $now = now();
        $hasTicketLedger = $this->ticketLedgerService->isEnabled();

        $balance = UserBalance::query()->lockForUpdate()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'available_balance' => 0,
                'locked_balance' => 0,
                'used_balance' => 0,
                'expired_balance' => 0,
                'total_lifetime_purchased' => 0,
                'total_lifetime_used' => 0,
                'last_updated' => $now,
                'version' => 0,
            ]
        );

        $balanceBefore = (int) $balance->available_balance;

        $hasPurchasedAt = Schema::hasColumn('tickets', 'purchased_at');
        $hasSourceType = Schema::hasColumn('tickets', 'source_type');
        $hasSourceId = Schema::hasColumn('tickets', 'source_id');
        $hasSourceOrderItemId = Schema::hasColumn('tickets', 'source_order_item_id');
        $hasCreatedAt = Schema::hasColumn('tickets', 'created_at');
        $hasUpdatedAt = Schema::hasColumn('tickets', 'updated_at');

        foreach ($rewardRows as $rewardRow) {
            for ($i = 0; $i < $rewardRow['ticket_count']; $i++) {
                $ticketPayload = [
                    'ticket_code' => $this->generateTicketCode(),
                    'user_id' => $user->id,
                    'status' => 'AVAILABLE',
                    'ticket_type_code' => $rewardRow['ticket_type_code'],
                ];

                if ($hasPurchasedAt) {
                    $ticketPayload['purchased_at'] = $now;
                }

                if ($hasSourceType) {
                    $ticketPayload['source_type'] = 'ORDER_REWARD';
                }

                if ($hasSourceId) {
                    $ticketPayload['source_id'] = (string) $order->id;
                }

                if ($hasSourceOrderItemId) {
                    $ticketPayload['source_order_item_id'] = $rewardRow['order_item_id'];
                }

                if ($hasCreatedAt) {
                    $ticketPayload['created_at'] = $now;
                }

                if ($hasUpdatedAt) {
                    $ticketPayload['updated_at'] = $now;
                }

                Ticket::query()->create($ticketPayload);
            }
        }

        if ($hasTicketLedger) {
            foreach ($rewardRows as $rewardRow) {
                $ticketTypeCode = strtoupper(trim((string) ($rewardRow['ticket_type_code'] ?? '')));
                $resolvedType = $this->ticketPricingService->resolveTicketType($ticketTypeCode);
                $ticketTypeCode = strtoupper(trim((string) ($resolvedType['code'] ?? $this->ticketPricingService->getDefaultTicketTypeCode())));
                $ticketTypeName = trim((string) ($resolvedType['name'] ?? $this->ticketPricingService->getDefaultTicketTypeName()));

                $this->ticketLedgerService->record([
                    'user_id' => $user->id,
                    'action_type' => 'ISSUED',
                    'amount_change' => $rewardRow['ticket_count'],
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceBefore + $ticketCount,
                    'reference_id' => (string) $order->id,
                    'reference_type' => 'ORDER',
                    'ticket_type_code' => $ticketTypeCode,
                    'ticket_type_name' => $ticketTypeName,
                    'metadata' => [
                        'source_type' => 'ORDER_REWARD',
                        'order_id' => (string) $order->id,
                        'order_code' => $order->order_code ?? null,
                        'ticket_recovery_vnd' => $estimatedRecovery,
                        'ticket_type_code' => $ticketTypeCode,
                        'ticket_type_name' => $ticketTypeName,
                        'ticket_quantity' => $rewardRow['ticket_count'],
                    ],
                    'created_at' => $now,
                ]);
            }
        }

        $balance->available_balance = $balanceBefore + $ticketCount;
        $balance->last_updated = $now;
        $balance->version = (int) $balance->version + 1;
        $balance->save();

        return $ticketCount;
    }

    private function resolveTicketCountAndRecovery(Order $order, ?float $fallbackFinalTotal = null): array
    {
        $hasOrderItems = Schema::hasTable('order_items')
            && Schema::hasColumn('order_items', 'order_id')
            && Schema::hasColumn('order_items', 'quantity')
            && Schema::hasColumn('order_items', 'product_id')
            && Schema::hasTable('products')
            && Schema::hasColumn('products', 'id')
            && Schema::hasColumn('products', 'ticket_reward_quantity');

        if (!$hasOrderItems) {
            return [0, 0.0];
        }

        $rows = DB::table('order_items as oi')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'oi.variant_id')
            ->where('oi.order_id', (string) $order->id)
            ->selectRaw('oi.id as order_item_id')
            ->selectRaw('COALESCE(oi.quantity, 0) as quantity')
            ->selectRaw('COALESCE(pv.ticket_reward_quantity, p.ticket_reward_quantity, 0) as ticket_reward_quantity')
            ->selectRaw('COALESCE(pv.ticket_type_code, p.ticket_type_code, "") as ticket_type_code')
            ->selectRaw('COALESCE(oi.unit_price, 0) as unit_price')
            ->get();

        $totalTickets = 0;
        $totalRecovery = 0.0;

        foreach ($rows as $row) {
            $quantity = max(0, (int) ($row->quantity ?? 0));
            $rewardPerUnit = max(0, (int) ($row->ticket_reward_quantity ?? 0));
            $ticketTypeCode = strtoupper(trim((string) ($row->ticket_type_code ?? '')));

            if ($ticketTypeCode === '') {
                $ticketTypeCode = $this->ticketPricingService->getDefaultTicketTypeCode();
            } else {
                $resolvedTicketType = $this->ticketPricingService->resolveTicketType($ticketTypeCode);
                if (!$resolvedTicketType || strtoupper((string) ($resolvedTicketType['code'] ?? '')) !== $ticketTypeCode) {
                    $ticketTypeCode = $this->ticketPricingService->getDefaultTicketTypeCode();
                }
            }

            if ($quantity < 1 || $rewardPerUnit < 1) {
                continue;
            }

            $lineTickets = $quantity * $rewardPerUnit;
            $unitPrice = (float) ($row->unit_price ?? 0);
            $perTicketValue = $this->ticketPricingService->calculatePerTicketValue($unitPrice);

            $totalTickets += $lineTickets;
            $totalRecovery += $lineTickets * $perTicketValue;
        }

        return [$totalTickets, round($totalRecovery)];
    }

    private function resolveTicketRewardRows(Order $order): array
    {
        $hasOrderItems = Schema::hasTable('order_items')
            && Schema::hasColumn('order_items', 'order_id')
            && Schema::hasColumn('order_items', 'quantity')
            && Schema::hasColumn('order_items', 'product_id')
            && Schema::hasTable('products')
            && Schema::hasColumn('products', 'id')
            && Schema::hasColumn('products', 'ticket_reward_quantity');

        if (!$hasOrderItems) {
            return [];
        }

        $rows = DB::table('order_items as oi')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'oi.variant_id')
            ->where('oi.order_id', (string) $order->id)
            ->selectRaw('oi.id as order_item_id')
            ->selectRaw('COALESCE(oi.quantity, 0) as quantity')
            ->selectRaw('COALESCE(pv.ticket_reward_quantity, p.ticket_reward_quantity, 0) as ticket_reward_quantity')
            ->selectRaw('COALESCE(pv.ticket_type_code, p.ticket_type_code, "") as ticket_type_code')
            ->selectRaw('COALESCE(oi.unit_price, 0) as unit_price')
            ->get();

        $rewardRows = [];

        foreach ($rows as $row) {
            $quantity = max(0, (int) ($row->quantity ?? 0));
            $rewardPerUnit = max(0, (int) ($row->ticket_reward_quantity ?? 0));

            if ($quantity < 1 || $rewardPerUnit < 1) {
                continue;
            }

            $ticketTypeCode = strtoupper(trim((string) ($row->ticket_type_code ?? '')));

            if ($ticketTypeCode === '') {
                $ticketTypeCode = $this->ticketPricingService->getDefaultTicketTypeCode();
            } else {
                $resolvedTicketType = $this->ticketPricingService->resolveTicketType($ticketTypeCode);
                if (!$resolvedTicketType || strtoupper((string) ($resolvedTicketType['code'] ?? '')) !== $ticketTypeCode) {
                    $ticketTypeCode = $this->ticketPricingService->getDefaultTicketTypeCode();
                }
            }

            $ticketCount = $quantity * $rewardPerUnit;
            $unitPrice = (float) ($row->unit_price ?? 0);
            $perTicketValue = $this->ticketPricingService->calculatePerTicketValue($unitPrice);
            $estimatedRecovery = $ticketCount * $perTicketValue;

            $rewardRows[] = [
                'order_item_id' => $row->order_item_id,
                'ticket_count' => $ticketCount,
                'ticket_type_code' => $ticketTypeCode,
                'estimated_recovery' => $estimatedRecovery,
            ];
        }

        return $rewardRows;
    }

    private function generateTicketCode(): string
    {
        do {
            $code = 'TK' . now()->format('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        } while (Ticket::query()->where('ticket_code', $code)->exists());

        return $code;
    }
}

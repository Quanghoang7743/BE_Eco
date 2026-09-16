<?php

namespace App\Http\Controllers\Api;

use App\Events\DrawRoomStartingSoon;
use App\Events\DrawRoomUpdated;
use App\Events\UserNotificationCreated;
use App\Jobs\RunRoomDrawJob;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Room;
use App\Models\RoomSchedule;
use App\Models\RoomTemplateSet;
use App\Models\RoomTicket;
use App\Models\Ticket;
use App\Models\TicketBatch;
use App\Models\TicketLedger;
use App\Models\User;
use App\Models\UserBalance;
use App\Services\TicketPricingService;
use App\Services\RoomTemplateService;
use App\Services\TicketLedgerService;
use App\Services\TicketLockService;
use App\Services\NotificationService;
use App\Services\ProductReturnEligibilityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ClientTicketController extends ClientController
{
    protected function abortJsonResponse(int $status, string $message)
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
        ], $status));
    }

    public function indexTickets(Request $request)
    {
        $user = $this->resolveApiUser($request);

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $status = strtolower((string) $request->query('status', ''));
        $idsOnly = $request->boolean('ids_only');
        $now = now('Asia/Ho_Chi_Minh');

        $query = Ticket::query()
            ->where('user_id', $user->id)
            ->when($status !== '' && $status !== 'all', function ($query) use ($status, $now) {
                if ($status === 'available') {
                    $query->whereRaw('LOWER(status) = ?', ['available'])
                        ->whereNotNull('expired_at')
                        ->where('expired_at', '>', $now);
                } elseif ($status === 'expired') {
                    $query->where(function ($q) use ($now) {
                        $q->whereRaw('LOWER(status) = ?', ['expired'])
                            ->orWhere(function ($sub) use ($now) {
                                $sub->whereRaw('LOWER(status) = ?', ['available'])
                                    ->where(function ($expiryCheck) use ($now) {
                                        $expiryCheck->whereNull('expired_at')
                                            ->orWhere('expired_at', '<=', $now);
                                    });
                            });
                    });
                } else {
                    $query->whereRaw('LOWER(status) = ?', [$status]);
                }
            })
            ->orderByDesc('created_at');

        if ($idsOnly) {
            $ticketIds = $query
                ->pluck('id')
                ->map(static fn ($id) => (string) $id)
                ->values();

            return response()->json($ticketIds);
        }

        $tickets = $query->get();
        $returnMetadataByTicket = app(ProductReturnEligibilityService::class)->describeTickets($tickets);

        // Map ticket type information
        $ticketPricingService = app(TicketPricingService::class);
        $ticketsWithType = $tickets->map(function (Ticket $ticket) use ($ticketPricingService, $returnMetadataByTicket, $now) {
            $ticketData = $ticket->toArray();
            
            // Add ticket type information
            $ticketTypeCode = strtoupper(trim((string) ($ticket->ticket_type_code ?? '')));
            $ticketType = null;
            
            if ($ticketTypeCode !== '') {
                $ticketType = $ticketPricingService->resolveTicketType($ticketTypeCode);
            } else {
                $ticketType = $ticketPricingService->resolveTicketType(null);
            }
            
            $ticketData['ticket_type_code'] = $ticketType ? (string) $ticketType['code'] : null;
            $ticketData['ticket_type_name'] = $ticketType ? (string) $ticketType['name'] : null;
            $ticketData['expires_at'] = optional($ticket->expired_at)->toIso8601String();
            $returnMetadata = $returnMetadataByTicket[(string) $ticket->id] ?? null;
            $ticketData['source_order_item_id'] = $returnMetadata['order_item_id'] ?? ($ticket->source_order_item_id ?? null);
            $ticketData['source_order_id'] = $returnMetadata['order_id'] ?? ($ticket->source_id ?? null);
            $ticketData['source_order_code'] = $returnMetadata['order_code'] ?? null;
            $ticketData['source_product_id'] = $returnMetadata['product_id'] ?? null;
            $ticketData['source_product_name'] = $returnMetadata['product_name'] ?? null;
            $ticketData['return_deadline_at'] = $returnMetadata['return_deadline_at'] ?? null;
            $ticketData['return_blocked_at'] = $returnMetadata['return_blocked_at'] ?? null;
            $ticketData['requires_return_waiver'] = (bool) ($returnMetadata['requires_return_waiver'] ?? false);
            
            // Dynamically set status to 'expired' if the ticket is actually expired but marked as AVAILABLE in DB
            if (strtoupper((string) $ticket->status) === 'AVAILABLE') {
                if (empty($ticket->expired_at) || $ticket->expired_at->lte($now)) {
                    $ticketData['status'] = 'expired';
                }
            }
            
            return $ticketData;
        })->values();

        return response()->json($ticketsWithType);
    }

    public function ticketLedger(Request $request)
    {
        $user = $this->resolveApiUser($request);

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $limit = (int) $request->query('limit', 200);
        $limit = max(1, min($limit, 1000));

        $entries = TicketLedger::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $referenceMaps = $this->buildLedgerReferenceMaps($entries);

        // Pre-load all orders referenced by ORDER_REWARD ledger entries in one query
        // instead of running Order::find() per entry inside the loop (fixes N+1).
        $rewardOrderIds = $entries
            ->filter(function (TicketLedger $entry) {
                $metadata = is_array($entry->metadata) ? $entry->metadata : [];
                return isset($metadata['source_type'])
                    && strtoupper((string) $metadata['source_type']) === 'ORDER_REWARD'
                    && !empty($metadata['order_id']);
            })
            ->map(fn (TicketLedger $entry) => (string) $entry->metadata['order_id'])
            ->unique()
            ->values()
            ->all();

        $rewardOrders = collect();
        if (!empty($rewardOrderIds)) {
            $rewardOrders = Order::query()
                ->with(['items.variant', 'items.product'])
                ->whereIn('id', $rewardOrderIds)
                ->get()
                ->keyBy(fn (Order $order) => (string) $order->id);
        }

        $payload = $entries->map(function (TicketLedger $entry) use ($referenceMaps, $rewardOrders) {
            $amountChange = (int) ($entry->amount_change ?? 0);
            $actionType = strtoupper((string) ($entry->action_type ?? ''));
            $referenceType = strtoupper((string) ($entry->reference_type ?? ''));
            $referenceId = $entry->reference_id ? (string) $entry->reference_id : null;
            $metadata = is_array($entry->metadata) ? $entry->metadata : [];

            $ticketPricingPreview = null;
            $metadataTicketTypeCode = null;
            $metadataTicketTypeName = null;

            if (isset($metadata['ticket_type_code']) && trim((string) $metadata['ticket_type_code']) !== '') {
                $metadataTicketTypeCode = strtoupper(trim((string) $metadata['ticket_type_code']));
            }
            if ($metadataTicketTypeCode === null && isset($metadata['ticketTypeCode']) && trim((string) $metadata['ticketTypeCode']) !== '') {
                $metadataTicketTypeCode = strtoupper(trim((string) $metadata['ticketTypeCode']));
            }

            if (isset($metadata['ticket_type_name']) && trim((string) $metadata['ticket_type_name']) !== '') {
                $metadataTicketTypeName = trim((string) $metadata['ticket_type_name']);
            }
            if ($metadataTicketTypeName === null && isset($metadata['ticketTypeName']) && trim((string) $metadata['ticketTypeName']) !== '') {
                $metadataTicketTypeName = trim((string) $metadata['ticketTypeName']);
            }

            // If this ledger entry comes from an order reward, try to derive ticket type/preview.
            if (isset($metadata['source_type']) && strtoupper((string) $metadata['source_type']) === 'ORDER_REWARD') {
                try {
                    if ($metadataTicketTypeCode !== null) {
                        $ticketQty = max(0, (int) ($metadata['ticket_quantity'] ?? $amountChange));
                        $ticketPricingPreview = app(TicketPricingService::class)->calculatePreview(0.0, $ticketQty, $metadataTicketTypeCode);
                    }

                    if ($ticketPricingPreview === null && !empty($metadata['order_id'])) {
                        $orderId = (string) $metadata['order_id'];
                        $order = $rewardOrders->get($orderId);
                        if ($order) {
                            $match = null;
                            foreach ($order->items as $it) {
                                $qty = (int) ($it->ticket_reward_quantity ?? 0);
                                if ($qty > 0) {
                                    $match = $it;
                                    break;
                                }
                            }

                            if ($match) {
                                $price = (float) ($match->unit_price ?? $match->price ?? 0);
                                $ticketQty = max(0, $amountChange);
                                $ticketTypeCode = null;
                                if (isset($match->variant) && isset($match->variant->ticket_type_code) && trim((string) $match->variant->ticket_type_code) !== '') {
                                    $ticketTypeCode = (string) $match->variant->ticket_type_code;
                                }
                                if ($ticketTypeCode === null && isset($match->product) && isset($match->product->ticket_type_code) && trim((string) $match->product->ticket_type_code) !== '') {
                                    $ticketTypeCode = (string) $match->product->ticket_type_code;
                                }

                                $ticketPricingPreview = app(TicketPricingService::class)->calculatePreview($price, $ticketQty, $ticketTypeCode);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore and continue without preview
                }
            }

            return [
                'id' => (string) $entry->id,
                'ticket_id' => (string) $entry->ticket_id,
                'user_id' => (string) $entry->user_id,
                'action_type' => $actionType,
                'action' => $this->mapLedgerActionToUiAction($actionType, $amountChange),
                'amount_change' => $amountChange,
                'reference_id' => $referenceId,
                'reference_type' => $referenceType !== '' ? $referenceType : null,
                'metadata' => $metadata,
                'ticket_type_code' => $metadataTicketTypeCode,
                'ticket_type_name' => $metadataTicketTypeName,
                'ticket_pricing_preview' => $ticketPricingPreview,
                'note' => $this->buildLedgerNote($actionType, $amountChange, $referenceType, $referenceId, $metadata, $referenceMaps),
                'created_at' => optional($entry->created_at)->toIso8601String(),
            ];
        })->values();

        return response()->json($payload);
    }

    public function ticketPricingConfig(Request $request): JsonResponse
    {
        $user = $this->resolveApiUser($request);

        $ticketPricingService = app(TicketPricingService::class);
        $config = $ticketPricingService->getConfig();
        $ticketTypes = $ticketPricingService->getTicketTypes();
        $defaultTypeCode = $ticketPricingService->getDefaultTicketTypeCode();

        // Format ticket types for config endpoint
        $formattedTicketTypes = array_map(function ($type) {
            return [
                'code' => (string) $type['code'],
                'name' => (string) $type['name'],
                'price' => (float) $type['price'],
                'is_default' => (bool) $type['is_default'],
                'is_active' => (bool) $type['is_active'],
                'sort_order' => (int) $type['sort_order'],
            ];
        }, $ticketTypes);

        return response()->json([
            'ticket_types' => $formattedTicketTypes,
            'data' => [
                'ticket_calc_type' => $config['calc_type'],
                'ticket_value' => (float) $config['ticket_value'],
                'ticket_types' => $formattedTicketTypes,
                'default_ticket_type_code' => $defaultTypeCode,
            ],
            'meta' => [
                'authenticated' => $user !== null,
                'has_store' => false,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function leaderboard(Request $request)
    {
        $limit = (int) $request->query('limit', 10);
        $limit = max(1, min($limit, 50));

        $rows = Ticket::query()
            ->select('user_id', DB::raw('COUNT(*) as total_tickets_available'))
            ->whereRaw('UPPER(status) = ?', ['AVAILABLE'])
            ->groupBy('user_id')
            ->orderByDesc('total_tickets_available')
            ->orderBy('user_id')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return response()->json([]);
        }

        $users = User::query()
            ->whereIn('id', $rows->pluck('user_id')->all())
            ->get()
            ->keyBy(fn (User $user) => (string) $user->id);

        $payload = $rows->values()->map(function ($row, int $index) use ($users) {
            $userId = (string) $row->user_id;
            $user = $users->get($userId);
            $displayName = $user && isset($user->name) && trim((string) $user->name) !== ''
                ? (string) $user->name
                : 'Người chơi ' . $userId;
            $avatarUrl = $user && isset($user->avatar_url) && trim((string) $user->avatar_url) !== ''
                ? (string) $user->avatar_url
                : 'https://i.pravatar.cc/150?u=' . urlencode($userId);

            return [
                'rank' => $index + 1,
                'userId' => $userId,
                'displayName' => $displayName,
                'avatarUrl' => $avatarUrl,
                'totalWins' => $user ? (int) ($user->total_wins ?? 0) : 0,
                'totalTicketsUsed' => (int) $row->total_tickets_available,
                'totalTicketsAvailable' => (int) $row->total_tickets_available,
            ];
        })->values();

        return response()->json($payload);
    }

    public function purchaseTickets(Request $request)
    {
        $user = $this->resolveApiUser($request);

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'quantity' => 'nullable|integer|min:1|max:1000',
        ]);

        $quantity = (int) ($validated['quantity'] ?? 100);
        $unitPrice = max(0, (int) config('ecoluck.ticket_unit_price', 25000));
        $totalAmount = $quantity * $unitPrice;
        $now = now();
        $ledgerService = app(TicketLedgerService::class);

        DB::beginTransaction();

        try {
            $batchCode = 'BATCH-' . $now->format('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

            $batch = TicketBatch::query()->create([
                'batch_code' => $batchCode,
                'user_id' => $user->id,
                'quantity' => $quantity,
                'total_amount' => $totalAmount,
                'status' => 'completed',
                'completed_at' => $now,
            ]);

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

            $hasPurchasedAt = Schema::hasColumn('tickets', 'purchased_at');
            $hasPurchasedPrice = Schema::hasColumn('tickets', 'purchased_price');
            $hasValueAmount = Schema::hasColumn('tickets', 'value_amount');
            $hasSourceType = Schema::hasColumn('tickets', 'source_type');
            $hasSourceId = Schema::hasColumn('tickets', 'source_id');
            $hasTicketTypeCode = Schema::hasColumn('tickets', 'ticket_type_code');
            $hasBatchId = Schema::hasColumn('tickets', 'batch_id');
            $hasCreatedAt = Schema::hasColumn('tickets', 'created_at');
            $hasUpdatedAt = Schema::hasColumn('tickets', 'updated_at');
            $defaultTicketTypeCode = app(TicketPricingService::class)->getDefaultTicketTypeCode();

            $balanceBefore = (int) $balance->available_balance;

            for ($i = 0; $i < $quantity; $i++) {
                $ticketPayload = [
                    'ticket_code' => $this->generateTicketCode(),
                    'user_id' => $user->id,
                    'status' => 'AVAILABLE',
                ];

                if ($hasPurchasedAt) {
                    $ticketPayload['purchased_at'] = $now;
                }

                if ($hasPurchasedPrice) {
                    $ticketPayload['purchased_price'] = $unitPrice;
                }

                if ($hasValueAmount) {
                    $ticketPayload['value_amount'] = $unitPrice;
                }

                if ($hasSourceType) {
                    $ticketPayload['source_type'] = 'PREPAID_PURCHASE';
                }

                if ($hasSourceId) {
                    $ticketPayload['source_id'] = (string) $batch->id;
                }

                if ($hasTicketTypeCode) {
                    $ticketPayload['ticket_type_code'] = $defaultTicketTypeCode;
                }

                if ($hasBatchId) {
                    $ticketPayload['batch_id'] = (string) $batch->id;
                }

                if ($hasCreatedAt) {
                    $ticketPayload['created_at'] = $now;
                }

                if ($hasUpdatedAt) {
                    $ticketPayload['updated_at'] = $now;
                }

                $ticket = Ticket::query()->create($ticketPayload);

                if ($ledgerService->isEnabled()) {
                    $ledgerService->record([
                        'ticket_id' => $ticket->id,
                        'user_id' => $user->id,
                        'action_type' => 'PURCHASE',
                        'amount_change' => 1,
                        'balance_before' => $balanceBefore + $i,
                        'balance_after' => $balanceBefore + $i + 1,
                        'reference_id' => (string) $batch->id,
                        'reference_type' => 'TICKET_BATCH',
                        'metadata' => [
                            'quantity' => $quantity,
                            'unit_price' => $unitPrice,
                            'source_type' => 'PREPAID_PURCHASE',
                        ],
                        'created_at' => $now,
                    ]);
                }
            }

            $balance->available_balance = $balanceBefore + $quantity;
            $balance->total_lifetime_purchased = (int) $balance->total_lifetime_purchased + $quantity;
            $balance->last_updated = $now;
            $balance->version = (int) $balance->version + 1;
            $balance->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Mua vé thành công.',
                'data' => [
                    'batch_id' => $batch->id,
                    'batch_code' => $batch->batch_code,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => $totalAmount,
                    'available_balance' => (int) $balance->available_balance,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Failed to purchase tickets', [
                'user_id' => $user->id,
                'quantity' => $quantity,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Không thể mua vé lúc này. Vui lòng thử lại.',
            ], 500);
        }
    }

    public function giftTickets(Request $request)
    {
        $sender = $this->resolveApiUser($request);

        if (!$sender) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'ticket_ids' => ['required', 'array', 'min:1'],
            'ticket_ids.*' => ['string'],
            'receiver_phone' => ['required', 'string', 'max:20'],
        ]);

        $ticketIds = collect($validated['ticket_ids'])
            ->map(static fn ($id) => trim((string) $id))
            ->filter(static fn ($id) => $id !== '')
            ->unique()
            ->values()
            ->all();

        if (empty($ticketIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Vui long chon it nhat 1 ve.',
            ], 422);
        }

        $receiverPhoneCandidates = $this->phoneLookupCandidates($validated['receiver_phone']);
        if (empty($receiverPhoneCandidates)) {
            return response()->json([
                'success' => false,
                'message' => 'So dien thoai nguoi nhan khong hop le.',
            ], 422);
        }

        $receiver = User::query()->whereIn('phone', $receiverPhoneCandidates)->first();
        if (!$receiver) {
            return response()->json([
                'success' => false,
                'message' => 'Khong tim thay nguoi nhan.',
            ], 404);
        }

        if ((string) $receiver->id === (string) $sender->id) {
            return response()->json([
                'success' => false,
                'message' => 'Khong the tang ve cho chinh minh.',
            ], 422);
        }

        $now = now('Asia/Ho_Chi_Minh');
        $ledgerService = app(TicketLedgerService::class);
        $notificationService = app(NotificationService::class);

        try {
            $giftContext = DB::transaction(function () use ($sender, $receiver, $ticketIds, $now, $ledgerService, $notificationService) {
                $tickets = Ticket::query()
                    ->where('user_id', $sender->id)
                    ->whereIn('id', $ticketIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(fn (Ticket $ticket) => (string) $ticket->id);

                if ($tickets->count() !== count($ticketIds)) {
                    abort(422, 'Co ve khong hop le hoac khong thuoc ve ban.');
                }

                $invalidTickets = $tickets->filter(function (Ticket $ticket) use ($now): bool {
                    if (strtoupper((string) $ticket->status) !== 'AVAILABLE') {
                        return true;
                    }

                    if (empty($ticket->expired_at)) {
                        return true;
                    }

                    return $ticket->expired_at->lte($now);
                });

                if ($invalidTickets->isNotEmpty()) {
                    abort(422, 'Co ve khong con hop le de tang. Chi ve AVAILABLE va con han moi duoc tang.');
                }

                $ticketCount = $tickets->count();
                $ticketCodeList = $tickets->pluck('ticket_code')->values()->all();
                $giftTransferId = (string) Str::uuid();

                $senderBalance = UserBalance::query()->lockForUpdate()->firstOrCreate(
                    ['user_id' => $sender->id],
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

                $receiverBalance = UserBalance::query()->lockForUpdate()->firstOrCreate(
                    ['user_id' => $receiver->id],
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

                $senderBalanceBefore = (int) $senderBalance->available_balance;
                $receiverBalanceBefore = (int) $receiverBalance->available_balance;

                Ticket::query()
                    ->whereIn('id', $ticketIds)
                    ->update([
                        'user_id' => $receiver->id,
                        'updated_at' => $now,
                    ]);

                $senderBalance->available_balance = max(0, $senderBalanceBefore - $ticketCount);
                $senderBalance->last_updated = $now;
                $senderBalance->version = (int) $senderBalance->version + 1;
                $senderBalance->save();

                $receiverBalance->available_balance = $receiverBalanceBefore + $ticketCount;
                $receiverBalance->last_updated = $now;
                $receiverBalance->version = (int) $receiverBalance->version + 1;
                $receiverBalance->save();

                if ($ledgerService->isEnabled()) {
                    $ledgerService->record([
                        'ticket_id' => $ticketIds[0],
                        'user_id' => $sender->id,
                        'action_type' => 'GIFT_SEND',
                        'amount_change' => -$ticketCount,
                        'balance_before' => $senderBalanceBefore,
                        'balance_after' => max(0, $senderBalanceBefore - $ticketCount),
                        'reference_id' => $giftTransferId,
                        'reference_type' => 'TICKET_GIFT',
                        'metadata' => [
                            'gift_transfer_id' => $giftTransferId,
                            'ticket_ids' => $ticketIds,
                            'ticket_codes' => $ticketCodeList,
                            'receiver_id' => (string) $receiver->id,
                            'receiver_phone' => (string) ($receiver->phone ?? ''),
                            'receiver_name' => (string) ($receiver->full_name ?? $receiver->name ?? ''),
                            'direction' => 'send',
                        ],
                        'created_at' => $now,
                    ]);

                    $ledgerService->record([
                        'ticket_id' => $ticketIds[0],
                        'user_id' => $receiver->id,
                        'action_type' => 'GIFT_RECEIVE',
                        'amount_change' => $ticketCount,
                        'balance_before' => $receiverBalanceBefore,
                        'balance_after' => $receiverBalanceBefore + $ticketCount,
                        'reference_id' => $giftTransferId,
                        'reference_type' => 'TICKET_GIFT',
                        'metadata' => [
                            'gift_transfer_id' => $giftTransferId,
                            'ticket_ids' => $ticketIds,
                            'ticket_codes' => $ticketCodeList,
                            'sender_id' => (string) $sender->id,
                            'sender_phone' => (string) ($sender->phone ?? ''),
                            'sender_name' => (string) ($sender->full_name ?? $sender->name ?? ''),
                            'direction' => 'receive',
                        ],
                        'created_at' => $now,
                    ]);
                }

                $senderName = trim((string) ($sender->full_name ?? $sender->name ?? ''));
                if ($senderName === '') {
                    $senderName = 'người gửi';
                }

                $notification = null;
                if (Schema::hasTable('notifications')) {
                    $notification = $notificationService->create([
                        'user_id' => (string) $receiver->id,
                        'audience_scope' => 'user_private',
                        'type' => 'ticket_gift_received',
                        'title' => 'Bạn vừa nhận Ticket',
                        'message' => sprintf('Bạn nhận %d Lucky Ticket từ %s.', $ticketCount, $senderName),
                        'body' => sprintf('Bạn nhận %d Lucky Ticket từ %s.', $ticketCount, $senderName),
                        'action_url' => '/ticket-ledger',
                        'is_read' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                return [
                    'result' => [
                        'gift_transfer_id' => $giftTransferId,
                        'ticket_count' => $ticketCount,
                        'receiver' => [
                            'id' => (string) $receiver->id,
                            'name' => (string) ($receiver->full_name ?? $receiver->name ?? ''),
                            'phone' => (string) ($receiver->phone ?? ''),
                        ],
                    ],
                    'notification' => $notification,
                ];
            });

            $result = $giftContext['result'];
            $notification = $giftContext['notification'] ?? null;
            if ($notification instanceof Notification) {
                UserNotificationCreated::dispatch($notification);
            }

            return response()->json([
                'success' => true,
                'message' => 'Tang Lucky Ticket thanh cong.',
                'data' => $result,
            ]);
        } catch (
            \Throwable $e
        ) {
            Log::error('Failed to gift tickets', [
                'sender_id' => $sender->id,
                'receiver_phone_candidates' => $receiverPhoneCandidates,
                'ticket_ids' => $ticketIds,
                'error' => $e->getMessage(),
            ]);

            $statusCode = $e instanceof HttpExceptionInterface
                ? $e->getStatusCode()
                : (int) ($e->getCode() ?: 500);
            if ($statusCode < 400 || $statusCode > 599) {
                $statusCode = 500;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Khong the tang ve luc nay. Vui long thu lai.',
            ], $statusCode);
        }
    }

    public function joinDrawRoom(Request $request, string $roomId)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = validator($request->all(), [
            'ticketIds' => ['required', 'array', 'min:1'],
            'ticketIds.*' => ['string'],
            'acceptReturnWaiver' => ['nullable', 'boolean'],
        ])->validate();

        $ticketIds = array_values(array_unique($validated['ticketIds']));
        $acceptReturnWaiver = (bool) ($validated['acceptReturnWaiver'] ?? false);

        [$room, $shouldScheduleDraw, $scheduledDrawAt] = DB::transaction(function () use ($roomId, $ticketIds, $user, $acceptReturnWaiver) {
            $room = Room::query()->whereKey($roomId)->lockForUpdate()->first();

            if (! $room) {
                $this->abortJsonResponse(404, 'Không tìm thấy phòng.');
            }

            if (in_array(strtolower((string) $room->status), ['drawing', 'completed', 'cancelled'], true)) {
                $this->abortJsonResponse(422, 'Phòng đang quay hoặc đã kết thúc.');
            }

            $now = now('Asia/Ho_Chi_Minh');
            $openAt = $this->resolveRoomBoundaryAtLocalTimezone($room, 'open_at');
            if ($openAt !== null && $now->lt($openAt)) {
                abort(422, 'Phòng chưa đến thời điểm mở.');
            }

            $closeAt = $this->resolveRoomBoundaryAtLocalTimezone($room, 'close_at');
            if ($closeAt !== null && $now->gte($closeAt)) {
                $this->abortJsonResponse(422, 'Phòng đã đóng, không thể nộp vé.');
            }

            $tickets = Ticket::query()
                ->where('user_id', $user->id)
                ->whereIn('id', $ticketIds)
                ->lockForUpdate()
                ->get();

            if ($tickets->count() !== count($ticketIds)) {
                $this->abortJsonResponse(422, 'Có vé không hợp lệ hoặc không thuộc về bạn.');
            }

            $roomTicketTypeCode = $this->resolveRoomTicketTypeCode($room);
            if ($roomTicketTypeCode === null) {
                $this->abortJsonResponse(422, 'Phòng chưa cấu hình loại vé tham gia. Vui lòng liên hệ quản trị viên.');
            }

            $acceptAllTicketTypes = $this->isAllTicketTypesCode($roomTicketTypeCode);

            if (!$acceptAllTicketTypes) {
                if (!Schema::hasColumn('tickets', 'ticket_type_code')) {
                    $this->abortJsonResponse(422, 'Hệ thống chưa có thông tin loại vé để đối chiếu với phòng.');
                }

                $ticketPricingService = app(TicketPricingService::class);
                $roomTicketTypeName = $this->resolveRoomTicketTypeName($roomTicketTypeCode);
                $mismatchedTickets = $tickets->filter(function (Ticket $ticket) use ($roomTicketTypeCode, $ticketPricingService): bool {
                    $normalizedTicketTypeCode = $this->resolveTicketTypeCodeForJoinValidation($ticket, $ticketPricingService);

                    return $normalizedTicketTypeCode !== $roomTicketTypeCode;
                });

                if ($mismatchedTickets->count() > 0) {
                    $errorMessage = $roomTicketTypeName
                        ? "Phòng này yêu cầu $roomTicketTypeName. Bạn chưa chọn đúng loại vé."
                        : 'Loại vé không phù hợp với cấu hình của phòng.';
                    
                    $this->abortJsonResponse(422, $errorMessage);
                }
            }

            $hasInvalidOrExpired = $tickets->contains(function (Ticket $ticket) use ($now): bool {
                if (strtoupper((string) $ticket->status) !== 'AVAILABLE') {
                    return true;
                }
                if (empty($ticket->expired_at)) {
                    return true;
                }
                return $ticket->expired_at->lte($now);
            });

            if ($hasInvalidOrExpired) {
                $this->abortJsonResponse(422, 'Có vé không còn khả dụng hoặc đã hết hạn.');
            }

            $waiverProducts = app(ProductReturnEligibilityService::class)->waiverProducts($tickets, $now);
            if (!empty($waiverProducts) && !$acceptReturnWaiver) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Bạn cần xác nhận mất quyền trả hàng/hoàn tiền trước khi sử dụng Ticket này.',
                    'error_code' => 'TICKET_RETURN_WAIVER_REQUIRED',
                    'products' => $waiverProducts,
                ], 409));
            }

            $room->refresh();

            $currentVotes = (int) $room->current_votes;
            $requiredVotes = max(1, (int) ($room->required_votes ?: 1000));
            $newVotes = $currentVotes + $tickets->count();
            $ledgerService = app(TicketLedgerService::class);
            $ticketLockService = app(TicketLockService::class);
            $hasTicketLedger = $ledgerService->isEnabled();
            $lockOwner = 'room:' . (string) $room->id . ':' . (string) $user->id . ':' . Str::random(12);
            $runningBalance = null;

            if ($hasTicketLedger) {
                $runningBalance = (int) Ticket::query()
                    ->where('user_id', $user->id)
                    ->whereRaw('UPPER(status) = ?', ['AVAILABLE'])
                    ->count();
            }

            if ($newVotes > $requiredVotes) {
                $this->abortJsonResponse(422, 'Phòng đã đủ vé.');
            }
            $ticketIdList = $tickets->pluck('id')->all();

            foreach ($tickets as $ticket) {
                if (strtoupper((string) $ticket->status) !== 'AVAILABLE') {
                    $this->abortJsonResponse(422, 'Có vé không còn khả dụng để vào phòng.');
                }

                $ticketLockService->acquire($ticket, $lockOwner, TicketLockService::DEFAULT_TTL_SECONDS);
            }

            $existingRoomTickets = RoomTicket::query()
                ->where('room_id', $room->id)
                ->whereIn('ticket_id', $ticketIdList)
                ->get(['id', 'ticket_id', 'is_refunded']);

            $existingLookup = [];
            $refundedRoomTicketIds = [];
            foreach ($existingRoomTickets as $existingRoomTicket) {
                if ((bool) $existingRoomTicket->is_refunded) {
                    $refundedRoomTicketIds[] = $existingRoomTicket->id;
                    continue;
                }

                $existingLookup[(string) $existingRoomTicket->ticket_id] = true;
            }

            $roomTicketRows = [];

            foreach ($ticketIdList as $ticketId) {
                if (isset($existingLookup[(string) $ticketId])) {
                    continue;
                }

                $roomTicketRows[] = [
                    'id' => (string) Str::uuid(),
                    'room_id' => $room->id,
                    'ticket_id' => $ticketId,
                    'user_id' => $user->id,
                    'joined_at' => $now,
                    'is_winner' => false,
                    'is_refunded' => false,
                ];
            }

            if (!empty($roomTicketRows)) {
                RoomTicket::query()->insert($roomTicketRows);
            }

            if (!empty($refundedRoomTicketIds)) {
                RoomTicket::query()
                    ->whereIn('id', $refundedRoomTicketIds)
                    ->update([
                        'user_id' => $user->id,
                        'joined_at' => $now,
                        'is_winner' => false,
                        'is_refunded' => false,
                    ]);
            }

            if (!empty($waiverProducts)) {
                app(ProductReturnEligibilityService::class)->blockReturnsForTickets($tickets, $now);
            }

            Ticket::query()
                ->whereIn('id', $ticketIdList)
                ->update([
                    'status' => 'USED_IN_ROOM',
                    'used_in_room_id' => $room->id,
                    'used_at' => $now,
                    'locked_at' => null,
                    'locked_by' => null,
                    'lock_expires_at' => null,
                ]);

            foreach ($ticketIdList as $ticketId) {
                $ticketLockService->deleteLockRow((string) $ticketId);
            }

            if ($hasTicketLedger) {
                foreach ($tickets as $ticket) {
                    $balanceBefore = $runningBalance;
                    $balanceAfter = max(0, (int) $balanceBefore - 1);

                    $ledgerService->record([
                        'ticket_id' => $ticket->id,
                        'user_id' => $user->id,
                        'action_type' => 'ROOM_USE',
                        'amount_change' => -1,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceAfter,
                        'reference_id' => (string) $room->id,
                        'reference_type' => 'ROOM',
                        'metadata' => [
                            'room_id' => (string) $room->id,
                            'room_code' => $room->room_code ?? null,
                            'room_number' => $room->room_number ?? null,
                            'event' => 'join_draw_room',
                        ],
                        'created_at' => $now,
                    ]);

                    $runningBalance = $balanceAfter;
                }
            }

            $room->current_votes = $newVotes;
            $shouldScheduleDraw = false;
            $scheduledDrawAt = null;

            if ($room->current_votes >= $requiredVotes) {
                $room->status = 'FULL';
                $shouldScheduleDraw = true;

                $configuredDrawAt = $room->draw_started_at
                    ? $room->draw_started_at->copy()->setTimezone('Asia/Ho_Chi_Minh')
                    : null;

                if ($configuredDrawAt && $configuredDrawAt->gt($now)) {
                    $scheduledDrawAt = $configuredDrawAt;
                } else {
                    if($configuredDrawAt){
                        $scheduledDrawAt = $now->copy()->addMinute();
                    }else{
                        $scheduledDrawAt = $now->copy()->addDay();
                    }
                }

                $room->draw_started_at = $scheduledDrawAt->copy()->setTimezone('UTC');
            } elseif ($room->current_votes > 0 && in_array(strtolower((string) $room->status), ['waiting', 'filling'], true)) {
                $room->status = 'FILLING';
            }
            $room->save();

            return [$room, $shouldScheduleDraw, $scheduledDrawAt];
        });

        $freshRoom = $room->fresh();
        $serializedRoom = $this->serializeDrawRoomPayload($freshRoom);
        event(new DrawRoomUpdated($serializedRoom));
        $this->publishDrawRoomRealtimeUpdate($serializedRoom);

        if ($shouldScheduleDraw && $scheduledDrawAt !== null) {
            event(new DrawRoomStartingSoon($this->buildDrawSoonPayload($room, $serializedRoom, $scheduledDrawAt)));

            try {
                RunRoomDrawJob::dispatch((string) $room->id)
                    ->onConnection('database')
                    ->delay($scheduledDrawAt);
            } catch (\Throwable $e) {
                Log::error('Failed to dispatch delayed room draw job.', [
                    'room_id' => (string) $room->id,
                    'scheduled_draw_at' => $scheduledDrawAt->toIso8601String(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json($serializedRoom);
    }

    public function withdrawDrawRoom(Request $request, string $roomId)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = validator($request->all(), [
            'ticketCount' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $requestedTicketCount = isset($validated['ticketCount']) ? (int) $validated['ticketCount'] : null;

        $room = DB::transaction(function () use ($roomId, $user, $requestedTicketCount) {
            $room = Room::query()->whereKey($roomId)->lockForUpdate()->first();

            if (! $room) {
                $this->abortJsonResponse(404, 'Không tìm thấy phòng.');
            }

            $requiredVotes = max(1, (int) ($room->required_votes ?: 1000));
            $currentVotes = (int) $room->current_votes;
            $status = strtolower((string) $room->status);

            if ($currentVotes >= $requiredVotes || in_array($status, ['full', 'drawing', 'completed', 'cancelled'], true)) {
                $this->abortJsonResponse(422, 'Không thể rút vé khi phòng đã đủ vé hoặc đã quay.');
            }

            $tickets = Ticket::query()
                ->where('user_id', $user->id)
                ->where('used_in_room_id', $room->id)
                ->where('status', 'USED_IN_ROOM')
                ->lockForUpdate()
                ->get();

            if ($tickets->isEmpty()) {
                $this->abortJsonResponse(422, 'Bạn chưa có vé nào trong phòng này.');
            }

            if ($requestedTicketCount !== null && $requestedTicketCount > $tickets->count()) {
                $this->abortJsonResponse(422, 'Số vé muốn rút lớn hơn số vé bạn đang có trong phòng này.');
            }

            $ticketsToRelease = $requestedTicketCount === null
                ? $tickets
                : $tickets->take($requestedTicketCount)->values();
            $now = now();
            $ledgerService = app(TicketLedgerService::class);
            $hasTicketLedger = $ledgerService->isEnabled();
            $runningBalance = null;

            if ($hasTicketLedger) {
                $runningBalance = (int) Ticket::query()
                    ->where('user_id', $user->id)
                    ->whereRaw('UPPER(status) = ?', ['AVAILABLE'])
                    ->count();
            }

            $ticketIds = $ticketsToRelease->pluck('id')->all();

            Ticket::query()
                ->whereIn('id', $ticketIds)
                ->update([
                    'status' => 'AVAILABLE',
                    'used_in_room_id' => null,
                    'used_at' => null,
                ]);

            if ($hasTicketLedger) {
                foreach ($ticketsToRelease as $ticket) {
                    $balanceBefore = $runningBalance;
                    $balanceAfter = (int) $balanceBefore + 1;

                    $ledgerService->record([
                        'ticket_id' => $ticket->id,
                        'user_id' => $user->id,
                        'action_type' => 'ROOM_WITHDRAW',
                        'amount_change' => 1,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceAfter,
                        'reference_id' => (string) $room->id,
                        'reference_type' => 'ROOM',
                        'metadata' => [
                            'room_id' => (string) $room->id,
                            'room_code' => $room->room_code ?? null,
                            'room_number' => $room->room_number ?? null,
                            'event' => 'withdraw_draw_room',
                        ],
                        'created_at' => $now,
                    ]);

                    $runningBalance = $balanceAfter;
                }
            }

            RoomTicket::query()
                ->where('room_id', $room->id)
                ->where('user_id', $user->id)
                ->whereIn('ticket_id', $ticketIds)
                ->delete();

            $releasedCount = count($ticketIds);
            $newVotes = max(0, $currentVotes - $releasedCount);

            $room->current_votes = $newVotes;

            if ($newVotes <= 0) {
                $room->status = 'WAITING';
            } elseif ($newVotes < $requiredVotes) {
                $room->status = 'FILLING';
            }

            $room->save();

            return [$room->fresh(), $releasedCount];
        });

        [$updatedRoom, $releasedCount] = $room;
        $serializedRoom = $this->serializeDrawRoomPayload($updatedRoom);
        event(new DrawRoomUpdated($serializedRoom));
        $this->publishDrawRoomRealtimeUpdate($serializedRoom);

        return response()->json([
            'releasedTickets' => $releasedCount,
            'room' => $serializedRoom,
        ]);
    }

    public function todayDrawRooms()
    {
        return Cache::remember('draw_rooms_today_payload', 3, function () {
            $schedule = RoomSchedule::getOrCreateDailyConfig();
            $today = now('Asia/Ho_Chi_Minh')->toDateString();
            $roomsPerDay = max(1, (int) ($schedule->rooms_per_day ?? RoomSchedule::DEFAULT_ROOMS_PER_DAY));
            $requiredVotes = max(1, (int) ($schedule->required_votes ?? RoomSchedule::DEFAULT_REQUIRED_VOTES));
            $defaultPrizeType = (string) ($schedule->default_prize_type ?: RoomSchedule::DEFAULT_PRIZE_TYPE);
            $defaultPrizeValue = $schedule->default_prize_value !== null ? (float) $schedule->default_prize_value : 25000000;
            $defaultPrizeDescription = (string) ($schedule->default_prize_description ?: 'Giải thưởng xe điện Ecoluck');
            $defaultSecondaryPrizes = is_array($schedule->secondary_prizes ?? null) ? $schedule->secondary_prizes : [];
            $hasSecondaryPrizesColumn = Schema::hasColumn('rooms', 'secondary_prizes');
            $defaultRoomTicketTypeCode = $this->resolveDefaultRoomTicketTypeCode();

            if (!$this->generateRoomsFromSelectedTemplateSetIfPossible($schedule, $today)) {
                for ($roomNumber = 1; $roomNumber <= $roomsPerDay; $roomNumber++) {
                    $roomDefaults = [
                        'room_code' => Room::makeRoomCode($today, $roomNumber),
                        'status' => 'WAITING',
                        'current_votes' => 0,
                        'required_votes' => $requiredVotes,
                        'prize_type' => $defaultPrizeType,
                        'prize_value' => $defaultPrizeValue,
                        'prize_description' => $defaultPrizeDescription,
                    ];

                    if (Schema::hasColumn('rooms', 'ticket_type_code')) {
                        $roomDefaults['ticket_type_code'] = $defaultRoomTicketTypeCode;
                    }

                    if ($hasSecondaryPrizesColumn) {
                        $roomDefaults['secondary_prizes'] = !empty($defaultSecondaryPrizes)
                            ? json_encode($defaultSecondaryPrizes, JSON_UNESCAPED_UNICODE)
                            : null;
                    }

                    Room::withTrashed()->firstOrCreate(
                        [
                            'room_date' => $today,
                            'room_number' => $roomNumber,
                        ],
                        $roomDefaults
                    );
                }
            }

            $roomModels = Room::query()
                ->whereDate('room_date', $today)
                ->whereNotIn(DB::raw('LOWER(status)'), ['cancelled'])
                ->orderBy('room_number')
                ->limit($roomsPerDay)
                ->get();

            $participantsByRoom = [];
            $roomIds = $roomModels->pluck('id')->map(fn ($id) => (string) $id)->all();

            if (!empty($roomIds)) {
                $participantRows = RoomTicket::query()
                    ->select('room_id', 'user_id', DB::raw('COUNT(*) as ticket_count'), DB::raw('MIN(joined_at) as first_joined_at'))
                    ->whereIn('room_id', $roomIds)
                    ->where('is_refunded', false)
                    ->groupBy('room_id', 'user_id')
                    ->orderBy('first_joined_at')
                    ->get();

                $users = User::query()
                    ->whereIn('id', $participantRows->pluck('user_id')->unique()->all())
                    ->get()
                    ->keyBy(fn (User $user) => (string) $user->id);

                foreach ($participantRows as $row) {
                    $roomId = (string) $row->room_id;
                    $userId = (string) $row->user_id;
                    $user = $users->get($userId);

                    $displayName = trim((string) ($user?->name ?? $user?->full_name ?? ''));
                    if ($displayName === '') {
                        $displayName = 'Người chơi ' . substr($userId, 0, 6);
                    }

                    $avatarUrl = trim((string) ($user?->avatar_url ?? $user?->avatar ?? ''));
                    if ($avatarUrl === '') {
                        $avatarUrl = 'https://i.pravatar.cc/150?u=' . urlencode($userId);
                    }

                    $participantsByRoom[$roomId][] = [
                        'userId' => $userId,
                        'displayName' => $displayName,
                        'avatarUrl' => $avatarUrl,
                        'ticketCount' => (int) $row->ticket_count,
                        'joinedAt' => optional($row->first_joined_at)->toIso8601String() ?: now()->toIso8601String(),
                    ];
                }
            }

            $winnerTicketCodesById = [];
            $winnerTicketIds = $roomModels
                ->pluck('winner_ticket_id')
                ->filter(fn ($id) => !empty($id))
                ->map(fn ($id) => (string) $id)
                ->unique()
                ->values()
                ->all();

            if (!empty($winnerTicketIds)) {
                $winnerTicketCodesById = Ticket::query()
                    ->whereIn('id', $winnerTicketIds)
                    ->pluck('ticket_code', 'id')
                    ->toArray();
            }

            $serializeRoom = function (Room $room) use ($schedule, $today, $participantsByRoom, $winnerTicketCodesById) {
                $currentTickets = (int) $room->current_votes;
                $requiredVotes = max(1, (int) ($room->required_votes ?: $schedule->required_votes));
                $status = strtolower((string) $room->status);

                if (! in_array($status, ['waiting', 'filling', 'full', 'drawing', 'completed', 'cancelled'], true)) {
                    $status = 'waiting';
                }

                if (in_array($status, ['drawing', 'completed', 'cancelled'], true)) {
                    // Keep terminal statuses from persisted backend draw state.
                } elseif ($currentTickets >= $requiredVotes) {
                    $status = 'full';
                } elseif ($currentTickets > 0) {
                    $status = 'filling';
                }

                $winnerTicketId = $room->winner_ticket_id ? (string) $room->winner_ticket_id : null;
                $winnerTicketCode = $winnerTicketId ? (string) ($winnerTicketCodesById[$winnerTicketId] ?? $winnerTicketId) : null;
                $drawResult = null;

                if ($status === 'completed' && $room->winner_id && $winnerTicketId) {
                    $winnerName = trim((string) ($room->winner_name ?? ''));
                    if ($winnerName === '') {
                        $winnerName = 'Người chơi ' . substr((string) $room->winner_id, 0, 6);
                    }

                    $drawResult = [
                        'roomId' => (string) $room->id,
                        'winnerId' => (string) $room->winner_id,
                        'winnerName' => $winnerName,
                        'winnerTicketCode' => $winnerTicketCode,
                        'drawnAt' => optional($room->draw_completed_at)->toIso8601String(),
                        'prizeDescription' => $this->normalizePrizeText($room->prize_description ?: 'Phòng quay hôm nay'),
                        'prizeDescriptionHtml' => (string) ($room->prize_description ?: 'Phòng quay hôm nay'),
                        'prizeImageUrl' => $room->prize_image_url ? (string) $room->prize_image_url : null,
                    ];
                }

                $scheduledDrawAt = (new \DateTimeImmutable($today . ' 04:00:00', new \DateTimeZone('Asia/Ho_Chi_Minh')))
                    ->modify('+' . (($room->room_number - 1) * 30) . ' minutes');

                $scheduledDrawAtIso = null;
                if ($room->draw_started_at) {
                    try {
                        $scheduledDrawAtIso = $room->draw_started_at->setTimezone('Asia/Ho_Chi_Minh')->toIso8601String();
                    } catch (\Throwable $e) {
                        $scheduledDrawAtIso = null;
                    }
                }

                if (! $scheduledDrawAtIso) {
                    $scheduledDrawAtIso = $scheduledDrawAt->format(\DateTimeInterface::ATOM);
                }

                $roomTicketTypeCode = $this->resolveRoomTicketTypeCode($room);

                return [
                    'id' => (string) $room->id,
                    'name' => sprintf('Phòng %02d hôm nay', (int) $room->room_number),
                    'description' => 'Phòng quay hôm nay, mỗi phòng cần 1000 vé để mở kết quả.',
                    'maxTickets' => $requiredVotes,
                    'currentTickets' => $currentTickets,
                    'prizeDescription' => $this->normalizePrizeText($room->prize_description ?: 'Phòng quay hôm nay'),
                    'prizeDescriptionHtml' => (string) ($room->prize_description ?: 'Phòng quay hôm nay'),
                    'prizeValue' => (float) ($room->prize_value ?: 18000000),
                    'prizeImageUrl' => $room->prize_image_url ? (string) $room->prize_image_url : null,
                    'secondaryPrizes' => is_array($room->secondary_prizes ?? null) ? $room->secondary_prizes : [],
                    'status' => $status,
                    'ticketTypeCode' => $roomTicketTypeCode,
                    'acceptAllTicketTypes' => $this->isAllTicketTypesCode($roomTicketTypeCode),
                    'participants' => $participantsByRoom[(string) $room->id] ?? [],
                    'drawResult' => $drawResult,
                    'createdAt' => optional($room->created_at)->toIso8601String(),
                    'scheduledDrawAt' => $scheduledDrawAtIso,
                ];
            };

            return response()->json($roomModels->map($serializeRoom)->values());
        });
    }

    public function rooms(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, min(100, (int) $request->query('page_size', 20)));
        $today = now('Asia/Ho_Chi_Minh')->toDateString();

        $this->ensureTodayRoomsGenerated($today);

        $query = Room::query()
            ->whereDate('room_date', $today)
            ->whereIn(DB::raw('LOWER(status)'), ['waiting', 'filling'])
            ->with('banners')
            ->orderBy('room_number');

        $total = (clone $query)->count();
        $rooms = $query
            ->forPage($page, $pageSize)
            ->get();

        $totalPages = (int) ceil($total / $pageSize);

        return response()->json([
            'data' => $rooms->map(function (Room $room) {
                $roomTicketTypeCode = $this->resolveRoomTicketTypeCode($room);
                $roomTicketTypeName = $this->resolveRoomTicketTypeName($roomTicketTypeCode);

                return [
                    'id' => (string) $room->id,
                    'room_code' => (string) ($room->room_code ?? ''),
                    'room_number' => (int) ($room->room_number ?? 0),
                    'status' => strtoupper((string) ($room->status ?? 'WAITING')),
                    'current_votes' => (int) ($room->current_votes ?? 0),
                    'required_votes' => max(1, (int) ($room->required_votes ?? 1000)),
                    'ticket_type_code' => $roomTicketTypeCode,
                    'ticket_type_name' => $roomTicketTypeName,
                    'accept_all_ticket_types' => $this->isAllTicketTypesCode($roomTicketTypeCode),
                    'prize' => [
                        'type' => (string) ($room->prize_type ?? ''),
                        'value' => $room->prize_value !== null ? (float) $room->prize_value : null,
                        'description' => $this->normalizePrizeText($room->prize_description ?? ''),
                        'description_html' => (string) ($room->prize_description ?? ''),
                        'image_url' => $room->prize_image_url ? (string) $room->prize_image_url : null,
                    ],
                    'banner_images' => collect($room->banners->pluck('image_url')->all())
                        ->map(fn ($image) => trim((string) $image))
                        ->filter(fn ($image) => $image !== '')
                        ->values()
                        ->all(),
                    'open_at' => optional($room->open_at)->toIso8601String(),
                    'close_at' => optional($room->close_at)->toIso8601String(),
                ];
            })->values()->all(),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'filters' => [
                    'statuses' => ['WAITING', 'FILLING'],
                    'room_date' => $today,
                ],
            ],
            'errors' => [],
        ]);
    }

    public function roomDetail(Request $request, string $roomId): JsonResponse
    {
        $today = now('Asia/Ho_Chi_Minh')->toDateString();
        $this->ensureTodayRoomsGenerated($today);

        $room = Room::query()
            ->where(function ($query) use ($roomId) {
                $query->where('id', $roomId)
                    ->orWhere('room_code', $roomId);
            })
            ->with('banners')
            ->first();

        if (!$room) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'room_id' => $roomId,
                ],
                'errors' => [
                    [
                        'code' => 'ROOM_NOT_FOUND_OR_UNAVAILABLE',
                        'message' => 'Phong quay khong ton tai hoac khong con kha dung.',
                    ],
                ],
            ], 404);
        }

        $participantRows = RoomTicket::query()
            ->select('user_id', DB::raw('COUNT(*) as ticket_count'), DB::raw('MIN(joined_at) as first_joined_at'))
            ->where('room_id', $room->id)
            ->where('is_refunded', false)
            ->groupBy('user_id')
            ->orderBy('first_joined_at')
            ->get();

        $users = User::query()
            ->whereIn('id', $participantRows->pluck('user_id')->unique()->all())
            ->get()
            ->keyBy(fn (User $user) => (string) $user->id);

        $participants = $participantRows->map(function ($row) use ($users) {
            $userId = (string) $row->user_id;
            $user = $users->get($userId);

            $displayName = trim((string) ($user?->name ?? $user?->full_name ?? ''));
            if ($displayName === '') {
                $displayName = 'Người chơi ' . substr($userId, 0, 6);
            }

            $avatarUrl = trim((string) ($user?->avatar_url ?? $user?->avatar ?? ''));
            if ($avatarUrl === '') {
                $avatarUrl = 'https://i.pravatar.cc/150?u=' . urlencode($userId);
            }

            return [
                'userId' => $userId,
                'displayName' => $displayName,
                'avatarUrl' => $avatarUrl,
                'ticketCount' => (int) $row->ticket_count,
                'joinedAt' => optional($row->first_joined_at)->toIso8601String() ?: now()->toIso8601String(),
            ];
        })->values()->all();

        $secondaryPrizes = $this->normalizeSecondaryPrizes($room->secondary_prizes ?? null);
        $allPrizes = $this->buildAllRoomPrizes($room, $secondaryPrizes);
        $status = strtolower((string) $room->status);
        if (!in_array($status, ['waiting', 'filling', 'full', 'drawing', 'completed', 'cancelled'], true)) {
            $status = 'waiting';
        }
        if (in_array($status, ['drawing', 'completed', 'cancelled'], true)) {
            // preserve terminal status
        } elseif ((int) ($room->current_votes ?? 0) >= max(1, (int) ($room->required_votes ?? 1000))) {
            $status = 'full';
        } elseif ((int) ($room->current_votes ?? 0) > 0) {
            $status = 'filling';
        }

        $winnerTicketId = $room->winner_ticket_id ? (string) $room->winner_ticket_id : null;
        $winnerTicketCode = $winnerTicketId
            ? (string) Ticket::query()->whereKey($winnerTicketId)->value('ticket_code') ?? $winnerTicketId
            : null;

        $drawResult = null;
        if ($status === 'completed' && $room->winner_id && $winnerTicketId) {
            $winnerName = trim((string) ($room->winner_name ?? ''));
            if ($winnerName === '') {
                $winnerName = 'Người chơi ' . substr((string) $room->winner_id, 0, 6);
            }

            $drawResult = [
                'roomId' => (string) $room->id,
                'winnerId' => (string) $room->winner_id,
                'winnerName' => $winnerName,
                'winnerTicketCode' => $winnerTicketCode,
                'drawnAt' => optional($room->draw_completed_at)->toIso8601String(),
                'prizeDescription' => $this->normalizePrizeText($room->prize_description ?: 'Phòng quay hôm nay'),
                'prizeDescriptionHtml' => (string) ($room->prize_description ?: 'Phòng quay hôm nay'),
                'prizeImageUrl' => $room->prize_image_url ? (string) $room->prize_image_url : null,
            ];
        }

        return response()->json([
            'data' => [
                'id' => (string) $room->id,
                'room_code' => (string) ($room->room_code ?? ''),
                'room_number' => (int) ($room->room_number ?? 0),
                'room_date' => optional($room->room_date)->format('Y-m-d'),
                'status' => strtoupper($status),
                'current_votes' => (int) ($room->current_votes ?? 0),
                'required_votes' => max(1, (int) ($room->required_votes ?? 1000)),
                'ticket_type_code' => $this->resolveRoomTicketTypeCode($room),
                'ticket_type_name' => $this->resolveRoomTicketTypeName($this->resolveRoomTicketTypeCode($room)),
                'accept_all_ticket_types' => $this->isAllTicketTypesCode($this->resolveRoomTicketTypeCode($room)),
                'banner_images' => collect($room->banners->pluck('image_url')->all())
                    ->map(fn ($image) => trim((string) $image))
                    ->filter(fn ($image) => $image !== '')
                    ->values()
                    ->all(),
                'all_prizes' => $allPrizes,
                'primary_prize' => $allPrizes[0] ?? null,
                'secondary_prizes' => $secondaryPrizes,
                'participants' => $participants,
                'drawResult' => $drawResult,
                'open_at' => optional($room->open_at)->toIso8601String(),
                'close_at' => optional($room->close_at)->toIso8601String(),
                'scheduledDrawAt' => optional($room->draw_started_at ?? $room->created_at)
                    ->setTimezone('Asia/Ho_Chi_Minh')
                    ->toIso8601String(),
            ],
            'meta' => [
                'total_prizes' => count($allPrizes),
                'statuses' => ['WAITING', 'FILLING', 'FULL', 'DRAWING', 'COMPLETED', 'CANCELLED'],
            ],
            'errors' => [],
        ]);
    }

    public function streamTodayDrawRooms(Request $request)
    {
        $sinceVersion = max(0, (int) $request->query('since', 0));

        return response()->stream(function () use ($sinceVersion) {
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', '0');

            if (function_exists('apache_setenv')) {
                @apache_setenv('no-gzip', '1');
            }

            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            echo "retry: 1200\n\n";
            @flush();

            $lastVersion = $sinceVersion;
            $startedAt = time();
            $lastPingAt = 0;

            while (! connection_aborted() && (time() - $startedAt) < 20) {
                $currentVersion = (int) Cache::get('draw_rooms_realtime_version', 0);

                if ($currentVersion > $lastVersion) {
                    $payload = Cache::get('draw_rooms_realtime_last_payload');

                    if (is_array($payload)) {
                        $payload['version'] = $currentVersion;

                        echo "event: room:update_votes\n";
                        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
                        @flush();
                    }

                    $lastVersion = $currentVersion;
                }

                if ((time() - $lastPingAt) >= 8) {
                    echo ": ping\n\n";
                    @flush();
                    $lastPingAt = time();
                }

                usleep(250000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function authenticatedUser(Request $request)
    {
        return $request->user();
    }

    private function ensureTodayRoomsGenerated(string $today): void
    {
        $schedule = RoomSchedule::getOrCreateDailyConfig();
        $roomsPerDay = max(1, (int) ($schedule->rooms_per_day ?? RoomSchedule::DEFAULT_ROOMS_PER_DAY));
        $requiredVotes = max(1, (int) ($schedule->required_votes ?? RoomSchedule::DEFAULT_REQUIRED_VOTES));
        $defaultPrizeType = (string) ($schedule->default_prize_type ?: RoomSchedule::DEFAULT_PRIZE_TYPE);
        $defaultPrizeValue = $schedule->default_prize_value !== null ? (float) $schedule->default_prize_value : 25000000;
        $defaultPrizeDescription = (string) ($schedule->default_prize_description ?: 'Giải thưởng xe điện Ecoluck');
        $defaultSecondaryPrizes = is_array($schedule->secondary_prizes ?? null) ? $schedule->secondary_prizes : [];
        $hasSecondaryPrizesColumn = Schema::hasColumn('rooms', 'secondary_prizes');
        $defaultRoomTicketTypeCode = $this->resolveDefaultRoomTicketTypeCode();

        if ($this->generateRoomsFromSelectedTemplateSetIfPossible($schedule, $today)) {
            return;
        }

        for ($roomNumber = 1; $roomNumber <= $roomsPerDay; $roomNumber++) {
            $roomDefaults = [
                'room_code' => Room::makeRoomCode($today, $roomNumber),
                'status' => 'WAITING',
                'current_votes' => 0,
                'required_votes' => $requiredVotes,
                'prize_type' => $defaultPrizeType,
                'prize_value' => $defaultPrizeValue,
                'prize_description' => $defaultPrizeDescription,
            ];

            if (Schema::hasColumn('rooms', 'ticket_type_code')) {
                $roomDefaults['ticket_type_code'] = $defaultRoomTicketTypeCode;
            }

            if ($hasSecondaryPrizesColumn) {
                $roomDefaults['secondary_prizes'] = !empty($defaultSecondaryPrizes)
                    ? json_encode($defaultSecondaryPrizes, JSON_UNESCAPED_UNICODE)
                    : null;
            }

            Room::withTrashed()->firstOrCreate(
                [
                    'room_date' => $today,
                    'room_number' => $roomNumber,
                ],
                $roomDefaults
            );
        }
    }

    private function generateRoomsFromSelectedTemplateSetIfPossible(RoomSchedule $schedule, string $targetDate): bool
    {
        $templateSetId = trim((string) ($schedule->selected_template_set_id ?? ''));
        if ($templateSetId === '') {
            return false;
        }

        if (!Schema::hasTable('room_template_sets') || !Schema::hasTable('room_templates')) {
            return false;
        }

        $roomsPerDay = max(1, (int) ($schedule->rooms_per_day ?? RoomSchedule::DEFAULT_ROOMS_PER_DAY));
        $hasExistingRooms = Room::withTrashed()
            ->whereDate('room_date', $targetDate)
            ->whereBetween('room_number', [1, $roomsPerDay])
            ->exists();

        if ($hasExistingRooms) {
            return true;
        }

        $templateSet = RoomTemplateSet::query()->find($templateSetId);
        if (!$templateSet) {
            return false;
        }

        try {
            app(RoomTemplateService::class)->createRoomsFromTemplateSet($templateSet, $targetDate, $schedule);
            Cache::forget('draw_rooms_today_payload');

            return true;
        } catch (\Throwable $e) {
            Log::warning('draw_rooms_template_generation_failed', [
                'template_set_id' => $templateSetId,
                'target_date' => $targetDate,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    private function resolveDefaultRoomTicketTypeCode(): string
    {
        $defaultCode = strtoupper(trim((string) app(TicketPricingService::class)->getDefaultTicketTypeCode()));

        return $defaultCode !== '' ? $defaultCode : 'ALL_TYPES';
    }

    private function resolveRoomTicketTypeCode(Room $room): ?string
    {
        if (!Schema::hasColumn('rooms', 'ticket_type_code')) {
            return null;
        }

        $rawCode = trim((string) ($room->ticket_type_code ?? ''));
        $code = strtoupper($rawCode);

        if ($code === '') {
            return null;
        }

        // First, try to resolve as code
        $ticketPricingService = app(TicketPricingService::class);
        $resolvedType = $ticketPricingService->resolveTicketType($code);
        if ($resolvedType && strtoupper((string) $resolvedType['code']) === $code) {
            return $code;
        }

        // If not a valid code, try to find by name
        $types = $ticketPricingService->getTicketTypes();
        foreach ($types as $type) {
            if (strtoupper(trim((string) ($type['name'] ?? ''))) === $code) {
                return strtoupper((string) $type['code']);
            }
        }

        // If still not found, return the raw code as fallback
        return $code;
    }

    private function isAllTicketTypesCode(?string $code): bool
    {
        if ($code === null) {
            return false;
        }

        return in_array($code, ['ALL', 'ALL_TYPES', 'ALL_TICKETS', 'TAT_CA', '*'], true);
    }

    private function resolveRoomTicketTypeName(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        if ($this->isAllTicketTypesCode($code)) {
            return 'Tất cả loại vé';
        }

        $ticketPricingService = app(TicketPricingService::class);
        $ticketType = $ticketPricingService->resolveTicketType($code);

        return $ticketType ? (string) $ticketType['name'] : null;
    }

    private function resolveRoomBoundaryAtLocalTimezone(Room $room, string $column): ?Carbon
    {
        $raw = trim((string) $room->getRawOriginal($column));

        if ($raw !== '') {
            try {
                return Carbon::createFromFormat('Y-m-d H:i:s', $raw, 'Asia/Ho_Chi_Minh');
            } catch (\Throwable) {
                try {
                    return Carbon::parse($raw, 'Asia/Ho_Chi_Minh');
                } catch (\Throwable) {
                    // Continue with casted value.
                }
            }
        }

        $value = $room->getAttribute($column);
        if ($value instanceof Carbon) {
            return $value->copy()->timezone('Asia/Ho_Chi_Minh');
        }

        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value, 'Asia/Ho_Chi_Minh');
    }

    private function resolveTicketTypeCodeForJoinValidation(Ticket $ticket, TicketPricingService $ticketPricingService): ?string
    {
        $rawCode = strtoupper(trim((string) ($ticket->ticket_type_code ?? '')));
        if ($rawCode !== '') {
            return $rawCode;
        }

        $resolvedType = $ticketPricingService->resolveTicketType(null);
        $resolvedCode = strtoupper(trim((string) ($resolvedType['code'] ?? '')));

        return $resolvedCode !== '' ? $resolvedCode : null;
    }

    private function normalizeSecondaryPrizes(mixed $raw): array
    {
        $items = [];

        if (is_array($raw)) {
            $items = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $items = $decoded;
            }
        }

        return collect($items)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item): array {
                return [
                    'type' => (string) ($item['type'] ?? ''),
                    'id' => isset($item['id']) ? (string) $item['id'] : null,
                    'name' => $this->normalizePrizeText($item['name'] ?? $item['label'] ?? ''),
                    'description' => $this->normalizePrizeText($item['description'] ?? ''),
                    'description_html' => (string) ($item['description'] ?? ''),
                    'image_url' => !empty($item['image_url']) ? (string) $item['image_url'] : (!empty($item['image']) ? (string) $item['image'] : null),
                    'qty' => max(0, (int) ($item['qty'] ?? $item['quantity'] ?? 0)),
                    'value' => isset($item['value']) && $item['value'] !== '' ? (float) $item['value'] : null,
                ];
            })
            ->values()
            ->all();
    }

    private function buildAllRoomPrizes(Room $room, array $secondaryPrizes): array
    {
        $primaryPrize = [
            'is_primary' => true,
            'type' => (string) ($room->prize_type ?? ''),
            'id' => null,
            'name' => $this->normalizePrizeText($room->prize_description ?? ''),
            'description' => $this->normalizePrizeText($room->prize_description ?? ''),
            'description_html' => (string) ($room->prize_description ?? ''),
            'image_url' => $room->prize_image_url ? (string) $room->prize_image_url : null,
            'qty' => 1,
            'value' => $room->prize_value !== null ? (float) $room->prize_value : null,
        ];

        $secondary = collect($secondaryPrizes)
            ->map(function (array $prize): array {
                $prize['is_primary'] = false;
                return $prize;
            })
            ->values()
            ->all();

        return array_merge([$primaryPrize], $secondary);
    }

    private function normalizePrizeText(mixed $value): string
    {
        $text = html_entity_decode(trim((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text ?? '') ?? '';

        return trim($text);
    }

    private function mapLedgerActionToUiAction(string $actionType, int $amountChange): string
    {
        $type = strtoupper($actionType);

        if ($type === 'GIFT_SEND') {
            return 'gifted';
        }

        if ($type === 'GIFT_RECEIVE') {
            return 'received';
        }

        if ($type === 'PURCHASE' || $type === 'ISSUED') {
            return 'issued';
        }

        if ($type === 'LOCK' || $type === 'LOCKED') {
            return 'locked';
        }

        if ($type === 'RELEASE' || $type === 'RELEASED' || $type === 'UNLOCK' || $type === 'UNLOCKED') {
            return 'released';
        }

        if ($type === 'REVOKED' || $type === 'CANCELLED') {
            return 'used';
        }

        if ($type === 'EXPIRED' || $type === 'EXPIRY') {
            return 'expired';
        }

        if ($type === 'REFUND' || $type === 'REFUNDED') {
            return 'refunded';
        }

        if ($type === 'ROOM_WITHDRAW' || $type === 'ROOM_RELEASE') {
            return 'refunded';
        }

        if ($type === 'ORDER_USE' || $type === 'ROOM_USE' || $type === 'USED') {
            return 'used';
        }

        return $amountChange >= 0 ? 'issued' : 'used';
    }

    public function publishDrawRoomRealtimeUpdate(array $serializedRoom): void
    {
        $payload = [
            'event' => 'room:update_votes',
            'updatedAt' => now()->toIso8601String(),
            'room' => $serializedRoom,
        ];

        Cache::forever('draw_rooms_realtime_last_payload', $payload);

        $currentVersion = (int) Cache::get('draw_rooms_realtime_version', 0);
        Cache::forever('draw_rooms_realtime_version', $currentVersion + 1);
    }

    private function buildDrawSoonPayload(Room $room, array $serializedRoom, \DateTimeInterface $scheduledDrawAt): array
    {
        $participantUserIds = collect($serializedRoom['participants'] ?? [])
            ->map(fn ($participant) => isset($participant['userId']) ? (string) $participant['userId'] : null)
            ->filter(fn (?string $userId) => $userId !== null && $userId !== '')
            ->unique()
            ->values()
            ->all();

        $roomName = (string) ($serializedRoom['name'] ?? ('Phòng ' . ((string) ($room->room_number ?? $room->id))));

        return [
            'roomId' => (string) $room->id,
            'roomCode' => $room->room_code ? (string) $room->room_code : null,
            'roomName' => $roomName,
            'drawAt' => $scheduledDrawAt->format(\DateTimeInterface::ATOM),
            'countdownSeconds' => max(0, now()->diffInSeconds($scheduledDrawAt, false)),
            'participantUserIds' => $participantUserIds,
            'participantCount' => count($participantUserIds),
            'message' => $roomName . ' sẽ bắt đầu quay sau ' . ceil(max(0, now()->diffInSeconds($scheduledDrawAt, false)) / 60) . ' phút.',
        ];
    }

    private function buildLedgerNote(
        string $actionType,
        int $amountChange,
        string $referenceType,
        ?string $referenceId,
        array $metadata,
        array $referenceMaps = []
    ): string {
        $amountText = ($amountChange >= 0 ? '+' : '') . $amountChange . ' vé';
        $type = strtoupper($actionType);
        $refType = strtoupper($referenceType);
        $orderMap = $referenceMaps['orders'] ?? [];
        $batchMap = $referenceMaps['batches'] ?? [];
        $roomMap = $referenceMaps['rooms'] ?? [];

        $metadataOrderCode = isset($metadata['order_code']) ? (string) $metadata['order_code'] : null;
        $metadataBatchCode = isset($metadata['batch_code']) ? (string) $metadata['batch_code'] : null;
        $metadataRoomName = isset($metadata['room_name']) ? (string) $metadata['room_name'] : null;
        $metadataRoomCode = isset($metadata['room_code']) ? (string) $metadata['room_code'] : null;
        $metadataRoomId = isset($metadata['room_id']) ? (string) $metadata['room_id'] : null;
        $metadataSenderName = isset($metadata['sender_name']) ? (string) $metadata['sender_name'] : null;

        if ($type === 'PURCHASE' || $refType === 'TICKET_BATCH') {
            $batchLabel = $metadataBatchCode
                ?? ($referenceId ? ($batchMap[$referenceId] ?? null) : null)
                ?? $referenceId;
            if ($batchLabel) {
                return sprintf('%s từ giao dịch mua vé #%s', $amountText, $batchLabel);
            }
            return sprintf('%s từ giao dịch mua vé', $amountText);
        }

        if ($type === 'ORDER_USE' || $refType === 'ORDER') {
            $orderLabel = $metadataOrderCode
                ?? ($referenceId ? ($orderMap[$referenceId] ?? null) : null)
                ?? $referenceId;
            if ($orderLabel) {
                return sprintf('%s từ đơn #%s', $amountText, $orderLabel);
            }
            return sprintf('%s từ đơn hàng', $amountText);
        }

        if ($type === 'LOCK' || $type === 'LOCKED') {
            if ($refType === 'ORDER_RETURN') {
                $orderLabel = $metadataOrderCode
                    ?? ($referenceId ? ($orderMap[$referenceId] ?? null) : null)
                    ?? $referenceId;
                if ($orderLabel) {
                    return sprintf('%s tạm khóa cho yêu cầu hoàn hàng đơn #%s', $amountText, $orderLabel);
                }
                return sprintf('%s tạm khóa cho yêu cầu hoàn hàng', $amountText);
            }
            if ($refType === 'COMPLAINT') {
                $orderLabel = $metadataOrderCode
                    ?? ($referenceId ? ($orderMap[$referenceId] ?? null) : null)
                    ?? $referenceId;
                if ($orderLabel) {
                    return sprintf('%s tạm khóa cho yêu cầu khiếu nại đơn #%s', $amountText, $orderLabel);
                }
                return sprintf('%s tạm khóa cho yêu cầu khiếu nại', $amountText);
            }
            return sprintf('%s tạm khóa', $amountText);
        }

        if ($type === 'RELEASE' || $type === 'RELEASED' || $type === 'UNLOCK' || $type === 'UNLOCKED') {
            if ($refType === 'ORDER_RETURN') {
                $orderLabel = $metadataOrderCode
                    ?? ($referenceId ? ($orderMap[$referenceId] ?? null) : null)
                    ?? $referenceId;
                if ($orderLabel) {
                    return sprintf('%s mở khóa từ yêu cầu hoàn hàng đơn #%s', $amountText, $orderLabel);
                }
                return sprintf('%s mở khóa từ yêu cầu hoàn hàng', $amountText);
            }
            if ($refType === 'COMPLAINT') {
                $orderLabel = $metadataOrderCode
                    ?? ($referenceId ? ($orderMap[$referenceId] ?? null) : null)
                    ?? $referenceId;
                if ($orderLabel) {
                    return sprintf('%s mở khóa từ yêu cầu khiếu nại đơn #%s', $amountText, $orderLabel);
                }
                return sprintf('%s mở khóa từ yêu cầu khiếu nại', $amountText);
            }
            return sprintf('%s mở khóa', $amountText);
        }

        if ($type === 'REVOKED' || $type === 'CANCELLED') {
            if ($refType === 'ORDER_RETURN') {
                $orderLabel = $metadataOrderCode
                    ?? ($referenceId ? ($orderMap[$referenceId] ?? null) : null)
                    ?? $referenceId;
                if ($orderLabel) {
                    return sprintf('%s thu hồi do hoàn trả đơn #%s', $amountText, $orderLabel);
                }
                return sprintf('%s thu hồi do hoàn trả', $amountText);
            }
            if ($refType === 'COMPLAINT') {
                $orderLabel = $metadataOrderCode
                    ?? ($referenceId ? ($orderMap[$referenceId] ?? null) : null)
                    ?? $referenceId;
                if ($orderLabel) {
                    return sprintf('%s thu hồi do khiếu nại đơn #%s', $amountText, $orderLabel);
                }
                return sprintf('%s thu hồi do khiếu nại', $amountText);
            }
            return sprintf('%s thu hồi', $amountText);
        }

        if ($type === 'REFUND' || $type === 'REFUNDED') {
            if ($refType === 'ORDER_RETURN') {
                $orderLabel = $metadataOrderCode
                    ?? ($referenceId ? ($orderMap[$referenceId] ?? null) : null)
                    ?? $referenceId;
                if ($orderLabel) {
                    return sprintf('%s hoàn trả đơn #%s', $amountText, $orderLabel);
                }
                return sprintf('%s hoàn trả đơn hàng', $amountText);
            }
            return sprintf('%s hoàn vé', $amountText);
        }

        if ($type === 'ROOM_WITHDRAW' || $type === 'ROOM_RELEASE') {
            $roomLookupId = $metadataRoomId ?? $referenceId;
            $roomName = $metadataRoomName
                ?? $metadataRoomCode
                ?? ($roomLookupId ? ($roomMap[$roomLookupId] ?? null) : null)
                ?? $referenceId;
            if ($roomName) {
                return sprintf('%s rút khỏi phòng %s', $amountText, $roomName);
            }
            return sprintf('%s rút vé khỏi phòng quay', $amountText);
        }

        if ($type === 'ROOM_USE' || (($refType === 'ROOM' || $refType === 'DRAW_ROOM') && $amountChange < 0)) {
            $roomLookupId = $metadataRoomId ?? $referenceId;
            $roomName = $metadataRoomName
                ?? $metadataRoomCode
                ?? ($roomLookupId ? ($roomMap[$roomLookupId] ?? null) : null)
                ?? $referenceId;
            if ($roomName) {
                return sprintf('%s tham gia phòng %s', $amountText, $roomName);
            }
            return sprintf('%s tham gia phòng quay', $amountText);
        }

        if ($type === 'GIFT_SEND') {
            $receiverName = isset($metadata['receiver_name']) ? (string) $metadata['receiver_name'] : null;

            if ($receiverName !== null && trim($receiverName) !== '') {
                return sprintf('%s tặng vé cho %s', $amountText, $receiverName);
            }

            return sprintf('%s tặng vé', $amountText);
        }

        if ($type === 'GIFT_RECEIVE') {
            $senderName = $metadataSenderName;

            if ($senderName !== null && trim($senderName) !== '') {
                return sprintf('%s nhận vé từ %s', $amountText, $senderName);
            }

            return sprintf('%s nhận vé tặng', $amountText);
        }

        return sprintf('%s cập nhật số dư vé', $amountText);
    }

    private function buildLedgerReferenceMaps($entries): array
    {
        $ordersTableHasOrderCode = Schema::hasColumn('orders', 'order_code');
        $ticketBatchesHasBatchCode = Schema::hasColumn('ticket_batches', 'batch_code');
        $roomsHasRoomCode = Schema::hasColumn('rooms', 'room_code');
        $roomsHasRoomNumber = Schema::hasColumn('rooms', 'room_number');

        $orderIds = [];
        $batchIds = [];
        $roomIds = [];

        foreach ($entries as $entry) {
            $referenceType = strtoupper((string) ($entry->reference_type ?? ''));
            $referenceId = $entry->reference_id ? (string) $entry->reference_id : null;
            $metadata = is_array($entry->metadata) ? $entry->metadata : [];

            if ($referenceType === 'ORDER' && $referenceId) {
                $orderIds[] = $referenceId;
            }

            if ($referenceType === 'TICKET_BATCH' && $referenceId) {
                $batchIds[] = $referenceId;
            }

            if (($referenceType === 'ROOM' || $referenceType === 'DRAW_ROOM') && $referenceId) {
                $roomIds[] = $referenceId;
            }

            if (isset($metadata['order_id']) && $metadata['order_id']) {
                $orderIds[] = (string) $metadata['order_id'];
            }

            if (isset($metadata['batch_id']) && $metadata['batch_id']) {
                $batchIds[] = (string) $metadata['batch_id'];
            }

            if (isset($metadata['room_id']) && $metadata['room_id']) {
                $roomIds[] = (string) $metadata['room_id'];
            }
        }

        $orderIds = array_values(array_unique($orderIds));
        $batchIds = array_values(array_unique($batchIds));
        $roomIds = array_values(array_unique($roomIds));

        $orders = [];
        if (!empty($orderIds)) {
            $orderColumns = ['id'];
            if ($ordersTableHasOrderCode) {
                $orderColumns[] = 'order_code';
            }

            $orders = Order::query()
                ->whereIn('id', $orderIds)
                ->get($orderColumns)
                ->mapWithKeys(function (Order $order) use ($ordersTableHasOrderCode) {
                    $label = $ordersTableHasOrderCode && isset($order->order_code) && $order->order_code
                        ? (string) $order->order_code
                        : (string) $order->id;
                    return [(string) $order->id => $label];
                })
                ->toArray();
        }

        $batches = [];
        if (!empty($batchIds)) {
            $batchColumns = ['id'];
            if ($ticketBatchesHasBatchCode) {
                $batchColumns[] = 'batch_code';
            }

            $batches = TicketBatch::query()
                ->whereIn('id', $batchIds)
                ->get($batchColumns)
                ->mapWithKeys(function (TicketBatch $batch) use ($ticketBatchesHasBatchCode) {
                    $label = $ticketBatchesHasBatchCode && isset($batch->batch_code) && $batch->batch_code
                        ? (string) $batch->batch_code
                        : (string) $batch->id;
                    return [(string) $batch->id => $label];
                })
                ->toArray();
        }

        $rooms = [];
        if (!empty($roomIds)) {
            $roomColumns = ['id'];
            if ($roomsHasRoomCode) {
                $roomColumns[] = 'room_code';
            }
            if ($roomsHasRoomNumber) {
                $roomColumns[] = 'room_number';
            }

            $rooms = Room::query()
                ->whereIn('id', $roomIds)
                ->get($roomColumns)
                ->mapWithKeys(function (Room $room) use ($roomsHasRoomCode, $roomsHasRoomNumber) {
                    $roomCode = $roomsHasRoomCode && isset($room->room_code) && $room->room_code ? (string) $room->room_code : '';
                    $roomNumber = $roomsHasRoomNumber && isset($room->room_number) ? (string) $room->room_number : '';
                    $roomLabel = $roomCode !== ''
                        ? $roomCode
                        : ('Phòng ' . str_pad($roomNumber, 2, '0', STR_PAD_LEFT));
                    return [(string) $room->id => $roomLabel];
                })
                ->toArray();
        }

        return [
            'orders' => $orders,
            'batches' => $batches,
            'rooms' => $rooms,
        ];
    }

    public function persistRoomDrawResult(Room $room): array
    {
        $status = strtoupper((string) $room->status);
        if (in_array($status, ['DRAWING', 'COMPLETED', 'CANCELLED'], true)) {
            return [$room->fresh(), null];
        }

        $requiredVotes = max(1, (int) ($room->required_votes ?: 1000));
        $actualVotes = (int) RoomTicket::query()
            ->where('room_id', $room->id)
            ->where('is_refunded', false)
            ->count();

        if ($actualVotes < $requiredVotes) {
            return [$room->fresh(), null];
        }

        $startedAt = now();
        $room->update([
            'status' => 'DRAWING',
            'current_votes' => $actualVotes,
            'draw_started_at' => $startedAt,
        ]);

        $winnerTicketRow = RoomTicket::query()
            ->where('room_id', $room->id)
            ->where('is_refunded', false)
            ->inRandomOrder()
            ->first();

        if (! $winnerTicketRow) {
            throw new \RuntimeException('Không tìm thấy vé tham gia để quay thưởng.');
        }

        $winnerUser = User::query()->whereKey($winnerTicketRow->user_id)->first();
        $winnerName = trim((string) ($winnerUser?->full_name ?? $winnerUser?->name ?? ''));
        if ($winnerName === '') {
            $winnerName = 'User #' . (string) $winnerTicketRow->user_id;
        }

        $winnerTicketCode = (string) $winnerTicketRow->ticket_id;
        $ticketCode = Ticket::query()->whereKey($winnerTicketRow->ticket_id)->value('ticket_code');
        if (is_string($ticketCode) && trim($ticketCode) !== '') {
            $winnerTicketCode = trim($ticketCode);
        }

        $seed = bin2hex(random_bytes(16));
        $blockHash = hash('sha256', $seed . '|' . $room->id . '|' . microtime(true));
        $txHash = '0x' . hash('sha256', $blockHash . '|auto_draw|' . $winnerTicketRow->ticket_id);
        $completedAt = now();

        $room->update([
            'status' => 'COMPLETED',
            'winner_id' => $winnerTicketRow->user_id,
            'winner_ticket_id' => $winnerTicketRow->ticket_id,
            'winner_name' => $winnerName,
            'draw_seed' => $seed,
            'block_hash' => $blockHash,
            'tx_hash' => $txHash,
            'draw_completed_at' => $completedAt,
        ]);

        RoomTicket::query()
            ->where('room_id', $room->id)
            ->update(['is_winner' => false]);

        RoomTicket::query()
            ->whereKey($winnerTicketRow->id)
            ->update(['is_winner' => true]);

        DB::table('draws')->insert([
            'id' => (string) Str::uuid(),
            'room_id' => $room->id,
            'draw_code' => 'DRAW-' . $startedAt->format('YmdHis') . '-' . strtoupper(Str::random(8)),
            'status' => 'completed',
            'random_seed' => $seed,
            'algorithm' => 'seed_hash',
            'block_hash' => $blockHash,
            'block_number' => null,
            'block_timestamp' => $completedAt,
            'winner_ticket_id' => $winnerTicketRow->ticket_id,
            'winner_user_id' => $winnerTicketRow->user_id,
            'winner_index' => null,
            'tx_hash' => $txHash,
            'tx_status' => 'confirmed',
            'tx_gas_fee' => 0,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'created_at' => now(),
        ]);

        $notificationPayload = [
            'message' => 'Chúc mừng ' . $winnerName . ' đã trúng thưởng ở phòng ' . $room->room_code . '.',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('notifications', 'order_id')) {
            $notificationPayload['order_id'] = null;
        }

        if (Schema::hasColumn('notifications', 'title')) {
            $notificationPayload['title'] = 'Bạn vừa trúng thưởng';
        }

        if (Schema::hasColumn('notifications', 'type')) {
            $notificationPayload['type'] = 'draw';
        }

        if (Schema::hasColumn('notifications', 'user_id')) {
            $notificationPayload['user_id'] = (string) $winnerTicketRow->user_id;
        }

        if (Schema::hasColumn('notifications', 'audience_scope')) {
            $notificationPayload['audience_scope'] = Schema::hasColumn('notifications', 'user_id')
                ? 'user_private'
                : 'admin_shared';
        }

        Notification::query()->create($notificationPayload);

        return [
            $room->fresh(),
            [
                'room_id' => (string) $room->id,
                'room_code' => (string) ($room->room_code ?? ''),
                'status' => 'COMPLETED',
                'winner_id' => (string) $winnerTicketRow->user_id,
                'winner_ticket_id' => (string) $winnerTicketRow->ticket_id,
                'winner_ticket_code' => $winnerTicketCode,
                'winner_name' => $winnerName,
                'draw_completed_at' => $completedAt->toDateTimeString(),
                'tx_hash' => $txHash,
                'block_hash' => $blockHash,
                'random_seed' => $seed,
            ],
        ];
    }

    public function serializeDrawRoomPayload(Room $room): array
    {
        $participantRows = RoomTicket::query()
            ->select('user_id', DB::raw('COUNT(*) as ticket_count'), DB::raw('MIN(joined_at) as first_joined_at'))
            ->where('room_id', $room->id)
            ->where('is_refunded', false)
            ->groupBy('user_id')
            ->orderBy('first_joined_at')
            ->get();

        $users = User::query()
            ->whereIn('id', $participantRows->pluck('user_id')->unique()->all())
            ->get()
            ->keyBy(fn (User $user) => (string) $user->id);

        $participants = $participantRows->map(function ($row) use ($users) {
            $userId = (string) $row->user_id;
            $user = $users->get($userId);

            $displayName = trim((string) ($user?->name ?? $user?->full_name ?? ''));
            if ($displayName === '') {
                $displayName = 'Người chơi ' . substr($userId, 0, 6);
            }

            $avatarUrl = trim((string) ($user?->avatar_url ?? $user?->avatar ?? ''));
            if ($avatarUrl === '') {
                $avatarUrl = 'https://i.pravatar.cc/150?u=' . urlencode($userId);
            }

            return [
                'userId' => $userId,
                'displayName' => $displayName,
                'avatarUrl' => $avatarUrl,
                'ticketCount' => (int) $row->ticket_count,
                'joinedAt' => optional($row->first_joined_at)->toIso8601String() ?: now()->toIso8601String(),
            ];
        })->values()->all();

        $status = strtolower((string) $room->status);
        if (! in_array($status, ['waiting', 'filling', 'full', 'drawing', 'completed', 'cancelled'], true)) {
            $status = ((int) $room->current_votes) > 0 ? 'filling' : 'waiting';
        }

        $winnerTicketCode = null;
        if ($room->winner_ticket_id) {
            $winnerTicketId = (string) $room->winner_ticket_id;
            $ticketCode = Ticket::query()->whereKey($winnerTicketId)->value('ticket_code');
            $winnerTicketCode = is_string($ticketCode) && trim($ticketCode) !== ''
                ? trim($ticketCode)
                : $winnerTicketId;
        }

        $drawResult = null;
        if ($status === 'completed' && $room->winner_id && $room->winner_ticket_id) {
            $winnerName = trim((string) ($room->winner_name ?? ''));
            if ($winnerName === '') {
                $winnerName = 'Người chơi ' . substr((string) $room->winner_id, 0, 6);
            }

            $drawResult = [
                'roomId' => (string) $room->id,
                'winnerId' => (string) $room->winner_id,
                'winnerName' => $winnerName,
                'winnerTicketCode' => $winnerTicketCode,
                'drawnAt' => optional($room->draw_completed_at)->toIso8601String(),
                'prizeDescription' => (string) ($room->prize_description ?: 'Phòng quay hôm nay'),
                'prizeImageUrl' => $room->prize_image_url ? (string) $room->prize_image_url : null,
            ];
        }

        $roomTicketTypeCode = $this->resolveRoomTicketTypeCode($room);
        $roomTicketTypeName = $this->resolveRoomTicketTypeName($roomTicketTypeCode);

        return [
            'id' => (string) $room->id,
            'name' => sprintf('Phòng %02d hôm nay', (int) $room->room_number),
            'description' => 'Phòng quay hôm nay, mỗi phòng cần 1000 vé để mở kết quả.',
            'maxTickets' => max(1, (int) ($room->required_votes ?: 1000)),
            'currentTickets' => (int) $room->current_votes,
            'prizeDescription' => (string) ($room->prize_description ?: 'Phòng quay hôm nay'),
            'prizeValue' => (float) ($room->prize_value ?: 18000000),
            'status' => $status,
            'ticketTypeCode' => $roomTicketTypeCode,
            'ticketTypeName' => $roomTicketTypeName,
            'acceptAllTicketTypes' => $this->isAllTicketTypesCode($roomTicketTypeCode),
            'participants' => $participants,
            'drawResult' => $drawResult,
            'createdAt' => optional($room->created_at)->toIso8601String(),
            'scheduledDrawAt' => optional($room->draw_started_at ?? $room->created_at)
                ->setTimezone('Asia/Ho_Chi_Minh')
                ->toIso8601String(),
        ];
    }

    public function prizeHistory(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $rooms = \App\Models\Room::query()
            ->from('rooms')
            ->leftJoin('tickets', 'tickets.id', '=', 'rooms.winner_ticket_id')
            ->where('rooms.status', 'COMPLETED')
            ->where('rooms.winner_id', $userId)
            ->select([
                'rooms.id',
                'rooms.room_code',
                'rooms.room_number',
                'rooms.room_date',
                'rooms.draw_completed_at',
                'rooms.prize_type',
                'rooms.prize_value',
                'rooms.prize_description',
                'rooms.prize_image_url',
                'rooms.tx_hash',
                'rooms.block_hash',
                'tickets.ticket_code as winner_ticket_code',
            ])
            ->orderByDesc('rooms.draw_completed_at')
            ->get();

        $wins = $rooms->map(function ($room) {
            $roomNumber = (int) $room->room_number;
            $roomName = $roomNumber > 0 ? sprintf('Phòng #%03d', $roomNumber) : 'Phòng quay';
            if ($room->room_code) {
                $roomName .= ' (' . $room->room_code . ')';
            }

            $desc = strtolower((string) ($room->prize_description ?? ''));
            $prizeType = 'other';
            if (str_contains($desc, 'xe') || str_contains($desc, 'car') || str_contains($desc, 'máy') || str_contains($desc, 'motorbike')) {
                $prizeType = 'vehicle';
            } elseif (str_contains($desc, 'voucher') || str_contains($desc, 'code') || str_contains($desc, 'giảm giá')) {
                $prizeType = 'voucher';
            } elseif (str_contains($desc, 'tiền') || str_contains($desc, 'cash') || str_contains($desc, 'vnd')) {
                $prizeType = 'cash';
            } elseif (str_contains($desc, 'sản phẩm') || str_contains($desc, 'product') || str_contains($desc, 'hàng')) {
                $prizeType = 'product';
            }

            $winnerTicketCode = $room->winner_ticket_code;
            if (!$winnerTicketCode && $room->winner_ticket_id) {
                $winnerTicketCode = substr((string) $room->winner_ticket_id, 0, 8);
            }

            return [
                'roomId' => (string) $room->id,
                'roomName' => $roomName,
                'prizeTitle' => (string) ($room->prize_type ?: ''),
                'prizeDescription' => (string) ($room->prize_description ?: 'Giải thưởng trúng thưởng'),
                'prizeValue' => (float) ($room->prize_value ?: 0),
                'prizeType' => $prizeType,
                'prizeImageUrl' => $room->prize_image_url ? (string) $room->prize_image_url : null,
                'drawnAt' => $room->draw_completed_at ? $room->draw_completed_at->toIso8601String() : null,
                'winnerTicketCode' => (string) ($winnerTicketCode ?: ''),
                'txHash' => $room->tx_hash,
                'blockHash' => $room->block_hash,
            ];
        });

        $totalWins = $wins->count();
        $totalValue = $wins->sum('prizeValue');

        $byType = [];
        foreach ($wins as $win) {
            $type = $win['prizeType'];
            if (!isset($byType[$type])) {
                $byType[$type] = ['count' => 0, 'value' => 0];
            }
            $byType[$type]['count']++;
            $byType[$type]['value'] += $win['prizeValue'];
        }

        return response()->json([
            'totalWins' => $totalWins,
            'totalValue' => $totalValue,
            'byType' => (object) $byType,
            'wins' => $wins,
        ]);
    }
} // End of ClientTicketController


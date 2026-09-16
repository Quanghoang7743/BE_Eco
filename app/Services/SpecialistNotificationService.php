<?php

namespace App\Services;

use App\Models\SpecialistNotification;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SpecialistNotificationService
{
    public const TYPE_STORE_ASSIGNED = 'store_assigned';
    public const TYPE_FIRST_ORDER = 'first_order';
    public const TYPE_ABNORMAL_TICKET = 'abnormal_ticket';
    public const TYPE_SETTLEMENT_CREATED = 'settlement_created';
    public const TYPE_INCOME_APPROVED = 'income_approved';
    public const TYPE_INCOME_PAID = 'income_paid';
    public const TYPE_TASK_NEW = 'task_new';
    public const TYPE_RATING_NEW = 'rating_new';
    public const TYPE_CREDIT_CHANGED = 'credit_changed';

    private const SUCCESS_ORDER_STATUSES = ['completed', 'delivered', 'call_completed'];
    private const ABNORMAL_TICKET_STATUSES = ['HELD', 'DISPUTED', 'LOCKED'];
    private const ABNORMAL_TICKET_THRESHOLD = 0.2;
    private const ABNORMAL_TICKET_MIN_TOTAL = 5;
    private const ABNORMAL_TICKET_MIN_HELD = 3;

    public function create(array $attributes): ?SpecialistNotification
    {
        if (! Schema::hasTable('specialist_notifications')) {
            return null;
        }

        $specialistId = (int) ($attributes['specialist_id'] ?? 0);
        $type = trim((string) ($attributes['type'] ?? ''));
        $title = trim((string) ($attributes['title'] ?? ''));
        $message = trim((string) ($attributes['message'] ?? ''));

        if ($specialistId <= 0 || $type === '' || $title === '' || $message === '') {
            return null;
        }

        $payload = [
            'id' => (string) Str::uuid(),
            'specialist_id' => $specialistId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $attributes['data'] ?? null,
            'action_url' => $this->sanitizeInternalUrl($attributes['action_url'] ?? null),
            'event_key' => $this->nullableString($attributes['event_key'] ?? null),
            'is_read' => false,
            'read_at' => null,
        ];

        if ($payload['event_key'] !== null) {
            return SpecialistNotification::query()->firstOrCreate(
                [
                    'specialist_id' => $specialistId,
                    'event_key' => $payload['event_key'],
                ],
                $payload
            );
        }

        return SpecialistNotification::query()->create($payload);
    }

    public function notifyStoreAssigned(Store $store, int $specialistId, ?int $previousSpecialistId = null, ?string $eventSuffix = null): ?SpecialistNotification
    {
        $storeName = trim((string) ($store->name ?? 'Cửa hàng'));
        $storeCode = trim((string) ($store->store_code ?? ''));
        $storeLabel = $storeName . ($storeCode !== '' ? ' (' . $storeCode . ')' : '');

        return $this->create([
            'specialist_id' => $specialistId,
            'type' => self::TYPE_STORE_ASSIGNED,
            'title' => 'Cửa hàng mới được phân công',
            'message' => 'Bạn vừa được phân công phụ trách ' . $storeLabel . '.',
            'data' => [
                'store_id' => (string) $store->id,
                'store_name' => $storeName,
                'store_code' => $storeCode,
                'previous_specialist_id' => $previousSpecialistId,
            ],
            'action_url' => $this->routeUrl('admin.stores.show', ['store' => $store->id]),
            'event_key' => 'store_assigned:' . $specialistId . ':' . ($eventSuffix ?: ((string) $store->id . ':' . now()->format('YmdHis'))),
        ]);
    }

    public function syncDigestForSpecialist(int $specialistId): void
    {
        if ($specialistId <= 0 || ! Schema::hasTable('specialist_notifications')) {
            return;
        }

        Cache::remember('specialist_notifications_sync:' . $specialistId, now()->addMinutes(10), function () use ($specialistId): bool {
            $this->syncForSpecialist($specialistId);

            return true;
        });
    }

    public function syncForSpecialist(int $specialistId): void
    {
        if ($specialistId <= 0 || ! Schema::hasTable('specialist_notifications')) {
            return;
        }

        $assignments = $this->activeAssignmentsForSpecialist($specialistId);

        foreach ($assignments as $assignment) {
            $this->syncStoreAssignmentNotification($assignment);
            $this->syncFirstOrderNotification($assignment);
            $this->syncAbnormalTicketNotification($assignment);
        }

        $this->syncSettlementNotifications($specialistId);
        $this->syncTaskNotifications($specialistId);
        $this->syncRatingNotifications($specialistId);
    }

    public function markAsRead(SpecialistNotification $notification): void
    {
        if ((bool) $notification->is_read) {
            return;
        }

        $notification->forceFill([
            'is_read' => true,
            'read_at' => now(),
        ])->save();
    }

    public function markAsUnread(SpecialistNotification $notification): void
    {
        if (! (bool) $notification->is_read && $notification->read_at === null) {
            return;
        }

        $notification->forceFill([
            'is_read' => false,
            'read_at' => null,
        ])->save();
    }

    public function markAllAsRead(Builder $query): int
    {
        return (clone $query)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function activeAssignmentsForSpecialist(int $specialistId): Collection
    {
        if (
            Schema::hasTable('specialist_store_assignments')
            && Schema::hasColumn('specialist_store_assignments', 'specialist_id')
            && Schema::hasColumn('specialist_store_assignments', 'store_id')
        ) {
            $query = DB::table('specialist_store_assignments as ssa')
                ->leftJoin('stores as s', 's.id', '=', 'ssa.store_id')
                ->where('ssa.specialist_id', $specialistId)
                ->select([
                    'ssa.id as assignment_id',
                    'ssa.specialist_id',
                    'ssa.store_id',
                    'ssa.assigned_from',
                    's.name as store_name',
                    's.store_code',
                ]);

            if (Schema::hasColumn('specialist_store_assignments', 'assigned_to')) {
                $query->where(function ($window): void {
                    $window->whereNull('ssa.assigned_to')
                        ->orWhere('ssa.assigned_to', '>=', now('Asia/Ho_Chi_Minh')->toDateTimeString());
                });
            }

            return $query->get();
        }

        if (! Schema::hasTable('stores') || ! Schema::hasColumn('stores', 'sale_id')) {
            return collect();
        }

        return DB::table('stores')
            ->where('sale_id', $specialistId)
            ->selectRaw('NULL as assignment_id')
            ->selectRaw('? as specialist_id', [$specialistId])
            ->selectRaw('id as store_id')
            ->selectRaw('created_at as assigned_from')
            ->selectRaw('name as store_name')
            ->selectRaw('store_code')
            ->get();
    }

    private function syncStoreAssignmentNotification(object $assignment): void
    {
        $storeName = trim((string) ($assignment->store_name ?? 'Cửa hàng'));
        $storeCode = trim((string) ($assignment->store_code ?? ''));
        $storeLabel = $storeName . ($storeCode !== '' ? ' (' . $storeCode . ')' : '');
        $eventSuffix = $assignment->assignment_id ?: ((string) $assignment->store_id . ':' . (string) $assignment->assigned_from);

        $this->create([
            'specialist_id' => (int) $assignment->specialist_id,
            'type' => self::TYPE_STORE_ASSIGNED,
            'title' => 'Cửa hàng mới được phân công',
            'message' => 'Bạn đang được phân công phụ trách ' . $storeLabel . '.',
            'data' => [
                'store_id' => (string) $assignment->store_id,
                'store_name' => $storeName,
                'store_code' => $storeCode,
                'assigned_from' => $assignment->assigned_from,
            ],
            'action_url' => $this->routeUrl('admin.stores.show', ['store' => $assignment->store_id]),
            'event_key' => 'store_assigned:' . $assignment->specialist_id . ':' . $eventSuffix,
        ]);
    }

    private function syncFirstOrderNotification(object $assignment): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'store_id')) {
            return;
        }

        $scopeDate = Schema::hasColumn('orders', 'delivered_at')
            ? 'COALESCE(delivered_at, created_at)'
            : 'created_at';
        $query = DB::table('orders')
            ->where('store_id', (string) $assignment->store_id)
            ->orderByRaw($scopeDate . ' asc')
            ->select(['id', 'order_code', 'final_amount', 'status_payment', 'created_at'])
            ->selectRaw($scopeDate . ' as completed_at');

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), self::SUCCESS_ORDER_STATUSES);
        }

        if (Schema::hasColumn('orders', 'status_payment')) {
            $query->where('status_payment', 'paid');
        }

        if (! empty($assignment->assigned_from)) {
            $query->whereRaw($scopeDate . ' >= ?', [$assignment->assigned_from]);
        }

        $order = $query->first();
        if (! $order) {
            return;
        }

        $storeName = trim((string) ($assignment->store_name ?? 'Cửa hàng'));
        $orderCode = trim((string) ($order->order_code ?? ''));

        $this->create([
            'specialist_id' => (int) $assignment->specialist_id,
            'type' => self::TYPE_FIRST_ORDER,
            'title' => 'Cửa hàng có đơn đầu tiên',
            'message' => $storeName . ' đã có đơn thành công đầu tiên' . ($orderCode !== '' ? ': ' . $orderCode : '') . '.',
            'data' => [
                'store_id' => (string) $assignment->store_id,
                'order_id' => (string) $order->id,
                'order_code' => $orderCode,
                'final_amount' => (float) ($order->final_amount ?? 0),
                'completed_at' => $order->completed_at,
            ],
            'action_url' => $this->routeUrl('admin.orders.index', ['store_id' => (string) $assignment->store_id]),
            'event_key' => 'first_order:' . $assignment->specialist_id . ':' . $assignment->store_id . ':' . $order->id,
        ]);
    }

    private function syncAbnormalTicketNotification(object $assignment): void
    {
        if (! Schema::hasTable('tickets') || ! Schema::hasColumn('tickets', 'status')) {
            return;
        }

        $summary = $this->ticketStatusSummaryForStore((string) $assignment->store_id);
        if ($summary['total'] < self::ABNORMAL_TICKET_MIN_TOTAL || $summary['held'] < self::ABNORMAL_TICKET_MIN_HELD) {
            return;
        }

        $ratio = $summary['total'] > 0 ? $summary['held'] / $summary['total'] : 0;
        if ($ratio < self::ABNORMAL_TICKET_THRESHOLD) {
            return;
        }

        $storeName = trim((string) ($assignment->store_name ?? 'Cửa hàng'));

        $this->create([
            'specialist_id' => (int) $assignment->specialist_id,
            'type' => self::TYPE_ABNORMAL_TICKET,
            'title' => 'Ticket treo bất thường',
            'message' => $storeName . ' có tỷ lệ ticket treo ' . number_format($ratio * 100, 2, ',', '.') . '%.',
            'data' => [
                'store_id' => (string) $assignment->store_id,
                'total_tickets' => $summary['total'],
                'held_tickets' => $summary['held'],
                'held_ratio' => round($ratio, 4),
                'threshold' => self::ABNORMAL_TICKET_THRESHOLD,
                'period' => '7 ngày gần nhất',
            ],
            'action_url' => $this->routeUrl('admin.tickets.specialist', [
                'store_id' => (string) $assignment->store_id,
                'status' => 'held',
            ]),
            'event_key' => 'abnormal_ticket:' . $assignment->specialist_id . ':' . $assignment->store_id . ':' . now('Asia/Ho_Chi_Minh')->format('Ymd'),
        ]);
    }

    private function ticketStatusSummaryForStore(string $storeId): array
    {
        $storeExpressionParts = [];
        $query = DB::table('tickets as t');

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'store_id')) {
            if (Schema::hasColumn('tickets', 'source_id') && Schema::hasColumn('tickets', 'source_type')) {
                $query->leftJoin('orders as source_order', function ($join): void {
                    $join->on('source_order.id', '=', 't.source_id')
                        ->whereIn(DB::raw("UPPER(COALESCE(t.source_type, ''))"), ['ORDER', 'ORDER_REWARD', 'ORDER_REDEEM']);
                });
                $storeExpressionParts[] = 'source_order.store_id';
            }

            if (Schema::hasColumn('tickets', 'used_in_order_id')) {
                $query->leftJoin('orders as used_order', 'used_order.id', '=', 't.used_in_order_id');
                $storeExpressionParts[] = 'used_order.store_id';
            }

            if (
                Schema::hasTable('order_tickets')
                && Schema::hasColumn('order_tickets', 'ticket_id')
                && Schema::hasColumn('order_tickets', 'order_id')
            ) {
                $query->leftJoin('order_tickets as ot', 'ot.ticket_id', '=', 't.id')
                    ->leftJoin('orders as linked_order', 'linked_order.id', '=', 'ot.order_id');
                $storeExpressionParts[] = 'linked_order.store_id';
            }
        }

        if (
            Schema::hasTable('stores')
            && Schema::hasColumn('tickets', 'source_id')
            && Schema::hasColumn('tickets', 'source_type')
        ) {
            $query->leftJoin('stores as source_store', function ($join): void {
                $join->on('source_store.id', '=', 't.source_id')
                    ->whereIn(DB::raw("UPPER(COALESCE(t.source_type, ''))"), ['STORE']);
            });
            $storeExpressionParts[] = 'source_store.id';
        }

        if (empty($storeExpressionParts)) {
            return ['total' => 0, 'held' => 0];
        }

        $storeExpression = 'COALESCE(' . implode(', ', $storeExpressionParts) . ')';
        $dateColumn = Schema::hasColumn('tickets', 'purchased_at') ? 't.purchased_at' : 't.created_at';

        $row = $query
            ->whereRaw($storeExpression . ' = ?', [$storeId])
            ->where($dateColumn, '>=', now('Asia/Ho_Chi_Minh')->subDays(7)->startOfDay())
            ->selectRaw('COUNT(DISTINCT t.id) as total_tickets')
            ->selectRaw("COUNT(DISTINCT CASE WHEN UPPER(COALESCE(t.status, '')) IN ('HELD','DISPUTED','LOCKED') THEN t.id END) as held_tickets")
            ->first();

        return [
            'total' => (int) ($row->total_tickets ?? 0),
            'held' => (int) ($row->held_tickets ?? 0),
        ];
    }

    private function syncSettlementNotifications(int $specialistId): void
    {
        if (! Schema::hasTable('specialist_settlements')) {
            return;
        }

        $query = DB::table('specialist_settlements')
            ->where('specialist_id', $specialistId)
            ->orderByDesc(Schema::hasColumn('specialist_settlements', 'created_at') ? 'created_at' : 'id')
            ->limit(100);

        foreach ($query->get() as $settlement) {
            $status = $this->normalizeStatus((string) ($settlement->status ?? 'pending'));
            $type = match ($status) {
                'approved' => self::TYPE_INCOME_APPROVED,
                'paid' => self::TYPE_INCOME_PAID,
                default => self::TYPE_SETTLEMENT_CREATED,
            };

            $title = match ($type) {
                self::TYPE_INCOME_APPROVED => 'Thu nhập được duyệt',
                self::TYPE_INCOME_PAID => 'Thu nhập đã thanh toán',
                default => 'Kỳ đối soát mới',
            };

            $amount = $settlement->final_income ?? $settlement->amount ?? 0;
            $periodFrom = $settlement->period_from ?? null;
            $periodTo = $settlement->period_to ?? null;
            $periodLabel = $periodFrom || $periodTo ? trim((string) $periodFrom . ' - ' . (string) $periodTo) : 'kỳ mới';

            $this->create([
                'specialist_id' => $specialistId,
                'type' => $type,
                'title' => $title,
                'message' => $title . ' cho ' . $periodLabel . ', giá trị ' . number_format((float) $amount, 0, ',', '.') . ' đ.',
                'data' => [
                    'settlement_id' => (string) $settlement->id,
                    'status' => $status,
                    'period_from' => $periodFrom,
                    'period_to' => $periodTo,
                    'amount' => (float) $amount,
                ],
                'action_url' => $this->routeUrl('admin.specialist-settlements.show', [
                    'settlement' => (string) $settlement->id,
                ]) ?? $this->routeUrl('admin.specialist-settlements.index', [
                    'period_from' => $periodFrom,
                    'period_to' => $periodTo,
                    'status' => $status === 'pending_settlement' ? 'WAITING_APPROVAL' : strtoupper($status),
                ]) ?? $this->routeUrl('admin.specialist-income.index', [
                    'date_from' => $periodFrom,
                    'date_to' => $periodTo,
                    'status' => $status === 'pending_settlement' ? 'pending_settlement' : $status,
                ]),
                'event_key' => 'settlement:' . $specialistId . ':' . $settlement->id . ':' . $type,
            ]);
        }
    }

    private function syncTaskNotifications(int $specialistId): void
    {
        if (! Schema::hasTable('specialist_tasks') || ! Schema::hasColumn('specialist_tasks', 'specialist_id')) {
            return;
        }

        $titleColumn = $this->firstExistingColumn('specialist_tasks', ['title', 'name', 'task_title']);
        $messageColumn = $this->firstExistingColumn('specialist_tasks', ['message', 'description', 'content', 'notes']);
        $createdColumn = $this->firstExistingColumn('specialist_tasks', ['created_at', 'assigned_at', 'due_at']);

        $query = DB::table('specialist_tasks')
            ->where('specialist_id', $specialistId)
            ->limit(100);

        if ($createdColumn !== null) {
            $query->orderByDesc($createdColumn);
        }

        foreach ($query->get() as $task) {
            $taskTitle = $titleColumn ? trim((string) ($task->{$titleColumn} ?? '')) : '';
            $message = $messageColumn ? trim((string) ($task->{$messageColumn} ?? '')) : '';

            $this->create([
                'specialist_id' => $specialistId,
                'type' => self::TYPE_TASK_NEW,
                'title' => 'Nhiệm vụ mới',
                'message' => $taskTitle !== '' ? $taskTitle : ($message !== '' ? $message : 'Bạn có nhiệm vụ mới cần xử lý.'),
                'data' => [
                    'task_id' => (string) ($task->id ?? ''),
                    'title' => $taskTitle,
                    'message' => $message,
                ],
                'action_url' => $this->routeUrl('admin.specialist-care.index', [
                    'task_status' => 'pending',
                ]) ?? $this->routeUrl('admin.specialist-notifications.index'),
                'event_key' => 'task_new:' . $specialistId . ':' . (string) ($task->id ?? md5(json_encode($task))),
            ]);
        }
    }

    private function syncRatingNotifications(int $specialistId): void
    {
        if (! Schema::hasTable('specialist_ratings') || ! Schema::hasColumn('specialist_ratings', 'specialist_id')) {
            return;
        }

        $scoreColumn = $this->firstExistingColumn('specialist_ratings', ['total_score', 'score']);
        $starColumn = $this->firstExistingColumn('specialist_ratings', ['star_level', 'stars', 'rating']);
        $messageColumn = $this->firstExistingColumn('specialist_ratings', ['message', 'comment', 'notes', 'description']);
        $createdColumn = $this->firstExistingColumn('specialist_ratings', ['approved_at', 'created_at', 'rated_at']);

        $query = DB::table('specialist_ratings')
            ->where('specialist_id', $specialistId)
            ->limit(100);

        if (Schema::hasColumn('specialist_ratings', 'status')) {
            $query->whereIn(DB::raw("UPPER(COALESCE(status, ''))"), ['APPROVED', 'NOTIFIED']);
        } elseif (Schema::hasColumn('specialist_ratings', 'approved_at')) {
            $query->whereNotNull('approved_at');
        }

        if ($createdColumn !== null) {
            $query->orderByDesc($createdColumn);
        }

        foreach ($query->get() as $rating) {
            $score = $scoreColumn ? trim((string) ($rating->{$scoreColumn} ?? '')) : '';
            $star = $starColumn ? trim((string) ($rating->{$starColumn} ?? '')) : '';
            $message = $messageColumn ? trim((string) ($rating->{$messageColumn} ?? '')) : '';

            $this->create([
                'specialist_id' => $specialistId,
                'type' => self::TYPE_RATING_NEW,
                'title' => 'Có kết quả đánh giá sao mới',
                'message' => 'Bạn có kết quả đánh giá mới' . ($star !== '' ? ': ' . $star . ' sao' : '') . ($score !== '' ? ', ' . $score . ' điểm' : '') . ($message !== '' ? ' - ' . $message : '') . '.',
                'data' => [
                    'rating_id' => (string) ($rating->id ?? ''),
                    'star_level' => $star,
                    'score' => $score,
                    'message' => $message,
                ],
                'action_url' => $this->routeUrl('admin.specialist-ratings.show', ['rating' => (string) ($rating->id ?? '')]) ?? $this->routeUrl('admin.specialist-ratings.index'),
                'event_key' => 'rating_new:' . $specialistId . ':' . (string) ($rating->id ?? md5(json_encode($rating))) . ':approved',
            ]);
        }
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim(str_replace([' ', '-'], '_', $status)));

        return match ($status) {
            'approved' => 'approved',
            'paid', 'settled', 'completed' => 'paid',
            default => 'pending_settlement',
        };
    }

    private function routeUrl(string $routeName, array $params = []): ?string
    {
        if (! Route::has($routeName)) {
            return null;
        }

        return route($routeName, array_filter($params, static fn ($value): bool => $value !== null && $value !== ''), false);
    }

    private function sanitizeInternalUrl(mixed $value): ?string
    {
        $url = $this->nullableString($value);
        if ($url === null) {
            return null;
        }

        if (str_starts_with($url, '/')) {
            return $url;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl !== '' && ($url === $appUrl || str_starts_with($url, $appUrl . '/'))) {
            return $url;
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }
}

<?php

namespace App\Services;

use App\Models\SpecialistRating;
use App\Models\SpecialistRatingAuditLog;
use App\Models\SpecialistRatingPeriod;
use App\Models\SpecialistRatingReviewRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class SpecialistRatingService
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_CALCULATING = 'CALCULATING';
    public const STATUS_WAITING_REVIEW = 'WAITING_REVIEW';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_NOTIFIED = 'NOTIFIED';

    public const REVIEW_SUBMITTED = 'SUBMITTED';
    public const REVIEW_UNDER_REVIEW = 'UNDER_REVIEW';
    public const REVIEW_ACCEPTED = 'ACCEPTED';
    public const REVIEW_REJECTED = 'REJECTED';

    private const SUCCESS_ORDER_STATUSES = ['completed', 'delivered', 'call_completed'];
    private const ACTIVE_STORE_STATUSES = ['active', 'approved'];
    private const COMPLETED_TASK_STATUSES = ['completed', 'done', 'resolved', 'closed'];
    private const RISK_TICKET_STATUSES = ['HELD', 'DISPUTED', 'LOCKED', 'CANCELLED', 'CANCELED', 'REFUNDED', 'FRAUD', 'FRAUD_CANCELLED'];
    private const RESOLVED_COMPLAINT_STATUSES = ['resolved', 'closed', 'rejected', 'cancelled', 'canceled', 'done'];

    private const CRITERIA = [
        'CONSULTING' => [
            'label' => 'Tư vấn & kích hoạt cửa hàng',
            'weight' => 20,
        ],
        'PROFILE_QUALITY' => [
            'label' => 'Chất lượng hồ sơ cửa hàng',
            'weight' => 20,
        ],
        'SUPPORT' => [
            'label' => 'Hỗ trợ & hoàn thành nhiệm vụ',
            'weight' => 20,
        ],
        'INTEGRITY' => [
            'label' => 'Liêm chính & rủi ro',
            'weight' => 20,
        ],
        'COORDINATION' => [
            'label' => 'Phối hợp vận hành đơn hàng',
            'weight' => 20,
        ],
    ];

    public function __construct(
        private readonly SpecialistNotificationService $specialistNotificationService
    ) {
    }

    public function criteriaLabels(): array
    {
        return collect(self::CRITERIA)
            ->mapWithKeys(fn (array $item, string $code): array => [$code => $item['label']])
            ->all();
    }

    public function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Nháp',
            self::STATUS_CALCULATING => 'Đang tính',
            self::STATUS_WAITING_REVIEW => 'Chờ rà soát',
            self::STATUS_APPROVED => 'Đã duyệt',
            self::STATUS_NOTIFIED => 'Đã thông báo',
        ];
    }

    public function reviewStatusLabels(): array
    {
        return [
            self::REVIEW_SUBMITTED => 'Đã gửi',
            self::REVIEW_UNDER_REVIEW => 'Đang xem xét',
            self::REVIEW_ACCEPTED => 'Chấp nhận',
            self::REVIEW_REJECTED => 'Từ chối',
        ];
    }

    public function starFromScore(float $score): int
    {
        return match (true) {
            $score >= 90 => 5,
            $score >= 75 => 4,
            $score >= 60 => 3,
            $score >= 40 => 2,
            default => 1,
        };
    }

    public function saleDashboard(int $specialistId): array
    {
        $current = SpecialistRating::query()
            ->with(['criteriaScores', 'period', 'reviewRequests' => fn ($query) => $query->orderByDesc('created_at')])
            ->where('specialist_id', $specialistId)
            ->whereIn('status', [self::STATUS_APPROVED, self::STATUS_NOTIFIED])
            ->orderByDesc('period_to')
            ->orderByDesc('created_at')
            ->first();

        $history = SpecialistRating::query()
            ->with('period')
            ->where('specialist_id', $specialistId)
            ->whereIn('status', [self::STATUS_APPROVED, self::STATUS_NOTIFIED])
            ->orderByDesc('period_to')
            ->orderByDesc('created_at')
            ->limit(12)
            ->get();

        $previous = $history->skip(1)->first();

        return [
            'current' => $current,
            'history' => $history,
            'criteriaLabels' => $this->criteriaLabels(),
            'statusLabels' => $this->statusLabels(),
            'reviewStatusLabels' => $this->reviewStatusLabels(),
            'trend' => [
                'score_delta' => $current && $previous ? round((float) $current->total_score - (float) $previous->total_score, 2) : null,
                'star_delta' => $current && $previous ? (int) $current->star_level - (int) $previous->star_level : null,
            ],
        ];
    }

    public function adminRatings(array $filters): LengthAwarePaginator
    {
        $query = SpecialistRating::query()
            ->with(['specialist:id,name,full_name,email,phone,role', 'period'])
            ->orderByDesc('period_to')
            ->orderByDesc('created_at');

        if (! empty($filters['specialist_id'])) {
            $query->where('specialist_id', (int) $filters['specialist_id']);
        }

        if (! empty($filters['star_level']) && $filters['star_level'] !== 'all') {
            $query->where('star_level', (int) $filters['star_level']);
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['period_from'])) {
            $query->whereDate('period_to', '>=', $filters['period_from']);
        }

        if (! empty($filters['period_to'])) {
            $query->whereDate('period_from', '<=', $filters['period_to']);
        }

        return $query->paginate(20)->appends($filters);
    }

    public function reviewRequests(array $filters = []): LengthAwarePaginator
    {
        $query = SpecialistRatingReviewRequest::query()
            ->with(['rating.period', 'specialist:id,name,full_name,email,role'])
            ->orderByDesc('created_at');

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['specialist_id'])) {
            $query->where('specialist_id', (int) $filters['specialist_id']);
        }

        return $query->paginate(20)->appends($filters);
    }

    public function calculateForMany(?int $specialistId, Carbon $from, Carbon $to, ?int $actorId = null): array
    {
        $query = User::query()
            ->where('role', 'sale')
            ->select(['id', 'name', 'full_name', 'email', 'role']);

        if ($specialistId !== null) {
            $query->where('id', $specialistId);
        }

        $ratings = [];
        $query->orderBy('id')->chunkById(50, function ($specialists) use ($from, $to, $actorId, &$ratings): void {
            foreach ($specialists as $specialist) {
                $ratings[] = $this->calculateForSpecialist((int) $specialist->id, $from, $to, $actorId);
            }
        });

        return $ratings;
    }

    public function calculateForSpecialist(int $specialistId, Carbon $from, Carbon $to, ?int $actorId = null): SpecialistRating
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $period = $this->ratingPeriod($from, $to);

        return DB::transaction(function () use ($specialistId, $from, $to, $period, $actorId): SpecialistRating {
            $rating = SpecialistRating::query()
                ->where('specialist_id', $specialistId)
                ->where('rating_period_id', $period->id)
                ->first();

            $oldValues = $rating ? $this->ratingSnapshot($rating) : null;
            $criteria = $this->calculateCriteria($specialistId, $from, $to);
            $totalScore = round(collect($criteria)->sum('weighted_score'), 2);
            $starLevel = $this->starFromScore($totalScore);
            $previousStar = $this->previousStarLevel($specialistId, $from);

            if (! $rating) {
                $rating = new SpecialistRating([
                    'specialist_id' => $specialistId,
                    'rating_period_id' => $period->id,
                ]);
            }

            $rating->fill([
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'total_score' => $totalScore,
                'score' => $totalScore,
                'star_level' => $starLevel,
                'stars' => $starLevel,
                'previous_star_level' => $previousStar,
                'status' => self::STATUS_WAITING_REVIEW,
                'calculated_at' => now(),
                'data_source_json' => [
                    'criteria_count' => count($criteria),
                    'calculated_by' => $actorId,
                ],
            ]);
            $rating->save();

            $rating->criteriaScores()->delete();
            foreach ($criteria as $criteriaRow) {
                $rating->criteriaScores()->create($criteriaRow);
            }

            $this->audit($rating, 'CALCULATE', $oldValues, $this->ratingSnapshot($rating), $actorId, 'Chạy tính điểm tự động');

            return $rating->fresh(['criteriaScores', 'period', 'specialist']);
        });
    }

    public function approve(SpecialistRating $rating, int $actorId, ?string $notes = null): SpecialistRating
    {
        return DB::transaction(function () use ($rating, $actorId, $notes): SpecialistRating {
            $oldValues = $this->ratingSnapshot($rating);

            $rating->forceFill([
                'status' => self::STATUS_APPROVED,
                'approved_by' => $actorId,
                'approved_at' => now(),
                'notified_at' => now(),
                'notes' => $notes ?: $rating->notes,
                'score' => $rating->total_score,
                'stars' => $rating->star_level,
            ])->save();

            $this->audit($rating, 'APPROVE', $oldValues, $this->ratingSnapshot($rating), $actorId, $notes);
            $this->notifyRatingApproved($rating);

            return $rating->fresh(['criteriaScores', 'period', 'specialist']);
        });
    }

    public function adjust(SpecialistRating $rating, float $newScore, int $actorId, string $reason): SpecialistRating
    {
        return DB::transaction(function () use ($rating, $newScore, $actorId, $reason): SpecialistRating {
            $oldValues = $this->ratingSnapshot($rating);
            $newScore = max(0, min(100, $newScore));
            $starLevel = $this->starFromScore($newScore);

            $rating->forceFill([
                'total_score' => $newScore,
                'score' => $newScore,
                'star_level' => $starLevel,
                'stars' => $starLevel,
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
                'status' => self::STATUS_WAITING_REVIEW,
                'adjustment_reason' => $reason,
            ])->save();

            $this->audit($rating, 'ADJUST', $oldValues, $this->ratingSnapshot($rating), $actorId, $reason);

            return $rating->fresh(['criteriaScores', 'period', 'specialist']);
        });
    }

    public function submitReviewRequest(SpecialistRating $rating, int $specialistId, string $reason): SpecialistRatingReviewRequest
    {
        return DB::transaction(function () use ($rating, $specialistId, $reason): SpecialistRatingReviewRequest {
            $existing = SpecialistRatingReviewRequest::query()
                ->where('rating_id', $rating->id)
                ->where('specialist_id', $specialistId)
                ->whereIn('status', [self::REVIEW_SUBMITTED, self::REVIEW_UNDER_REVIEW])
                ->first();

            if ($existing) {
                return $existing;
            }

            $request = SpecialistRatingReviewRequest::query()->create([
                'rating_id' => $rating->id,
                'specialist_id' => $specialistId,
                'request_reason' => $reason,
                'status' => self::REVIEW_SUBMITTED,
                'old_score' => $rating->total_score,
            ]);

            $this->audit($rating, 'REQUEST_REVIEW', null, [
                'request_id' => $request->id,
                'reason' => $reason,
            ], $specialistId, 'Chuyên viên gửi yêu cầu xem xét lại');

            return $request;
        });
    }

    public function processReviewRequest(SpecialistRatingReviewRequest $request, string $status, int $actorId, ?float $newScore, ?string $response): SpecialistRatingReviewRequest
    {
        return DB::transaction(function () use ($request, $status, $actorId, $newScore, $response): SpecialistRatingReviewRequest {
            $rating = $request->rating()->lockForUpdate()->firstOrFail();
            $oldRating = $this->ratingSnapshot($rating);

            $status = $status === self::REVIEW_ACCEPTED ? self::REVIEW_ACCEPTED : self::REVIEW_REJECTED;
            if ($status === self::REVIEW_ACCEPTED && $newScore !== null) {
                $newScore = max(0, min(100, $newScore));
                $rating->forceFill([
                    'total_score' => $newScore,
                    'score' => $newScore,
                    'star_level' => $this->starFromScore($newScore),
                    'stars' => $this->starFromScore($newScore),
                    'reviewed_by' => $actorId,
                    'reviewed_at' => now(),
                    'status' => self::STATUS_WAITING_REVIEW,
                    'adjustment_reason' => $response,
                ])->save();
            }

            $request->forceFill([
                'status' => $status,
                'new_score' => $status === self::REVIEW_ACCEPTED ? ($newScore ?? $rating->total_score) : $request->new_score,
                'admin_response' => $response,
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
            ])->save();

            $this->audit($rating, 'PROCESS_REVIEW_REQUEST', $oldRating, [
                'rating' => $this->ratingSnapshot($rating),
                'request_id' => $request->id,
                'request_status' => $status,
                'response' => $response,
            ], $actorId, $response);

            return $request->fresh(['rating.period', 'specialist']);
        });
    }

    public function specialistOptions(): Collection
    {
        return User::query()
            ->select(['id', 'name', 'full_name', 'email'])
            ->where('role', 'sale')
            ->orderBy('full_name')
            ->orderBy('name')
            ->get();
    }

    private function ratingPeriod(Carbon $from, Carbon $to): SpecialistRatingPeriod
    {
        return SpecialistRatingPeriod::query()->firstOrCreate(
            [
                'period_type' => 'month',
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
            ],
            [
                'name' => $from->format('m/Y'),
                'status' => 'open',
            ]
        );
    }

    private function calculateCriteria(int $specialistId, Carbon $from, Carbon $to): array
    {
        $storeIds = $this->assignedStoreIds($specialistId, $from, $to);
        $storeMetrics = $this->storeMetrics($storeIds);
        $orderMetrics = $this->orderMetrics($storeIds, $from, $to);
        $taskMetrics = $this->taskMetrics($specialistId, $from, $to);
        $riskMetrics = $this->riskMetrics($storeIds, $from, $to);

        $consulting = $storeMetrics['total'] > 0
            ? $orderMetrics['stores_with_success'] / $storeMetrics['total'] * 100
            : 0;
        $profileQuality = $storeMetrics['total'] > 0
            ? $storeMetrics['active'] / $storeMetrics['total'] * 100
            : 0;
        $support = $taskMetrics['total'] > 0
            ? $taskMetrics['completed'] / $taskMetrics['total'] * 100
            : 100;
        $integrity = $riskMetrics['total_tickets'] > 0
            ? max(0, 100 - (($riskMetrics['risk_tickets'] / $riskMetrics['total_tickets']) * 160) - ($riskMetrics['active_complaints'] * 5))
            : max(0, 100 - ($riskMetrics['active_complaints'] * 5));
        $coordination = $orderMetrics['total_orders'] > 0
            ? $orderMetrics['successful_paid_orders'] / $orderMetrics['total_orders'] * 100
            : 70;

        return [
            $this->criteriaRow('CONSULTING', $consulting, [
                'assigned_stores' => $storeMetrics['total'],
                'stores_with_successful_order' => $orderMetrics['stores_with_success'],
            ]),
            $this->criteriaRow('PROFILE_QUALITY', $profileQuality, [
                'assigned_stores' => $storeMetrics['total'],
                'active_stores' => $storeMetrics['active'],
            ]),
            $this->criteriaRow('SUPPORT', $support, $taskMetrics),
            $this->criteriaRow('INTEGRITY', $integrity, $riskMetrics),
            $this->criteriaRow('COORDINATION', $coordination, $orderMetrics),
        ];
    }

    private function criteriaRow(string $code, float $rawScore, array $source): array
    {
        $weight = (float) self::CRITERIA[$code]['weight'];
        $rawScore = round(max(0, min(100, $rawScore)), 2);

        return [
            'criteria_code' => $code,
            'raw_score' => $rawScore,
            'weight_percent' => $weight,
            'weighted_score' => round($rawScore * $weight / 100, 2),
            'data_source_json' => $source,
        ];
    }

    private function assignedStoreIds(int $specialistId, Carbon $from, Carbon $to): array
    {
        if (
            Schema::hasTable('specialist_store_assignments')
            && Schema::hasColumn('specialist_store_assignments', 'specialist_id')
            && Schema::hasColumn('specialist_store_assignments', 'store_id')
        ) {
            $query = DB::table('specialist_store_assignments')
                ->where('specialist_id', $specialistId);

            if (Schema::hasColumn('specialist_store_assignments', 'assigned_from')) {
                $query->where('assigned_from', '<=', $to->toDateTimeString());
            }

            if (Schema::hasColumn('specialist_store_assignments', 'assigned_to')) {
                $query->where(function ($window) use ($from): void {
                    $window->whereNull('assigned_to')
                        ->orWhere('assigned_to', '>=', $from->toDateTimeString());
                });
            }

            $storeIds = $query->pluck('store_id')
                ->map(static fn ($id): string => (string) $id)
                ->unique()
                ->values()
                ->all();

            if (! empty($storeIds)) {
                return $storeIds;
            }
        }

        if (! Schema::hasTable('stores') || ! Schema::hasColumn('stores', 'sale_id')) {
            return [];
        }

        return DB::table('stores')
            ->where('sale_id', $specialistId)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function storeMetrics(array $storeIds): array
    {
        if (empty($storeIds) || ! Schema::hasTable('stores')) {
            return ['total' => 0, 'active' => 0];
        }

        $base = DB::table('stores')->whereIn('id', $storeIds);
        $active = clone $base;

        if (Schema::hasColumn('stores', 'status')) {
            $active->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), self::ACTIVE_STORE_STATUSES);
        }

        return [
            'total' => (int) $base->count(),
            'active' => (int) $active->count(),
        ];
    }

    private function orderMetrics(array $storeIds, Carbon $from, Carbon $to): array
    {
        if (empty($storeIds) || ! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'store_id')) {
            return ['total_orders' => 0, 'successful_paid_orders' => 0, 'stores_with_success' => 0];
        }

        $dateExpression = Schema::hasColumn('orders', 'delivered_at')
            ? 'COALESCE(delivered_at, created_at)'
            : 'created_at';

        $base = DB::table('orders')
            ->whereIn('store_id', $storeIds)
            ->whereRaw($dateExpression . ' >= ?', [$from->toDateTimeString()])
            ->whereRaw($dateExpression . ' <= ?', [$to->toDateTimeString()]);

        $success = clone $base;
        if (Schema::hasColumn('orders', 'status')) {
            $success->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), self::SUCCESS_ORDER_STATUSES);
        }
        if (Schema::hasColumn('orders', 'status_payment')) {
            $success->where('status_payment', 'paid');
        }

        return [
            'total_orders' => (int) $base->count(),
            'successful_paid_orders' => (int) (clone $success)->count(),
            'stores_with_success' => (int) (clone $success)->distinct('store_id')->count('store_id'),
        ];
    }

    private function taskMetrics(int $specialistId, Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('specialist_tasks') || ! Schema::hasColumn('specialist_tasks', 'specialist_id')) {
            return ['total' => 0, 'completed' => 0];
        }

        $dateColumn = Schema::hasColumn('specialist_tasks', 'due_at') ? 'due_at' : 'created_at';
        $base = DB::table('specialist_tasks')
            ->where('specialist_id', $specialistId)
            ->where(function ($query) use ($dateColumn, $from, $to): void {
                $query->whereNull($dateColumn)
                    ->orWhereBetween($dateColumn, [$from->toDateTimeString(), $to->toDateTimeString()]);
            });

        $completed = clone $base;
        $completed->where(function ($query): void {
            if (Schema::hasColumn('specialist_tasks', 'status')) {
                $query->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), self::COMPLETED_TASK_STATUSES);
            }
            if (Schema::hasColumn('specialist_tasks', 'completed_at')) {
                $query->orWhereNotNull('completed_at');
            }
        });

        return [
            'total' => (int) $base->count(),
            'completed' => (int) $completed->count(),
        ];
    }

    private function riskMetrics(array $storeIds, Carbon $from, Carbon $to): array
    {
        $ticketSummary = $this->ticketRiskMetrics($storeIds, $from, $to);
        $activeComplaints = $this->activeComplaintCount($storeIds, $from, $to);

        return [
            'total_tickets' => $ticketSummary['total_tickets'],
            'risk_tickets' => $ticketSummary['risk_tickets'],
            'active_complaints' => $activeComplaints,
        ];
    }

    private function ticketRiskMetrics(array $storeIds, Carbon $from, Carbon $to): array
    {
        if (empty($storeIds) || ! Schema::hasTable('tickets') || ! Schema::hasColumn('tickets', 'status')) {
            return ['total_tickets' => 0, 'risk_tickets' => 0];
        }

        [$query, $storeExpression] = $this->ticketStoreScopedQuery();
        if ($storeExpression === null) {
            return ['total_tickets' => 0, 'risk_tickets' => 0];
        }

        $dateColumn = Schema::hasColumn('tickets', 'purchased_at') ? 't.purchased_at' : 't.created_at';
        $row = $query
            ->whereIn(DB::raw($storeExpression), $storeIds)
            ->whereBetween($dateColumn, [$from->toDateTimeString(), $to->toDateTimeString()])
            ->selectRaw('COUNT(DISTINCT t.id) as total_tickets')
            ->selectRaw("COUNT(DISTINCT CASE WHEN UPPER(COALESCE(t.status, '')) IN ('" . implode("','", self::RISK_TICKET_STATUSES) . "') THEN t.id END) as risk_tickets")
            ->first();

        return [
            'total_tickets' => (int) ($row->total_tickets ?? 0),
            'risk_tickets' => (int) ($row->risk_tickets ?? 0),
        ];
    }

    private function ticketStoreScopedQuery(): array
    {
        $query = DB::table('tickets as t');
        $storeExpressionParts = [];

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

        return [$query, empty($storeExpressionParts) ? null : 'COALESCE(' . implode(', ', $storeExpressionParts) . ')'];
    }

    private function activeComplaintCount(array $storeIds, Carbon $from, Carbon $to): int
    {
        if (empty($storeIds) || ! Schema::hasTable('complaints') || ! Schema::hasColumn('complaints', 'store_id')) {
            return 0;
        }

        $query = DB::table('complaints')
            ->whereIn('store_id', $storeIds);

        if (Schema::hasColumn('complaints', 'created_at')) {
            $query->whereBetween('created_at', [$from->toDateTimeString(), $to->toDateTimeString()]);
        }

        if (Schema::hasColumn('complaints', 'status')) {
            $query->whereNotIn(DB::raw("LOWER(COALESCE(status, ''))"), self::RESOLVED_COMPLAINT_STATUSES);
        }

        return (int) $query->count();
    }

    private function previousStarLevel(int $specialistId, Carbon $periodFrom): ?int
    {
        $rating = SpecialistRating::query()
            ->where('specialist_id', $specialistId)
            ->whereDate('period_to', '<', $periodFrom->toDateString())
            ->orderByDesc('period_to')
            ->orderByDesc('created_at')
            ->first();

        return $rating ? (int) $rating->star_level : null;
    }

    private function notifyRatingApproved(SpecialistRating $rating): void
    {
        $rating->loadMissing('period');
        $periodLabel = $rating->period?->name ?: ($rating->period_from?->format('d/m/Y') . ' - ' . $rating->period_to?->format('d/m/Y'));

        $this->specialistNotificationService->create([
            'specialist_id' => (int) $rating->specialist_id,
            'type' => SpecialistNotificationService::TYPE_RATING_NEW,
            'title' => 'Có kết quả đánh giá sao mới',
            'message' => 'Kết quả đánh giá ' . $periodLabel . ': ' . (int) $rating->star_level . ' sao, ' . number_format((float) $rating->total_score, 2, ',', '.') . ' điểm.',
            'data' => [
                'rating_id' => (string) $rating->id,
                'period_from' => optional($rating->period_from)->toDateString(),
                'period_to' => optional($rating->period_to)->toDateString(),
                'star_level' => (int) $rating->star_level,
                'total_score' => (float) $rating->total_score,
            ],
            'action_url' => Route::has('admin.specialist-ratings.show')
                ? route('admin.specialist-ratings.show', $rating->id, false)
                : null,
            'event_key' => 'rating_new:' . $rating->specialist_id . ':' . $rating->id . ':approved',
        ]);
    }

    private function audit(SpecialistRating $rating, string $action, ?array $oldValues, ?array $newValues, ?int $actorId, ?string $notes = null): void
    {
        SpecialistRatingAuditLog::query()->create([
            'rating_id' => $rating->id,
            'specialist_id' => $rating->specialist_id,
            'actor_id' => $actorId,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    private function ratingSnapshot(SpecialistRating $rating): array
    {
        return [
            'id' => $rating->id,
            'specialist_id' => $rating->specialist_id,
            'rating_period_id' => $rating->rating_period_id,
            'total_score' => (float) $rating->total_score,
            'star_level' => (int) $rating->star_level,
            'previous_star_level' => $rating->previous_star_level,
            'status' => $rating->status,
            'reviewed_by' => $rating->reviewed_by,
            'approved_by' => $rating->approved_by,
        ];
    }
}

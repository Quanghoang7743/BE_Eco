<?php

namespace App\Services;

use App\Models\AffiliateAuditLog;
use App\Models\AffiliateCustomer;
use App\Models\AffiliateProfile;
use App\Models\CommissionUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AffiliateCustomerService
{
    public function isLegacyMode(): bool
    {
        return !$this->hasAffiliateCustomersTable();
    }

    public function createRegistrationAssignment(
        User $customer,
        int $affiliateId,
        array $source = [],
        ?Request $request = null,
    ): ?AffiliateCustomer {
        if (!$this->hasAffiliateCustomersTable() || !$customer->id || $affiliateId <= 0) {
            return null;
        }

        if ((int) $customer->id === $affiliateId || !User::query()->whereKey($affiliateId)->exists()) {
            return null;
        }

        $existing = $this->findCurrentAssignmentForCustomer((int) $customer->id);
        if ($existing) {
            if ((int) $existing->affiliate_id === $affiliateId) {
                return $existing;
            }

            return null;
        }

        $sourceType = $this->normalizeRegistrationSourceType($source['source_type'] ?? null);
        $sourceId = trim((string) ($source['source_id'] ?? $source['referrer_input'] ?? $affiliateId));
        $assignedAt = $customer->created_at ?: now();

        $assignment = AffiliateCustomer::query()->create([
            'affiliate_id' => $affiliateId,
            'customer_id' => (int) $customer->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId !== '' ? $sourceId : (string) $affiliateId,
            'assigned_at' => $assignedAt,
            'status' => AffiliateCustomer::STATUS_ACTIVE,
        ]);

        if (empty($customer->referrer_id) || (int) $customer->referrer_id === $affiliateId) {
            $customer->forceFill(['referrer_id' => $affiliateId])->save();
        }

        $this->logAction(
            null,
            'affiliate_customer.registration_converted',
            (string) $assignment->id,
            null,
            [
                'affiliate_id' => $assignment->affiliate_id,
                'customer_id' => $assignment->customer_id,
                'source_type' => $assignment->source_type,
                'source_id' => $assignment->source_id,
                'source_url' => $source['source_url'] ?? null,
                'clicked_at' => $source['clicked_at'] ?? null,
                'assigned_at' => optional($assignment->assigned_at)->toIso8601String(),
                'status' => $assignment->status,
            ],
            'registration_converted',
            $request,
        );

        return $assignment;
    }

    public function ensureOrderBackfillAssignment(Order $order, int $affiliateId): ?AffiliateCustomer
    {
        if (!$this->hasAffiliateCustomersTable() || !$order->user_id || $affiliateId <= 0) {
            return null;
        }

        $customerId = (int) $order->user_id;
        if ($customerId === $affiliateId || !User::query()->whereKey($affiliateId)->exists()) {
            return null;
        }

        $existing = $this->findCurrentAssignmentForCustomer($customerId);
        if ($existing) {
            if ((int) $existing->affiliate_id !== $affiliateId) {
                return null;
            }

            if (empty($existing->first_order_id)) {
                $existing->forceFill(['first_order_id' => (string) $order->id])->save();
            }

            return $existing;
        }

        $assignedAt = $order->created_at ?: now();
        $assignment = AffiliateCustomer::query()->create([
            'affiliate_id' => $affiliateId,
            'customer_id' => $customerId,
            'source_type' => 'order_conversion_backfill',
            'source_id' => (string) $order->id,
            'first_order_id' => (string) $order->id,
            'assigned_at' => $assignedAt,
            'status' => AffiliateCustomer::STATUS_ACTIVE,
        ]);

        $customer = User::query()->find($customerId);
        if ($customer && (empty($customer->referrer_id) || (int) $customer->referrer_id === $affiliateId)) {
            $customer->forceFill(['referrer_id' => $affiliateId])->save();
        }

        $this->logAction(
            null,
            'affiliate_customer.order_backfilled',
            (string) $assignment->id,
            null,
            [
                'affiliate_id' => $assignment->affiliate_id,
                'customer_id' => $assignment->customer_id,
                'source_type' => $assignment->source_type,
                'source_id' => $assignment->source_id,
                'first_order_id' => $assignment->first_order_id,
                'assigned_at' => optional($assignment->assigned_at)->toIso8601String(),
                'status' => $assignment->status,
            ],
            'order_conversion_backfill',
        );

        return $assignment;
    }

    public function approvedAffiliateOptions(): Collection
    {
        $approvedSortColumn = Schema::hasColumn('affiliate_profiles', 'approved_at')
            ? 'approved_at'
            : 'created_at';

        return AffiliateProfile::query()
            ->with('user')
            ->whereIn('status', AffiliateProfile::ACTIVE_STATUSES)
            ->orderByDesc($approvedSortColumn)
            ->get()
            ->map(function (AffiliateProfile $profile) {
                $user = $profile->user;

                return [
                    'profile_id' => (int) $profile->id,
                    'user_id' => (int) $profile->user_id,
                    'name' => $user?->personal_full_name ?? $user?->full_name ?? $user?->name ?? ('Affiliate #' . $profile->user_id),
                    'phone' => $user?->phone,
                    'affiliate_code' => $profile->affiliate_code,
                ];
            })
            ->values();
    }

    public function paginateAssignments(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        if (!$this->hasAffiliateCustomersTable()) {
            return $this->paginateLegacyAssignments($filters, $perPage);
        }

        $paginator = $this->buildAssignmentsQuery($filters)
            ->paginate($perPage);

        $enriched = $this->enrichAssignments($paginator->getCollection());
        $paginator->setCollection($enriched);

        return $paginator;
    }

    public function buildSummary(array $filters = []): array
    {
        if (!$this->hasAffiliateCustomersTable()) {
            return $this->buildLegacySummary($filters);
        }

        $rows = $this->buildAssignmentsQuery($filters)
            ->get([
                'id',
                'affiliate_id',
                'customer_id',
                'source_type',
                'assigned_at',
                'status',
            ]);

        if ($rows->isEmpty()) {
            return [
                'total_customers' => 0,
                'new_customers_in_period' => 0,
                'customers_with_orders' => 0,
                'customers_without_orders' => 0,
                'conversion_rate' => 0,
                'total_orders' => 0,
                'total_gmv' => 0,
                'total_commission_generated' => 0,
                'total_lucky_tickets' => 0,
                'customers_transferred_out' => 0,
                'customers_transferred_in' => 0,
            ];
        }

        $currentRows = $rows->filter(fn (AffiliateCustomer $row) => in_array($row->status, AffiliateCustomer::CURRENT_STATUSES, true))->values();
        $customerIds = $currentRows->pluck('customer_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $affiliateIds = $currentRows->pluck('affiliate_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $orderStats = $this->buildOrderStatsMap($customerIds);
        $ticketStats = $this->buildTicketStatsMap($customerIds);
        $commissionStats = $this->buildCommissionStatsMap($affiliateIds, $customerIds);

        $customersWithOrders = 0;
        $totalOrders = 0;
        $totalGmv = 0.0;
        $totalTickets = 0;
        $totalCommission = 0.0;

        foreach ($currentRows as $row) {
            $customerId = (int) $row->customer_id;
            $affiliateId = (int) $row->affiliate_id;
            $orderStat = $orderStats[$customerId] ?? ['total_valid_orders' => 0, 'total_gmv' => 0.0];
            $ticketStat = $ticketStats[$customerId] ?? 0;
            $commissionStat = $commissionStats[$this->pairKey($affiliateId, $customerId)] ?? 0.0;

            if (($orderStat['total_valid_orders'] ?? 0) > 0) {
                $customersWithOrders++;
            }

            $totalOrders += (int) ($orderStat['total_valid_orders'] ?? 0);
            $totalGmv += (float) ($orderStat['total_gmv'] ?? 0);
            $totalTickets += (int) $ticketStat;
            $totalCommission += (float) $commissionStat;
        }

        $currentCount = $currentRows->count();

        return [
            'total_customers' => $currentCount,
            'new_customers_in_period' => $currentRows->count(),
            'customers_with_orders' => $customersWithOrders,
            'customers_without_orders' => max(0, $currentCount - $customersWithOrders),
            'conversion_rate' => $currentCount > 0 ? round(($customersWithOrders / $currentCount) * 100, 2) : 0,
            'total_orders' => $totalOrders,
            'total_gmv' => round($totalGmv, 2),
            'total_commission_generated' => round($totalCommission, 2),
            'total_lucky_tickets' => $totalTickets,
            'customers_transferred_out' => $rows->where('status', AffiliateCustomer::STATUS_TRANSFERRED)->count(),
            'customers_transferred_in' => $rows->filter(fn (AffiliateCustomer $row) => $row->source_type === 'admin_transfer')->count(),
        ];
    }

    public function getAssignmentById(int $assignmentId): array
    {
        $this->ensureAffiliateCustomersTable();

        $assignment = AffiliateCustomer::query()
            ->with(['affiliate', 'customer', 'transferredToAffiliate'])
            ->findOrFail($assignmentId);

        $this->touchFirstOrderForMissingAssignment($assignment);

        return $this->enrichAssignments(collect([$assignment]))->first();
    }

    public function getAssignmentForAffiliateCustomer(int $affiliateId, int $customerId): array
    {
        $this->ensureAffiliateCustomersTable();

        $assignment = $this->findLatestAssignment($affiliateId, $customerId);

        if (!$assignment) {
            abort(404, 'Không tìm thấy khách hàng dưới Affiliate này.');
        }

        return $this->getAssignmentById((int) $assignment->id);
    }

    public function transferCustomer(int $affiliateId, int $customerId, int $newAffiliateId, string $reason, ?User $actor, ?Request $request = null): array
    {
        $this->ensureAffiliateCustomersTable();

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'transfer_reason' => 'Vui lòng nhập lý do chuyển khách.',
            ]);
        }

        if ($affiliateId === $newAffiliateId) {
            throw ValidationException::withMessages([
                'new_affiliate_id' => 'Affiliate đích phải khác Affiliate hiện tại.',
            ]);
        }

        $this->ensureApprovedAffiliate($newAffiliateId);

        return DB::transaction(function () use ($affiliateId, $customerId, $newAffiliateId, $reason, $actor, $request) {
            $assignment = $this->findCurrentAssignmentForUpdate($affiliateId, $customerId);
            $before = $this->formatAssignmentRecord($assignment);

            $assignment->status = AffiliateCustomer::STATUS_TRANSFERRED;
            $assignment->transferred_to_affiliate_id = $newAffiliateId;
            $assignment->transferred_at = now();
            $assignment->transfer_reason = $reason;
            $assignment->updated_by = $actor?->id;
            $assignment->save();

            $newAssignment = AffiliateCustomer::query()->create([
                'affiliate_id' => $newAffiliateId,
                'customer_id' => $assignment->customer_id,
                'source_type' => 'admin_transfer',
                'source_id' => (string) $assignment->id,
                'first_order_id' => $assignment->first_order_id,
                'assigned_at' => now(),
                'status' => AffiliateCustomer::STATUS_ACTIVE,
                'transferred_to_affiliate_id' => null,
                'transferred_at' => null,
                'transfer_reason' => $reason,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            $this->syncUserReferrer((int) $assignment->customer_id, $newAffiliateId);

            $after = $this->formatAssignmentRecord($newAssignment->fresh(['affiliate', 'customer', 'transferredToAffiliate']));
            $this->logAction(
                $actor,
                'affiliate_customer.transfer',
                (string) $assignment->id,
                $before,
                $after,
                $reason,
                $request,
            );

            return $after;
        });
    }

    public function removeCustomer(int $affiliateId, int $customerId, string $reason, ?User $actor, ?Request $request = null): array
    {
        $this->ensureAffiliateCustomersTable();

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'transfer_reason' => 'Vui lòng nhập lý do loại khách khỏi Affiliate.',
            ]);
        }

        return DB::transaction(function () use ($affiliateId, $customerId, $reason, $actor, $request) {
            $assignment = $this->findCurrentAssignmentForUpdate($affiliateId, $customerId);
            $before = $this->formatAssignmentRecord($assignment);

            $assignment->status = AffiliateCustomer::STATUS_REMOVED;
            $assignment->transferred_to_affiliate_id = null;
            $assignment->transferred_at = now();
            $assignment->transfer_reason = $reason;
            $assignment->updated_by = $actor?->id;
            $assignment->save();

            $this->syncUserReferrer((int) $assignment->customer_id, null);

            $after = $this->formatAssignmentRecord($assignment->fresh(['affiliate', 'customer', 'transferredToAffiliate']));
            $this->logAction(
                $actor,
                'affiliate_customer.remove',
                (string) $assignment->id,
                $before,
                $after,
                $reason,
                $request,
            );

            return $after;
        });
    }

    public function lockCustomer(int $affiliateId, int $customerId, string $reason, ?User $actor, ?Request $request = null): array
    {
        $this->ensureAffiliateCustomersTable();

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'transfer_reason' => 'Vui lòng nhập lý do khóa khách.',
            ]);
        }

        return DB::transaction(function () use ($affiliateId, $customerId, $reason, $actor, $request) {
            $assignment = $this->findCurrentAssignmentForUpdate($affiliateId, $customerId);
            $before = $this->formatAssignmentRecord($assignment);

            $assignment->status = AffiliateCustomer::STATUS_LOCKED;
            $assignment->transfer_reason = $reason;
            $assignment->updated_by = $actor?->id;
            $assignment->save();

            $after = $this->formatAssignmentRecord($assignment->fresh(['affiliate', 'customer', 'transferredToAffiliate']));
            $this->logAction(
                $actor,
                'affiliate_customer.lock',
                (string) $assignment->id,
                $before,
                $after,
                $reason,
                $request,
            );

            return $after;
        });
    }

    public function unlockCustomer(int $affiliateId, int $customerId, ?User $actor, ?Request $request = null): array
    {
        $this->ensureAffiliateCustomersTable();

        return DB::transaction(function () use ($affiliateId, $customerId, $actor, $request) {
            $assignment = AffiliateCustomer::query()
                ->where('affiliate_id', $affiliateId)
                ->where('customer_id', $customerId)
                ->where('status', AffiliateCustomer::STATUS_LOCKED)
                ->lockForUpdate()
                ->latest('assigned_at')
                ->latest('id')
                ->first();

            if (!$assignment) {
                throw ValidationException::withMessages([
                    'customer' => 'Không tìm thấy khách hàng đang bị khóa để mở khóa.',
                ]);
            }

            $before = $this->formatAssignmentRecord($assignment);
            $assignment->status = AffiliateCustomer::STATUS_ACTIVE;
            $assignment->updated_by = $actor?->id;
            $assignment->save();

            $after = $this->formatAssignmentRecord($assignment->fresh(['affiliate', 'customer', 'transferredToAffiliate']));
            $this->logAction(
                $actor,
                'affiliate_customer.unlock',
                (string) $assignment->id,
                $before,
                $after,
                null,
                $request,
            );

            return $after;
        });
    }

    public function directDownlineForAffiliate(int $affiliateId): Collection
    {
        $directUsers = User::query()
            ->where('referrer_id', $affiliateId)
            ->get(['id', 'name', 'full_name', 'personal_full_name', 'phone', 'avatar', 'created_at']);

        if (!Schema::hasTable('affiliate_customers')) {
            return $directUsers
                ->map(function (User $user) {
                    return [
                        'user_id' => (int) $user->id,
                        'name' => (string) ($user->personal_full_name ?? $user->full_name ?? $user->name ?? ''),
                        'phone' => $user->phone,
                        'avatar' => $user->avatar ?? null,
                        'level' => 'F1',
                        'personal_sales_completed' => 0.0,
                        'commission_paid' => 0.0,
                        'created_at' => optional($user->created_at)->toIso8601String(),
                        'joined_at' => optional($user->created_at)->toIso8601String(),
                        'activated_at' => optional($user->created_at)->toIso8601String(),
                        'activation_status' => AffiliateCustomer::STATUS_ACTIVE,
                        'status' => AffiliateCustomer::STATUS_ACTIVE,
                        'affiliate_status' => AffiliateCustomer::STATUS_ACTIVE,
                        'is_active' => true,
                        'is_activated' => true,
                        'activated' => true,
                        'ticket_count' => 0,
                        'total_tickets' => 0,
                        'tickets' => 0,
                    ];
                })
                ->values();
        }

        $directUserIds = $directUsers
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (empty($directUserIds)) {
            return collect();
        }

        $assignments = AffiliateCustomer::query()
            ->where('affiliate_id', $affiliateId)
            ->whereIn('customer_id', $directUserIds)
            ->where('status', AffiliateCustomer::STATUS_ACTIVE)
            ->get();

        $enrichedByCustomerId = $this->enrichAssignments($assignments)
            ->keyBy(fn (array $item) => (int) ($item['customer_id'] ?? 0));
        $orderStats = $this->buildOrderStatsMap($directUserIds);
        $ticketStats = $this->buildTicketStatsMap($directUserIds);
        $commissionStats = $this->buildCommissionStatsMap([$affiliateId], $directUserIds);

        return $directUsers
            ->map(function (User $user) use ($affiliateId, $commissionStats, $enrichedByCustomerId, $orderStats, $ticketStats) {
                $customerId = (int) $user->id;
                $item = $enrichedByCustomerId->get($customerId);
                $assignedAt = $item['assigned_at'] ?? optional($user->created_at)->toIso8601String();
                $status = $item['status'] ?? AffiliateCustomer::STATUS_ACTIVE;
                $orderStat = $orderStats[$customerId] ?? ['total_gmv' => 0];

                return [
                    'user_id' => $customerId,
                    'name' => (string) ($item['customer_name'] ?? $user->personal_full_name ?? $user->full_name ?? $user->name ?? ''),
                    'phone' => $item['phone'] ?? $user->phone,
                    'avatar' => $item['avatar'] ?? $user->avatar ?? null,
                    'level' => 'F1',
                    'personal_sales_completed' => (float) ($item['total_gmv'] ?? $orderStat['total_gmv'] ?? 0),
                    'commission_paid' => (float) ($item['total_commission_generated'] ?? $commissionStats[$this->pairKey($affiliateId, $customerId)] ?? 0),
                    'created_at' => $assignedAt,
                    'joined_at' => $assignedAt,
                    'activated_at' => $assignedAt,
                    'activation_status' => $status,
                    'status' => $status,
                    'affiliate_status' => $status,
                    'is_active' => $status === AffiliateCustomer::STATUS_ACTIVE,
                    'is_activated' => $status === AffiliateCustomer::STATUS_ACTIVE,
                    'activated' => $status === AffiliateCustomer::STATUS_ACTIVE,
                    'ticket_count' => (int) ($item['total_lucky_tickets'] ?? $ticketStats[$customerId] ?? 0),
                    'total_tickets' => (int) ($item['total_lucky_tickets'] ?? $ticketStats[$customerId] ?? 0),
                    'tickets' => (int) ($item['total_lucky_tickets'] ?? $ticketStats[$customerId] ?? 0),
                ];
            })
            ->values();
    }

    public function resolveComplaintAction(int $customerId, string $action, ?int $newReferrerId, int $actorId, ?string $reason = null): array
    {
        $this->ensureAffiliateCustomersTable();

        $currentAssignment = AffiliateCustomer::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', AffiliateCustomer::CURRENT_STATUSES)
            ->latest('assigned_at')
            ->latest('id')
            ->first();

        if (!$currentAssignment) {
            throw ValidationException::withMessages([
                'customer' => 'Khách hàng này hiện chưa có quan hệ Affiliate active để xử lý.',
            ]);
        }

        $actor = User::query()->find($actorId);
        $resolvedReason = trim((string) ($reason ?? 'Affiliate dispute approved by admin'));

        if ($action === 'detach') {
            return $this->removeCustomer((int) $currentAssignment->affiliate_id, $customerId, $resolvedReason, $actor);
        }

        if (!$newReferrerId) {
            throw ValidationException::withMessages([
                'new_referrer_id' => 'Cần chọn người giới thiệu mới.',
            ]);
        }

        return $this->transferCustomer((int) $currentAssignment->affiliate_id, $customerId, $newReferrerId, $resolvedReason, $actor);
    }

    private function buildAssignmentsQuery(array $filters = []): Builder
    {
        $this->ensureAffiliateCustomersTable();

        $query = AffiliateCustomer::query()
            ->with(['affiliate:id,name,full_name,phone', 'customer:id,name,full_name,phone,email,avatar,created_at,referrer_id', 'transferredToAffiliate:id,name,full_name,phone'])
            ->orderByDesc('assigned_at')
            ->orderByDesc('id');

        if (!empty($filters['affiliate_id'])) {
            $query->where('affiliate_id', (int) $filters['affiliate_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', strtoupper(trim((string) $filters['status'])));
        }

        if (!empty($filters['source_type'])) {
            $query->where('source_type', trim((string) $filters['source_type']));
        }

        if (array_key_exists('risk_flag', $filters) && $filters['risk_flag'] !== null && $filters['risk_flag'] !== '') {
            $wantsRisk = filter_var($filters['risk_flag'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($wantsRisk !== null) {
                $query->{$wantsRisk ? 'whereHas' : 'whereDoesntHave'}('customer.orders', function (Builder $orderQuery) {
                    $orderQuery->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), ['cancelled', 'canceled', 'returned', 'refunded']);
                });
            }
        }

        if (!empty($filters['assigned_from'])) {
            $query->whereDate('assigned_at', '>=', Carbon::parse((string) $filters['assigned_from'])->toDateString());
        }

        if (!empty($filters['assigned_to'])) {
            $query->whereDate('assigned_at', '<=', Carbon::parse((string) $filters['assigned_to'])->toDateString());
        }

        if (!empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);

            $query->where(function (Builder $nested) use ($keyword) {
                $nested
                    ->where('customer_id', 'like', '%' . $keyword . '%')
                    ->orWhere('first_order_id', 'like', '%' . $keyword . '%')
                    ->orWhereHas('customer', function (Builder $customerQuery) use ($keyword) {
                        $customerQuery
                            ->where('full_name', 'like', '%' . $keyword . '%')
                            ->orWhere('name', 'like', '%' . $keyword . '%')
                            ->orWhere('phone', 'like', '%' . $keyword . '%');
                    });
            });
        }

        return $query;
    }

    private function enrichAssignments(Collection $assignments): Collection
    {
        if ($assignments->isEmpty()) {
            return collect();
        }

        $this->hydrateMissingFirstOrders($assignments);

        $customerIds = $assignments->pluck('customer_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $affiliateIds = $assignments->pluck('affiliate_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $firstOrderIds = $assignments->pluck('first_order_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();

        $orderStats = $this->buildOrderStatsMap($customerIds);
        $ticketStats = $this->buildTicketStatsMap($customerIds);
        $commissionStats = $this->buildCommissionStatsMap($affiliateIds, $customerIds);
        $riskFlags = $this->buildRiskFlagMap($customerIds);
        $firstProductNames = $this->buildFirstProductNameMap($firstOrderIds);

        return $assignments->map(function (AffiliateCustomer $assignment) use ($orderStats, $ticketStats, $commissionStats, $riskFlags, $firstProductNames) {
            $formatted = $this->formatAssignmentRecord($assignment);
            $customerId = (int) $assignment->customer_id;
            $affiliateId = (int) $assignment->affiliate_id;
            $orderStat = $orderStats[$customerId] ?? ['total_valid_orders' => 0, 'total_gmv' => 0.0];

            $formatted['first_product_name'] = $firstProductNames[(string) $assignment->first_order_id] ?? null;
            $formatted['total_valid_orders'] = (int) ($orderStat['total_valid_orders'] ?? 0);
            $formatted['total_gmv'] = (float) ($orderStat['total_gmv'] ?? 0);
            $formatted['total_lucky_tickets'] = (int) ($ticketStats[$customerId] ?? 0);
            $formatted['total_commission_generated'] = (float) ($commissionStats[$this->pairKey($affiliateId, $customerId)] ?? 0);
            $formatted['risk_flag'] = (bool) ($riskFlags[$customerId] ?? false);

            return $formatted;
        })->values();
    }

    private function formatAssignmentRecord(AffiliateCustomer $assignment): array
    {
        $assignment->loadMissing(['affiliate', 'customer', 'transferredToAffiliate']);

        return [
            'assignment_id' => (int) $assignment->id,
            'affiliate_id' => (int) $assignment->affiliate_id,
            'affiliate_name' => $assignment->affiliate?->personal_full_name ?? $assignment->affiliate?->full_name ?? $assignment->affiliate?->name,
            'customer_id' => (int) $assignment->customer_id,
            'customer_name' => $assignment->customer?->personal_full_name ?? $assignment->customer?->full_name ?? $assignment->customer?->name,
            'phone' => $this->maskPhone($assignment->customer?->phone),
            'raw_phone' => $assignment->customer?->phone,
            'email' => $assignment->customer?->email,
            'avatar' => $assignment->customer?->avatar,
            'source_type' => $assignment->source_type,
            'source_id' => $assignment->source_id,
            'first_order_id' => $assignment->first_order_id,
            'assigned_at' => $assignment->assigned_at?->toIso8601String(),
            'status' => $assignment->status,
            'transferred_to_affiliate_id' => $assignment->transferred_to_affiliate_id,
            'transferred_to_affiliate_name' => $assignment->transferredToAffiliate?->personal_full_name ?? $assignment->transferredToAffiliate?->full_name ?? $assignment->transferredToAffiliate?->name,
            'transferred_at' => $assignment->transferred_at?->toIso8601String(),
            'transfer_reason' => $assignment->transfer_reason,
            'created_by' => $assignment->created_by,
            'updated_by' => $assignment->updated_by,
            'created_at' => $assignment->created_at?->toIso8601String(),
            'updated_at' => $assignment->updated_at?->toIso8601String(),
        ];
    }

    private function buildOrderStatsMap(array $customerIds): array
    {
        if (empty($customerIds)) {
            return [];
        }

        $rows = Order::query()
            ->whereIn('user_id', $customerIds)
            ->whereNotIn(DB::raw("LOWER(COALESCE(status, ''))"), ['cancelled', 'canceled', 'returned', 'refunded'])
            ->where(function ($q) {
                $q->whereNotNull('delivered_at')
                    ->orWhereRaw("LOWER(COALESCE(status_payment, '')) = 'paid'")
                    ->orWhereRaw("LOWER(COALESCE(status, '')) IN ('delivered', 'completed')");
            })
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as total_valid_orders, SUM(COALESCE(final_amount, total_amount, total, 0)) as total_gmv')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->user_id] = [
                'total_valid_orders' => (int) ($row->total_valid_orders ?? 0),
                'total_gmv' => (float) ($row->total_gmv ?? 0),
            ];
        }

        return $map;
    }

    private function buildTicketStatsMap(array $customerIds): array
    {
        if (empty($customerIds) || !Schema::hasTable('order_items')) {
            return [];
        }

        $rows = Order::query()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.user_id', $customerIds)
            ->whereNotIn(DB::raw("LOWER(COALESCE(orders.status, ''))"), ['cancelled', 'canceled', 'returned', 'refunded'])
            ->where(function ($q) {
                $q->whereNotNull('orders.delivered_at')
                    ->orWhereRaw("LOWER(COALESCE(orders.status_payment, '')) = 'paid'")
                    ->orWhereRaw("LOWER(COALESCE(orders.status, '')) IN ('delivered', 'completed')");
            })
            ->groupBy('orders.user_id')
            ->selectRaw('orders.user_id, SUM(COALESCE(order_items.ticket_reward_quantity, 0)) as total_lucky_tickets')
            ->pluck('total_lucky_tickets', 'orders.user_id');

        return $rows->mapWithKeys(fn ($value, $key) => [(int) $key => (int) $value])->all();
    }

    private function buildCommissionStatsMap(array $affiliateIds, array $customerIds): array
    {
        if (empty($affiliateIds) || empty($customerIds) || !Schema::hasTable('commission_users')) {
            return [];
        }

        $rows = CommissionUser::query()
            ->join('order_items', 'commission_users.order_item_id', '=', 'order_items.id')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('commission_users.user_id', $affiliateIds)
            ->whereIn('orders.user_id', $customerIds)
            ->groupBy('commission_users.user_id', 'orders.user_id')
            ->selectRaw('commission_users.user_id as affiliate_id, orders.user_id as customer_id, SUM(COALESCE(commission_users.amount, 0)) as total_commission')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$this->pairKey((int) $row->affiliate_id, (int) $row->customer_id)] = (float) ($row->total_commission ?? 0);
        }

        return $map;
    }

    private function buildRiskFlagMap(array $customerIds): array
    {
        if (empty($customerIds)) {
            return [];
        }

        $invalidOrders = Order::query()
            ->whereIn('user_id', $customerIds)
            ->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), ['cancelled', 'canceled', 'returned', 'refunded'])
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as invalid_orders')
            ->pluck('invalid_orders', 'user_id');

        return $invalidOrders
            ->mapWithKeys(fn ($count, $userId) => [(int) $userId => (int) $count > 0])
            ->all();
    }

    private function buildFirstProductNameMap(array $firstOrderIds): array
    {
        if (empty($firstOrderIds) || !Schema::hasTable('order_items')) {
            return [];
        }

        $hasItemName = Schema::hasColumn('order_items', 'item_name');
        $hasProductName = Schema::hasColumn('order_items', 'product_name');

        if (!$hasItemName && !$hasProductName) {
            return [];
        }

        $nameExpression = $hasItemName && $hasProductName
            ? "COALESCE(NULLIF(item_name, ''), NULLIF(product_name, ''), '')"
            : ($hasItemName ? "COALESCE(NULLIF(item_name, ''), '')" : "COALESCE(NULLIF(product_name, ''), '')");

        return OrderItem::query()
            ->whereIn('order_id', $firstOrderIds)
            ->groupBy('order_id')
            ->selectRaw("order_id, MIN({$nameExpression}) as first_product_name")
            ->pluck('first_product_name', 'order_id')
            ->mapWithKeys(fn ($value, $key) => [(string) $key => $value ?: null])
            ->all();
    }

    private function hydrateMissingFirstOrders(Collection $assignments): void
    {
        $customerIds = $assignments
            ->filter(fn (AffiliateCustomer $assignment) => empty($assignment->first_order_id))
            ->pluck('customer_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($customerIds)) {
            return;
        }

        $firstOrders = Order::query()
            ->whereIn('user_id', $customerIds)
            ->orderBy('created_at')
            ->get(['id', 'user_id', 'created_at'])
            ->groupBy('user_id')
            ->map(fn (Collection $rows) => $rows->first());

        foreach ($assignments as $assignment) {
            $firstOrder = $firstOrders->get((int) $assignment->customer_id);
            if (!$firstOrder) {
                continue;
            }

            $assignment->first_order_id = (string) $firstOrder->id;
            if (!$assignment->assigned_at) {
                $assignment->assigned_at = $firstOrder->created_at;
            }
        }
    }

    private function touchFirstOrderForMissingAssignment(AffiliateCustomer $assignment): void
    {
        if ($assignment->first_order_id && $assignment->assigned_at) {
            return;
        }

        $firstOrder = Order::query()
            ->where('user_id', $assignment->customer_id)
            ->orderBy('created_at')
            ->first(['id', 'created_at']);

        if (!$firstOrder) {
            return;
        }

        $assignment->first_order_id = (string) $firstOrder->id;
        if (!$assignment->assigned_at) {
            $assignment->assigned_at = $firstOrder->created_at;
        }
    }

    private function findLatestAssignment(int $affiliateId, int $customerId): ?AffiliateCustomer
    {
        return AffiliateCustomer::query()
            ->where('affiliate_id', $affiliateId)
            ->where('customer_id', $customerId)
            ->latest('assigned_at')
            ->latest('id')
            ->first();
    }

    private function findCurrentAssignmentForUpdate(int $affiliateId, int $customerId): AffiliateCustomer
    {
        $assignment = AffiliateCustomer::query()
            ->where('affiliate_id', $affiliateId)
            ->where('customer_id', $customerId)
            ->whereIn('status', AffiliateCustomer::CURRENT_STATUSES)
            ->lockForUpdate()
            ->latest('assigned_at')
            ->latest('id')
            ->first();

        if (!$assignment) {
            throw ValidationException::withMessages([
                'customer' => 'Không tìm thấy khách hàng active dưới Affiliate này.',
            ]);
        }

        return $assignment;
    }

    private function ensureApprovedAffiliate(int $affiliateUserId): void
    {
        $hasApprovedProfile = AffiliateProfile::query()
            ->where('user_id', $affiliateUserId)
            ->whereIn('status', AffiliateProfile::ACTIVE_STATUSES)
            ->exists();

        if (!$hasApprovedProfile) {
            throw ValidationException::withMessages([
                'new_affiliate_id' => 'Affiliate đích chưa có hồ sơ được duyệt.',
            ]);
        }
    }

    private function syncUserReferrer(int $customerId, ?int $affiliateId): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'referrer_id')) {
            return;
        }

        User::query()
            ->whereKey($customerId)
            ->update(['referrer_id' => $affiliateId]);
    }

    private function pairKey(int $affiliateId, int $customerId): string
    {
        return $affiliateId . ':' . $customerId;
    }

    private function paginateLegacyAssignments(array $filters, int $perPage): LengthAwarePaginator
    {
        $items = $this->buildLegacyAssignmentsCollection($filters)->values();
        $page = max(1, (int) request()->integer('page', 1));
        $total = $items->count();
        $pageItems = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $pageItems,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }

    private function buildLegacySummary(array $filters): array
    {
        $items = $this->buildLegacyAssignmentsCollection($filters);
        $totalCustomers = $items->count();
        $customersWithOrders = $items->where('total_valid_orders', '>', 0)->count();

        return [
            'total_customers' => $totalCustomers,
            'new_customers_in_period' => $totalCustomers,
            'customers_with_orders' => $customersWithOrders,
            'customers_without_orders' => max(0, $totalCustomers - $customersWithOrders),
            'conversion_rate' => $totalCustomers > 0 ? round(($customersWithOrders / $totalCustomers) * 100, 2) : 0,
            'total_orders' => (int) $items->sum('total_valid_orders'),
            'total_gmv' => round((float) $items->sum('total_gmv'), 2),
            'total_commission_generated' => round((float) $items->sum('total_commission_generated'), 2),
            'total_lucky_tickets' => (int) $items->sum('total_lucky_tickets'),
            'customers_transferred_out' => 0,
            'customers_transferred_in' => 0,
        ];
    }

    private function buildLegacyAssignmentsCollection(array $filters): Collection
    {
        $statusFilter = strtoupper(trim((string) ($filters['status'] ?? '')));
        if ($statusFilter !== '' && $statusFilter !== AffiliateCustomer::STATUS_ACTIVE) {
            return collect();
        }

        $sourceTypeFilter = trim((string) ($filters['source_type'] ?? ''));
        if ($sourceTypeFilter !== '' && stripos('legacy_referrer_backfill', $sourceTypeFilter) === false) {
            return collect();
        }

        $query = User::query()
            ->whereNotNull('referrer_id')
            ->get(['id', 'name', 'full_name', 'personal_full_name', 'phone', 'email', 'avatar', 'referrer_id', 'created_at']);

        if (!empty($filters['affiliate_id'])) {
            $affiliateId = (int) $filters['affiliate_id'];
            $query = $query->where('referrer_id', $affiliateId)->values();
        }

        $customerIds = $query->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $affiliateIds = $query->pluck('referrer_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $affiliates = User::query()
            ->whereIn('id', $affiliateIds)
            ->get(['id', 'name', 'full_name', 'personal_full_name', 'phone'])
            ->keyBy('id');

        $orderStats = $this->buildOrderStatsMap($customerIds);
        $ticketStats = $this->buildTicketStatsMap($customerIds);
        $commissionStats = $this->buildCommissionStatsMap($affiliateIds, $customerIds);
        $riskFlags = $this->buildRiskFlagMap($customerIds);
        $firstOrders = $this->buildFirstOrderMap($customerIds);
        $firstProductNames = $this->buildFirstProductNameMap(array_values(array_filter(array_map(
            fn ($order) => $order['first_order_id'] ?? null,
            $firstOrders
        ))));

        $items = $query->map(function (User $customer) use ($affiliates, $orderStats, $ticketStats, $commissionStats, $riskFlags, $firstOrders, $firstProductNames) {
            $affiliateId = (int) $customer->referrer_id;
            $affiliate = $affiliates->get($affiliateId);
            $customerId = (int) $customer->id;
            $orderStat = $orderStats[$customerId] ?? ['total_valid_orders' => 0, 'total_gmv' => 0.0];
            $firstOrder = $firstOrders[$customerId] ?? ['first_order_id' => null, 'assigned_at' => optional($customer->created_at)->toIso8601String()];
            $firstOrderId = $firstOrder['first_order_id'] ?? null;

            return [
                'assignment_id' => null,
                'can_view_detail' => false,
                'affiliate_id' => $affiliateId,
                'affiliate_name' => $affiliate?->personal_full_name ?? $affiliate?->full_name ?? $affiliate?->name,
                'customer_id' => $customerId,
                'customer_name' => $customer->personal_full_name ?? $customer->full_name ?? $customer->name,
                'phone' => $this->maskPhone($customer->phone),
                'raw_phone' => $customer->phone,
                'email' => $customer->email,
                'avatar' => $customer->avatar,
                'source_type' => 'legacy_referrer_backfill',
                'source_id' => (string) $affiliateId,
                'first_order_id' => $firstOrderId,
                'first_product_name' => $firstOrderId ? ($firstProductNames[(string) $firstOrderId] ?? null) : null,
                'assigned_at' => $firstOrder['assigned_at'] ?? optional($customer->created_at)->toIso8601String(),
                'status' => AffiliateCustomer::STATUS_ACTIVE,
                'transferred_to_affiliate_id' => null,
                'transferred_to_affiliate_name' => null,
                'transferred_at' => null,
                'transfer_reason' => null,
                'created_by' => null,
                'updated_by' => null,
                'created_at' => optional($customer->created_at)->toIso8601String(),
                'updated_at' => optional($customer->created_at)->toIso8601String(),
                'total_valid_orders' => (int) ($orderStat['total_valid_orders'] ?? 0),
                'total_gmv' => (float) ($orderStat['total_gmv'] ?? 0),
                'total_lucky_tickets' => (int) ($ticketStats[$customerId] ?? 0),
                'total_commission_generated' => (float) ($commissionStats[$this->pairKey($affiliateId, $customerId)] ?? 0),
                'risk_flag' => (bool) ($riskFlags[$customerId] ?? false),
            ];
        })->values();

        if (!empty($filters['assigned_from'])) {
            $from = Carbon::parse((string) $filters['assigned_from'])->startOfDay();
            $items = $items->filter(fn (array $item) => !empty($item['assigned_at']) && Carbon::parse((string) $item['assigned_at'])->greaterThanOrEqualTo($from))->values();
        }

        if (!empty($filters['assigned_to'])) {
            $to = Carbon::parse((string) $filters['assigned_to'])->endOfDay();
            $items = $items->filter(fn (array $item) => !empty($item['assigned_at']) && Carbon::parse((string) $item['assigned_at'])->lessThanOrEqualTo($to))->values();
        }

        if (array_key_exists('risk_flag', $filters) && $filters['risk_flag'] !== null && $filters['risk_flag'] !== '') {
            $wantsRisk = filter_var($filters['risk_flag'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($wantsRisk !== null) {
                $items = $items->filter(fn (array $item) => (bool) ($item['risk_flag'] ?? false) === $wantsRisk)->values();
            }
        }

        if (!empty($filters['keyword'])) {
            $keyword = mb_strtolower(trim((string) $filters['keyword']));
            $items = $items->filter(function (array $item) use ($keyword) {
                $haystacks = [
                    (string) ($item['customer_id'] ?? ''),
                    (string) ($item['first_order_id'] ?? ''),
                    mb_strtolower((string) ($item['customer_name'] ?? '')),
                    mb_strtolower((string) ($item['raw_phone'] ?? '')),
                ];

                foreach ($haystacks as $haystack) {
                    if ($haystack !== '' && str_contains($haystack, $keyword)) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        return $items->sortByDesc(function (array $item) {
            return $item['assigned_at'] ?? $item['created_at'] ?? '';
        })->values();
    }

    private function buildFirstOrderMap(array $customerIds): array
    {
        if (empty($customerIds)) {
            return [];
        }

        return Order::query()
            ->whereIn('user_id', $customerIds)
            ->orderBy('created_at')
            ->get(['id', 'user_id', 'created_at'])
            ->groupBy('user_id')
            ->mapWithKeys(function (Collection $rows, $userId) {
                $first = $rows->first();

                return [
                    (int) $userId => [
                        'first_order_id' => $first ? (string) $first->id : null,
                        'assigned_at' => $first?->created_at?->toIso8601String(),
                    ],
                ];
            })
            ->all();
    }

    private function maskPhone(?string $phone): ?string
    {
        $normalized = trim((string) $phone);
        if ($normalized === '') {
            return null;
        }

        $length = strlen($normalized);
        if ($length <= 4) {
            return $normalized;
        }

        return substr($normalized, 0, min(3, $length)) . str_repeat('*', max($length - 6, 1)) . substr($normalized, -3);
    }

    private function logAction(
        ?User $actor,
        string $action,
        string $objectId,
        ?array $oldValue,
        ?array $newValue,
        ?string $reason,
        ?Request $request = null,
    ): void {
        if (!Schema::hasTable('affiliate_audit_logs')) {
            return;
        }

        try {
            AffiliateAuditLog::query()->create([
                'actor_id' => $actor?->id,
                'actor_role' => $actor?->role ?? 'system',
                'action' => $action,
                'object_type' => 'AffiliateCustomer',
                'object_id' => $objectId,
                'old_value_json' => $oldValue,
                'new_value_json' => $newValue,
                'reason' => $reason,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);
        } catch (\Throwable $exception) {
            // ignore on environments without migrated audit log support
        }
    }

    private function findCurrentAssignmentForCustomer(int $customerId): ?AffiliateCustomer
    {
        return AffiliateCustomer::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', AffiliateCustomer::CURRENT_STATUSES)
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->first();
    }

    private function normalizeRegistrationSourceType($value): string
    {
        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['affiliate_qr', 'qr', 'qrcode', 'registration_affiliate_qr'], true)) {
            return 'registration_affiliate_qr';
        }

        return 'registration_affiliate_link';
    }

    private function hasAffiliateCustomersTable(): bool
    {
        return Schema::hasTable('affiliate_customers');
    }

    private function ensureAffiliateCustomersTable(): void
    {
        if ($this->hasAffiliateCustomersTable()) {
            return;
        }

        throw ValidationException::withMessages([
            'affiliate_customers' => 'Bảng affiliate_customers chưa được tạo. Vui lòng chạy migration mới trước khi dùng phần 7.',
        ]);
    }
}

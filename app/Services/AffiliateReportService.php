<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use RuntimeException;

class AffiliateReportService
{
    public function __construct(
        private readonly AffiliateCustomerService $affiliateCustomerService,
    ) {
    }

    public function paginateReports(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->buildReportsQuery($filters);
        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items());
        $mapped = $this->transformReports($items);

        return new LengthAwarePaginator(
            $mapped,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]
        );
    }

    public function changeUpline(Complaint $complaint, string $action, ?int $newReferrerId, int $actorId): array
    {
        return DB::transaction(function () use ($complaint, $action, $newReferrerId, $actorId) {
            $lockedComplaint = Complaint::query()->lockForUpdate()->find($complaint->id);

            if (! $lockedComplaint) {
                throw new RuntimeException('Không tìm thấy báo cáo cần xử lý.');
            }

            if ((string) $lockedComplaint->complaint_type !== 'affiliate_dispute') {
                throw new RuntimeException('Bản ghi này không thuộc luồng affiliate dispute.');
            }

            if (in_array((string) $lockedComplaint->status, ['resolved', 'closed'], true)) {
                throw new RuntimeException('Báo cáo này đã được xử lý trước đó.');
            }

            $targetUserId = $this->extractUserId($lockedComplaint->user_id);
            if (! $targetUserId) {
                throw new RuntimeException('Không xác định được F2 từ báo cáo này.');
            }

            $targetUser = User::query()->lockForUpdate()->find($targetUserId);
            if (! $targetUser) {
                throw new RuntimeException('Không tìm thấy F2 trong hệ thống.');
            }

            $oldReferrerId = $this->extractUserId($targetUser->referrer_id);
            $previousReferrer = $oldReferrerId ? User::query()->find($oldReferrerId) : null;

            $newReferrer = $newReferrerId ? User::query()->lockForUpdate()->find($newReferrerId) : null;
            if ($action === 'transfer' && ! $newReferrer) {
                throw new RuntimeException('Người giới thiệu mới không tồn tại.');
            }

            $customerAction = $this->affiliateCustomerService->resolveComplaintAction(
                $targetUserId,
                $action,
                $newReferrerId,
                $actorId,
                'Affiliate dispute approved from complaint #' . $lockedComplaint->id,
            );

            $noteParts = [
                'Affiliate dispute approved by admin #' . $actorId,
                'Action: ' . $action,
                'F2: ' . $targetUserId,
                'Old referrer: ' . ($oldReferrerId ?? 'null'),
                'New referrer: ' . ($newReferrerId ?? 'null'),
                'Assignment: ' . ($customerAction['assignment_id'] ?? 'n/a'),
            ];

            $lockedComplaint->status = 'resolved';
            $lockedComplaint->resolved_by = (string) $actorId;
            $lockedComplaint->resolved_at = now();
            $lockedComplaint->resolution_note = implode("\n", $noteParts);
            $lockedComplaint->save();

            return [
                'complaint_id' => (string) $lockedComplaint->id,
                'complaint_code' => (string) $lockedComplaint->complaint_code,
                'action' => $action,
                'f2_user_id' => $targetUserId,
                'old_referrer_id' => $oldReferrerId,
                'old_referrer_name' => $previousReferrer?->full_name ?? $previousReferrer?->name,
                'new_referrer_id' => $newReferrerId,
                'new_referrer_name' => $newReferrer?->full_name ?? $newReferrer?->name,
                'customer_assignment' => $customerAction,
            ];
        });
    }

    public function buildReportsQuery(array $filters): Builder
    {
        $query = Complaint::query()
            ->where('complaint_type', 'affiliate_dispute')
            ->with(['user:id,name,full_name,phone,role,referrer_id,avatar'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function (Builder $sub) use ($keyword) {
                $sub->where('complaint_code', 'like', '%' . $keyword . '%')
                    ->orWhere('title', 'like', '%' . $keyword . '%')
                    ->orWhere('description', 'like', '%' . $keyword . '%')
                    ->orWhere('user_id', 'like', '%' . $keyword . '%');
            });
        }

        return $query;
    }

    public function transformReports(Collection $reports): Collection
    {
        $reportedUplineIds = $reports
            ->map(fn (Complaint $complaint) => $this->extractReportedUplineId($complaint->description))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $f2Ids = $reports
            ->map(fn (Complaint $complaint) => $this->extractUserId($complaint->user_id))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $currentReferrerIds = User::query()
            ->whereIn('id', $f2Ids->all())
            ->pluck('referrer_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $allIds = $f2Ids
            ->merge($reportedUplineIds)
            ->merge($currentReferrerIds)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $usersById = User::query()
            ->whereIn('id', $allIds->all())
            ->get(['id', 'name', 'full_name', 'phone', 'avatar', 'referrer_id', 'role'])
            ->keyBy(fn (User $user) => (int) $user->id);

        return $reports->map(function (Complaint $complaint) use ($usersById) {
            $f2UserId = $this->extractUserId($complaint->user_id);
            $reportedUplineId = $this->extractReportedUplineId($complaint->description);

            $f2User = $f2UserId ? $usersById->get($f2UserId) : null;
            $reportedUpline = $reportedUplineId ? $usersById->get($reportedUplineId) : null;
            $currentUplineId = $f2User ? $this->extractUserId($f2User->referrer_id) : null;
            $currentUpline = $currentUplineId ? $usersById->get($currentUplineId) : null;

            return [
                'complaint_id' => (string) $complaint->id,
                'complaint_code' => (string) $complaint->complaint_code,
                'status' => (string) $complaint->status,
                'severity' => (string) $complaint->severity,
                'title' => (string) ($complaint->title ?? 'Báo cáo người giới thiệu'),
                'description' => (string) ($complaint->description ?? ''),
                'created_at' => $complaint->created_at?->format('Y-m-d H:i:s'),
                'resolved_at' => $complaint->resolved_at?->format('Y-m-d H:i:s'),
                'resolution_note' => (string) ($complaint->resolution_note ?? ''),
                'f2_user' => $this->summarizeUser($f2UserId, $f2User),
                'reported_upline' => $this->summarizeUser($reportedUplineId, $reportedUpline),
                'current_upline' => $this->summarizeUser($currentUplineId, $currentUpline),
                'raw_complaint' => $complaint,
            ];
        })->values();
    }

    private function summarizeUser(?int $userId, ?User $user): array
    {
        if (! $userId) {
            return [
                'id' => null,
                'name' => null,
                'phone' => null,
                'avatar' => null,
                'role' => null,
            ];
        }

        return [
            'id' => $userId,
            'name' => $user?->full_name ?? $user?->name,
            'phone' => $user?->phone,
            'avatar' => $user?->avatar,
            'role' => $user?->role,
        ];
    }

    private function extractReportedUplineId(?string $description): ?int
    {
        if (! $description) {
            return null;
        }

        if (preg_match('/Upline user_id:\s*(\d+)/i', $description, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function extractUserId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}

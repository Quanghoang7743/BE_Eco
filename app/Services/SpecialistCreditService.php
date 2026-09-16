<?php

namespace App\Services;

use App\Models\Specialist;
use App\Models\SpecialistCreditLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SpecialistCreditService
{
    public function __construct(
        private readonly SpecialistNotificationService $notificationService
    ) {
    }

    public function actionLabels(): array
    {
        return [
            SpecialistCreditLog::ACTION_PLUS => 'Cộng điểm',
            SpecialistCreditLog::ACTION_MINUS => 'Trừ điểm',
            SpecialistCreditLog::ACTION_ADJUST => 'Điều chỉnh điểm',
            SpecialistCreditLog::ACTION_CANCEL => 'Hủy đánh giá / hoàn điểm',
        ];
    }

    public function statusLabels(): array
    {
        return [
            SpecialistCreditLog::STATUS_ACTIVE => 'Đang áp dụng',
            SpecialistCreditLog::STATUS_CANCELLED => 'Đã hủy',
        ];
    }

    public function scoreLevelLabels(): array
    {
        return [
            'good' => 'Tốt - 100 điểm',
            'fair' => 'Khá - 80 đến 99 điểm',
            'medium' => 'Trung bình - 60 đến 79 điểm',
            'low' => 'Cần cải thiện - dưới 60 điểm',
        ];
    }

    public function rankLabel(int $points): string
    {
        return match (true) {
            $points >= 100 => 'Tốt',
            $points >= 80 => 'Khá',
            $points >= 60 => 'Trung bình',
            default => 'Cần cải thiện',
        };
    }

    public function rankBadgeClass(int $points): string
    {
        return match (true) {
            $points >= 100 => 'bg-success',
            $points >= 80 => 'bg-primary',
            $points >= 60 => 'bg-warning text-dark',
            default => 'bg-danger',
        };
    }

    public function profileForUser(User $user): Specialist
    {
        return Specialist::query()->firstOrCreate(
            ['user_id' => (int) $user->id],
            ['credit_points' => 100]
        );
    }

    public function specialistOptions(): Collection
    {
        $this->ensureSaleProfiles();

        return Specialist::query()
            ->with('user:id,name,full_name,email,phone,role,status')
            ->whereHas('user', fn ($query) => $query->where('role', 'sale'))
            ->orderBy('id')
            ->get()
            ->sortBy(fn (Specialist $specialist): string => $this->displayName($specialist))
            ->values();
    }

    public function regionOptions(): Collection
    {
        if (
            ! Schema::hasTable('stores')
            || ! Schema::hasColumn('stores', 'region_id')
        ) {
            return collect();
        }

        return DB::table('stores')
            ->whereNotNull('region_id')
            ->select('region_id')
            ->distinct()
            ->orderBy('region_id')
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->region_id,
                'label' => 'Khu vực ' . (int) $row->region_id,
            ]);
    }

    public function adminProfiles(array $filters): LengthAwarePaginator
    {
        $this->ensureSaleProfiles();

        $query = Specialist::query()
            ->with(['user:id,name,full_name,email,phone,role,status'])
            ->whereHas('user', fn ($userQuery) => $userQuery->where('role', 'sale'))
            ->withCount([
                'creditLogs as active_credit_logs_count' => fn ($logQuery) => $logQuery->where('status', SpecialistCreditLog::STATUS_ACTIVE),
                'creditLogs as cancelled_credit_logs_count' => fn ($logQuery) => $logQuery->where('status', SpecialistCreditLog::STATUS_CANCELLED),
            ])
            ->orderByDesc('credit_points')
            ->orderBy('id');

        if (! empty($filters['specialist_id'])) {
            $query->whereKey((int) $filters['specialist_id']);
        }

        if (! empty($filters['region_id'])) {
            $this->applyRegionFilter($query, (int) $filters['region_id']);
        }

        if (! empty($filters['user_status']) && $filters['user_status'] !== 'all' && Schema::hasColumn('users', 'status')) {
            $query->whereHas('user', fn ($userQuery) => $userQuery->where('status', $filters['user_status']));
        }

        if (! empty($filters['log_status']) && $filters['log_status'] !== 'all') {
            if ($filters['log_status'] === 'none') {
                $query->doesntHave('creditLogs');
            } else {
                $query->whereHas('creditLogs', fn ($logQuery) => $logQuery->where('status', $filters['log_status']));
            }
        }

        if (! empty($filters['score_level']) && $filters['score_level'] !== 'all') {
            $this->applyScoreLevelFilter($query, (string) $filters['score_level']);
        }

        $paginator = $query->paginate(20)->appends($filters);
        $paginator->getCollection()->each(function (Specialist $specialist): void {
            $specialist->setAttribute('region_labels', $this->regionLabelsForUser((int) $specialist->user_id));
            $specialist->setRelation('latestCreditLog', $this->latestLogForSpecialist((int) $specialist->id));
        });

        return $paginator;
    }

    public function history(Specialist $specialist, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $specialist->creditLogs()
            ->with([
                'adminUser:id,name,full_name,email',
                'canceller:id,name,full_name,email',
                'sourceLog:id,action_type,points_change',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($filters['log_status']) && $filters['log_status'] !== 'all') {
            $query->where('status', $filters['log_status']);
        }

        if (! empty($filters['action_type']) && $filters['action_type'] !== 'all') {
            $query->where('action_type', $filters['action_type']);
        }

        return $query->paginate($perPage)->appends($filters);
    }

    public function evaluate(Specialist $specialist, string $actionType, int $points, string $reason, int $actorId): SpecialistCreditLog
    {
        $actionType = strtoupper(trim($actionType));
        $reason = trim($reason);

        if (! in_array($actionType, [
            SpecialistCreditLog::ACTION_PLUS,
            SpecialistCreditLog::ACTION_MINUS,
            SpecialistCreditLog::ACTION_ADJUST,
        ], true)) {
            throw ValidationException::withMessages(['action_type' => 'Loại đánh giá không hợp lệ.']);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Vui lòng nhập lý do thay đổi điểm.']);
        }

        return DB::transaction(function () use ($specialist, $actionType, $points, $reason, $actorId): SpecialistCreditLog {
            $locked = Specialist::query()->whereKey($specialist->id)->lockForUpdate()->firstOrFail();
            $before = $this->normalizedPoints($locked->credit_points);
            $after = match ($actionType) {
                SpecialistCreditLog::ACTION_PLUS => $before + $points,
                SpecialistCreditLog::ACTION_MINUS => $before - $points,
                SpecialistCreditLog::ACTION_ADJUST => $points,
            };

            $this->assertValidPointChange($actionType, $points, $before, $after);
            $change = $after - $before;

            if ($change === 0) {
                throw ValidationException::withMessages(['points' => 'Điểm sau đánh giá phải khác điểm hiện tại.']);
            }

            $locked->forceFill(['credit_points' => $after])->save();

            $log = SpecialistCreditLog::query()->create([
                'specialist_id' => (int) $locked->id,
                'admin_user_id' => $actorId,
                'action_type' => $actionType,
                'points_change' => $change,
                'points_before' => $before,
                'points_after' => $after,
                'reason' => $reason,
                'status' => SpecialistCreditLog::STATUS_ACTIVE,
            ]);

            $this->notifyCreditChanged($locked->fresh('user'), $log);

            return $log->fresh(['specialist.user', 'adminUser']);
        });
    }

    public function cancelLog(SpecialistCreditLog $log, string $reason, int $actorId): SpecialistCreditLog
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['cancel_reason' => 'Vui lòng nhập lý do hủy đánh giá.']);
        }

        return DB::transaction(function () use ($log, $reason, $actorId): SpecialistCreditLog {
            $lockedLog = SpecialistCreditLog::query()->whereKey($log->id)->lockForUpdate()->firstOrFail();

            if ((string) $lockedLog->status !== SpecialistCreditLog::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['cancel_reason' => 'Đánh giá này đã bị hủy trước đó.']);
            }

            if ((string) $lockedLog->action_type === SpecialistCreditLog::ACTION_CANCEL) {
                throw ValidationException::withMessages(['cancel_reason' => 'Không thể hủy log hoàn điểm.']);
            }

            $specialist = Specialist::query()->whereKey($lockedLog->specialist_id)->lockForUpdate()->firstOrFail();
            $before = $this->normalizedPoints($specialist->credit_points);
            $change = -1 * (int) $lockedLog->points_change;
            $after = $before + $change;

            if ($after < 0 || $after > 100) {
                throw ValidationException::withMessages([
                    'cancel_reason' => 'Không thể hủy vì điểm hoàn lại sẽ vượt ngoài khoảng 0-100. Hãy tạo điều chỉnh điểm thủ công.',
                ]);
            }

            $lockedLog->forceFill([
                'status' => SpecialistCreditLog::STATUS_CANCELLED,
                'cancelled_by' => $actorId,
                'cancelled_at' => now('Asia/Ho_Chi_Minh'),
                'cancel_reason' => $reason,
            ])->save();

            $specialist->forceFill(['credit_points' => $after])->save();

            $cancelLog = SpecialistCreditLog::query()->create([
                'specialist_id' => (int) $specialist->id,
                'admin_user_id' => $actorId,
                'action_type' => SpecialistCreditLog::ACTION_CANCEL,
                'points_change' => $change,
                'points_before' => $before,
                'points_after' => $after,
                'reason' => 'Hủy đánh giá #' . $lockedLog->id . ': ' . $reason,
                'status' => SpecialistCreditLog::STATUS_ACTIVE,
                'source_log_id' => (int) $lockedLog->id,
            ]);

            $this->notifyCreditChanged($specialist->fresh('user'), $cancelLog);

            return $cancelLog->fresh(['specialist.user', 'adminUser', 'sourceLog']);
        });
    }

    public function displayName(Specialist $specialist): string
    {
        $user = $specialist->user;

        return $user ? ($user->full_name ?: ($user->name ?: ($user->email ?: 'N/A'))) : 'N/A';
    }

    private function ensureSaleProfiles(): void
    {
        if (! Schema::hasTable('specialists')) {
            return;
        }

        $now = now();
        DB::table('users')
            ->where('role', 'sale')
            ->orderBy('id')
            ->select('id')
            ->chunkById(200, function ($users) use ($now): void {
                $rows = $users->map(static fn ($user): array => [
                    'user_id' => (int) $user->id,
                    'credit_points' => 100,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('specialists')->insertOrIgnore($rows);
                }
            });
    }

    private function applyScoreLevelFilter($query, string $level): void
    {
        match ($level) {
            'good' => $query->where('credit_points', 100),
            'fair' => $query->whereBetween('credit_points', [80, 99]),
            'medium' => $query->whereBetween('credit_points', [60, 79]),
            'low' => $query->where('credit_points', '<', 60),
            default => null,
        };
    }

    private function applyRegionFilter($query, int $regionId): void
    {
        if (
            ! Schema::hasTable('stores')
            || ! Schema::hasColumn('stores', 'region_id')
        ) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($regionScope) use ($regionId): void {
            if (
                Schema::hasTable('specialist_store_assignments')
                && Schema::hasColumn('specialist_store_assignments', 'specialist_id')
                && Schema::hasColumn('specialist_store_assignments', 'store_id')
            ) {
                $regionScope->whereExists(function ($exists) use ($regionId): void {
                    $exists->selectRaw('1')
                        ->from('specialist_store_assignments as ssa')
                        ->join('stores as assigned_store', 'assigned_store.id', '=', 'ssa.store_id')
                        ->whereColumn('ssa.specialist_id', 'specialists.user_id')
                        ->where('assigned_store.region_id', $regionId);
                });
            }

            if (Schema::hasColumn('stores', 'sale_id')) {
                $regionScope->orWhereExists(function ($exists) use ($regionId): void {
                    $exists->selectRaw('1')
                        ->from('stores as sale_store')
                        ->whereColumn('sale_store.sale_id', 'specialists.user_id')
                        ->where('sale_store.region_id', $regionId);
                });
            }
        });
    }

    private function latestLogForSpecialist(int $specialistId): ?SpecialistCreditLog
    {
        return SpecialistCreditLog::query()
            ->where('specialist_id', $specialistId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function regionLabelsForUser(int $userId): array
    {
        if (
            ! Schema::hasTable('stores')
            || ! Schema::hasColumn('stores', 'region_id')
        ) {
            return [];
        }

        $query = DB::table('stores')->whereNotNull('region_id');
        $query->where(function ($scope) use ($userId): void {
            if (Schema::hasColumn('stores', 'sale_id')) {
                $scope->where('sale_id', $userId);
            }

            if (
                Schema::hasTable('specialist_store_assignments')
                && Schema::hasColumn('specialist_store_assignments', 'specialist_id')
                && Schema::hasColumn('specialist_store_assignments', 'store_id')
            ) {
                $scope->orWhereExists(function ($exists) use ($userId): void {
                    $exists->selectRaw('1')
                        ->from('specialist_store_assignments as ssa')
                        ->where('ssa.specialist_id', $userId)
                        ->whereColumn('ssa.store_id', 'stores.id');
                });
            }
        });

        return $query->distinct()
            ->orderBy('region_id')
            ->pluck('region_id')
            ->map(static fn ($id): string => 'Khu vực ' . (int) $id)
            ->all();
    }

    private function normalizedPoints(mixed $points): int
    {
        return max(0, min(100, (int) ($points ?? 100)));
    }

    private function assertValidPointChange(string $actionType, int $points, int $before, int $after): void
    {
        if ($actionType === SpecialistCreditLog::ACTION_ADJUST) {
            if ($points < 0 || $points > 100) {
                throw ValidationException::withMessages(['points' => 'Điểm điều chỉnh phải nằm trong khoảng 0-100.']);
            }
        } elseif ($points <= 0) {
            throw ValidationException::withMessages(['points' => 'Số điểm cộng/trừ phải lớn hơn 0.']);
        }

        if ($after > 100) {
            throw ValidationException::withMessages(['points' => 'Không được cộng vượt quá 100 điểm.']);
        }

        if ($after < 0) {
            throw ValidationException::withMessages(['points' => 'Không được trừ xuống dưới 0 điểm.']);
        }
    }

    private function notifyCreditChanged(?Specialist $specialist, SpecialistCreditLog $log): void
    {
        if (! $specialist || ! $specialist->user_id) {
            return;
        }

        $changeText = $log->points_change > 0
            ? '+' . $log->points_change
            : (string) $log->points_change;
        $rank = $this->rankLabel((int) $log->points_after);

        $this->notificationService->create([
            'specialist_id' => (int) $specialist->user_id,
            'type' => SpecialistNotificationService::TYPE_CREDIT_CHANGED,
            'title' => 'Điểm tín dụng chuyên viên thay đổi',
            'message' => 'Điểm tín dụng của bạn thay đổi ' . $changeText . ' điểm, hiện tại '
                . $log->points_after . '/100 - ' . $rank . '.',
            'data' => [
                'specialist_profile_id' => (int) $specialist->id,
                'credit_log_id' => (int) $log->id,
                'action_type' => (string) $log->action_type,
                'points_before' => (int) $log->points_before,
                'points_after' => (int) $log->points_after,
                'points_change' => (int) $log->points_change,
                'rank' => $rank,
            ],
            'action_url' => Route::has('admin.specialist-credit.show')
                ? route('admin.specialist-credit.show', ['specialist' => $specialist->id], false)
                : null,
            'event_key' => 'credit_points:' . $specialist->user_id . ':' . $log->id,
        ]);
    }
}

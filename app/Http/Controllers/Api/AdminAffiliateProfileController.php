<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AffiliateProfile;
use App\Models\User;
use App\Services\AffiliateProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AdminAffiliateProfileController extends Controller
{
    public function __construct(
        private readonly AffiliateProfileService $affiliateProfileService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        return $this->respondWithProfiles($request, false);
    }

    public function requests(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        return $this->respondWithProfiles($request, true);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $profile = AffiliateProfile::query()
            ->with(['user.bankAccount.bank', 'approvedBy', 'rejectedBy'])
            ->findOrFail($id);

        $checklist = $this->affiliateProfileService->buildApprovalChecklist($profile);

        $this->affiliateProfileService->logAction(
            $request->user(),
            'affiliate_profile.view_sensitive',
            'AffiliateProfile',
            (string) $profile->id,
            null,
            ['status' => $profile->status],
            'Viewed affiliate profile details',
            $request,
        );

        return response()->json([
            'success' => true,
            'data' => $this->transformProfileForList($profile) + [
                'profile' => $this->affiliateProfileService->formatProfile($profile),
                'bank_account' => $this->affiliateProfileService->formatBankAccount($profile->user?->bankAccount),
                'approval_checklist' => $checklist,
            ],
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $status = (string) $request->input('status', 'approved');

        if ($status === 'rejected') {
            return $this->reject($request, $id);
        }

        return $this->performTransition($request, $id, 'approve');
    }

    public function startReview(Request $request, int $id): JsonResponse
    {
        return $this->performTransition($request, $id, 'start_review');
    }

    public function requestMoreInfo(Request $request, int $id): JsonResponse
    {
        return $this->performTransition($request, $id, 'request_more_info');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->performTransition($request, $id, 'reject');
    }

    public function suspend(Request $request, int $id): JsonResponse
    {
        return $this->performTransition($request, $id, 'suspend');
    }

    public function lock(Request $request, int $id): JsonResponse
    {
        return $this->performTransition($request, $id, 'lock');
    }

    public function unlock(Request $request, int $id): JsonResponse
    {
        return $this->performTransition($request, $id, 'unlock');
    }

    public function terminate(Request $request, int $id): JsonResponse
    {
        return $this->performTransition($request, $id, 'terminate');
    }

    private function respondWithProfiles(Request $request, bool $reviewQueueOnly): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'submitted_from' => ['nullable', 'date'],
            'submitted_to' => ['nullable', 'date'],
        ]);

        $query = AffiliateProfile::query()
            ->with(['user.bankAccount.bank'])
            ->orderByDesc($this->submittedDateColumn())
            ->orderByDesc('created_at');

        if ($reviewQueueOnly) {
            $query->whereIn('status', AffiliateProfile::REVIEWABLE_STATUSES);
        }

        if (! empty($validated['status'])) {
            $this->applyStatusFilter($query, (string) $validated['status']);
        }

        if (! empty($validated['submitted_from'])) {
            $query->whereDate($this->submittedDateColumn(), '>=', $validated['submitted_from']);
        }

        if (! empty($validated['submitted_to'])) {
            $query->whereDate($this->submittedDateColumn(), '<=', $validated['submitted_to']);
        }

        if (! empty($validated['keyword'])) {
            $keyword = trim((string) $validated['keyword']);
            $query->where(function ($nested) use ($keyword) {
                $nested
                    ->where('id_number', 'like', '%' . $keyword . '%')
                    ->orWhere('personal_tax_code', 'like', '%' . $keyword . '%')
                    ->orWhereHas('user', function ($userQuery) use ($keyword) {
                        $userQuery
                            ->where('full_name', 'like', '%' . $keyword . '%')
                            ->orWhere('name', 'like', '%' . $keyword . '%')
                            ->orWhere('phone', 'like', '%' . $keyword . '%');
                    });
            });
        }

        $profiles = $query
            ->paginate((int) ($validated['per_page'] ?? 20));

        $data = $profiles->getCollection()
            ->map(fn (AffiliateProfile $profile) => $this->transformProfileForList($profile))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $profiles->currentPage(),
                'last_page' => $profiles->lastPage(),
                'per_page' => $profiles->perPage(),
                'total' => $profiles->total(),
            ],
        ]);
    }

    private function performTransition(Request $request, int $id, string $action): JsonResponse
    {
        $this->ensureSuperAdmin($request);

        $validated = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
            'need_more_info_reason' => ['nullable', 'string', 'max:2000'],
            'suspended_reason' => ['nullable', 'string', 'max:2000'],
            'terminated_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $profile = AffiliateProfile::query()
            ->with(['user.bankAccount.bank'])
            ->findOrFail($id);

        $profile = $this->affiliateProfileService->transition(
            $profile,
            $action,
            $validated,
            $request->user(),
            $request,
        );
        $this->sendStatusNotification($profile, $action, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Đã cập nhật hồ sơ Affiliate.',
            'data' => [
                'id' => $profile->id,
                'status' => $profile->status,
                'profile' => $this->affiliateProfileService->formatProfile($profile),
            ],
        ]);
    }

    private function transformProfileForList(AffiliateProfile $profile): array
    {
        $user = $profile->user;
        $bank = $user?->bankAccount;
        $formattedProfile = $this->affiliateProfileService->formatProfile($profile);

        return [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'status' => $profile->status,
            'rejection_reason' => $profile->rejection_reason,
            'need_more_info_reason' => $formattedProfile['need_more_info_reason'],
            'affiliate_code' => $formattedProfile['affiliate_code'],
            'id_number' => $profile->id_number,
            'masked_id_number' => $this->maskValue($profile->id_number, 3, 2),
            'personal_tax_code' => $profile->personal_tax_code,
            'masked_personal_tax_code' => $this->maskValue($profile->personal_tax_code, 2, 2),
            'created_at' => $profile->created_at,
            'submitted_at' => $formattedProfile['submitted_at'] ?? $profile->created_at,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $formattedProfile['full_name'] ?? $user->full_name ?? $user->name,
                'full_name' => $formattedProfile['full_name'] ?? $user->full_name,
                'phone' => $user->phone,
                'email' => $user->email,
                'role' => $user->role,
            ] : null,
            'bank_account' => $bank ? [
                'account_name' => $bank->account_name,
                'account_number' => $bank->account_number,
                'masked_account_number' => $this->maskValue($bank->account_number, 2, 2),
                'bank_name' => $bank->bank_name ?? $bank->bank?->name,
            ] : null,
        ];
    }

    private function sendStatusNotification(AffiliateProfile $profile, string $action, array $payload): void
    {
        $messageMap = [
            'start_review' => ['message' => 'Hồ sơ Affiliate của bạn đang được kiểm tra.', 'type' => 'affiliate_profile_under_review'],
            'request_more_info' => ['message' => 'Hồ sơ Affiliate cần bổ sung thông tin.', 'type' => 'affiliate_profile_need_more_info'],
            'approve' => ['message' => 'Hồ sơ Affiliate của bạn đã được duyệt.', 'type' => 'affiliate_profile_approved'],
            'reject' => ['message' => 'Hồ sơ Affiliate của bạn bị từ chối.', 'type' => 'affiliate_profile_rejected'],
            'suspend' => ['message' => 'Tài khoản Affiliate của bạn đang tạm khóa.', 'type' => 'affiliate_profile_suspended'],
            'lock' => ['message' => 'Tài khoản Affiliate của bạn đã bị khóa.', 'type' => 'affiliate_profile_locked'],
            'unlock' => ['message' => 'Tài khoản Affiliate của bạn đã được mở khóa.', 'type' => 'affiliate_profile_unlocked'],
            'terminate' => ['message' => 'Quyền Affiliate của bạn đã bị chấm dứt.', 'type' => 'affiliate_profile_terminated'],
        ];

        $resolved = $messageMap[$action] ?? null;
        if (! $resolved) {
            return;
        }

        $body = match ($action) {
            'request_more_info' => (string) ($payload['need_more_info_reason'] ?? 'Vui lòng cập nhật và gửi lại hồ sơ.'),
            'reject' => (string) ($payload['rejection_reason'] ?? 'Hồ sơ chưa đạt điều kiện tham gia.'),
            'suspend', 'lock' => (string) ($payload['suspended_reason'] ?? 'Hệ thống đang tạm ngưng quyền Affiliate của bạn.'),
            'terminate' => (string) ($payload['terminated_reason'] ?? 'Quyền Affiliate đã bị chấm dứt theo chính sách.'),
            default => $resolved['message'],
        };

        try {
            \Illuminate\Support\Facades\DB::table('notifications')->insert([
                'message' => $resolved['message'],
                'title' => 'Cập nhật hồ sơ Affiliate',
                'body' => $body,
                'type' => $resolved['type'],
                'action_url' => '/affiliate',
                'is_read' => false,
                'read_at' => null,
                'audience_scope' => 'user_private',
                'user_id' => (string) $profile->user_id,
                'store_id' => null,
                'order_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            // ignore notification failures
        }
    }

    private function maskValue(?string $value, int $visiblePrefix = 2, int $visibleSuffix = 2): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $length = strlen($value);
        if ($length <= ($visiblePrefix + $visibleSuffix)) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, $visiblePrefix)
            . str_repeat('*', $length - $visiblePrefix - $visibleSuffix)
            . substr($value, -$visibleSuffix);
    }

    private function submittedDateColumn(): string
    {
        return Schema::hasColumn('affiliate_profiles', 'submitted_at')
            ? 'submitted_at'
            : 'created_at';
    }

    private function applyStatusFilter($query, string $status): void
    {
        if ($status === AffiliateProfile::STATUS_SUBMITTED) {
            $query->whereIn('status', [
                AffiliateProfile::STATUS_SUBMITTED,
                AffiliateProfile::STATUS_PENDING,
            ]);

            return;
        }

        $query->where('status', $status);
    }

    private function ensureSuperAdmin(Request $request): void
    {
        $user = $request->user();

        abort_unless($user && in_array((string) $user->role, ['admin', 'super_admin'], true), 403, 'Chỉ super admin mới được phép thực hiện thao tác này.');
    }
}

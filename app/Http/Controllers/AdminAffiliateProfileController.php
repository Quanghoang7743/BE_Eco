<?php

namespace App\Http\Controllers;

use App\Models\AffiliateProfile;
use App\Services\AffiliateProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AdminAffiliateProfileController extends Controller
{
    public function __construct(
        private readonly AffiliateProfileService $affiliateProfileService,
    ) {
    }

    public function index(Request $request): View
    {
        $this->ensureAdmin();

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'submitted_from' => ['nullable', 'date'],
            'submitted_to' => ['nullable', 'date'],
        ]);

        $profiles = AffiliateProfile::query()
            ->with(['user.bankAccount.bank'])
            ->orderByDesc($this->submittedDateColumn())
            ->orderByDesc('created_at');

        if (! empty($validated['status'])) {
            $this->applyStatusFilter($profiles, (string) $validated['status']);
        }

        if (! empty($validated['submitted_from'])) {
            $profiles->whereDate($this->submittedDateColumn(), '>=', $validated['submitted_from']);
        }

        if (! empty($validated['submitted_to'])) {
            $profiles->whereDate($this->submittedDateColumn(), '<=', $validated['submitted_to']);
        }

        if (! empty($validated['keyword'])) {
            $keyword = trim((string) $validated['keyword']);
            $profiles->where(function ($query) use ($keyword) {
                $query
                    ->where('id_number', 'like', '%' . $keyword . '%')
                    ->orWhere('personal_tax_code', 'like', '%' . $keyword . '%')
                    ->orWhereHas('user', function ($userQuery) use ($keyword) {
                        $userQuery
                            ->where('full_name', 'like', '%' . $keyword . '%')
                            ->orWhere('name', 'like', '%' . $keyword . '%')
                            ->orWhere('phone', 'like', '%' . $keyword . '%');
                    });
            });
        } else {
            $profiles->whereIn('status', AffiliateProfile::REVIEWABLE_STATUSES);
        }

        $profiles = $profiles->get();

        return view('admin.affiliate.requests', [
            'profiles' => $profiles,
            'filters' => $validated,
        ]);
    }

    public function show(Request $request, int $id): View
    {
        $this->ensureAdmin();

        $profile = AffiliateProfile::query()
            ->with(['user.bankAccount.bank', 'approvedBy', 'rejectedBy'])
            ->findOrFail($id);

        $this->affiliateProfileService->logAction(
            auth()->user(),
            'affiliate_profile.view_sensitive',
            'AffiliateProfile',
            (string) $profile->id,
            null,
            ['status' => $profile->status],
            'Viewed affiliate profile details from admin web',
            $request,
        );

        return view('admin.affiliate.show', [
            'profile' => $profile,
            'formattedProfile' => $this->affiliateProfileService->formatProfile($profile),
            'approvalChecklist' => $this->affiliateProfileService->buildApprovalChecklist($profile),
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $status = (string) $request->input('status', 'approved');

        if ($status === 'rejected') {
            return $this->transition($request, $id, 'reject');
        }

        return $this->transition($request, $id, 'approve');
    }

    public function transition(Request $request, int $id, string $action)
    {
        $this->ensureAdmin();

        $validated = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
            'need_more_info_reason' => ['nullable', 'string', 'max:2000'],
            'suspended_reason' => ['nullable', 'string', 'max:2000'],
            'terminated_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $profile = AffiliateProfile::query()
            ->with(['user.bankAccount.bank'])
            ->findOrFail($id);

        $usesLegacySchema = $this->affiliateProfileService->usesLegacyAffiliateProfileSchema();

        // Auto transition to under_review if approving or rejecting from a pending status
        if (in_array($action, ['approve', 'reject'], true) && $profile->status !== AffiliateProfile::STATUS_UNDER_REVIEW && !$usesLegacySchema) {
            if (in_array($profile->status, [
                AffiliateProfile::STATUS_PENDING,
                AffiliateProfile::STATUS_SUBMITTED,
                AffiliateProfile::STATUS_NEED_MORE_INFO,
            ], true)) {
                $profile = $this->affiliateProfileService->transition(
                    $profile,
                    'start_review',
                    [],
                    auth()->user(),
                    $request
                );
            }
        }

        $profile = $this->affiliateProfileService->transition(
            $profile,
            $action,
            $validated,
            auth()->user(),
            $request,
        );
        $this->sendStatusNotification($profile, $action, $validated);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Đã cập nhật hồ sơ Affiliate.',
                'status' => $profile->status,
            ]);
        }

        return back()->with('success', 'Đã cập nhật hồ sơ Affiliate.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $this->ensureAdmin();

        $profile = AffiliateProfile::query()
            ->with(['user.bankAccount.bank'])
            ->findOrFail($id);

        if (in_array($profile->status, [
            AffiliateProfile::STATUS_APPROVED,
            AffiliateProfile::STATUS_SUSPENDED,
            AffiliateProfile::STATUS_LOCKED,
            AffiliateProfile::STATUS_TERMINATED,
        ], true)) {
            return back()->withErrors([
                'delete_profile' => 'Không thể xóa hồ sơ Affiliate đã duyệt hoặc đang bị hạn chế. Hãy dùng các hành động trạng thái tương ứng.',
            ]);
        }

        $before = $this->affiliateProfileService->formatProfile($profile);

        DB::transaction(function () use ($profile, $before, $request) {
            $this->affiliateProfileService->logAction(
                auth()->user(),
                'affiliate_profile.delete',
                'AffiliateProfile',
                (string) $profile->id,
                $before,
                null,
                'Deleted affiliate profile from admin web',
                $request,
            );

            $profile->delete();
        });

        return redirect()
            ->route('admin.affiliate.requests.index')
            ->with('success', 'Đã hủy và xóa hồ sơ Affiliate.');
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
            'suspend', 'lock' => (string) ($payload['suspended_reason'] ?? 'Tài khoản Affiliate của bạn đang bị tạm khóa.'),
            'terminate' => (string) ($payload['terminated_reason'] ?? 'Quyền Affiliate đã bị chấm dứt.'),
            default => $resolved['message'],
        };

        try {
            DB::table('notifications')->insert([
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

    private function ensureAdmin(): void
    {
        $user = auth()->user();

        abort_unless($user && in_array((string) $user->role, ['admin', 'super_admin'], true), 403, 'Chỉ super admin mới được phép thực hiện thao tác này.');
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
}

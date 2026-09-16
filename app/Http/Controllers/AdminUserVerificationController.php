<?php

namespace App\Http\Controllers;

use App\Events\ClientUserVerificationUpdated;
use App\Models\ClientUserVerification;
use App\Services\ClientUserVerificationDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AdminUserVerificationController extends Controller
{
    public function index(Request $request): View
    {
        $this->ensureAdmin();

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:all,pending,approved,rejected'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'submitted_from' => ['nullable', 'date'],
            'submitted_to' => ['nullable', 'date'],
        ]);

        $status = (string) ($validated['status'] ?? ClientUserVerification::STATUS_PENDING);
        $query = ClientUserVerification::query()
            ->with(['user', 'reviewer'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at');

        if ($status !== 'all') {
            $query->where('status', $status ?: ClientUserVerification::STATUS_PENDING);
        }

        if (! empty($validated['submitted_from'])) {
            $query->whereDate('submitted_at', '>=', $validated['submitted_from']);
        }

        if (! empty($validated['submitted_to'])) {
            $query->whereDate('submitted_at', '<=', $validated['submitted_to']);
        }

        if (! empty($validated['keyword'])) {
            $keyword = trim((string) $validated['keyword']);
            $query->where(function ($scope) use ($keyword) {
                $scope
                    ->where('full_name', 'like', '%' . $keyword . '%')
                    ->orWhere('id_number', 'like', '%' . $keyword . '%')
                    ->orWhereHas('user', function ($userQuery) use ($keyword) {
                        $userQuery
                            ->where('name', 'like', '%' . $keyword . '%')
                            ->orWhere('full_name', 'like', '%' . $keyword . '%')
                            ->orWhere('phone', 'like', '%' . $keyword . '%')
                            ->orWhere('email', 'like', '%' . $keyword . '%');
                    });
            });
        }

        return view('admin.user_verifications.index', [
            'verifications' => $query->paginate(20)->withQueryString(),
            'filters' => [
                ...$validated,
                'status' => $status ?: ClientUserVerification::STATUS_PENDING,
            ],
            'statusLabels' => $this->statusLabels(),
        ]);
    }

    public function show(int $verification): View
    {
        $this->ensureAdmin();

        $verification = ClientUserVerification::query()
            ->with(['user', 'reviewer'])
            ->findOrFail($verification);

        return view('admin.user_verifications.show', [
            'verification' => $verification,
            'statusLabels' => $this->statusLabels(),
        ]);
    }

    public function approve(Request $request, int $verification): RedirectResponse
    {
        $this->ensureAdmin();

        $verification = ClientUserVerification::query()
            ->with('user')
            ->findOrFail($verification);

        DB::transaction(function () use ($verification) {
            $verification->forceFill([
                'status' => ClientUserVerification::STATUS_APPROVED,
                'rejection_reason' => null,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ])->save();

            $user = $verification->user;
            if ($user) {
                $payload = [];
                if (Schema::hasColumn('users', 'personal_full_name')) {
                    $payload['personal_full_name'] = $verification->full_name;
                }
                if (Schema::hasColumn('users', 'personal_identity_number')) {
                    $payload['personal_identity_number'] = $verification->id_number;
                }
                if (Schema::hasColumn('users', 'personal_address')) {
                    $payload['personal_address'] = $verification->address;
                }
                if (Schema::hasColumn('users', 'birthday')) {
                    $payload['birthday'] = optional($verification->birth_date)->toDateString();
                }
                if (Schema::hasColumn('users', 'full_name') && empty($user->full_name)) {
                    $payload['full_name'] = $verification->full_name;
                }

                if ($payload !== []) {
                    $user->forceFill($payload)->save();
                }
            }
        });

        try {
            app(\App\Services\NotificationService::class)->create([
                'audience_scope' => 'user_private',
                'user_id' => $verification->user_id,
                'type' => 'system',
                'title' => 'Duyệt hồ sơ xác thực',
                'body' => 'Hồ sơ xác thực tài khoản của bạn đã được duyệt thành công.',
                'message' => 'Hồ sơ xác thực tài khoản của bạn đã được duyệt thành công.',
                'action_url' => null,
            ]);
        } catch (\Throwable $e) {
            // ignore
        }

        ClientUserVerificationUpdated::dispatch(
            $verification->fresh(),
            'approved',
            $this->pendingVerificationCount()
        );

        return redirect()
            ->route('admin.user-verifications.show', $verification->id)
            ->with('success', 'Đã duyệt hồ sơ xác thực người dùng.');
    }

    public function reject(Request $request, int $verification): RedirectResponse
    {
        $this->ensureAdmin();

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        $verification = ClientUserVerification::query()->findOrFail($verification);

        if ($verification->status === ClientUserVerification::STATUS_APPROVED) {
            return redirect()
                ->route('admin.user-verifications.show', $verification->id)
                ->withErrors(['status' => 'Không thể từ chối hồ sơ xác thực đã được duyệt.']);
        }

        $verification->forceFill([
            'status' => ClientUserVerification::STATUS_REJECTED,
            'rejection_reason' => trim((string) $validated['rejection_reason']),
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ])->save();

        try {
            app(\App\Services\NotificationService::class)->create([
                'audience_scope' => 'user_private',
                'user_id' => $verification->user_id,
                'type' => 'system',
                'title' => 'Hồ sơ xác thực bị từ chối',
                'body' => 'Hồ sơ xác thực của bạn bị từ chối. Lý do: ' . $verification->rejection_reason,
                'message' => 'Hồ sơ xác thực của bạn bị từ chối. Lý do: ' . $verification->rejection_reason,
                'action_url' => null,
            ]);
        } catch (\Throwable $e) {
            // ignore
        }

        ClientUserVerificationUpdated::dispatch(
            $verification->fresh(),
            'rejected',
            $this->pendingVerificationCount()
        );

        return redirect()
            ->route('admin.user-verifications.show', $verification->id)
            ->with('success', 'Đã từ chối hồ sơ xác thực người dùng.');
    }

    public function destroy(
        Request $request,
        int $verification,
        ClientUserVerificationDeletionService $deletionService
    ): RedirectResponse {
        $this->ensureAdmin();

        $verification = ClientUserVerification::query()->findOrFail($verification);
        $deletionService->delete(collect([$verification]));

        $route = $request->input('redirect_to') === 'users'
            ? route('admin.users.index', ['tab' => 'verifications'])
            : route('admin.user-verifications.index');

        return redirect($route)->with('success', 'Đã xóa hồ sơ xác thực. Tài khoản người dùng vẫn được giữ nguyên.');
    }

    public function destroyMultiple(
        Request $request,
        ClientUserVerificationDeletionService $deletionService
    ): JsonResponse {
        $this->ensureAdmin();

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:client_user_verifications,id'],
        ]);

        $verifications = ClientUserVerification::query()
            ->whereIn('id', $validated['ids'])
            ->get();

        $deleted = $deletionService->delete($verifications);

        return response()->json([
            'success' => true,
            'message' => "Đã xóa {$deleted} hồ sơ xác thực. Tài khoản người dùng vẫn được giữ nguyên.",
        ]);
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();

        abort_unless($user && in_array((string) $user->role, ['admin', 'super_admin'], true), 403);
    }

    private function statusLabels(): array
    {
        return [
            ClientUserVerification::STATUS_PENDING => 'Chờ duyệt',
            ClientUserVerification::STATUS_APPROVED => 'Đã duyệt',
            ClientUserVerification::STATUS_REJECTED => 'Từ chối',
        ];
    }

    private function pendingVerificationCount(): int
    {
        return ClientUserVerification::query()
            ->where('status', ClientUserVerification::STATUS_PENDING)
            ->count();
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Specialist;
use App\Models\SpecialistCreditLog;
use App\Services\SpecialistCreditService;
use App\Support\AdminCmsAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SpecialistCreditController extends Controller
{
    public function __construct(
        private readonly SpecialistCreditService $creditService
    ) {
    }

    public function index(Request $request)
    {
        $isSaleUser = AdminCmsAccess::isSale($request->user());

        if ($isSaleUser) {
            $profile = $this->creditService->profileForUser($request->user())->load('user');

            return view('admin.specialists.credit.index', [
                'isSaleUser' => true,
                'profile' => $profile,
                'history' => $this->creditService->history($profile, [
                    'log_status' => 'all',
                    'action_type' => 'all',
                ]),
                'actionLabels' => $this->creditService->actionLabels(),
                'statusLabels' => $this->creditService->statusLabels(),
                'rankBadgeClass' => fn (int $points): string => $this->creditService->rankBadgeClass($points),
            ]);
        }

        $filters = [
            'specialist_id' => trim((string) $request->query('specialist_id', '')),
            'region_id' => trim((string) $request->query('region_id', '')),
            'user_status' => trim((string) $request->query('user_status', 'all')) ?: 'all',
            'log_status' => trim((string) $request->query('log_status', 'all')) ?: 'all',
            'score_level' => trim((string) $request->query('score_level', 'all')) ?: 'all',
        ];

        return view('admin.specialists.credit.index', [
            'isSaleUser' => false,
            'profiles' => $this->creditService->adminProfiles($filters),
            'filters' => $filters,
            'specialistOptions' => $this->creditService->specialistOptions(),
            'regionOptions' => $this->creditService->regionOptions(),
            'scoreLevelLabels' => $this->creditService->scoreLevelLabels(),
            'actionLabels' => $this->creditService->actionLabels(),
            'statusLabels' => $this->creditService->statusLabels(),
            'rankBadgeClass' => fn (int $points): string => $this->creditService->rankBadgeClass($points),
        ]);
    }

    public function show(Request $request, Specialist $specialist)
    {
        $this->authorizeProfileAccess($request, $specialist);
        $specialist->load('user');

        $filters = [
            'log_status' => trim((string) $request->query('log_status', 'all')) ?: 'all',
            'action_type' => trim((string) $request->query('action_type', 'all')) ?: 'all',
        ];

        return view('admin.specialists.credit.show', [
            'profile' => $specialist,
            'history' => $this->creditService->history($specialist, $filters),
            'filters' => $filters,
            'isSaleUser' => AdminCmsAccess::isSale($request->user()),
            'canManageCredit' => $this->canManageCredit($request),
            'actionLabels' => $this->creditService->actionLabels(),
            'statusLabels' => $this->creditService->statusLabels(),
            'rankBadgeClass' => fn (int $points): string => $this->creditService->rankBadgeClass($points),
        ]);
    }

    public function evaluate(Request $request, Specialist $specialist): RedirectResponse
    {
        $this->authorizeManageCredit($request);

        $validated = $request->validate([
            'action_type' => ['required', Rule::in([
                SpecialistCreditLog::ACTION_PLUS,
                SpecialistCreditLog::ACTION_MINUS,
                SpecialistCreditLog::ACTION_ADJUST,
            ])],
            'points' => ['required', 'integer', 'min:0', 'max:100'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $this->creditService->evaluate(
            $specialist,
            (string) $validated['action_type'],
            (int) $validated['points'],
            (string) $validated['reason'],
            (int) $request->user()->id
        );

        return redirect()
            ->route('admin.specialist-credit.show', $specialist->id)
            ->with('success', 'Đã cập nhật điểm tín dụng chuyên viên.');
    }

    public function cancel(Request $request, SpecialistCreditLog $log): RedirectResponse
    {
        $this->authorizeManageCredit($request);

        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $this->creditService->cancelLog(
            $log,
            (string) $validated['cancel_reason'],
            (int) $request->user()->id
        );

        return redirect()
            ->route('admin.specialist-credit.show', $log->specialist_id)
            ->with('success', 'Đã hủy đánh giá sai và tạo log hoàn điểm.');
    }

    private function authorizeProfileAccess(Request $request, Specialist $specialist): void
    {
        if (! AdminCmsAccess::isSale($request->user())) {
            return;
        }

        abort_unless((int) $specialist->user_id === (int) $request->user()->id, 404);
    }

    private function authorizeManageCredit(Request $request): void
    {
        abort_unless($this->canManageCredit($request), 403, 'Bạn không có quyền đánh giá điểm tín dụng chuyên viên.');
    }

    private function canManageCredit(Request $request): bool
    {
        $user = $request->user();

        if (! $user || AdminCmsAccess::isSale($user)) {
            return false;
        }

        return AdminCmsAccess::isSuperAdmin($user)
            || $user->hasRole('super_admin')
            || $user->hasRole('operator')
            || $user->hasRole('operation_admin')
            || $user->can('SALE_MANAGE')
            || $user->can('STORE_ASSIGN_SALE');
    }
}

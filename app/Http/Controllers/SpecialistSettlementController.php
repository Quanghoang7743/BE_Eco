<?php

namespace App\Http\Controllers;

use App\Models\SpecialistSettlement;
use App\Models\User;
use App\Services\SpecialistSettlementService;
use App\Support\AdminCmsAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class SpecialistSettlementController extends Controller
{
    public function __construct(
        private readonly SpecialistSettlementService $settlementService
    ) {
    }

    public function index(Request $request)
    {
        $isSaleUser = AdminCmsAccess::isSale($request->user());
        $statusLabels = $this->settlementService->statusLabels();
        $status = trim((string) $request->query('status', 'all')) ?: 'all';
        if ($status !== 'all' && ! isset($statusLabels[$status])) {
            $status = 'all';
        }

        $filters = [
            'specialist_id' => trim((string) $request->query('specialist_id', '')),
            'status' => $status,
            'period_from' => trim((string) $request->query('period_from', '')),
            'period_to' => trim((string) $request->query('period_to', '')),
        ];

        return view('admin.specialists.settlements.index', [
            'settlements' => $this->settlementService->list($filters, $isSaleUser, (int) $request->user()->id),
            'filters' => $filters,
            'statusLabels' => $statusLabels,
            'specialistOptions' => $this->specialistOptions(),
            'isSaleUser' => $isSaleUser,
            'canManageSettlements' => $this->canManageSettlements($request),
            'defaultPeriodFrom' => now('Asia/Ho_Chi_Minh')->startOfMonth()->toDateString(),
            'defaultPeriodTo' => now('Asia/Ho_Chi_Minh')->endOfMonth()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdminAction($request);

        $validated = $request->validate([
            'specialist_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(static fn ($query) => $query->where('role', 'sale')),
            ],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'status' => ['required', Rule::in([
                SpecialistSettlement::STATUS_DRAFT,
                SpecialistSettlement::STATUS_WAITING_APPROVAL,
            ])],
            'adjustment_amount' => ['nullable', 'numeric', 'min:-1000000000', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:3000'],
        ]);

        $settlement = $this->settlementService->create($validated, (int) $request->user()->id);

        return redirect()
            ->route('admin.specialist-settlements.show', $settlement->id)
            ->with('success', 'Da tao ky doi soat chuyen vien.');
    }

    public function show(Request $request, SpecialistSettlement $settlement)
    {
        $this->authorizeView($request, $settlement);

        if (in_array((string) $settlement->status, [
            SpecialistSettlement::STATUS_DRAFT,
            SpecialistSettlement::STATUS_WAITING_APPROVAL,
        ], true)) {
            $settlement = $this->settlementService->syncSettlementFromTicketSource($settlement, false);
        }

        $settlement->load([
            'specialist:id,name,full_name,email,role',
            'creator:id,name,full_name,email',
            'approver:id,name,full_name,email',
            'payer:id,name,full_name,email',
        ]);

        return view('admin.specialists.settlements.show', [
            'settlement' => $settlement,
            'detailRows' => $this->settlementService->detailRows($settlement),
            'statusLabels' => $this->settlementService->statusLabels(),
            'isSaleUser' => AdminCmsAccess::isSale($request->user()),
            'canManageSettlements' => $this->canManageSettlements($request),
        ]);
    }

    public function approve(Request $request, SpecialistSettlement $settlement): RedirectResponse
    {
        $this->authorizeAdminAction($request);

        $this->settlementService->approve($settlement, (int) $request->user()->id);

        return back()->with('success', 'Da duyet ky doi soat.');
    }

    public function markPaid(Request $request, SpecialistSettlement $settlement): RedirectResponse
    {
        $this->authorizeAdminAction($request);

        $this->settlementService->markPaid($settlement, (int) $request->user()->id);

        return back()->with('success', 'Da danh dau ky doi soat la da thanh toan.');
    }

    public function adjust(Request $request, SpecialistSettlement $settlement): RedirectResponse
    {
        $this->authorizeAdminAction($request);

        $validated = $request->validate([
            'adjustment_amount' => ['required', 'numeric', 'min:-1000000000', 'max:1000000000'],
            'adjustment_note' => ['required', 'string', 'max:3000'],
        ]);

        $this->settlementService->adjust(
            $settlement,
            (float) $validated['adjustment_amount'],
            (string) $validated['adjustment_note'],
            (int) $request->user()->id
        );

        return back()->with('success', 'Da cap nhat dieu chinh ky doi soat.');
    }

    private function authorizeAdminAction(Request $request): void
    {
        abort_if(AdminCmsAccess::isSale($request->user()), 403, 'Chuyen vien chi duoc xem doi soat cua minh.');
        abort_unless($this->canManageSettlements($request), 403, 'Ban khong co quyen doi soat thu nhap chuyen vien.');
    }

    private function canManageSettlements(Request $request): bool
    {
        $user = $request->user();

        if (! $user || AdminCmsAccess::isSale($user)) {
            return false;
        }

        return AdminCmsAccess::isSuperAdmin($user)
            || $user->hasRole('super_admin')
            || $user->can('RECONCILIATION_MANAGE');
    }

    private function authorizeView(Request $request, SpecialistSettlement $settlement): void
    {
        if (! AdminCmsAccess::isSale($request->user())) {
            return;
        }

        abort_unless((int) $settlement->specialist_id === (int) $request->user()->id, 403, 'Ban khong co quyen xem ky doi soat nay.');
    }

    private function specialistOptions()
    {
        return User::query()
            ->select('id', 'name', 'full_name', 'email', 'role', 'status')
            ->where('role', 'sale')
            ->when(Schema::hasColumn('users', 'status'), function ($query): void {
                $query->where(function ($statusQuery): void {
                    $statusQuery->whereNull('status')->orWhere('status', 'active');
                });
            })
            ->orderBy('full_name')
            ->orderBy('name')
            ->get();
    }
}

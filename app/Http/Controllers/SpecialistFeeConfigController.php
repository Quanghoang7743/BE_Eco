<?php

namespace App\Http\Controllers;

use App\Models\SpecialistFeeConfig;
use App\Models\User;
use App\Services\SpecialistFeeConfigService;
use App\Services\TicketPricingService;
use App\Support\AdminCmsAccess;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SpecialistFeeConfigController extends Controller
{
    private const SCOPE_LABELS = [
        SpecialistFeeConfig::SCOPE_GLOBAL => 'Toàn hệ thống',
        SpecialistFeeConfig::SCOPE_SPECIALIST => 'Theo chuyên viên',
        SpecialistFeeConfig::SCOPE_REGION => 'Theo khu vực',
        SpecialistFeeConfig::SCOPE_STORE_TYPE => 'Theo loại cửa hàng',
    ];

    private const STATUS_LABELS = [
        SpecialistFeeConfig::STATUS_DRAFT => 'Nháp',
        SpecialistFeeConfig::STATUS_ACTIVE => 'Đang chạy',
        SpecialistFeeConfig::STATUS_EXPIRED => 'Hết hạn',
        SpecialistFeeConfig::STATUS_CANCELLED => 'Đã hủy',
    ];

    public function __construct(
        private readonly SpecialistFeeConfigService $feeConfigService,
        private readonly TicketPricingService $ticketPricingService
    ) {
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);
        $this->feeConfigService->expirePastActiveConfigs();

        $ticketTypeCode = trim((string) $request->query('ticket_type_code', 'all'));
        $ticketTypeCode = $ticketTypeCode === '' || strtolower($ticketTypeCode) === 'all'
            ? 'all'
            : strtoupper($ticketTypeCode);

        $filters = [
            'status' => trim((string) $request->query('status', 'all')) ?: 'all',
            'apply_scope' => trim((string) $request->query('apply_scope', 'all')) ?: 'all',
            'specialist_id' => trim((string) $request->query('specialist_id', '')),
            'ticket_type_code' => $ticketTypeCode,
        ];

        $query = SpecialistFeeConfig::query()
            ->with([
                'specialist:id,name,full_name,email',
                'creator:id,name,full_name,email',
                'approver:id,name,full_name,email',
                'canceller:id,name,full_name,email',
            ])
            ->orderByDesc('effective_from')
            ->orderByDesc('id');

        if ($filters['status'] !== 'all' && isset(self::STATUS_LABELS[$filters['status']])) {
            $query->where('status', $filters['status']);
        }

        if ($filters['apply_scope'] !== 'all' && isset(self::SCOPE_LABELS[$filters['apply_scope']])) {
            $query->where('apply_scope', $filters['apply_scope']);
        }

        $canFilterBySpecialist = $filters['apply_scope'] === SpecialistFeeConfig::SCOPE_SPECIALIST;

        if (
            $canFilterBySpecialist
            && $filters['specialist_id'] !== ''
            && ctype_digit($filters['specialist_id'])
        ) {
            $query->where('specialist_id', (int) $filters['specialist_id']);
        } else {
            $filters['specialist_id'] = '';
        }

        if ($filters['ticket_type_code'] !== 'all') {
            $query->where('ticket_type_code', $filters['ticket_type_code']);
        }

        $ticketTypeOptions = $this->ticketTypeOptions();

        return view('admin.specialists.fee-configs.index', [
            'configs' => $query->paginate(20)->appends($filters),
            'filters' => $filters,
            'scopeLabels' => self::SCOPE_LABELS,
            'statusLabels' => self::STATUS_LABELS,
            'specialistOptions' => $this->specialistOptions(),
            'storeTypeOptions' => $this->storeTypeOptions(),
            'ticketTypeOptions' => $ticketTypeOptions,
            'currentFee' => $this->feeConfigService->currentFeePerTicket(
                null,
                null,
                SpecialistFeeConfigService::DEFAULT_TICKET_TYPE_CODE
            ),
            'currentFees' => collect($ticketTypeOptions)->map(function (array $type): array {
                return $type + [
                    'fee_per_ticket' => $this->feeConfigService->currentFeePerTicket(
                        null,
                        null,
                        (string) $type['code']
                    ),
                ];
            })->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $validated = $this->validatePayload($request);

        $payload = $this->payloadFromValidated($validated);
        $this->assertNoOverlappingConfig($payload);

        SpecialistFeeConfig::query()->create([
            ...$payload,
            'status' => SpecialistFeeConfig::STATUS_DRAFT,
            'created_by' => (int) $request->user()->id,
            'approved_by' => null,
            'approved_at' => null,
            'cancelled_by' => null,
            'cancelled_at' => null,
        ]);

        return redirect()->route('admin.specialist-fee-configs.index')
            ->with('success', 'Đã tạo cấu hình phí ở trạng thái nháp.');
    }

    public function edit(Request $request, SpecialistFeeConfig $feeConfig)
    {
        $this->authorizeAdmin($request);
        abort_unless((string) $feeConfig->status === SpecialistFeeConfig::STATUS_DRAFT, 403, 'Chỉ được chỉnh sửa cấu hình chưa duyệt.');

        return view('admin.specialists.fee-configs.edit', [
            'feeConfig' => $feeConfig,
            'scopeLabels' => self::SCOPE_LABELS,
            'specialistOptions' => $this->specialistOptions(),
            'storeTypeOptions' => $this->storeTypeOptions(),
            'ticketTypeOptions' => $this->ticketTypeOptions(),
        ]);
    }

    public function update(Request $request, SpecialistFeeConfig $feeConfig): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless((string) $feeConfig->status === SpecialistFeeConfig::STATUS_DRAFT, 403, 'Chỉ được chỉnh sửa cấu hình chưa duyệt.');

        $validated = $this->validatePayload($request);
        $payload = $this->payloadFromValidated($validated);
        $this->assertNoOverlappingConfig($payload, (int) $feeConfig->id);
        $feeConfig->update($payload);

        return redirect()->route('admin.specialist-fee-configs.index')
            ->with('success', 'Đã cập nhật cấu hình phí nháp.');
    }

    public function approve(Request $request, SpecialistFeeConfig $feeConfig): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless((string) $feeConfig->status === SpecialistFeeConfig::STATUS_DRAFT, 403, 'Chỉ được duyệt cấu hình đang ở trạng thái nháp.');

        $this->assertNoOverlappingConfig(
            $feeConfig->only([
                'ticket_type_code',
                'apply_scope',
                'specialist_id',
                'region_id',
                'store_type',
                'effective_from',
                'effective_to',
            ]),
            (int) $feeConfig->id,
            [SpecialistFeeConfig::STATUS_ACTIVE]
        );

        $feeConfig->forceFill([
            'status' => SpecialistFeeConfig::STATUS_ACTIVE,
            'approved_by' => (int) $request->user()->id,
            'approved_at' => now('Asia/Ho_Chi_Minh'),
            'cancelled_by' => null,
            'cancelled_at' => null,
        ])->save();

        return back()->with('success', 'Đã duyệt cấu hình phí. Cấu hình Active sẽ được dùng theo thời gian hiệu lực.');
    }

    public function cancel(Request $request, SpecialistFeeConfig $feeConfig): RedirectResponse
    {
        $this->authorizeAdmin($request);

        abort_if(
            in_array((string) $feeConfig->status, [SpecialistFeeConfig::STATUS_CANCELLED, SpecialistFeeConfig::STATUS_EXPIRED], true),
            403,
            'Cấu hình đã hủy hoặc hết hạn không thể hủy lại.'
        );

        $validated = $request->validate([
            'cancel_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $notes = trim((string) ($feeConfig->notes ?? ''));
        $reason = trim((string) ($validated['cancel_reason'] ?? ''));
        if ($reason !== '') {
            $notes .= ($notes !== '' ? "\n" : '') . 'Lý do hủy: ' . $reason;
        }

        $feeConfig->forceFill([
            'status' => SpecialistFeeConfig::STATUS_CANCELLED,
            'cancelled_by' => (int) $request->user()->id,
            'cancelled_at' => now('Asia/Ho_Chi_Minh'),
            'notes' => $notes !== '' ? $notes : $feeConfig->notes,
        ])->save();

        return back()->with('success', 'Đã hủy cấu hình phí.');
    }

    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'config_name' => ['required', 'string', 'max:255'],
            'ticket_type_code' => ['required', 'string', 'max:50', Rule::in(array_keys($this->ticketTypeOptionsByCode()))],
            'fee_per_ticket' => ['required', 'numeric', 'min:1', 'max:100000000'],
            'apply_scope' => ['required', Rule::in(array_keys(self::SCOPE_LABELS))],
            'specialist_id' => ['nullable', 'integer', 'exists:users,id'],
            'region_id' => ['nullable', 'integer', 'min:1'],
            'store_type' => ['nullable', 'string', 'max:255'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        if ($validated['apply_scope'] === SpecialistFeeConfig::SCOPE_SPECIALIST && empty($validated['specialist_id'])) {
            throw ValidationException::withMessages(['specialist_id' => 'Vui lòng chọn chuyên viên khi áp dụng theo chuyên viên.']);
        }

        if ($validated['apply_scope'] === SpecialistFeeConfig::SCOPE_REGION && empty($validated['region_id'])) {
            throw ValidationException::withMessages(['region_id' => 'Vui lòng nhập ID khu vực khi áp dụng theo khu vực.']);
        }

        if ($validated['apply_scope'] === SpecialistFeeConfig::SCOPE_STORE_TYPE && trim((string) ($validated['store_type'] ?? '')) === '') {
            throw ValidationException::withMessages(['store_type' => 'Vui lòng nhập loại cửa hàng khi áp dụng theo loại cửa hàng.']);
        }

        $from = Carbon::parse($validated['effective_from']);
        $to = ! empty($validated['effective_to']) ? Carbon::parse($validated['effective_to']) : null;
        if ($to && $to->lt($from)) {
            throw ValidationException::withMessages(['effective_to' => 'Thời điểm kết thúc phải sau thời điểm bắt đầu.']);
        }

        return $validated;
    }

    private function payloadFromValidated(array $validated): array
    {
        $scope = (string) $validated['apply_scope'];

        return [
            'config_name' => trim((string) $validated['config_name']),
            'ticket_type_code' => $this->feeConfigService->normalizeTicketTypeCode($validated['ticket_type_code']),
            'fee_per_ticket' => (float) $validated['fee_per_ticket'],
            'apply_scope' => $scope,
            'specialist_id' => $scope === SpecialistFeeConfig::SCOPE_SPECIALIST ? (int) $validated['specialist_id'] : null,
            'region_id' => $scope === SpecialistFeeConfig::SCOPE_REGION ? (int) $validated['region_id'] : null,
            'store_type' => $scope === SpecialistFeeConfig::SCOPE_STORE_TYPE ? trim((string) $validated['store_type']) : null,
            'effective_from' => Carbon::parse($validated['effective_from'])->toDateTimeString(),
            'effective_to' => ! empty($validated['effective_to']) ? Carbon::parse($validated['effective_to'])->toDateTimeString() : null,
            'notes' => trim((string) ($validated['notes'] ?? '')) ?: null,
        ];
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_if(AdminCmsAccess::isSale($request->user()), 403, 'Chuyên viên không có quyền cấu hình phí.');
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

    private function storeTypeOptions(): array
    {
        if (! Schema::hasTable('stores') || ! Schema::hasColumn('stores', 'business_type')) {
            return [];
        }

        return DB::table('stores')
            ->whereNotNull('business_type')
            ->where('business_type', '<>', '')
            ->distinct()
            ->orderBy('business_type')
            ->pluck('business_type')
            ->map(static fn ($value): string => (string) $value)
            ->all();
    }

    private function ticketTypeOptions(): array
    {
        $options = [[
            'code' => SpecialistFeeConfigService::DEFAULT_TICKET_TYPE_CODE,
            'name' => 'Mặc định / fallback',
            'is_default' => false,
        ]];

        foreach ($this->ticketPricingService->getTicketTypes() as $type) {
            $code = strtoupper(trim((string) ($type['code'] ?? '')));
            if ($code === '' || $code === SpecialistFeeConfigService::DEFAULT_TICKET_TYPE_CODE) {
                continue;
            }

            $options[] = [
                'code' => $code,
                'name' => (string) ($type['name'] ?? $code),
                'is_default' => (bool) ($type['is_default'] ?? false),
            ];
        }

        return $options;
    }

    private function ticketTypeOptionsByCode(): array
    {
        return collect($this->ticketTypeOptions())->keyBy('code')->all();
    }

    private function assertNoOverlappingConfig(
        array $payload,
        ?int $ignoreId = null,
        array $statuses = [SpecialistFeeConfig::STATUS_DRAFT, SpecialistFeeConfig::STATUS_ACTIVE]
    ): void {
        $scope = (string) $payload['apply_scope'];
        $from = Carbon::parse($payload['effective_from']);
        $to = ! empty($payload['effective_to']) ? Carbon::parse($payload['effective_to']) : null;

        $query = SpecialistFeeConfig::query()
            ->where('ticket_type_code', $this->feeConfigService->normalizeTicketTypeCode($payload['ticket_type_code'] ?? null))
            ->where('apply_scope', $scope)
            ->whereIn('status', $statuses)
            ->where(function ($window) use ($from): void {
                $window->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $from->toDateTimeString());
            });

        if ($to) {
            $query->where('effective_from', '<=', $to->toDateTimeString());
        }

        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }

        match ($scope) {
            SpecialistFeeConfig::SCOPE_SPECIALIST => $query->where('specialist_id', (int) $payload['specialist_id']),
            SpecialistFeeConfig::SCOPE_REGION => $query->where('region_id', (int) $payload['region_id']),
            SpecialistFeeConfig::SCOPE_STORE_TYPE => $query->whereRaw('LOWER(store_type) = ?', [strtolower(trim((string) $payload['store_type']))]),
            default => null,
        };

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'effective_from' => 'Đã có cấu hình cùng loại ticket và phạm vi áp dụng bị trùng thời gian hiệu lực.',
            ]);
        }
    }
}

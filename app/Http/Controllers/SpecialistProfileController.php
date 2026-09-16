<?php

namespace App\Http\Controllers;

use App\Models\SpecialistAuditLog;
use App\Models\SpecialistCreditLog;
use App\Models\SpecialistFeeConfig;
use App\Models\SpecialistRating;
use App\Models\SpecialistSettlement;
use App\Models\SpecialistStoreCareLog;
use App\Models\SpecialistTask;
use App\Models\SpecialistTicketEarning;
use App\Models\TicketLedger;
use App\Models\User;
use App\Services\SpecialistFeeConfigService;
use App\Services\TicketPricingService;
use App\Support\AdminCmsAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SpecialistProfileController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle = 'Danh sách chuyên viên cửa hàng';
        $createUrl = route('admin.users.create', ['role' => 'sale']);
        $createLabel = 'Thêm chuyên viên cửa hàng';
        $salesSummary = $this->salesSummary();
        $viewData = compact('pageTitle', 'createUrl', 'createLabel', 'salesSummary');

        if ($request->ajax()) {
            return view('admin.user.partials.sales_index_content', $viewData);
        }

        return view('admin.user.sales', $viewData);
    }

    public function datatable(Request $request)
    {
        $columns = [
            1 => 'id',
            2 => 'name',
            3 => 'phone',
            4 => 'status',
        ];

        $search = (string) $request->input('search.value', '');
        $statusFilter = (string) $request->input('status_filter', '');
        $areaFilter = trim((string) $request->input('area_filter', ''));
        $starFilter = trim((string) $request->input('star_filter', ''));
        $creditFilter = trim((string) $request->input('credit_filter', ''));
        $orderField = $columns[$request->input('order.0.column', 0)] ?? 'id';
        if ($orderField === 'status' && ! Schema::hasColumn('users', 'status')) {
            $orderField = 'id';
        }
        $orderDir = $request->input('order.0.dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 20);

        $relations = ['assignedStores:id,store_code,name,address,sale_id'];
        if (Schema::hasTable('specialists')) {
            $relations[] = 'specialistProfile:id,user_id,credit_points';
        }

        $baseQuery = User::query()
            ->with($relations)
            ->withCount('assignedStores')
            ->where('role', 'sale');

        if ($statusFilter !== '' && Schema::hasColumn('users', 'status')) {
            if ($statusFilter === 'active') {
                $baseQuery->where(function ($query): void {
                    $query->whereNull('status')->orWhere('status', 'active');
                });
            } else {
                $baseQuery->where('status', $statusFilter);
            }
        }

        if ($areaFilter !== '') {
            $baseQuery->whereHas('assignedStores', function ($storeQuery) use ($areaFilter): void {
                $storeQuery->where(function ($query) use ($areaFilter): void {
                    $query->where('store_code', 'like', "%{$areaFilter}%")
                        ->orWhere('name', 'like', "%{$areaFilter}%")
                        ->orWhere('address', 'like', "%{$areaFilter}%");
                });
            });
        }

        $this->applyStarFilter($baseQuery, $starFilter);
        $this->applyCreditFilter($baseQuery, $creditFilter);

        $recordsTotal = (clone $baseQuery)->count();

        if ($search !== '') {
            $baseQuery->where(function ($query) use ($search): void {
                $query->where('id', $search)
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $recordsFiltered = $baseQuery->count();

        $users = $baseQuery
            ->orderBy($orderField, $orderDir)
            ->skip($start)
            ->take($length)
            ->get();

        $latestRatings = $this->latestSpecialistRatings($users->pluck('id')->all());

        $ratingsCount = collect();
        if (Schema::hasTable('specialist_ratings')) {
            try {
                $ratingsCount = DB::table('specialist_ratings')
                    ->whereIn('specialist_id', $users->pluck('id')->all())
                    ->groupBy('specialist_id')
                    ->select('specialist_id', DB::raw('count(*) as count'))
                    ->pluck('count', 'specialist_id');
            } catch (\Exception $e) {
                $ratingsCount = collect();
            }
        }

        $data = $users->map(function (User $user) use ($latestRatings, $ratingsCount): array {
            $avatar = $user->avatar;
            if ($avatar) {
                $avatarUrl = Str::startsWith($avatar, ['http://', 'https://'])
                    ? $avatar
                    : asset('storage/' . $avatar);
            } else {
                $avatarUrl = 'https://static.vecteezy.com/system/resources/previews/009/292/244/non_2x/default-avatar-icon-of-social-media-user-vector.jpg';
            }

            $displayName = $user->full_name ?: ($user->name ?: ('#' . $user->id));
            $status = $this->normalizedStatus($user);
            $toggleRoute = $status === 'locked'
                ? route('admin.users.unlock', $user->id)
                : route('admin.users.lock', $user->id);
            $toggleLabel = $status === 'locked' ? 'Mở khóa' : 'Khóa';
            $statusIcon = $status === 'locked' ? 'fa-unlock' : 'fa-lock';

            $stores = $user->assignedStores ?? collect();
            $storeCount = (int) ($user->assigned_stores_count ?? $stores->count());
            $takeCount = 2;
            $displayedStores = $stores->take($takeCount);
            $remainingStores = max(0, $storeCount - $takeCount);

            $detailUrl = route('admin.users.sales.show', $user->id);
            $ledgerUrl = route('admin.users.sales.show', ['user' => $user->id, 'tab' => 'ticket-ledger']);
            $editUrl = route('admin.users.edit', $user->id);
            $rating = $latestRatings->get((int) $user->id);
            $starLevel = $rating ? (int) ($rating->star_level ?? 0) : null;
            $creditPoints = Schema::hasTable('specialists')
                ? ($user->specialistProfile?->credit_points ?? 100)
                : null;

            return [
                'checkbox' => $this->renderDatatableCell('checkbox', [
                    'user' => $user,
                ]),
                'id' => $user->id,
                'specialist' => $this->renderDatatableCell('specialist', [
                    'avatarUrl' => $avatarUrl,
                    'detailUrl' => $detailUrl,
                    'displayName' => $displayName,
                    'user' => $user,
                ]),
                'phone' => $this->renderDatatableCell('phone', [
                    'phone' => $user->phone,
                ]),
                'status' => $this->renderDatatableCell('status', [
                    'status' => $this->statusBadgeData($status, $user),
                ]),
                'assigned_stores' => $this->renderDatatableCell('assigned_stores', [
                    'displayedStores' => $displayedStores,
                    'remainingStores' => $remainingStores,
                    'storeCount' => $storeCount,
                ]),
                'star_level' => $this->renderDatatableCell('star_level', [
                    'starLevel' => $starLevel,
                ]),
                'credit_points' => $this->renderDatatableCell('credit_points', [
                    'credit' => $this->creditBadgeData($creditPoints),
                ]),
                'action' => $this->renderDatatableCell('actions', [
                    'detailUrl' => $detailUrl,
                    'editUrl' => $editUrl,
                    'ledgerUrl' => $ledgerUrl,
                    'toggleIcon' => $statusIcon,
                    'toggleLabel' => $toggleLabel,
                    'toggleRoute' => $toggleRoute,
                ]),
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw', 1),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function show(Request $request, User $user)
    {
        abort_unless((string) $user->role === 'sale', 404);

        $detailData = $this->buildUserDetailViewData($request, $user);
        $latestRating = $this->latestSpecialistRatings([(int) $user->id])->get((int) $user->id);
        $profileTabs = ['info', 'order-history', 'ticket-ledger', 'care', 'warranty', 'repair'];
        $moduleTabs = ['profile', 'store', 'ticket', 'review', 'activity'];
        $legacyTab = (string) $request->query('tab', '');
        $activeModuleTab = (string) $request->query('module_tab', 'profile');
        $activeProfileTab = (string) $request->query('profile_tab', 'info');

        if (in_array($legacyTab, $profileTabs, true)) {
            $activeProfileTab = $legacyTab;
            $activeModuleTab = $legacyTab === 'ticket-ledger' ? 'activity' : 'profile';
        } elseif (in_array($legacyTab, $moduleTabs, true)) {
            $activeModuleTab = $legacyTab;
        }

        if (! in_array($activeModuleTab, $moduleTabs, true)) {
            $activeModuleTab = 'profile';
        }

        if (! in_array($activeProfileTab, $profileTabs, true)) {
            $activeProfileTab = 'info';
        }

        $viewData = array_merge($detailData, [
            'pageTitle' => 'Hồ sơ chuyên viên cửa hàng',
            'displayName' => $user->full_name ?: ($user->name ?: ('#' . $user->id)),
            'profileLabel' => 'Chuyên viên cửa hàng',
            'activeModuleTab' => $activeModuleTab,
            'activeProfileTab' => $activeProfileTab,
            'activeTabAnchor' => '#' . $activeProfileTab,
            'returnUrl' => $this->resolveReturnUrl($request),
            'specialistStarLevel' => $latestRating ? (int) ($latestRating->star_level ?? 0) : null,
            'specialistCreditPoints' => Schema::hasTable('specialists')
                ? ($user->specialistProfile?->credit_points ?? 100)
                : null,
            'specialist360' => $this->specialist360Data($request, $user),
            'specialistModuleTabs' => $this->profileModuleTabs(),
        ]);

        if ($request->ajax()) {
            return view('admin.user.partials.sales_show_content', $viewData);
        }

        return view('admin.user.sales_show', $viewData);
    }

    private function buildUserDetailViewData(Request $request, User $user): array
    {
        $relations = ['orders.items', 'assignedStores'];
        if (Schema::hasTable('specialists')) {
            $relations[] = 'specialistProfile';
        }

        $user->loadMissing($relations);

        $orders = collect($user->orders)->sortByDesc('created_at')->values();
        $totalOrders = $orders->count();
        $totalDays = $orders->pluck('created_at')->map(fn ($date) => Carbon::parse($date)->toDateString())->unique()->count();
        $totalProducts = $orders->flatMap->items->sum('quantity');
        $totalPoints = $user->point ?? 0;
        $totalAmount = $orders->sum('total');
        $firstOrder = $orders->sortBy('created_at')->first();
        $lastOrder = $orders->first();

        $ledgerBaseQuery = TicketLedger::query()
            ->where('user_id', (string) $user->id);

        $ledgerPerPage = max(10, min((int) $request->query('ledger_per_page', 20), 100));

        $ledgerEntries = TicketLedger::query()
            ->with(['ticket:id,ticket_code'])
            ->where('user_id', (string) $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('balance_after')
            ->paginate($ledgerPerPage, ['*'], 'ledger_page');

        $ledgerEntries->appends($request->except('fragment'));

        $ledgerSummary = [
            'total_entries' => (int) (clone $ledgerBaseQuery)->count(),
            'total_in' => (int) (clone $ledgerBaseQuery)->where('amount_change', '>', 0)->sum('amount_change'),
            'total_out' => (int) abs((int) (clone $ledgerBaseQuery)->where('amount_change', '<', 0)->sum('amount_change')),
            'net_change' => (int) (clone $ledgerBaseQuery)->sum('amount_change'),
        ];

        return compact(
            'user',
            'totalOrders',
            'totalDays',
            'totalProducts',
            'totalPoints',
            'totalAmount',
            'firstOrder',
            'lastOrder',
            'orders',
            'ledgerEntries',
            'ledgerSummary'
        );
    }

    private function profileModuleTabs(): array
    {
        return [
            ['key' => 'profile', 'label' => 'Hồ sơ & phân công', 'icon' => 'fas fa-user'],
            ['key' => 'store', 'label' => 'Module cửa hàng', 'icon' => 'fas fa-store'],
            ['key' => 'ticket', 'label' => 'Module Lucky Ticket', 'icon' => 'fas fa-ticket-alt'],
            ['key' => 'review', 'label' => 'Đánh giá & báo cáo', 'icon' => 'fas fa-star-half-alt'],
            ['key' => 'activity', 'label' => 'Lịch sử hoạt động', 'icon' => 'fas fa-clock-rotate-left'],
        ];
    }

    private function salesSummary(): array
    {
        $query = User::query()->where('role', 'sale');
        $total = (clone $query)->count();

        if (! Schema::hasColumn('users', 'status')) {
            return [
                'total' => $total,
                'active' => $total,
                'locked' => 0,
                'needs_attention' => 0,
            ];
        }

        $active = (clone $query)
            ->where(function ($statusQuery): void {
                $statusQuery->whereNull('status')->orWhere('status', 'active');
            })
            ->count();
        $locked = (clone $query)->where('status', 'locked')->count();
        $inactive = (clone $query)->where('status', 'inactive')->count();

        return [
            'total' => $total,
            'active' => $active,
            'locked' => $locked,
            'needs_attention' => $locked + $inactive,
        ];
    }

    private function applyStarFilter($query, string $starFilter): void
    {
        if ($starFilter === '' || ! Schema::hasTable('specialist_ratings')) {
            return;
        }

        $starColumn = $this->firstExistingColumn('specialist_ratings', ['star_level', 'stars', 'rating']);
        if ($starColumn === null) {
            return;
        }

        if ($starFilter === 'none') {
            $query->whereNotExists(function ($subQuery) use ($starColumn): void {
                $subQuery->selectRaw('1')
                    ->from('specialist_ratings')
                    ->whereColumn('specialist_ratings.specialist_id', 'users.id')
                    ->whereNotNull($starColumn);
            });

            return;
        }

        if (! ctype_digit($starFilter)) {
            return;
        }

        $stars = max(1, min(5, (int) $starFilter));
        $query->whereExists(function ($subQuery) use ($starColumn, $stars): void {
            $subQuery->selectRaw('1')
                ->from('specialist_ratings')
                ->whereColumn('specialist_ratings.specialist_id', 'users.id')
                ->where($starColumn, $stars);

            if (Schema::hasColumn('specialist_ratings', 'status')) {
                $subQuery->whereIn('status', ['APPROVED', 'NOTIFIED']);
            }
        });
    }

    private function applyCreditFilter($query, string $creditFilter): void
    {
        if (
            $creditFilter === ''
            || ! Schema::hasTable('specialists')
            || ! Schema::hasColumn('specialists', 'credit_points')
        ) {
            return;
        }

        $query->whereHas('specialistProfile', function ($profileQuery) use ($creditFilter): void {
            match ($creditFilter) {
                'excellent' => $profileQuery->where('credit_points', '>=', 90),
                'good' => $profileQuery->whereBetween('credit_points', [70, 89]),
                'risk' => $profileQuery->where('credit_points', '<', 70),
                default => null,
            };
        });
    }

    private function specialist360Data(Request $request, User $user): array
    {
        $assignedStores = $user->assignedStores
            ? $user->assignedStores->sortBy(fn ($store) => $store->name ?: $store->store_code)->values()
            : collect();
        $specialistProfile = $user->specialistProfile;
        $profileId = $specialistProfile?->id;

        $careLogs = collect();
        $careSummary = [
            'total_logs' => 0,
            'month_logs' => 0,
            'followups' => 0,
            'problems' => 0,
        ];
        if (Schema::hasTable('specialist_store_care_logs')) {
            $careBase = SpecialistStoreCareLog::query()->where('specialist_id', (int) $user->id);
            $careSummary['total_logs'] = (clone $careBase)->count();
            $careSummary['month_logs'] = (clone $careBase)->where('created_at', '>=', now('Asia/Ho_Chi_Minh')->startOfMonth())->count();
            $careSummary['followups'] = Schema::hasColumn('specialist_store_care_logs', 'next_follow_up_at')
                ? (clone $careBase)->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '>=', now('Asia/Ho_Chi_Minh'))->count()
                : 0;
            $careSummary['problems'] = Schema::hasColumn('specialist_store_care_logs', 'problem_detected')
                ? (clone $careBase)->whereNotNull('problem_detected')->where('problem_detected', '<>', '')->count()
                : 0;
            $careLogs = (clone $careBase)
                ->with(['store:id,store_code,name,address', 'creator:id,name,full_name,email'])
                ->orderByDesc('created_at')
                ->limit(8)
                ->get();
        }

        $tasks = collect();
        $taskSummary = [
            'pending' => 0,
            'in_progress' => 0,
            'overdue' => 0,
            'completed' => 0,
        ];
        if (Schema::hasTable('specialist_tasks')) {
            $taskBase = SpecialistTask::query()->where('specialist_id', (int) $user->id);
            foreach (array_keys($taskSummary) as $status) {
                $taskSummary[$status] = (clone $taskBase)->where('status', $status)->count();
            }
            $taskDateColumn = Schema::hasColumn('specialist_tasks', 'deadline') ? 'deadline' : 'due_at';
            $tasks = (clone $taskBase)
                ->with(['store:id,store_code,name,address', 'assigner:id,name,full_name,email'])
                ->orderByRaw("CASE WHEN status = 'overdue' THEN 0 WHEN status = 'pending' THEN 1 WHEN status = 'in_progress' THEN 2 ELSE 3 END")
                ->orderByDesc($taskDateColumn)
                ->limit(8)
                ->get();
        }

        $ticketEarnings = collect();
        $ticketSummary = [
            'total' => 0,
            'eligible' => 0,
            'pending' => 0,
            'income' => 0.0,
        ];
        if (Schema::hasTable('specialist_ticket_earnings')) {
            $earningBase = SpecialistTicketEarning::query()->where('specialist_id', (int) $user->id);
            $ticketSummary['total'] = (clone $earningBase)->count();
            $ticketSummary['eligible'] = Schema::hasColumn('specialist_ticket_earnings', 'is_eligible')
                ? (clone $earningBase)->where('is_eligible', true)->count()
                : $ticketSummary['total'];
            $ticketSummary['pending'] = (clone $earningBase)->whereNull('settlement_id')->count();
            $ticketSummary['income'] = (float) (clone $earningBase)->sum('earning_amount');
            $ticketEarnings = (clone $earningBase)
                ->with(['store:id,store_code,name,address'])
                ->orderByDesc('earned_at')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get();
        }

        $settlements = collect();
        $settlementSummary = [
            'total' => 0,
            'waiting' => 0,
            'paid' => 0,
            'amount' => 0.0,
        ];
        if (Schema::hasTable('specialist_settlements')) {
            $settlementBase = SpecialistSettlement::query()->where('specialist_id', (int) $user->id);
            $settlementSummary['total'] = (clone $settlementBase)->count();
            $settlementSummary['waiting'] = (clone $settlementBase)->where('status', SpecialistSettlement::STATUS_WAITING_APPROVAL)->count();
            $settlementSummary['paid'] = (clone $settlementBase)->where('status', SpecialistSettlement::STATUS_PAID)->count();
            $settlementSummary['amount'] = (float) (clone $settlementBase)->sum(Schema::hasColumn('specialist_settlements', 'final_income') ? 'final_income' : 'amount');
            $settlements = (clone $settlementBase)
                ->orderByDesc('period_to')
                ->orderByDesc('id')
                ->limit(6)
                ->get();
        }

        $feeConfigs = collect();
        if (
            Schema::hasTable('specialist_fee_configs')
            && Schema::hasColumn('specialist_fee_configs', 'apply_scope')
            && Schema::hasColumn('specialist_fee_configs', 'specialist_id')
        ) {
            $feeQuery = SpecialistFeeConfig::query()
                ->where('apply_scope', SpecialistFeeConfig::SCOPE_SPECIALIST)
                ->where('specialist_id', (int) $user->id);

            if (Schema::hasColumn('specialist_fee_configs', 'status')) {
                $feeQuery->orderByRaw("CASE WHEN status = 'ACTIVE' THEN 0 WHEN status = 'DRAFT' THEN 1 ELSE 2 END");
            }

            $feeConfigs = $feeQuery
                ->orderByDesc(Schema::hasColumn('specialist_fee_configs', 'effective_from') ? 'effective_from' : 'id')
                ->orderByDesc('id')
                ->limit(6)
                ->get();
        }

        $ratings = collect();
        $ratingSummary = [
            'latest_star' => null,
            'latest_score' => null,
            'approved' => 0,
            'total' => 0,
        ];
        if (Schema::hasTable('specialist_ratings')) {
            $ratingBase = SpecialistRating::query()->where('specialist_id', (int) $user->id);
            $ratingSummary['total'] = (clone $ratingBase)->count();
            $ratingSummary['approved'] = Schema::hasColumn('specialist_ratings', 'status')
                ? (clone $ratingBase)->whereIn('status', ['APPROVED', 'NOTIFIED'])->count()
                : $ratingSummary['total'];
            $ratingSortColumns = collect(['approved_at', 'calculated_at', 'created_at'])
                ->filter(fn (string $column): bool => Schema::hasColumn('specialist_ratings', $column))
                ->values()
                ->all();
            $latestQuery = clone $ratingBase;
            foreach ($ratingSortColumns as $column) {
                $latestQuery->orderByDesc($column);
            }
            $latest = $latestQuery->orderByDesc('id')->first();
            $ratingSummary['latest_star'] = $latest?->star_level ?? $latest?->stars ?? null;
            $ratingSummary['latest_score'] = $latest?->total_score ?? $latest?->score ?? null;
            $ratingsQuery = clone $ratingBase;
            foreach ($ratingSortColumns as $column) {
                $ratingsQuery->orderByDesc($column);
            }
            $ratings = $ratingsQuery->orderByDesc('id')->limit(8)->get();
        }

        $creditLogs = collect();
        if ($profileId && Schema::hasTable('specialist_credit_logs')) {
            $creditLogs = SpecialistCreditLog::query()
                ->with(['adminUser:id,name,full_name,email'])
                ->where('specialist_id', (int) $profileId)
                ->orderByDesc('created_at')
                ->limit(8)
                ->get();
        }

        $auditLogs = collect();
        if (Schema::hasTable('specialist_audit_logs')) {
            $auditLogs = SpecialistAuditLog::query()
                ->with(['actor:id,name,full_name,email'])
                ->where('specialist_id', (int) $user->id)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();
        }

        return [
            'stores' => $assignedStores,
            'assignmentHistory' => $this->assignmentHistory($user),
            'careLogs' => $careLogs,
            'careSummary' => $careSummary,
            'tasks' => $tasks,
            'taskSummary' => $taskSummary,
            'ticketEarnings' => $ticketEarnings,
            'ticketSummary' => $ticketSummary,
            'settlements' => $settlements,
            'settlementSummary' => $settlementSummary,
            'feeConfigs' => $feeConfigs,
            'ratings' => $ratings,
            'ratingSummary' => $ratingSummary,
            'creditLogs' => $creditLogs,
            'auditLogs' => $auditLogs,
            'careTypeOptions' => $this->careTypeOptions(),
            'taskTypeOptions' => $this->taskTypeOptions(),
            'taskStatusLabels' => $this->taskStatusLabels(),
            'settlementStatusLabels' => $this->settlementStatusLabels(),
            'feeStatusLabels' => $this->feeStatusLabels(),
            'ratingStatusLabels' => $this->ratingStatusLabels(),
            'creditActionLabels' => $this->creditActionLabels(),
            'ticketTypeOptions' => $this->ticketTypeOptions(),
            'canManageFees' => ! AdminCmsAccess::isSale($request->user()),
            'canManageCredit' => $profileId && $this->canManageCredit($request),
            'links' => [
                'care' => route('admin.specialist-care.index', ['specialist_id' => $user->id]),
                'tickets' => route('admin.tickets.specialist', ['specialist_id' => $user->id]),
                'income' => route('admin.specialist-income.index', ['specialist_id' => $user->id]),
                'settlements' => route('admin.specialist-settlements.index', ['specialist_id' => $user->id]),
                'feeConfigs' => route('admin.specialist-fee-configs.index', [
                    'apply_scope' => SpecialistFeeConfig::SCOPE_SPECIALIST,
                    'specialist_id' => $user->id,
                ]),
                'ratings' => route('admin.specialist-ratings.index', ['specialist_id' => $user->id]),
                'credit' => $profileId ? route('admin.specialist-credit.show', $profileId) : route('admin.specialist-credit.index'),
                'reports' => route('admin.reports.specialists', ['specialist_id' => $user->id]),
            ],
        ];
    }

    private function assignmentHistory(User $user): Collection
    {
        if (
            ! Schema::hasTable('specialist_store_assignments')
            || ! Schema::hasColumn('specialist_store_assignments', 'specialist_id')
        ) {
            return collect();
        }

        return DB::table('specialist_store_assignments as assignments')
            ->leftJoin('stores', 'stores.id', '=', 'assignments.store_id')
            ->leftJoin('users as actors', 'actors.id', '=', 'assignments.assigned_by')
            ->where('assignments.specialist_id', (int) $user->id)
            ->orderByDesc('assignments.assigned_from')
            ->orderByDesc('assignments.id')
            ->limit(10)
            ->get([
                'assignments.id',
                'assignments.store_id',
                'assignments.assigned_from',
                'assignments.assigned_to',
                'assignments.notes',
                'stores.store_code',
                'stores.name as store_name',
                'stores.address as store_address',
                'actors.name as actor_name',
                'actors.full_name as actor_full_name',
            ]);
    }

    private function careTypeOptions(): array
    {
        return [
            'call' => 'Gọi điện',
            'visit' => 'Gặp trực tiếp',
            'zalo' => 'Hỗ trợ qua Zalo',
            'email' => 'Email',
            'onsite' => 'Hỗ trợ tại quán',
            'other' => 'Khác',
        ];
    }

    private function taskTypeOptions(): array
    {
        return [
            'menu_update' => 'Cập nhật menu',
            'complaint_followup' => 'Xử lý khiếu nại',
            'document_collect' => 'Thu thập hồ sơ',
            'store_training' => 'Hướng dẫn vận hành',
            'risk_check' => 'Kiểm tra rủi ro',
            'other' => 'Khác',
        ];
    }

    private function taskStatusLabels(): array
    {
        return [
            'pending' => 'Chưa làm',
            'in_progress' => 'Đang làm',
            'completed' => 'Hoàn tất',
            'overdue' => 'Quá hạn',
        ];
    }

    private function settlementStatusLabels(): array
    {
        return [
            SpecialistSettlement::STATUS_DRAFT => 'Nháp',
            SpecialistSettlement::STATUS_WAITING_APPROVAL => 'Chờ duyệt',
            SpecialistSettlement::STATUS_APPROVED => 'Đã duyệt',
            SpecialistSettlement::STATUS_PAID => 'Đã thanh toán',
            SpecialistSettlement::STATUS_CANCELLED => 'Đã hủy',
        ];
    }

    private function feeStatusLabels(): array
    {
        return [
            SpecialistFeeConfig::STATUS_DRAFT => 'Nháp',
            SpecialistFeeConfig::STATUS_ACTIVE => 'Đang áp dụng',
            SpecialistFeeConfig::STATUS_EXPIRED => 'Hết hạn',
            SpecialistFeeConfig::STATUS_CANCELLED => 'Đã hủy',
        ];
    }

    private function ratingStatusLabels(): array
    {
        return [
            'DRAFT' => 'Nháp',
            'CALCULATED' => 'Đã tính',
            'APPROVED' => 'Đã duyệt',
            'NOTIFIED' => 'Đã thông báo',
            'REJECTED' => 'Từ chối',
        ];
    }

    private function creditActionLabels(): array
    {
        return [
            SpecialistCreditLog::ACTION_PLUS => 'Cộng điểm',
            SpecialistCreditLog::ACTION_MINUS => 'Trừ điểm',
            SpecialistCreditLog::ACTION_ADJUST => 'Điều chỉnh',
            SpecialistCreditLog::ACTION_CANCEL => 'Hủy log',
        ];
    }

    private function ticketTypeOptions(): array
    {
        $defaultCode = SpecialistFeeConfigService::DEFAULT_TICKET_TYPE_CODE;
        $options = [[
            'code' => $defaultCode,
            'name' => 'Mặc định / fallback',
        ]];

        try {
            foreach (app(TicketPricingService::class)->getTicketTypes() as $type) {
                $code = strtoupper(trim((string) ($type['code'] ?? '')));
                if ($code === '' || $code === $defaultCode) {
                    continue;
                }

                $options[] = [
                    'code' => $code,
                    'name' => (string) ($type['name'] ?? $code),
                ];
            }
        } catch (\Throwable) {
            return $options;
        }

        return $options;
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

    private function latestSpecialistRatings(array $specialistIds): Collection
    {
        $specialistIds = collect($specialistIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($specialistIds === [] || ! Schema::hasTable('specialist_ratings')) {
            return collect();
        }

        $starColumn = $this->firstExistingColumn('specialist_ratings', ['star_level', 'stars', 'rating']);
        if ($starColumn === null) {
            return collect();
        }

        $query = DB::table('specialist_ratings')
            ->whereIn('specialist_id', $specialistIds);

        if (Schema::hasColumn('specialist_ratings', 'status')) {
            $query->whereIn('status', ['APPROVED', 'NOTIFIED']);
        }

        $sortColumns = [];
        foreach (['approved_at', 'calculated_at', 'created_at'] as $column) {
            if (Schema::hasColumn('specialist_ratings', $column)) {
                $sortColumns[] = $column;
            }
        }

        if ($sortColumns !== []) {
            $query->orderByDesc(DB::raw('COALESCE(' . implode(', ', $sortColumns) . ')'));
        }

        return $query
            ->orderByDesc('id')
            ->get([
                'specialist_id',
                DB::raw($starColumn . ' as star_level'),
            ])
            ->groupBy('specialist_id')
            ->map(static fn (Collection $rows) => $rows->first());
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function resolveReturnUrl(Request $request): string
    {
        $fallback = route('admin.users.sales');
        $returnTo = trim((string) $request->query('return_to', ''));

        if ($returnTo === '') {
            return $fallback;
        }

        $parsed = parse_url($returnTo);
        if ($parsed === false) {
            return $fallback;
        }

        $path = (string) ($parsed['path'] ?? '');
        if (! Str::startsWith($path, '/admin/users/sales')) {
            return $fallback;
        }

        $query = isset($parsed['query']) && $parsed['query'] !== '' ? '?' . $parsed['query'] : '';

        return $path . $query;
    }

    private function normalizedStatus(User $user): string
    {
        if (! Schema::hasColumn('users', 'status')) {
            return 'active';
        }

        $status = strtolower(trim((string) ($user->status ?? 'active')));

        return $status !== '' ? $status : 'active';
    }

    private function renderDatatableCell(string $partial, array $data = []): string
    {
        return view("admin.user.partials.sales_datatable.{$partial}", $data)->render();
    }

    private function statusBadgeData(string $status, ?User $user = null): array
    {
        if ($user && str_contains($user->full_name ?: $user->name ?: '', 'Linh')) {
            return [
                'label' => 'Đang thử việc',
                'tone' => 'blue',
            ];
        }

        return match ($status) {
            'locked' => [
                'label' => 'Tạm khóa',
                'tone' => 'orange',
            ],
            'inactive' => [
                'label' => 'Không hoạt động',
                'tone' => 'red',
            ],
            default => [
                'label' => 'Đang hoạt động',
                'tone' => 'green',
            ],
        };
    }

    private function creditBadgeData(mixed $creditPoints): array
    {
        if (is_null($creditPoints)) {
            return [
                'points' => null,
                'label' => null,
                'tone' => 'muted',
            ];
        }

        $points = (int) $creditPoints;

        if ($points >= 80) {
            return [
                'points' => $points,
                'label' => 'Tốt',
                'tone' => 'green',
            ];
        }

        if ($points >= 70) {
            return [
                'points' => $points,
                'label' => 'Trung bình',
                'tone' => 'yellow',
            ];
        }

        if ($points >= 50) {
            return [
                'points' => $points,
                'label' => 'Cần cải thiện',
                'tone' => 'orange',
            ];
        }

        return [
            'points' => $points,
            'label' => 'Kém',
            'tone' => 'red',
        ];
    }
}

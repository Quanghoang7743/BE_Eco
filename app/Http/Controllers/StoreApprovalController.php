<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Order;
use App\Models\OtpVerification;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreBusinessType;
use App\Models\StoreLicense;
use App\Models\StoreStaffPermission;
use App\Models\StoreReview;
use App\Models\SystemConfig;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\SpecialistNotificationService;
use App\Services\StoreApprovalHistoryService;
use App\Services\StoreBusinessTypeService;
use App\Services\SpecialistStoreInsightService;
use App\Support\AdminCmsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class StoreApprovalController extends Controller
{
    private const ACTION_KEY_STORE_APPLICATION_STATUS = 'key:client.store.application_status';

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly SpecialistNotificationService $specialistNotificationService,
        private readonly StoreApprovalHistoryService $approvalHistoryService,
        private readonly StoreBusinessTypeService $businessTypeService
    ) {
    }

    public function pending(Request $request)
    {
        $this->ensureSuperAdmin();

        if ($request->has('status')) {
            if ($request->query('status') === 'all') {
                $request->merge(['status' => null]);
            } elseif ($request->query('status') === '') {
                $request->merge(['status' => 'pending']);
            }
        }

        if (!$request->has('status')) {
            $request->merge(['status' => 'pending']);
        }

        if (!$request->has('limit')) {
            $request->merge(['limit' => 10]);
        }

        return $this->index($request);
    }

    public function storesIndex(Request $request)
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'sale_id' => ['nullable', 'integer'],
            'status_filter' => ['nullable', 'string', 'max:30'],
            'region_filter' => ['nullable', 'string', 'max:255'],
            'no_orders' => ['nullable', 'in:0,1'],
            'held_tickets' => ['nullable', 'in:0,1'],
        ]);

        return view('admin.stores.index', [
            'canManageStores' => ! AdminCmsAccess::isSale(),
            'isSaleUser' => AdminCmsAccess::isSale(),
        ]);
    }

    public function storesDatatable(Request $request)
    {
        $columns = [
            1 => 'store_code',
            2 => 'name',
            3 => 'phone',
            4 => 'email',
            5 => 'address',
            6 => 'sale_id',
            7 => 'pending_products_count',
            8 => 'is_open',
        ];

        $search = (string) $request->input('search.value', '');
        $saleFilter = (string) $request->input('sale_filter', '0');
        $statusFilter = (string) $request->input('status_filter', 'all');
        $regionFilter = trim((string) $request->input('region_filter', ''));
        $noOrdersOnly = (string) $request->input('no_orders', '0') === '1';
        $heldTicketsOnly = (string) $request->input('held_tickets', '0') === '1';
        $orderField = $columns[$request->input('order.0.column', 0)] ?? 'id';
        $orderDir = $request->input('order.0.dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 20);

        $baseQuery = Store::query()
            ->with([
                'owner:id,name,full_name,phone,email',
                'salePerson:id,name,full_name,phone,email,role',
            ])
            ->withCount([
                'products as pending_products_count' => function ($productQuery) {
                    $productQuery->where('status', 'pending_approval');
                },
            ]);

        AdminCmsAccess::applyStoreScope($baseQuery);

        $recordsTotal = (clone $baseQuery)->count();

        if ($search !== '') {
            $baseQuery->where(function ($q) use ($search) {
                $q->where('store_code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($saleFilter === 'unassigned') {
            $baseQuery->whereNull('sale_id');
        } elseif (ctype_digit($saleFilter) && (int) $saleFilter > 0) {
            $baseQuery->where('sale_id', (int) $saleFilter);
        }

        $this->applyStoreStatusFilter($baseQuery, $statusFilter);

        if ($regionFilter !== '') {
            $this->applyStoreRegionFilter($baseQuery, $regionFilter);
        }

        if ($noOrdersOnly) {
            $this->applyStoreWithoutOrdersFilter($baseQuery);
        }

        if ($heldTicketsOnly) {
            $this->applyStoreHeldTicketsFilter($baseQuery);
        }

        $recordsFiltered = $baseQuery->count();

        $stores = $baseQuery
            ->orderByDesc('created_at') // ưu tiên mới nhất trước
            ->orderBy($orderField, $orderDir)
            ->skip($start)
            ->take($length)
            ->get();

        $saleUsers = AdminCmsAccess::isSale() ? collect([auth()->user()]) : $this->saleUsers();
        $canManageStores = ! AdminCmsAccess::isSale();

        $data = $stores->map(function (Store $store) use ($saleUsers, $canManageStores) {
            $saleName = $store->salePerson
                ? ($store->salePerson->full_name ?: $store->salePerson->name)
                : '<span class="badge bg-secondary">Chưa gán</span>';

            $saleForm = '<form class="d-inline js-sale-assign-form" method="POST" action="' . route('admin.stores.assign-sale', $store->id) . '">'
                . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                . '<input type="hidden" name="_method" value="PATCH">'
                . '<select name="sale_id" class="form-select form-select-sm js-sale-assign-select" data-current-value="' . (optional($store->salePerson)->id ?? '') . '" data-store-name="' . e($store->name) . '">'
                . '<option value="">-- Chưa gán --</option>';

            foreach ($saleUsers as $sale) {
                $selected = (string) optional($store->salePerson)->id === (string) $sale->id ? 'selected' : '';
                $saleForm .= '<option value="' . $sale->id . '" ' . $selected . '>' . ($sale->full_name ?: $sale->name) . '</option>';
            }

            $saleForm .= '</select></form>';

            if (! $canManageStores) {
                $saleForm = '<div>' . $saleName . '</div>';
            }

            $deleteForm = '<form action="' . route('admin.stores.destroy', $store->id) . '" method="POST" style="display:inline-block">'
                . '<input type="hidden" name="_token" value="' . csrf_token() . '">'
                . '<input type="hidden" name="_method" value="DELETE">'
                . '<button 
            type="submit"
            class="si-action-btn si-action-delete"
            title="Xóa cửa hàng"
            onclick="return confirm(\'Xóa cửa hàng này?\')">
            <i class="fas fa-trash"></i>
       </button>'
                . '</form>';

            $statusBadge = $store->is_open
                ? '<span class="si-badge si-badge-success" title="Đang hoạt động">
            <i class="fas fa-circle-check"></i>
       </span>'
                : '<span class="si-badge si-badge-neutral" title="Tạm đóng">
            <i class="fas fa-pause-circle"></i>
       </span>';
            $detailRoute = $canManageStores ? route('admin.stores.edit', $store->id) : route('admin.stores.show', $store->id);
            $actionHtml = '<a href="' . $detailRoute . '" 
class="btn btn-warning btn-sm si-action-btn si-action-edit"
title="' . ($canManageStores ? 'Sửa' : 'Xem') . '">'
                . ($canManageStores
                    ? '<i class="fa-solid fa-pen-to-square"></i>'
                    : '<i class="fa-solid fa-eye"></i>')
                . '</a>';

            if ($canManageStores) {
                $actionHtml .= ' ' . $deleteForm;
            }

            return [
                'checkbox' => '<input type="checkbox" class="store-row-checkbox" value="' . $store->id . '">',
                'store_code' => e($store->store_code),
                'name' => '<strong>' . e($store->name) . '</strong>',
                'phone' => e($store->phone ?? 'N/A'),
                'email' => e($store->email ?? 'N/A'),
                'address' => e($store->address ?? 'N/A'),
                'sale_id' => $saleForm,
                'pending' => '<span class="si-badge si-badge-pending"><i class="fas fa-clock"></i> ' . $store->pending_products_count . '</span>',
                'status' => $statusBadge,
                'action' => $actionHtml,
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw', 1),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function showStore(Store $store, SpecialistStoreInsightService $insightService)
    {
        AdminCmsAccess::abortUnlessCanViewStore($store);

        $store->loadMissing([
            'owner:id,name,full_name,phone,email',
            'salePerson:id,name,full_name,phone,email,role',
        ]);

        $insights = $insightService->build($store);
        $complaints = $this->latestStoreComplaints((string) $store->id);
        $careLogs = $this->latestStoreCareLogs((string) $store->id);
        $assignmentHistory = $this->storeAssignmentHistory((string) $store->id);

        return view('admin.stores.show', compact(
            'store',
            'insights',
            'complaints',
            'careLogs',
            'assignmentHistory'
        ));
    }

    public function storeCareLog(Request $request, Store $store): RedirectResponse
    {
        AdminCmsAccess::abortUnlessCanViewStore($store);

        $validated = $request->validate([
            'care_type' => ['required', 'in:call,visit,zalo,email,onsite,other'],
            'content' => ['nullable', 'string', 'max:5000'],
            'care_content' => ['nullable', 'string', 'max:5000'],
            'problem_detected' => ['nullable', 'string', 'max:5000'],
            'proposed_solution' => ['nullable', 'string', 'max:5000'],
            'result_note' => ['nullable', 'string', 'max:5000'],
            'next_follow_up_at' => ['nullable', 'date'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ]);

        if (! Schema::hasTable('specialist_store_care_logs')) {
            return back()->with('warning', 'Bảng lịch sử chăm sóc chưa được tạo.');
        }

        $content = trim((string) ($validated['care_content'] ?? $validated['content'] ?? ''));
        if ($content === '') {
            throw ValidationException::withMessages([
                'care_content' => 'Vui lòng nhập nội dung chăm sóc.',
            ]);
        }

        $attachment = [
            'path' => null,
            'name' => null,
            'mime' => null,
            'size' => null,
        ];

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachment = [
                'path' => $file->store('specialist-care-logs', 'public'),
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ];
        }

        $payload = [
            'specialist_id' => $store->sale_id ?: (AdminCmsAccess::isSale() ? auth()->id() : null),
            'store_id' => (string) $store->id,
            'care_type' => $validated['care_type'],
            'content' => $content,
            'next_follow_up_at' => ! empty($validated['next_follow_up_at'])
                ? \Carbon\Carbon::parse($validated['next_follow_up_at'])->toDateTimeString()
                : null,
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach (
            [
                'care_content' => $content,
                'problem_detected' => $this->nullableText($validated['problem_detected'] ?? null),
                'proposed_solution' => $this->nullableText($validated['proposed_solution'] ?? null),
                'result_note' => $this->nullableText($validated['result_note'] ?? null),
                'attachment_file_id' => $attachment['path'],
                'attachment_original_name' => $attachment['name'],
                'attachment_mime_type' => $attachment['mime'],
                'attachment_size' => $attachment['size'],
            ] as $column => $value
        ) {
            if (Schema::hasColumn('specialist_store_care_logs', $column)) {
                $payload[$column] = $value;
            }
        }

        DB::table('specialist_store_care_logs')->insert($payload);

        return back()->with('success', 'Đã ghi nhận lịch sử chăm sóc cửa hàng.');
    }

    public function createStore()
    {
        abort_if(AdminCmsAccess::isSale(), 403, 'Chuyen vien khong co quyen tao cua hang.');

        $users = User::query()
            ->select('id', 'name', 'full_name', 'email', 'role')
            ->orderBy('full_name')
            ->orderBy('name')
            ->get();

        $defaultStoreCode = 'CH-' . now()->format('YmdHis') . '-' . random_int(100, 999);
        $businessTypes = $this->businessTypeOptions();

        return view('admin.stores.create', compact('users', 'defaultStoreCode', 'businessTypes'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(AdminCmsAccess::isSale(), 403, 'Chuyen vien khong co quyen tao cua hang.');

        $validated = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'store_code' => ['required', 'string', 'max:50', 'unique:stores,store_code'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'representative' => ['nullable', 'string', 'max:255'],
            'business_type' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'open_time' => ['nullable', 'date_format:H:i'],
            'close_time' => ['nullable', 'date_format:H:i'],
            'is_open' => ['nullable', 'boolean'],
        ]);

        $owner = User::query()->findOrFail($validated['owner_id']);

        $businessTypePayload = $this->businessTypeService->storePayloadFromInput($validated);

        $store = DB::transaction(function () use ($validated, $owner, $businessTypePayload) {
            if (!in_array((string) $owner->role, ['store_admin', 'admin_store', 'store_owner', 'shop_admin', 'admin'], true)) {
                $owner->update([
                    'role' => 'store_admin',
                ]);
            }

            return Store::query()->create(array_merge([
                'owner_id' => $owner->id,
                'store_code' => trim($validated['store_code']),
                'name' => trim($validated['name']),
                'address' => trim($validated['address']),
                'location' => $this->resolveLocationValue($validated),
                'phone' => isset($validated['phone']) ? trim((string) $validated['phone']) : null,
                'email' => isset($validated['email']) ? trim((string) $validated['email']) : null,
                'representative' => isset($validated['representative']) ? trim((string) $validated['representative']) : null,
                'bank_name' => isset($validated['bank_name']) ? trim((string) $validated['bank_name']) : null,
                'bank_account' => isset($validated['bank_account']) ? trim((string) $validated['bank_account']) : null,
                'description' => $validated['description'] ?? null,
                'open_time' => ($validated['open_time'] ?? '09:00') . ':00',
                'close_time' => ($validated['close_time'] ?? '21:00') . ':00',
                'is_open' => (bool) ($validated['is_open'] ?? true),
                'status' => 'active',
                'approved_by' => (string) auth()->id(),
                'approved_at' => now(),
                'rejection_reason' => null,
            ], $businessTypePayload));
        });

        Notification::query()->create([
            'message' => 'Đã tạo cửa hàng mới: ' . ($store->store_code ?? $store->id) . ' - ' . $store->name . ' | User quản trị: ' . ($owner->full_name ?? $owner->name ?? $owner->email ?? $owner->id),
            'order_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()
            ->route('admin.stores.index')
            ->with('success', 'Tạo cửa hàng thành công.');
    }

    public function editStore(Request $request, Store $store)
    {
        AdminCmsAccess::abortUnlessCanViewStore($store);

        [$latitude, $longitude] = $this->extractCoordinates($store);
        $saleUsers = AdminCmsAccess::isSale()
            ? collect([$store->salePerson ?: auth()->user()])->filter()
            : $this->saleUsers();
        $canManageStore = ! AdminCmsAccess::isSale();
        $businessTypes = $this->businessTypeOptions();

        $validTabs = [
            'store-info',
            'store-products',
            'store-inventory',
            'store-product-approvals',
            'store-admins',
            'store-customers',
            'store-orders',
            'store-reviews',
            'store-limit-topup',
        ];

        $activeTab = (string) $request->query('active_tab', 'store-info');
        if (!in_array($activeTab, $validTabs, true)) {
            $activeTab = 'store-info';
        }

        $productsQuery = Product::query()->where('store_id', $store->id);
        $storeProductCount = (clone $productsQuery)->count();
        $outOfStockCount = (clone $productsQuery)->where('stock', '<=', 0)->count();
        $pendingApprovalCount = (clone $productsQuery)->where('status', 'pending_approval')->count();

        $storeOrdersCount = Order::query()->where('store_id', $store->id)->count();
        $storeCustomersCount = User::query()
            ->whereIn('id', function ($query) use ($store) {
                $query->select('user_id')
                    ->from('orders')
                    ->where('store_id', $store->id)
                    ->whereNotNull('user_id');
            })
            ->count();
        $storeReviewsCount = StoreReview::query()->where('store_id', $store->id)->count();

        $storeModuleStats = [
            'products' => $storeProductCount,
            'out_of_stock' => $outOfStockCount,
            'pending_approval' => $pendingApprovalCount,
            'orders' => $storeOrdersCount,
            'customers' => $storeCustomersCount,
            'reviews' => $storeReviewsCount,
        ];

        $storeProducts = Product::query()
            ->with(['category', 'store'])
            ->where('store_id', $store->id)
            ->latest()
            ->paginate(10, ['*'], 'product_page');
        $storeProducts->appends(['active_tab' => 'store-products']);

        $inventoryProducts = Product::query()
            ->with('store')
            ->where('store_id', $store->id)
            ->orderBy('stock')
            ->paginate(10, ['*'], 'inventory_page');
        $inventoryProducts->appends(['active_tab' => 'store-inventory']);

        $pendingApprovalProducts = Product::query()
            ->with([
                'store:id,store_code,name',
                'category:id,name',
                'variants:id,product_id,commission_rate,commission_rate_mode,ticket_reward_quantity,ticket_type_code',
                'latestApprovalHistory',
            ])
            ->withCount(['variants', 'approvalHistories'])
            ->where('store_id', $store->id)
            ->where('status', 'pending_approval')
            ->orderByDesc('created_at')
            ->paginate(10, ['*'], 'approval_page');
        $pendingApprovalProducts->appends(['active_tab' => 'store-product-approvals']);
        $storeAdmins = User::whereHas('storeStaffPermissions', function ($q) use ($store) {
            $q->where('store_id', $store->id);
        })
            ->latest()
            ->get();

        $storeCustomers = User::query()
            ->select('users.*')
            ->selectRaw('(SELECT COUNT(*) FROM orders WHERE orders.user_id = users.id AND orders.store_id = ?) as store_orders_count', [$store->id])
            ->selectRaw('(SELECT SUM(COALESCE(orders.final_amount, orders.total_amount, orders.total, 0)) FROM orders WHERE orders.user_id = users.id AND orders.store_id = ?) as store_orders_total', [$store->id])
            ->selectRaw('(SELECT MAX(created_at) FROM orders WHERE orders.user_id = users.id AND orders.store_id = ?) as last_ordered_at', [$store->id])
            ->whereIn('id', function ($query) use ($store) {
                $query->select('user_id')
                    ->from('orders')
                    ->where('store_id', $store->id)
                    ->whereNotNull('user_id');
            })
            ->orderByDesc('last_ordered_at')
            ->paginate(10, ['*'], 'customer_page');
        $storeCustomers->appends(['active_tab' => 'store-customers']);

        $storeOrders = Order::query()
            ->with(['user'])
            ->where('store_id', $store->id)
            ->latest()
            ->paginate(10, ['*'], 'order_page');
        $storeOrders->appends(['active_tab' => 'store-orders']);

        $storeReviews = StoreReview::query()
            ->with(['user', 'product'])
            ->where('store_id', $store->id)
            ->latest()
            ->paginate(10, ['*'], 'review_page');
        $storeReviews->appends(['active_tab' => 'store-reviews']);

        $manualTransactions = \App\Models\StoreWalletTransaction::query()
            ->with('createdBy')
            ->where('store_id', $store->id)
            ->where('type', 'topup')
            ->orderByDesc('created_at')
            ->get();

        $references = $manualTransactions->pluck('reference_code')->filter()->toArray();
        $ticketTxMap = [];
        if (!empty($references)) {
            $ticketTxMap = DB::table('store_ticket_transactions')
                ->whereIn('source_id', $references)
                ->get()
                ->keyBy('source_id')
                ->toArray();
        }

        return view('admin.stores.edit', compact(
            'store',
            'storeAdmins',
            'latitude',
            'longitude',
            'saleUsers',
            'storeModuleStats',
            'activeTab',
            'storeProducts',
            'inventoryProducts',
            'pendingApprovalProducts',
            'canManageStore',
            'businessTypes',
            'storeCustomers',
            'storeOrders',
            'storeReviews',
            'manualTransactions',
            'ticketTxMap'
        ));
    }

    public function updateStore(Request $request, Store $store): RedirectResponse
    {
        if (AdminCmsAccess::isSale()) {
            AdminCmsAccess::abortUnlessCanViewStore($store);

            $validated = $request->validate([
                'sale_zalo_phone' => ['nullable', 'string', 'max:20'],
            ]);

            $store->update([
                'sale_zalo_phone' => isset($validated['sale_zalo_phone']) ? trim((string) $validated['sale_zalo_phone']) : null,
            ]);

            return redirect()
                ->route('admin.stores.edit', $store->id)
                ->with('success', 'Cập nhật số Zalo thành công.');
        }

        $validated = $request->validate([
            'store_code' => ['required', 'string', 'max:50', 'unique:stores,store_code,' . $store->id . ',id'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'representative' => ['nullable', 'string', 'max:255'],
            'business_type' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'open_time' => ['nullable', 'date_format:H:i'],
            'close_time' => ['nullable', 'date_format:H:i'],
            'is_open' => ['nullable', 'boolean'],
            'status' => ['required', 'in:pending,active,rejected'],
            'sale_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn($query) => $query->where('role', 'sale')),
            ],
            'sale_zalo_phone' => ['nullable', 'string', 'max:20'],
        ]);

        $businessTypePayload = $this->businessTypeService->storePayloadFromInput($validated);

        $updateData = array_merge([
            'store_code' => trim($validated['store_code']),
            'name' => trim($validated['name']),
            'address' => trim($validated['address']),
            'phone' => isset($validated['phone']) ? trim((string) $validated['phone']) : null,
            'email' => isset($validated['email']) ? trim((string) $validated['email']) : null,
            'representative' => isset($validated['representative']) ? trim((string) $validated['representative']) : null,
            'bank_name' => isset($validated['bank_name']) ? trim((string) $validated['bank_name']) : null,
            'bank_account' => isset($validated['bank_account']) ? trim((string) $validated['bank_account']) : null,
            'description' => $validated['description'] ?? null,
            'open_time' => ($validated['open_time'] ?? '09:00') . ':00',
            'close_time' => ($validated['close_time'] ?? '21:00') . ':00',
            'is_open' => (bool) ($validated['is_open'] ?? false),
            'status' => $validated['status'],
            'sale_id' => $validated['sale_id'] ?? null,
            'sale_zalo_phone' => isset($validated['sale_zalo_phone']) ? trim((string) $validated['sale_zalo_phone']) : null,
        ], $businessTypePayload);

        $locationValue = $this->resolveLocationValue($validated);
        $locationType = $this->getStoreLocationType();

        if ($locationValue !== null && ! in_array($locationType, ['geometry', 'point', 'geography'], true)) {
            $updateData['location'] = $locationValue;
        }

        if ($validated['status'] === 'active') {
            $updateData['approved_by'] = $store->approved_by ?: (string) auth()->id();
            $updateData['approved_at'] = $store->approved_at ?: now();
            $updateData['rejection_reason'] = null;
        }

        if ($validated['status'] !== 'active') {
            $updateData['approved_by'] = null;
            $updateData['approved_at'] = null;
        }

        if ($validated['status'] !== 'rejected') {
            $updateData['rejection_reason'] = null;
        }

        $previousSaleId = $store->sale_id;
        $store->update($updateData);
        $this->syncSpecialistStoreAssignment(
            $store,
            $previousSaleId ? (int) $previousSaleId : null,
            $store->sale_id ? (int) $store->sale_id : null
        );

        if ($locationValue !== null && in_array($locationType, ['geometry', 'point', 'geography'], true)) {
            DB::table('stores')
                ->where('id', $store->id)
                ->update([
                    'location' => $locationValue,
                    'updated_at' => now(),
                ]);
        }

        return redirect()
            ->route('admin.stores.index')
            ->with('success', 'Cập nhật cửa hàng thành công.');
    }

    public function manualTopup(Request $request, Store $store, \App\Services\StoreCreditService $storeCreditService): RedirectResponse
    {
        $this->ensureSuperAdmin();

        $validated = $request->validate([
            'amount'      => ['required', 'numeric', 'min:1000'],
            'note'        => ['required', 'string', 'max:1000'],
            'otp_session' => ['required', 'string'],
        ]);

        // Verify the OTP session is still valid before processing
        $otpRecord = OtpVerification::where('admin_id', auth()->id())
            ->where('context', 'manual_topup:' . $store->id)
            ->where('token_hash', $validated['otp_session'])
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $otpRecord) {
            return redirect()
                ->route('admin.stores.edit', ['store' => $store->id, 'active_tab' => 'store-limit-topup'])
                ->with('error', 'Phiên OTP không hợp lệ hoặc đã hết hạn. Vui lòng xác thực lại.');
        }

        // Mark the OTP as consumed so it can't be reused
        $otpRecord->update(['used_at' => now()]);

        try {
            $storeCreditService->recordAdminManualTopup(
                (string) $store->id,
                (float) $validated['amount'],
                trim($validated['note']),
                (int) auth()->id()
            );

            return redirect()
                ->route('admin.stores.edit', ['store' => $store->id, 'active_tab' => 'store-limit-topup'])
                ->with('success', 'Nạp hạn mức thủ công cho cửa hàng thành công.');
        } catch (\Exception $e) {
            return redirect()
                ->route('admin.stores.edit', ['store' => $store->id, 'active_tab' => 'store-limit-topup'])
                ->withInput()
                ->with('error', 'Lỗi khi nạp hạn mức: ' . $e->getMessage());
        }
    }

    // ─── Security: Verify access code ────────────────────────────────────────

    /**
     * POST /admin/stores/{store}/topup-verify-access-code
     * Validates the access code that "unlocks" the top-up UI.
     */
    public function topupVerifyAccessCode(Request $request, Store $store): JsonResponse
    {
        $this->ensureSuperAdmin();

        $validated = $request->validate([
            'access_code' => ['required', 'string', 'max:100'],
        ]);

        $storedHash = DB::table('system_configs')
            ->where('config_key', SystemConfig::KEY_MANUAL_TOPUP_ACCESS_CODE)
            ->value('config_value');

        if (! $storedHash || ! Hash::check($validated['access_code'], $storedHash)) {
            // Audit: failed access code attempt
            $this->logTopupAudit($store->id, 'topup_access_code_failed', [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Mã khóa không chính xác. Vui lòng thử lại.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Xác thực mã khóa thành công.',
        ]);
    }

    /**
     * POST /admin/stores/{store}/topup-send-otp
     * Generates an OTP and sends it to the currently logged-in admin's email.
     */
    public function topupSendOtp(Request $request, Store $store): JsonResponse
    {
        $this->ensureSuperAdmin();

        /** @var \App\Models\User $admin */
        $admin = auth()->user();

        $expiryMinutes = (int) (DB::table('system_configs')
            ->where('config_key', SystemConfig::KEY_OTP_EXPIRY_MINUTES)
            ->value('config_value') ?? 5);

        $otpLength = (int) (DB::table('system_configs')
            ->where('config_key', SystemConfig::KEY_OTP_LENGTH)
            ->value('config_value') ?? 6);

        $otpLength = max(4, min(10, $otpLength));

        // Invalidate any previous unused OTPs for this context
        OtpVerification::where('admin_id', $admin->id)
            ->where('context', 'manual_topup:' . $store->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        // Generate new OTP
        $otp = str_pad((string) random_int(0, (int) str_repeat('9', $otpLength)), $otpLength, '0', STR_PAD_LEFT);

        $record = OtpVerification::create([
            'admin_id'   => $admin->id,
            'context'    => 'manual_topup:' . $store->id,
            'token'      => $otp,
            'token_hash' => Hash::make($otp),
            'attempts'   => 0,
            'expires_at' => now()->addMinutes($expiryMinutes),
        ]);

        // Send OTP via email using Laravel's simple Mail facade
        $configuredEmail = DB::table('system_configs')
            ->where('config_key', SystemConfig::KEY_MANUAL_TOPUP_OTP_EMAIL)
            ->value('config_value');

        $adminEmail = !empty($configuredEmail) ? trim($configuredEmail) : $admin->email;
        $adminName  = $admin->full_name ?: $admin->name;

        try {
            $mailBody = "Xin chào,\n\n"
                . "Tài khoản Admin [{$adminName}] đang thực hiện yêu cầu nạp hạn mức thủ công cho cửa hàng [{$store->name}].\n\n"
                . "Mã OTP xác thực là: {$otp}\n\n"
                . "Mã này có hiệu lực trong {$expiryMinutes} phút. Vui lòng không chia sẻ mã này với bất kỳ ai.";

            Mail::raw(
                $mailBody,
                function ($message) use ($adminEmail) {
                    $message->to($adminEmail)
                        ->subject('[Ecoluck Admin] Mã OTP nạp hạn mức thủ công');
                }
            );
        } catch (\Exception $e) {
            Log::error('topupSendOtp mail failed: ' . $e->getMessage());
            // Don't block: OTP was still created; admin can see it in dev mode
        }

        $messageResponse = !empty($configuredEmail)
            ? 'Đã gửi mã OTP đến email được chỉ định trong cấu hình bảo mật.'
            : 'Đã gửi mã OTP đến email của bạn.';

        return response()->json([
            'success'         => true,
            'message'         => $messageResponse,
            'expires_minutes' => $expiryMinutes,
            // In production remove the next key – only for dev/debug
            // 'dev_otp' => $otp,
        ]);
    }

    /**
     * POST /admin/stores/{store}/topup-verify-otp
     * Verifies the submitted OTP and returns a short-lived session token
     * that the final manualTopup POST must include.
     */
    public function topupVerifyOtp(Request $request, Store $store): JsonResponse
    {
        $this->ensureSuperAdmin();

        $validated = $request->validate([
            'otp' => ['required', 'string', 'max:10'],
        ]);

        /** @var \App\Models\User $admin */
        $admin = auth()->user();

        $record = OtpVerification::where('admin_id', $admin->id)
            ->where('context', 'manual_topup:' . $store->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $record) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy OTP hợp lệ. Hãy yêu cầu mã mới.',
            ], 422);
        }

        // Guard against brute-force
        if ($record->attempts >= 5) {
            $record->update(['used_at' => now()]);
            return response()->json([
                'success' => false,
                'message' => 'Đã nhập sai quá nhiều lần. Vui lòng yêu cầu OTP mới.',
            ], 422);
        }

        if (! Hash::check($validated['otp'], $record->token_hash)) {
            $record->increment('attempts');
            $remaining = 5 - $record->attempts;
            return response()->json([
                'success' => false,
                'message' => "Mã OTP không đúng. Còn {$remaining} lần thử.",
            ], 422);
        }

        // OTP is correct – mark it used and return session token
        // We reuse token_hash as the session key so the final POST can verify
        $record->update(['used_at' => null]); // keep usable until final POST consumes it

        return response()->json([
            'success'     => true,
            'message'     => 'Xác thực OTP thành công.',
            'otp_session' => $record->token_hash,
        ]);
    }

    /** Write an audit log entry for top-up security events. */
    private function logTopupAudit(mixed $storeId, string $action, array $extra = []): void
    {
        try {
            if (Schema::hasTable('specialist_audit_logs')) {
                DB::table('specialist_audit_logs')->insert([
                    'specialist_id' => 0,
                    'actor_id'      => auth()->id(),
                    'action'        => $action,
                    'metadata'      => json_encode(array_merge(['store_id' => $storeId], $extra)),
                    'ip_address'    => request()->ip(),
                    'user_agent'    => request()->userAgent(),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        } catch (\Exception) {
            // Non-critical – swallow silently
        }
    }

    public function assignSale(Request $request, Store $store): RedirectResponse|JsonResponse
    {
        $this->ensureCanAssignSale();

        $validated = $request->validate([
            'sale_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn($query) => $query->where('role', 'sale')),
            ],
        ]);

        $previousSaleId = $store->sale_id;
        $newSaleId = $validated['sale_id'] ?? null;

        $store->update([
            'sale_id' => $newSaleId,
        ]);

        $this->syncSpecialistStoreAssignment($store, $previousSaleId ? (int) $previousSaleId : null, $newSaleId ? (int) $newSaleId : null);

        if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Đã cập nhật chuyên viên cửa hàng phụ trách cửa hàng.',
                'data' => [
                    'store_id' => $store->id,
                    'sale_id' => $store->sale_id,
                ],
            ]);
        }

        return back()->with('success', 'Đã cập nhật chuyên viên cửa hàng phụ trách cửa hàng.');
    }

    public function destroyStore(Store $store): RedirectResponse
    {
        abort_if(AdminCmsAccess::isSale(), 403, 'Chuyen vien khong co quyen xoa cua hang.');

        $storeName = $store->name;
        $store->delete();

        return back()->with('success', 'Đã xóa cửa hàng: ' . $storeName . '.');
    }

    public function index(Request $request)
    {
        $this->ensureSuperAdmin();

        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,active,rejected,all'],
        ]);

        $status = array_key_exists('status', $validated) ? $validated['status'] : 'pending';

        return view('admin.stores.approvals.index', compact('status'));
    }

    public function approvalsDatatable(Request $request): JsonResponse
    {
        $columns = [
            0 => 'store_code',
            1 => 'approval_submitted_at_sort',
            2 => 'approval_histories_count',
            3 => 'owner_id',
            4 => 'representative',
            5 => 'license_number',
            6 => 'status',
            7 => 'approved_at',
        ];

        $search = trim((string) $request->input('search.value', ''));
        $status = (string) $request->input('status', 'pending');
        if ($status === '' || !in_array($status, ['pending', 'active', 'rejected', 'all'], true)) {
            $status = 'pending';
        }

        $orderCol = (int) $request->input('order.0.column', 0);
        $orderField = $columns[$orderCol] ?? 'created_at';
        $orderDir = $request->input('order.0.dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $start = max(0, (int) $request->input('start', 0));
        $length = max(1, (int) $request->input('length', 20));

        $submittedSortExpr = Schema::hasColumn('stores', 'submitted_at')
            ? 'COALESCE(submitted_at, updated_at, created_at)'
            : 'COALESCE(updated_at, created_at)';

        $baseQuery = Store::query()
            ->select('stores.*')
            ->selectRaw($submittedSortExpr . ' as approval_submitted_at_sort')
            ->with([
                'owner:id,name,full_name,phone,email',
                'approver:id,name,full_name',
                'latestApprovalHistory',
                'licenses:id,store_id,license_type,license_number,image_url,status,rejection_reason,expiry_date,created_at',
            ])
            ->withCount('approvalHistories');

        if ($status !== 'all') {
            if ($status === 'pending') {
                $baseQuery->where(function ($query) {
                    $query->where('status', 'pending')
                        ->orWhereHas('licenses', function ($licenseQuery) {
                            $licenseQuery->where('status', 'pending');
                        });
                });
            } else {
                $baseQuery->where('status', $status);
            }
        }

        $recordsTotal = (clone $baseQuery)->count();

        if ($search !== '') {
            $baseQuery->where(function ($q) use ($search) {
                $q->where('store_code', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%')
                    ->orWhere('license_number', 'like', '%' . $search . '%')
                    ->orWhereHas('licenses', function ($licenseQuery) use ($search) {
                        $licenseQuery->where('license_type', 'like', '%' . $search . '%')
                            ->orWhere('license_number', 'like', '%' . $search . '%');
                    })
                    ->orWhere('representative', 'like', '%' . $search . '%')
                    ->orWhere('business_type', 'like', '%' . $search . '%')
                    ->orWhere('bank_name', 'like', '%' . $search . '%')
                    ->orWhere('bank_account', 'like', '%' . $search . '%')
                    ->orWhere('owner_id', 'like', '%' . $search . '%')
                    ->orWhereHas('owner', function ($ownerQ) use ($search) {
                        $ownerQ->where('name', 'like', '%' . $search . '%')
                            ->orWhere('full_name', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    });
            });
        }

        $recordsFiltered = (clone $baseQuery)->count();

        $stores = $baseQuery
            ->orderBy($orderField, $orderDir)
            ->skip($start)
            ->take($length)
            ->get();

        $data = $stores->map(function (Store $store) {
            $ownerName = optional($store->owner)->full_name ?: (optional($store->owner)->name ?: 'N/A');
            $ownerPhone = optional($store->owner)->phone ?: 'N/A';
            $ownerEmail = optional($store->owner)->email ?: ($store->email ?: 'N/A');
            $hasPendingLicenses = $store->licenses && $store->licenses->contains(function ($license) {
                return (string) ($license->status ?? 'pending') === 'pending';
            });

            $statusHtml = '<span class="badge bg-secondary">' . e((string) $store->status) . '</span>';
            if ($store->status === 'pending') {
                $statusHtml = '<span class="badge bg-warning text-dark">Chờ duyệt</span>';
            } elseif ($store->status === 'active') {
                $statusHtml = '<span class="badge bg-success">Đã duyệt</span>';
            } elseif ($store->status === 'rejected') {
                $statusHtml = '<span class="badge bg-danger">Từ chối</span>';
            }

            if ($hasPendingLicenses) {
                $statusHtml .= '<div class="mt-1"><span class="badge bg-warning text-dark">Có giấy tờ chờ duyệt</span></div>';
            }

            if (!empty($store->rejection_reason)) {
                $statusHtml .= '<div class="mt-1"><small class="text-danger">Lý do: ' . e((string) $store->rejection_reason) . '</small></div>';
            }

            $approverName = optional($store->approver)->full_name ?: (optional($store->approver)->name ?: 'N/A');
            $approvedAt = $store->approved_at ? $store->approved_at->format('Y-m-d H:i:s') : 'N/A';
            $submittedAtSource = $store->submitted_at ?? $store->updated_at ?? $store->created_at;
            $submittedAt = $submittedAtSource ? $submittedAtSource->format('Y-m-d H:i:s') : 'N/A';
            $attemptNumber = $this->resolveApprovalAttemptNumber($store);
            $historyCount = (int) ($store->approval_histories_count ?? 0);
            $attemptHtml = '<div><span class="badge bg-primary text-white">Lần ' . $attemptNumber . '</span></div>'
                . '<small class="text-muted">' . $historyCount . ' lịch sử</small>';
            $isPending = $store->status === 'pending';

            $approveDisabled = $isPending ? '' : 'disabled';
            $rejectDisabled = $isPending ? '' : 'disabled';
            $licenseDetails = $store->licenses->map(function ($license) {
                return [
                    'type' => (string) ($license->license_type ?? 'N/A'),
                    'number' => (string) ($license->license_number ?? 'N/A'),
                    'expiry' => $license->expiry_date ? $license->expiry_date->format('d/m/Y') : 'N/A',
                    'status' => (string) ($license->status ?? 'pending'),
                    'image_url' => (string) ($license->image_url ?? ''),
                    'rejection_reason' => (string) ($license->rejection_reason ?? ''),
                ];
            })->values()->all();
            $licenseCount = count($licenseDetails);

            $legalHtml = '<small class="text-muted">' . e($licenseCount) . ' giấy tờ</small>';

            return [
                'store' => '<div class="fw-semibold">' . e((string) $store->store_code) . '</div>'
                    . '<div>' . e((string) $store->name) . '</div>',
                'submitted_at' => '<small class="text-muted">' . e($submittedAt) . '</small>',
                'approval_attempt' => $attemptHtml,
                'owner' => '<div>' . e($ownerName) . '</div>',
                'registration' => '<div><strong>Loại hình KD:</strong> ' . e((string) ($store->business_type ?? 'N/A')) . '</div>',
                'legal' => $legalHtml,
                'status' => $statusHtml,
                'approval' => '<div><small class="text-muted">Người duyệt: ' . e($approverName) . '</small></div>'
                    . '<div><small class="text-muted">Thời gian: ' . e($approvedAt) . '</small></div>',
                'action' => '<div class="d-flex flex-column gap-2 mb-2">'
                    . '<a href="' . route('admin.store-approvals.licenses', $store->id) . '" class="btn btn-outline-primary btn-sm">Xem chi tiết</a>'
                    . '</div>',
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw', 1),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    private function resolveApprovalAttemptNumber(Store $store): int
    {
        $latestHistory = $store->latestApprovalHistory;
        if (!$latestHistory) {
            return 1;
        }

        $attemptNumber = max(1, (int) $latestHistory->attempt_number);
        $currentStatus = mb_strtolower(trim((string) ($store->status ?? '')));
        $latestHistoryStatus = mb_strtolower(trim((string) ($latestHistory->status ?? '')));

        if ($currentStatus === 'pending' && $latestHistoryStatus !== 'pending') {
            return $attemptNumber + 1;
        }

        return $attemptNumber;
    }

    public function review(Request $request, string $storeId): RedirectResponse
    {
        $this->ensureSuperAdmin();

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $store = Store::query()->where('id', $storeId)->first();
        if (!$store) {
            abort(404, 'Store not found.');
        }

        if (strtolower((string) $store->status) !== 'pending') {
            return back()->withErrors(['store' => 'Store already processed.']);
        }

        $action = strtolower((string) $validated['action']);

        if ($action === 'reject' && trim((string) ($validated['reason'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Vui lòng nhập lý do từ chối',
            ]);
        }

        DB::transaction(function () use ($action, $validated, $store) {
            if ($action === 'approve') {
                $store->update([
                    'status' => 'active',
                    'approved_by' => (string) auth()->id(),
                    'approved_at' => now(),
                    'rejection_reason' => null,
                ]);
                $this->approvalHistoryService->markReviewed($store, 'approved', auth()->id());

                if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
                    DB::table('users')
                        ->where('id', $store->owner_id)
                        ->update([
                            'role' => 'admin_store',
                            'updated_at' => now(),
                        ]);
                }

                // Also approve any pending store licenses when the store is approved
                try {
                    $store->licenses()->where('status', 'pending')->update([
                        'status' => 'approved',
                        'rejection_reason' => null,
                    ]);
                } catch (\Throwable $e) {
                    // swallow to avoid breaking the main approval flow; licenses can be handled separately
                }

                $this->notifyStoreOwner($store, 'approved', $reason ?? null);

                $pendingCount = Store::where('status', 'pending')->count();
                event(new \App\Events\StoreApprovalUpdated($store->id, 'active', $pendingCount));

                return;
            }

            $reason = trim((string) ($validated['reason'] ?? ''));

            $store->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'approved_by' => (string) auth()->id(),
                'approved_at' => now(),
            ]);
            $this->approvalHistoryService->markReviewed($store, 'rejected', auth()->id(), $reason);

            $this->notifyStoreOwner($store, 'rejected', $reason);

            if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
                DB::table('users')
                    ->where('id', $store->owner_id)
                    ->update([
                        'role' => 'account_user',
                        'updated_at' => now(),
                    ]);
            }

            $pendingCount = Store::where('status', 'pending')->count();
            event(new \App\Events\StoreApprovalUpdated($store->id, 'rejected', $pendingCount));
        });

        return back()->with('success', 'Xử lý duyệt cửa hàng thành công.');
    }

    public function approve(Request $request, Store $store): RedirectResponse
    {
        $this->ensureSuperAdmin();

        if ($store->status === 'active') {
            return back()->with('success', 'Cửa hàng đã ở trạng thái đã duyệt.');
        }

        $store->update([
            'status' => 'active',
            'approved_by' => (string) auth()->id(),
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);
        $this->approvalHistoryService->markReviewed($store, 'approved', auth()->id());

        // Also approve any pending store licenses when approving the store
        try {
            $store->licenses()->where('status', 'pending')->update([
                'status' => 'approved',
                'rejection_reason' => null,
            ]);
        } catch (\Throwable $e) {
            // ignore license errors to preserve main flow
        }

        $this->notifyStoreOwner($store, 'approved', null);

        $pendingCount = Store::where('status', 'pending')->count();
        event(new \App\Events\StoreApprovalUpdated($store->id, 'active', $pendingCount));

        return back()->with('success', 'Duyệt cửa hàng thành công.');
    }

    public function reject(Request $request, Store $store): RedirectResponse
    {
        $this->ensureSuperAdmin();

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        $store->update([
            'status' => 'rejected',
            'rejection_reason' => trim($validated['rejection_reason']),
            'approved_by' => null,
            'approved_at' => null,
        ]);
        $this->approvalHistoryService->markReviewed($store, 'rejected', auth()->id(), trim($validated['rejection_reason']));

        $this->notifyStoreOwner($store, 'rejected', trim($validated['rejection_reason']));

        $pendingCount = Store::where('status', 'pending')->count();
        event(new \App\Events\StoreApprovalUpdated($store->id, 'rejected', $pendingCount));

        return back()->with('success', 'Đã từ chối cửa hàng và lưu lý do.');
    }

    public function approveLicense(Request $request, StoreLicense $license): RedirectResponse
    {
        $this->ensureSuperAdmin();

        if ($license->status === 'approved') {
            return back()->with('success', 'Giấy tờ đã ở trạng thái đã duyệt.');
        }

        $license->update([
            'status' => 'approved',
            'rejection_reason' => null,
        ]);

        return back()->with('success', 'Duyệt giấy tờ thành công.');
    }

    public function rejectLicense(Request $request, StoreLicense $license): RedirectResponse
    {
        $this->ensureSuperAdmin();

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        $license->update([
            'status' => 'rejected',
            'rejection_reason' => trim((string) $validated['rejection_reason']),
        ]);

        return back()->with('success', 'Đã từ chối giấy tờ và lưu lý do.');
    }

    public function showLicenses(Store $store)
    {
        $this->ensureSuperAdmin();

        $licenses = $store->licenses()
            ->orderBy('created_at', 'desc')
            ->get();

        return view('admin.stores.approvals.licenses', compact('store', 'licenses'));
    }

    private function ensureSuperAdmin(): void
    {
        $role = (string) optional(auth()->user())->role;
        abort_unless(in_array($role, ['super_admin', 'admin'], true), 403, 'Chỉ admin được phép thực hiện thao tác này.');
    }

    private function ensureCanAssignSale(): void
    {
        $user = auth()->user();
        $role = (string) optional($user)->role;

        abort_unless(
            $user && (in_array($role, ['super_admin', 'admin'], true) || $user->can('STORE_ASSIGN_SALE')),
            403,
            'Ban khong co quyen phan cong chuyen vien cho cua hang.'
        );
    }

    private function resolveLocationValue(array $validated)
    {
        if (
            !isset($validated['latitude']) || $validated['latitude'] === '' ||
            !isset($validated['longitude']) || $validated['longitude'] === ''
        ) {
            return null;
        }

        $lat = (float) $validated['latitude'];
        $lng = (float) $validated['longitude'];
        if (in_array($this->getStoreLocationType(), ['geometry', 'point', 'geography'], true)) {
            return DB::raw("ST_GeomFromText('POINT({$lng} {$lat})')");
        }

        return [
            'lat' => $lat,
            'lng' => $lng,
        ];
    }

    private function getStoreLocationType(): ?string
    {
        try {
            return Schema::getColumnType('stores', 'location');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function notifyStoreOwner(Store $store, string $status, ?string $reason): void
    {
        $ownerId = (string) ($store->owner_id ?? '');
        if ($ownerId === '') {
            return;
        }

        $storeCode = trim((string) ($store->store_code ?? $store->id));
        $storeName = trim((string) ($store->name ?? '')) ?: $storeCode;
        $statusLabel = $status === 'approved' ? 'đã được duyệt' : 'bị từ chối';
        $body = 'Cửa hàng ' . $storeName . ' ' . $statusLabel . '.';
        if ($status === 'rejected' && $reason) {
            $body .= ' Lý do: ' . $reason;
        }

        $this->notificationService->create([
            'audience_scope' => 'user_private',
            'user_id' => $ownerId,
            'type' => 'system',
            'title' => $status === 'approved' ? 'Hồ sơ cửa hàng đã được duyệt' : 'Hồ sơ cửa hàng bị từ chối',
            'body' => $body,
            'message' => $body,
            'action_url' => self::ACTION_KEY_STORE_APPLICATION_STATUS,
        ]);
    }

    private function extractCoordinates(Store $store): array
    {
        $latitude = null;
        $longitude = null;

        if (is_array($store->location)) {
            $latitude = $store->location['lat'] ?? null;
            $longitude = $store->location['lng'] ?? null;
        }

        if ($latitude !== null && $longitude !== null) {
            return [$latitude, $longitude];
        }

        try {
            $locationType = Schema::getColumnType('stores', 'location');
            if (in_array($locationType, ['geometry', 'point', 'geography'], true)) {
                $point = DB::table('stores')
                    ->where('id', $store->id)
                    ->selectRaw('ST_Y(location) as latitude, ST_X(location) as longitude')
                    ->first();

                if ($point) {
                    return [$point->latitude, $point->longitude];
                }
            }
        } catch (\Throwable $e) {
        }

        return [null, null];
    }

    private function syncSpecialistStoreAssignment(Store $store, ?int $previousSaleId, ?int $newSaleId): void
    {
        if (
            ! Schema::hasTable('specialist_store_assignments')
            || ! Schema::hasColumn('specialist_store_assignments', 'specialist_id')
            || ! Schema::hasColumn('specialist_store_assignments', 'store_id')
            || ! Schema::hasColumn('specialist_store_assignments', 'assigned_to')
        ) {
            return;
        }

        $now = now();
        $storeId = (string) $store->id;

        DB::table('specialist_store_assignments')
            ->where('store_id', $storeId)
            ->whereNull('assigned_to')
            ->when($newSaleId !== null, function ($query) use ($newSaleId) {
                $query->where('specialist_id', '!=', $newSaleId);
            })
            ->update([
                'assigned_to' => $now,
                'updated_at' => $now,
            ]);

        if ($newSaleId === null) {
            return;
        }

        $activeExists = DB::table('specialist_store_assignments')
            ->where('store_id', $storeId)
            ->where('specialist_id', $newSaleId)
            ->whereNull('assigned_to')
            ->exists();

        if ($activeExists) {
            return;
        }

        $assignmentId = DB::table('specialist_store_assignments')->insertGetId([
            'specialist_id' => $newSaleId,
            'store_id' => $storeId,
            'assigned_from' => $now,
            'assigned_to' => null,
            'assigned_by' => auth()->id(),
            'notes' => $previousSaleId !== $newSaleId ? 'Phân công từ CMS quản lý cửa hàng' : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->specialistNotificationService->notifyStoreAssigned(
            $store,
            $newSaleId,
            $previousSaleId,
            (string) $assignmentId
        );
    }

    private function applyStoreStatusFilter($query, string $statusFilter): void
    {
        $statusFilter = strtolower(trim($statusFilter));
        if ($statusFilter === '' || $statusFilter === 'all') {
            return;
        }

        $hasStatus = Schema::hasColumn('stores', 'status');
        $hasIsOpen = Schema::hasColumn('stores', 'is_open');

        if ($statusFilter === 'active') {
            if ($hasStatus) {
                $query->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), ['active', 'approved']);
            }
            if ($hasIsOpen) {
                $query->where('is_open', true);
            }

            return;
        }

        if ($statusFilter === 'suspended') {
            if (! $hasStatus && ! $hasIsOpen) {
                return;
            }

            $query->where(function ($scope) use ($hasStatus, $hasIsOpen) {
                if ($hasStatus) {
                    $scope->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), [
                        'suspended',
                        'paused',
                        'inactive',
                        'blocked',
                        'closed',
                    ]);
                }

                if ($hasIsOpen) {
                    $scope->orWhere('is_open', false);
                }
            });

            return;
        }

        if ($hasStatus && in_array($statusFilter, ['pending', 'rejected'], true)) {
            $query->whereRaw("LOWER(COALESCE(status, '')) = ?", [$statusFilter]);
        }
    }

    private function applyStoreRegionFilter($query, string $region): void
    {
        if (Schema::hasColumn('stores', 'region_id') && ctype_digit($region)) {
            $query->where('region_id', (int) $region);

            return;
        }

        $query->where(function ($scope) use ($region) {
            $hasSearchableColumn = false;
            foreach (['address', 'name', 'store_code'] as $column) {
                if (! Schema::hasColumn('stores', $column)) {
                    continue;
                }

                $hasSearchableColumn = true;
                $scope->orWhere($column, 'like', '%' . $region . '%');
            }

            if (! $hasSearchableColumn) {
                $scope->whereRaw('1 = 1');
            }
        });
    }

    private function applyStoreWithoutOrdersFilter($query): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'store_id')) {
            return;
        }

        $query->whereNotExists(function ($orders) {
            $orders->selectRaw('1')
                ->from('orders as store_orders')
                ->whereColumn('store_orders.store_id', 'stores.id');
        });
    }

    private function applyStoreHeldTicketsFilter($query): void
    {
        if (! Schema::hasTable('tickets') || ! Schema::hasColumn('tickets', 'status')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $canSourceScope = Schema::hasColumn('tickets', 'source_type') && Schema::hasColumn('tickets', 'source_id');
        $canUsedOrderScope = Schema::hasColumn('tickets', 'used_in_order_id') && $this->canScopeTicketsByOrder();
        $canOrderTicketScope = Schema::hasTable('order_tickets')
            && Schema::hasColumn('order_tickets', 'ticket_id')
            && Schema::hasColumn('order_tickets', 'order_id')
            && $this->canScopeTicketsByOrder();

        if (! $canSourceScope && ! $canUsedOrderScope && ! $canOrderTicketScope) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereExists(function ($tickets) use ($canSourceScope, $canUsedOrderScope, $canOrderTicketScope) {
            $tickets->selectRaw('1')
                ->from('tickets as t')
                ->whereIn(DB::raw("UPPER(COALESCE(t.status, ''))"), ['HELD', 'DISPUTED', 'LOCKED'])
                ->where(function ($scope) use ($canSourceScope, $canUsedOrderScope, $canOrderTicketScope) {
                    if ($canSourceScope) {
                        $scope->orWhere(function ($sourceStore) {
                            $sourceStore
                                ->whereIn(DB::raw("UPPER(COALESCE(t.source_type, ''))"), ['STORE'])
                                ->whereColumn('t.source_id', 'stores.id');
                        });

                        if ($this->canScopeTicketsByOrder()) {
                            $scope->orWhereExists(function ($orderSource) {
                                $orderSource->selectRaw('1')
                                    ->from('orders as ticket_source_orders')
                                    ->whereColumn('ticket_source_orders.id', 't.source_id')
                                    ->whereColumn('ticket_source_orders.store_id', 'stores.id');
                            });
                        }
                    }

                    if ($canUsedOrderScope) {
                        $scope->orWhereExists(function ($usedOrder) {
                            $usedOrder->selectRaw('1')
                                ->from('orders as ticket_used_orders')
                                ->whereColumn('ticket_used_orders.id', 't.used_in_order_id')
                                ->whereColumn('ticket_used_orders.store_id', 'stores.id');
                        });
                    }

                    if ($canOrderTicketScope) {
                        $scope->orWhereExists(function ($orderTicket) {
                            $orderTicket->selectRaw('1')
                                ->from('order_tickets as ot')
                                ->join('orders as ticket_order_links', 'ticket_order_links.id', '=', 'ot.order_id')
                                ->whereColumn('ot.ticket_id', 't.id')
                                ->whereColumn('ticket_order_links.store_id', 'stores.id');
                        });
                    }
                });
        });
    }

    private function canScopeTicketsByOrder(): bool
    {
        return Schema::hasTable('orders')
            && Schema::hasColumn('orders', 'id')
            && Schema::hasColumn('orders', 'store_id');
    }

    private function businessTypeOptions()
    {
        if (! Schema::hasTable('store_business_types')) {
            return collect();
        }

        return StoreBusinessType::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    private function formatStoreStatusBadge(Store $store): string
    {
        $status = strtolower(trim((string) ($store->status ?? '')));
        $isOpen = (bool) ($store->is_open ?? true);

        if (in_array($status, ['active', 'approved'], true) && $isOpen) {
            return '<span class="badge bg-success">Hoạt động</span>';
        }

        if (! $isOpen) {
            return '<span class="badge bg-warning text-dark">Tạm ngưng</span>';
        }

        if ($status === 'pending') {
            return '<span class="badge bg-warning text-dark">Chờ duyệt</span>';
        }

        if ($status === 'rejected') {
            return '<span class="badge bg-danger">Từ chối</span>';
        }

        return '<span class="badge bg-secondary">' . e($store->status ?: 'N/A') . '</span>';
    }

    private function latestStoreComplaints(string $storeId)
    {
        if (! Schema::hasTable('complaints')) {
            return collect();
        }

        return DB::table('complaints')
            ->where('store_id', $storeId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get([
                'id',
                'complaint_code',
                'title',
                'status',
                'severity',
                'order_id',
                'created_at',
            ]);
    }

    private function latestStoreCareLogs(string $storeId)
    {
        if (! Schema::hasTable('specialist_store_care_logs')) {
            return collect();
        }

        $columns = [
            'logs.id',
            'logs.care_type',
            'logs.content',
            'logs.next_follow_up_at',
            'logs.created_at',
            'specialists.full_name as specialist_full_name',
            'specialists.name as specialist_name',
            'creators.full_name as creator_full_name',
            'creators.name as creator_name',
        ];

        foreach (
            [
                'care_content',
                'problem_detected',
                'proposed_solution',
                'result_note',
                'attachment_file_id',
                'attachment_original_name',
                'attachment_mime_type',
                'attachment_size',
            ] as $column
        ) {
            if (Schema::hasColumn('specialist_store_care_logs', $column)) {
                $columns[] = 'logs.' . $column;
            }
        }

        return DB::table('specialist_store_care_logs as logs')
            ->leftJoin('users as specialists', 'specialists.id', '=', 'logs.specialist_id')
            ->leftJoin('users as creators', 'creators.id', '=', 'logs.created_by')
            ->where('logs.store_id', $storeId)
            ->orderByDesc('logs.created_at')
            ->limit(20)
            ->get($columns);
    }

    private function nullableText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function storeAssignmentHistory(string $storeId)
    {
        if (! Schema::hasTable('specialist_store_assignments')) {
            return collect();
        }

        return DB::table('specialist_store_assignments as assignments')
            ->leftJoin('users as specialists', 'specialists.id', '=', 'assignments.specialist_id')
            ->where('assignments.store_id', $storeId)
            ->orderByDesc('assignments.assigned_from')
            ->limit(10)
            ->get([
                'assignments.specialist_id',
                'assignments.assigned_from',
                'assignments.assigned_to',
                'assignments.notes',
                'specialists.full_name as specialist_full_name',
                'specialists.name as specialist_name',
                'specialists.email as specialist_email',
            ]);
    }

    private function saleUsers()
    {
        return User::query()
            ->select('id', 'name', 'full_name', 'phone', 'email', 'role')
            ->where('role', 'sale')
            ->when(Schema::hasColumn('users', 'status'), function ($query) {
                $query->where(function ($statusQuery) {
                    $statusQuery->whereNull('status')->orWhere('status', 'active');
                });
            })
            ->orderBy('full_name')
            ->orderBy('name')
            ->get();
    }

    public function destroyAdminStore($userId, $storeId)
    {
        $user = User::find($userId);
        $store = Store::find($storeId);

        $deleted = StoreStaffPermission::where(
            'user_id',
            $userId
        )
            ->where(
                'store_id',
                $storeId
            )
            ->delete();

        if ($deleted > 0 && $user && $store) {
            $this->notificationService->notifyStoreAccountRemoved($user, $store);
        }

        return back()->with(
            'success',
            'Đã xóa admin khỏi cửa hàng'
        );
    }

    public function resetPassword($userId, $storeId)
    {
        $permissionExists =
            StoreStaffPermission::where(
                'user_id',
                $userId
            )
            ->where(
                'store_id',
                $storeId
            )
            ->exists();

        if (!$permissionExists) {

            abort(404);
        }

        $user =
            User::findOrFail($userId);

        $newPassword =
            Str::password(
                length: 10,
                letters: true,
                numbers: true,
                symbols: false
            );

        $user->update([

            'password' =>
            Hash::make(
                $newPassword
            )

        ]);

        return back()->with(

            'success',

            "Mật khẩu mới: {$newPassword}"

        );
    }
}

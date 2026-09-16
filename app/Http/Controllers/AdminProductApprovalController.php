<?php

namespace App\Http\Controllers;

use App\Events\ProductApprovalUpdated;
use App\Events\StoreAdminProductUpdated;
use App\Models\Notification;
use App\Models\Product;
use App\Models\StoreAdminNotification;
use App\Services\NotificationService;
use App\Services\ProductApprovalHistoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Yajra\DataTables\Facades\DataTables;

class AdminProductApprovalController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly ProductApprovalHistoryService $approvalHistoryService,
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:pending_approval,published,rejected,draft,all'],
        ]);

        $query = Product::query()
            ->with([
                'store:id,store_code,name',
                'category:id,name',
                'variants:id,product_id,price,sale_price,stock',
                'latestApprovalHistory',
            ])
            ->withCount(['variants', 'approvalHistories'])
            ->orderByDesc('created_at');

        $status = $validated['status'] ?? 'pending_approval';
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if (!empty($validated['keyword'])) {
            $keyword = trim((string) $validated['keyword']);
            $query->where(function ($sub) use ($keyword) {
                $sub->where('name', 'like', '%' . $keyword . '%')
                    ->orWhere('sku', 'like', '%' . $keyword . '%')
                    ->orWhereHas('store', function ($storeQuery) use ($keyword) {
                        $storeQuery->where('store_code', 'like', '%' . $keyword . '%')
                            ->orWhere('name', 'like', '%' . $keyword . '%');
                    })
                    ->orWhereHas('category', function ($categoryQuery) use ($keyword) {
                        $categoryQuery->where('name', 'like', '%' . $keyword . '%');
                    });
            });
        }

        $products = $query->paginate(15)->withQueryString();
        $products->getCollection()->transform(function (Product $product) {
            $product->setAttribute('price_label', $this->resolvePriceLabel($product));
            return $product;
        });

        return view('admin.products.approvals.index', [
            'products' => $products,
            'status' => $status,
        ]);
    }

    public function datatable(Request $request)
    {
        $query = Product::query()
            ->select([
                'id',
                'store_id',
                'category_id',
                'name',
                'sku',
                'status',
                'commission_rate',
                'commission_rate_mode',
                'ticket_reward_quantity',
                'ticket_reward_enabled',
                'ticket_type_code',
                'created_at',
            ])
            ->with([
                'store:id,store_code,name',
                'category:id,name',
                'variants:id,product_id,price,sale_price,commission_rate,commission_rate_mode,ticket_reward_quantity,ticket_type_code',
                'latestApprovalHistory',
            ])
            ->withCount(['variants', 'approvalHistories'])
            ->where('status', 'pending_approval');

        return DataTables::eloquent($query)
            ->addColumn('checkbox', function (Product $product) {

                return '
        <input
            type="checkbox"
            class="product-row-checkbox"
            value="' . $product->id . '">
    ';
            })
            ->addColumn('product', function (Product $product) {

                $html = '
                <a href="' . route('admin.product-approvals.show', $product->id) . '"
                   ><div class="fw-bold">'
                    . e($product->name)
                    . '</div>';

                if ($product->sku) {
                    $html .= '<div class="small text-muted">'
                        . e($product->sku)
                        . '</div>';
                }


                return $html .= '</a>';
            })

            ->addColumn('approval_attempt', function (Product $product) {
                $attemptNumber = $this->resolveApprovalAttemptNumber($product);
                $historyCount = (int) ($product->approval_histories_count ?? 0);

                return '
                <div class="approval-attempt-cell">
                    <div class="approval-attempt-badge">L&#7847;n ' . $attemptNumber . '</div>
                    <div class="approval-attempt-sub">' . $historyCount . ' l&#7883;ch s&#7917;</div>
                </div>
            ';
            })

            ->addColumn('store', function (Product $product) {

                if (!$product->store) {
                    return '--';
                }

                return '
                <div class="fw-semibold">' . e($product->store->name) . '</div>
                <div class="small text-muted">' . e($product->store->store_code) . '</div>
            ';
            })

            ->addColumn('category', function (Product $product) {
                return $product->category?->name ?? '--';
            })

            ->addColumn('price_label', function (Product $product) {

                if ($product->variants->isEmpty()) {
                    return '--';
                }

                $prices = $product->variants
                    ->map(fn($v) => $v->sale_price ?: $v->price)
                    ->filter();

                if ($prices->isEmpty()) {
                    return '--';
                }

                $min = $prices->min();
                $max = $prices->max();

                if ($min == $max) {
                    return number_format($min) . 'đ';
                }

                return number_format($min) . 'đ - ' . number_format($max) . 'đ';
            })

            // ->editColumn('created_at', function (Product $product) {
            //     return optional($product->created_at)->format('d/m/Y H:i');
            // })

            ->addColumn('ticket_reward_label', function (Product $product) {
                $ticket = $this->resolveProductTicketReward($product);

                if ($ticket['quantity'] <= 0) {
                    return '<span class="badge bg-secondary">Không có</span>';
                }

                return '<div class="fw-semibold text-success">+' . number_format($ticket['quantity'], 0, ',', '.') . ' Ticket</div>'
                    . '<div class="small text-muted">' . e($ticket['type_code']) . '</div>';
            })

            ->addColumn('affiliate_commission_label', function (Product $product) {
                $commission = $this->resolveProductCommission($product);

                if ($commission['value'] <= 0) {
                    return '<span class="badge bg-secondary">0%</span>';
                }

                return '<span class="badge bg-success">' . $this->formatCommissionLabel($commission['value'], $commission['mode']) . '</span>';
            })

            ->addColumn('action', function (Product $product) {

                return '
                <a href="' . route('admin.product-approvals.show', $product->id) . '"
                   class="btn btn-sm btn-primary">
                    Xem
                </a>
            ';
            })

            ->filter(function ($query) use ($request) {

                $keyword = trim($request->input('search.value', ''));

                if (!$keyword) {
                    return;
                }

                $query->where(function ($sub) use ($keyword) {

                    $sub->where('products.name', 'like', "%{$keyword}%")
                        ->orWhere('products.sku', 'like', "%{$keyword}%")

                        ->orWhereHas('store', function ($storeQuery) use ($keyword) {
                            $storeQuery->where('name', 'like', "%{$keyword}%")
                                ->orWhere('store_code', 'like', "%{$keyword}%");
                        })

                        ->orWhereHas('category', function ($categoryQuery) use ($keyword) {
                            $categoryQuery->where('name', 'like', "%{$keyword}%");
                        });
                });
            })

            ->rawColumns([
                'checkbox',
                'product',
                'approval_attempt',
                'store',
                'ticket_reward_label',
                'affiliate_commission_label',
                'action',
            ])

            ->make(true);
    }

    public function bulkApprove(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:products,id'],
        ]);

        $products = Product::query()
            ->whereIn('id', $validated['ids'])
            ->where('status', 'pending_approval')
            ->get();

        DB::transaction(function () use ($products): void {
            foreach ($products as $product) {
                $storeName = optional($product->store)->name ?? $product->store_id ?? 'N/A';

                $updateData = [
                    'status' => 'published',
                    'approved_by' => (string) auth()->id(),
                    'approved_at' => now(),
                    'published_at' => now(),
                ];

                if (Schema::hasColumn('products', 'rejection_reason')) {
                    $updateData['rejection_reason'] = null;
                }

                $product->update($updateData);
                $this->approvalHistoryService->markReviewed($product, 'approved', auth()->id());
                $this->upsertStoreMenuFromApprovedProduct($product);

                $actionUrl = !empty($product->store_id)
                    ? route('admin.stores.edit', [
                        'store' => $product->store_id,
                        'active_tab' => 'store-product-approvals',
                    ])
                    : route('admin.notifications.index');

                $this->notificationService->create([
                    'title' => 'Duyệt sản phẩm cửa hàng',
                    'message' => 'Sản phẩm "' . $product->name . '" của cửa hàng ' . $storeName . ' đã được duyệt và lên kệ.',
                    'type' => 'product_approval',
                    'action_url' => $actionUrl,
                    'order_id' => null,
                    'audience_scope' => 'admin_shared',
                ]);

                $this->createStoreAdminNotification($product->store_id, [
                    'title' => 'Sản phẩm đã được duyệt',
                    'message' => 'Sản phẩm "' . $product->name . '" đã được duyệt và đăng lên kệ.',
                    'type' => 'product_approval',
                    'data' => [
                        'product_id' => (string) $product->id,
                        'product_name' => $product->name,
                        'action_url' => '/admin_store/menu',
                    ],
                ]);

                if (Schema::hasColumn('products', 'status')) {
                    $pendingApprovalCount = Product::query()
                        ->where('store_id', $product->store_id)
                        ->where('status', 'pending_approval')
                        ->count();

                    event(new \App\Events\ProductApprovalUpdated(
                        (string) $product->store_id,
                        $pendingApprovalCount,
                        'approved',
                        (string) $product->id,
                        'published'
                    ));

                    event(new \App\Events\StoreAdminProductUpdated(
                        (string) ($product->store_id ?? ''),
                        (string) $product->id,
                        'approved'
                    ));
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Đã duyệt sản phẩm.',
        ]);
    }

    public function bulkReject(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:products,id'],
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        $products = Product::query()
            ->whereIn('id', $validated['ids'])
            ->where('status', 'pending_approval')
            ->get();

        $reason = trim((string) $validated['rejection_reason']);

        DB::transaction(function () use ($products, $reason): void {
            foreach ($products as $product) {
                $storeName = optional($product->store)->name ?? $product->store_id ?? 'N/A';

                $updateData = [
                    'status' => 'rejected',
                    'approved_by' => null,
                    'approved_at' => null,
                    'published_at' => null,
                ];

                if (Schema::hasColumn('products', 'rejection_reason')) {
                    $updateData['rejection_reason'] = $reason;
                }

                $product->update($updateData);
                $this->approvalHistoryService->markReviewed($product, 'rejected', auth()->id(), $reason);
                $this->removeProductFromStoreMenu($product);

                $actionUrl = !empty($product->store_id)
                    ? route('admin.stores.edit', [
                        'store' => $product->store_id,
                        'active_tab' => 'store-product-approvals',
                    ])
                    : route('admin.notifications.index');

                $this->notificationService->create([
                    'title' => 'Từ chối sản phẩm cửa hàng',
                    'message' => 'Sản phẩm "' . $product->name . '" của cửa hàng ' . $storeName . ' đã bị từ chối. Lý do: ' . $reason,
                    'type' => 'product_approval',
                    'action_url' => $actionUrl,
                    'order_id' => null,
                    'audience_scope' => 'admin_shared',
                ]);

                $this->createStoreAdminNotification($product->store_id, [
                    'title' => 'Sản phẩm bị từ chối',
                    'message' => 'Sản phẩm "' . $product->name . '" đã bị từ chối. Lý do: ' . $reason,
                    'type' => 'product_approval',
                    'data' => [
                        'product_id' => (string) $product->id,
                        'product_name' => $product->name,
                        'rejection_reason' => $reason,
                        'action_url' => '/admin_store/menu',
                    ],
                ]);

                if (Schema::hasColumn('products', 'status')) {
                    $pendingApprovalCount = Product::query()
                        ->where('store_id', $product->store_id)
                        ->where('status', 'pending_approval')
                        ->count();

                    event(new \App\Events\ProductApprovalUpdated(
                        (string) $product->store_id,
                        $pendingApprovalCount,
                        'rejected',
                        (string) $product->id,
                        'rejected'
                    ));

                    event(new \App\Events\StoreAdminProductUpdated(
                        (string) ($product->store_id ?? ''),
                        (string) $product->id,
                        'rejected'
                    ));
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Đã từ chối sản phẩm.',
        ]);
    }

    public function show(Product $product)
    {
        $product->load([
            'store.owner',
            'category',
            'variants.attributeValues.attribute',
            'approvalHistories.submitter:id,name,full_name,email',
            'approvalHistories.reviewer:id,name,full_name,email',
            'latestApprovalHistory',
            'toppings',
        ]);

        $product->setAttribute('price_label', $this->resolvePriceLabel($product));
        $product->setAttribute('approval_attempt_number', $this->resolveApprovalAttemptNumber($product));
        $approvalCommission = $this->resolveProductCommission($product);
        $product->setAttribute('approval_commission_rate', $approvalCommission['value']);
        $product->setAttribute('approval_commission_mode', $approvalCommission['mode']);
        $ticketReward = $this->resolveProductTicketReward($product);
        $product->setAttribute('approval_ticket_reward_quantity', $ticketReward['quantity']);
        $product->setAttribute('approval_ticket_type_code', $ticketReward['type_code']);
        $product->setAttribute('thumbnail_url', $this->resolveMediaUrl($product->thumbnail));
        $product->setAttribute('image_urls', collect($product->images ?? [])->filter()->map(fn($image) => $this->resolveMediaUrl((string) $image))->values()->all());
        $product->setAttribute(
            'ticket_label',
            $product->ticket_reward_enabled
                ? ($product->ticket_reward_quantity ?? 0) .
                ' ticket (' .
                ($product->ticket_type_code ?? 'default')
                . ')'
                : 'Không áp dụng'
        );
        $variantRows = $product->variants->map(function ($variant) use ($product) {
            $attributeLabels = $variant->attributeValues->map(function ($attributeValue) {
                $attributeName = $attributeValue->attribute->name ?? 'Thuộc tính';
                return $attributeName . ': ' . ($attributeValue->value ?? 'N/A');
            })->values()->all();

            $variantCommissionRate = $variant->commission_rate !== null && (float) $variant->commission_rate > 0
                ? (float) $variant->commission_rate
                : (float) ($product->approval_commission_rate ?? $product->commission_rate ?? 0);
            $variantCommissionMode = $variant->commission_rate !== null && (float) $variant->commission_rate > 0
                ? $this->normalizeCommissionMode($variant->commission_rate_mode ?? $product->approval_commission_mode ?? null)
                : $this->normalizeCommissionMode($product->approval_commission_mode ?? $product->commission_rate_mode ?? null);
            $variantTicketQuantity = (int) ($variant->ticket_reward_quantity ?? 0);
            if ($product->variants->count() <= 1 && $variantTicketQuantity <= 0) {
                $variantTicketQuantity = (int) ($product->approval_ticket_reward_quantity ?? $product->ticket_reward_quantity ?? 0);
            }

            return [
                'id' => $variant->id,
                'label' => !empty($attributeLabels) ? implode(' / ', $attributeLabels) : $product->name,
                'price' => $variant->price,
                'sale_price' => $variant->sale_price,
                'stock' => $variant->stock,
                'image_url' => $this->resolveMediaUrl($variant->image),
                'commission_rate' => $variantCommissionRate,
                'commission_rate_mode' => $variantCommissionMode,
                'ticket_reward_quantity' => $variantTicketQuantity,

                'ticket_type_code' =>
                $variant->ticket_type_code
                    ?? $product->ticket_type_code,
            ];
        })->values();

        $product->setAttribute(
            'topping_rows',

            $product->toppings
                ->map(fn($topping) => [

                    'id' => $topping->id,

                    'name' => $topping->name,

                    'sale_price' => $topping->sale_price,

                ])
                ->values()
        );
        return view('admin.products.approvals.show', [
            'product' => $product,
            'variantRows' => $variantRows,
        ]);
    }

    public function approve(Request $request, Product $product): RedirectResponse
    {
        if ($product->status === 'published') {
            $this->upsertStoreMenuFromApprovedProduct($product);

            return $this->redirectAfterReview($request, $product, 'Sản phẩm này đã ở trạng thái đã duyệt.');
        }

        DB::transaction(function () use ($product): void {
            $storeName = optional($product->store)->name ?? $product->store_id ?? 'N/A';

            $updateData = [
                'status' => 'published',
                'approved_by' => (string) auth()->id(),
                'approved_at' => now(),
                'published_at' => now(),
            ];

            if (Schema::hasColumn('products', 'rejection_reason')) {
                $updateData['rejection_reason'] = null;
            }

            $product->update($updateData);
            $this->approvalHistoryService->markReviewed($product, 'approved', auth()->id());
            $this->upsertStoreMenuFromApprovedProduct($product);

            $actionUrl = !empty($product->store_id)
                ? route('admin.stores.edit', [
                    'store' => $product->store_id,
                    'active_tab' => 'store-product-approvals',
                ])
                : route('admin.notifications.index');

            $this->notificationService->create([
                'title' => 'Duyệt sản phẩm cửa hàng',
                'message' => 'Sản phẩm "' . $product->name . '" của cửa hàng ' . $storeName . ' đã được duyệt và lên kệ.',
                'type' => 'product_approval',
                'action_url' => $actionUrl,
                'order_id' => null,
                'audience_scope' => 'admin_shared',
            ]);

            $this->createStoreAdminNotification($product->store_id, [
                'title' => 'Sản phẩm đã được duyệt',
                'message' => 'Sản phẩm "' . $product->name . '" đã được duyệt và đăng lên kệ.',
                'type' => 'product_approval',
                'data' => [
                    'product_id' => (string) $product->id,
                    'product_name' => $product->name,
                    'action_url' => '/admin_store/menu',
                ],
            ]);

            if (Schema::hasColumn('products', 'status')) {
                $pendingApprovalCount = Product::query()
                    ->where('store_id', $product->store_id)
                    ->where('status', 'pending_approval')
                    ->count();

                event(new ProductApprovalUpdated(
                    (string) $product->store_id,
                    $pendingApprovalCount,
                    'approved',
                    (string) $product->id,
                    'published'
                ));

                event(new StoreAdminProductUpdated(
                    (string) ($product->store_id ?? ''),
                    (string) $product->id,
                    'approved'
                ));
            }
        });

        return $this->redirectAfterReview($request, $product, 'Đã duyệt sản phẩm thành công.');
    }

    public function reject(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        $reason = trim((string) $validated['rejection_reason']);

        DB::transaction(function () use ($product, $reason): void {
            $storeName = optional($product->store)->name ?? $product->store_id ?? 'N/A';

            $updateData = [
                'status' => 'rejected',
                'approved_by' => null,
                'approved_at' => null,
                'published_at' => null,
            ];

            if (Schema::hasColumn('products', 'rejection_reason')) {
                $updateData['rejection_reason'] = $reason;
            }

            $product->update($updateData);
            $this->approvalHistoryService->markReviewed($product, 'rejected', auth()->id(), $reason);
            $this->removeProductFromStoreMenu($product);

            $actionUrl = !empty($product->store_id)
                ? route('admin.stores.edit', [
                    'store' => $product->store_id,
                    'active_tab' => 'store-product-approvals',
                ])
                : route('admin.notifications.index');

            $this->notificationService->create([
                'title' => 'Từ chối sản phẩm cửa hàng',
                'message' => 'Sản phẩm "' . $product->name . '" của cửa hàng ' . $storeName . ' đã bị từ chối. Lý do: ' . $reason,
                'type' => 'product_approval',
                'action_url' => $actionUrl,
                'order_id' => null,
                'audience_scope' => 'admin_shared',
            ]);

            $this->createStoreAdminNotification($product->store_id, [
                'title' => 'Sản phẩm bị từ chối',
                'message' => 'Sản phẩm "' . $product->name . '" đã bị từ chối. Lý do: ' . $reason,
                'type' => 'product_approval',
                'data' => [
                    'product_id' => (string) $product->id,
                    'product_name' => $product->name,
                    'rejection_reason' => $reason,
                    'action_url' => '/admin_store/menu',
                ],
            ]);

            if (Schema::hasColumn('products', 'status')) {
                $pendingApprovalCount = Product::query()
                    ->where('store_id', $product->store_id)
                    ->where('status', 'pending_approval')
                    ->count();

                event(new ProductApprovalUpdated(
                    (string) $product->store_id,
                    $pendingApprovalCount,
                    'rejected',
                    (string) $product->id,
                    'rejected'
                ));

                event(new StoreAdminProductUpdated(
                    (string) ($product->store_id ?? ''),
                    (string) $product->id,
                    'rejected'
                ));
            }
        });

        return $this->redirectAfterReview($request, $product, 'Đã từ chối sản phẩm và lưu lý do.');
    }

    private function resolveApprovalAttemptNumber(Product $product): int
    {
        $latestHistory = $product->latestApprovalHistory;
        if (!$latestHistory) {
            return 1;
        }

        $attemptNumber = max(1, (int) $latestHistory->attempt_number);
        $currentStatus = mb_strtolower(trim((string) ($product->status ?? '')));
        $latestHistoryStatus = mb_strtolower(trim((string) ($latestHistory->status ?? '')));

        if ($currentStatus === 'pending_approval' && $latestHistoryStatus !== 'pending') {
            return $attemptNumber + 1;
        }

        return $attemptNumber;
    }

    private function redirectAfterReview(Request $request, Product $product, string $message): RedirectResponse
    {
        $returnStoreId = (string) $request->input('return_store_id', '');
        if ($returnStoreId !== '') {
            return redirect()
                ->route('admin.stores.edit', [
                    'store' => $returnStoreId,
                    'active_tab' => (string) $request->input('active_tab', 'store-product-approvals'),
                ])
                ->with('success', $message);
        }

        return redirect()
            ->route('admin.stores.index')
            ->with('success', $message);
    }

    private function createStoreAdminNotification(?string $storeId, array $payload): void
    {
        if (empty($storeId) || !Schema::hasTable('store_admin_notifications')) {
            return;
        }

        StoreAdminNotification::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'store_id' => (string) $storeId,
            'order_id' => null,
            'order_code' => null,
            'type' => $payload['type'] ?? 'product_approval',
            'title' => $payload['title'] ?? null,
            'message' => $payload['message'] ?? null,
            'data' => $payload['data'] ?? null,
            'is_read' => false,
            'read_at' => null,
        ], $payload));
    }

    private function resolvePriceLabel(Product $product): string
    {
        $variantPrices = $product->variants
            ->map(function ($variant) {
                $basePrice = $variant->sale_price ?? $variant->price;
                return $basePrice !== null ? (float) $basePrice : null;
            })
            ->filter(fn($price) => $price !== null && $price > 0)
            ->values();

        if ($variantPrices->isNotEmpty()) {
            $min = (float) $variantPrices->min();
            $max = (float) $variantPrices->max();

            if (abs($min - $max) < 0.01) {
                return number_format($min, 0, ',', '.') . ' đ';
            }

            return number_format($min, 0, ',', '.') . ' - ' . number_format($max, 0, ',', '.') . ' đ';
        }

        $basePrice = $product->sale_price ?? $product->price;
        if ($basePrice === null) {
            return 'N/A';
        }

        return number_format((float) $basePrice, 0, ',', '.') . ' đ';
    }

    private function resolveProductCommissionRate(Product $product): float
    {
        return $this->resolveProductCommission($product)['value'];
    }

    private function resolveProductCommission(Product $product): array
    {
        $productRate = max(0.0, (float) ($product->commission_rate ?? 0));
        $productMode = $this->normalizeCommissionMode($product->commission_rate_mode ?? null);

        if ($product->relationLoaded('variants')) {
            $variantCommission = null;

            foreach ($product->variants as $variant) {
                $variantRate = max(0.0, (float) ($variant->commission_rate ?? 0));
                if ($variantRate > 0 && ($variantCommission === null || $variantRate > $variantCommission['value'])) {
                    $variantCommission = [
                        'value' => $variantRate,
                        'mode' => $this->normalizeCommissionMode($variant->commission_rate_mode ?? $productMode),
                    ];
                }
            }

            if ($variantCommission !== null) {
                return $variantCommission;
            }
        }

        return [
            'value' => $productRate,
            'mode' => $productMode,
        ];
    }

    private function normalizeCommissionMode(mixed $value): string
    {
        return strtolower(trim((string) $value)) === 'fixed' ? 'fixed' : 'percent';
    }

    private function formatCommissionLabel(float $value, string $mode): string
    {
        if ($mode === 'fixed') {
            return number_format($value, 0, ',', '.') . ' đ';
        }

        return number_format($value, 2, ',', '.') . '%';
    }

    private function resolveProductTicketReward(Product $product): array
    {
        $productQuantity = max(0, (int) ($product->ticket_reward_quantity ?? 0));
        $variantQuantity = 0;
        $variantTypeCode = null;

        if ($product->relationLoaded('variants')) {
            foreach ($product->variants as $variant) {
                $quantity = max(0, (int) ($variant->ticket_reward_quantity ?? 0));
                if ($quantity > $variantQuantity) {
                    $variantQuantity = $quantity;
                    $variantTypeCode = $variant->ticket_type_code;
                }
            }
        }

        $quantity = max($productQuantity, $variantQuantity);
        $typeCode = $product->ticket_type_code ?: $variantTypeCode ?: 'default';

        return [
            'quantity' => $quantity,
            'type_code' => strtoupper((string) $typeCode),
        ];
    }

    private function resolveMediaUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (preg_match('/^https?:\/\//', $path) || str_starts_with($path, '/')) {
            return $path;
        }

        $cleanPath = ltrim($path, '/');

        if (env('MEDIA_URL')) {
            return rtrim(env('MEDIA_URL'), '/') . '/' . $cleanPath;
        }

        return asset('storage/' . $cleanPath);
    }

    private function upsertStoreMenuFromApprovedProduct(Product $product): void
    {
        if (!Schema::hasTable('store_menu')) {
            return;
        }

        $storeId = (string) ($product->store_id ?? '');
        if ($storeId === '') {
            return;
        }

        $product->loadMissing(['variants', 'category']);

        $variants = $product->variants ?? collect();
        $menuPrice = null;
        foreach ($variants as $variant) {
            $candidate = $variant->sale_price ?? $variant->price;
            if ($candidate !== null && is_numeric($candidate)) {
                $candidate = (float) $candidate;
                $menuPrice = $menuPrice === null ? $candidate : min($menuPrice, $candidate);
            }
        }

        if ($menuPrice === null) {
            $menuPrice = $product->sale_price !== null
                ? (float) $product->sale_price
                : ($product->price !== null ? (float) $product->price : 0.0);
        }

        $originalPrice = $product->price !== null ? (float) $product->price : null;
        $description = $product->content ?: $product->summary;
        $imageUrl = $this->resolveMediaUrl($product->thumbnail);

        $categoryName = null;
        $subCategoryName = null;
        if (Schema::hasTable('product_categories') && $product->category_id !== null) {
            $category = DB::table('product_categories')
                ->select('id', 'name', 'parent_id')
                ->where('id', $product->category_id)
                ->first();

            if ($category) {
                $subCategoryName = (string) $category->name;
                if (Schema::hasColumn('product_categories', 'parent_id') && $category->parent_id !== null) {
                    $parent = DB::table('product_categories')
                        ->select('id', 'name')
                        ->where('id', $category->parent_id)
                        ->first();
                    $categoryName = $parent ? (string) $parent->name : (string) $category->name;
                } else {
                    $categoryName = (string) $category->name;
                    $subCategoryName = null;
                }
            }
        }

        $payload = [];
        if (Schema::hasColumn('store_menu', 'item_name')) {
            $payload['item_name'] = (string) $product->name;
        }
        if (Schema::hasColumn('store_menu', 'description')) {
            $payload['description'] = $description !== null ? (string) $description : null;
        }
        if (Schema::hasColumn('store_menu', 'price')) {
            $payload['price'] = $menuPrice;
        }
        if (Schema::hasColumn('store_menu', 'original_price')) {
            $payload['original_price'] = $originalPrice;
        }
        if (Schema::hasColumn('store_menu', 'image_url')) {
            $payload['image_url'] = $imageUrl;
        }
        if (Schema::hasColumn('store_menu', 'category')) {
            $payload['category'] = $categoryName;
        }
        if (Schema::hasColumn('store_menu', 'sub_category')) {
            $payload['sub_category'] = $subCategoryName;
        }
        if (Schema::hasColumn('store_menu', 'is_available')) {
            $payload['is_available'] = true;
        }
        if (Schema::hasColumn('store_menu', 'is_lucky_item')) {
            $payload['is_lucky_item'] = false;
        }
        if (Schema::hasColumn('store_menu', 'is_featured')) {
            $payload['is_featured'] = false;
        }
        if (Schema::hasColumn('store_menu', 'sort_order')) {
            $payload['sort_order'] = 0;
        }
        if (Schema::hasColumn('store_menu', 'product_id')) {
            $payload['product_id'] = (int) $product->id;
        }

        $menuQuery = DB::table('store_menu')->where('store_id', $storeId);
        if (Schema::hasColumn('store_menu', 'product_id')) {
            $menuQuery->where('product_id', (int) $product->id);
        } else {
            $menuQuery->where('item_name', (string) $product->name);
        }

        $existing = $menuQuery->orderByDesc('updated_at')->first();

        if (Schema::hasColumn('store_menu', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        if ($existing) {
            DB::table('store_menu')->where('id', $existing->id)->update($payload);

            if (Schema::hasColumn('store_menu', 'product_id')) {
                DB::table('store_menu')
                    ->where('store_id', $storeId)
                    ->where('product_id', (int) $product->id)
                    ->where('id', '!=', $existing->id)
                    ->delete();
            }

            return;
        }

        $insertPayload = array_merge(['store_id' => $storeId], $payload);

        if (Schema::hasColumn('store_menu', 'id') && !array_key_exists('id', $insertPayload)) {
            $insertPayload['id'] = (string) Str::uuid();
        }

        if (Schema::hasColumn('store_menu', 'item_code')) {
            $baseCode = 'PRD-' . strtoupper(substr((string) $storeId, 0, 6)) . '-' . $product->id;
            $code = $baseCode;
            $counter = 2;
            while (DB::table('store_menu')->where('store_id', $storeId)->where('item_code', $code)->exists()) {
                $code = $baseCode . '-' . $counter;
                $counter++;
            }
            $insertPayload['item_code'] = $code;
        }

        if (Schema::hasColumn('store_menu', 'created_at')) {
            $insertPayload['created_at'] = now();
        }

        DB::table('store_menu')->insert($insertPayload);
    }

    private function removeProductFromStoreMenu(Product $product): void
    {
        if (!Schema::hasTable('store_menu')) {
            return;
        }

        $storeId = (string) ($product->store_id ?? '');
        if ($storeId === '') {
            return;
        }

        $query = DB::table('store_menu')->where('store_id', $storeId);
        if (Schema::hasColumn('store_menu', 'product_id')) {
            $query->where('product_id', (int) $product->id);
        } else {
            $query->where('item_name', (string) $product->name);
        }

        if (Schema::hasColumn('store_menu', 'is_available')) {
            $payload = ['is_available' => false];
            if (Schema::hasColumn('store_menu', 'updated_at')) {
                $payload['updated_at'] = now();
            }
            $query->update($payload);
            return;
        }

        $query->delete();
    }
}

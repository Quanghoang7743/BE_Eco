<?php

namespace App\Http\Controllers;

use App\Events\PaymentConfigUpdated;
use App\Models\appSetting as AppSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\SpecialistDashboardService;
use App\Services\TicketPricingService;
use App\Support\AdminCmsAccess;
use App\Support\SafeEmail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AppSettingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function settingsOverview()
    {
        return view('admin.app_setting.overview');
    }

    public function index()
    {
        $setting = AppSetting::first(); // Lấy thông tin cài đặt
        if (!$setting) {
            $setting = new AppSetting();
        }

        $ticketPricingConfig = app(TicketPricingService::class)->getConfig();
        $ticketTypes = app(TicketPricingService::class)->getTicketTypes();
        $defaultTicketTypeCode = app(TicketPricingService::class)->getDefaultTicketTypeCode();

        return view('admin.app_setting.edit', compact('setting', 'ticketPricingConfig', 'ticketTypes', 'defaultTicketTypeCode'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if ($request->has('email')) {
            $request->merge(['email' => SafeEmail::normalizeForValidation($request->input('email'))]);
        }

        $validated = $request->validate([
            'app_name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'latitude' => 'nullable|string|max:50',
            'longitude' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:50',
            'email' => ['nullable', 'string', 'max:100', SafeEmail::validationRule()],
            'default_color' => 'nullable|string|regex:/^#([A-Fa-f0-9]{3}){1,2}$/',
            'description' => 'nullable|string',
            'logo_path' => 'nullable|image|max:2048',
            'banner_path' => 'nullable|image|max:4096',
            'favicon_path' => 'nullable|image|max:1024',
            'donated' => 'nullable',
            'ticket_expiry_days' => 'nullable|integer|min:1|max:3650',
            'ticket_calc_type' => 'nullable|in:fixed,percentage',
            'ticket_value' => 'nullable|numeric|min:0|max:1000000000',
            'order_sla_timeout_seconds' => 'nullable|integer|min:0|max:86400',
            'order_sla_warning_seconds' => 'nullable|integer|min:0|max:86400',
        ]);

        $setting = AppSetting::first() ?? new AppSetting();

        $setting->fill($validated);

        // Xử lý upload file nếu có
        if ($request->hasFile('logo_path')) {
            $setting->logo_path = $request->file('logo_path')->store('uploads/logo', 'public');
        }
        if ($request->hasFile('banner_path')) {
            $setting->banner_path = $request->file('banner_path')->store('uploads/banner', 'public');
        }
        if ($request->hasFile('favicon_path')) {
            $setting->favicon_path = $request->file('favicon_path')->store('uploads/favicon', 'public');
        }

        $setting->save();

        if ($request->filled('ticket_calc_type') || $request->filled('ticket_value')) {
            $calcType = (string) ($validated['ticket_calc_type'] ?? 'fixed');
            $ticketValue = (float) ($validated['ticket_value'] ?? 0);
            app(TicketPricingService::class)->upsertConfig($calcType, $ticketValue, auth()->id());
        }

        return redirect()->back()->with('success', 'Cập nhật thông tin ứng dụng thành công!');
    }


    /**
     * Display the specified resource.
     */
    public function show(AppSetting $appSetting)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(AppSetting $appSetting)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        // return $request;
        if ($request->has('email')) {
            $request->merge(['email' => SafeEmail::normalizeForValidation($request->input('email'))]);
        }

        $validated = $request->validate([
            'app_name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'latitude' => 'nullable|string|max:50',
            'longitude' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:50',
            'email' => ['nullable', 'string', 'max:100', SafeEmail::validationRule()],
            'default_color' => 'nullable|string|regex:/^#([A-Fa-f0-9]{3}){1,2}$/',
            'description' => 'nullable|string',
            'logo_path' => 'nullable|image|max:2048',
            'banner_path' => 'nullable|image|max:4096',
            'favicon_path' => 'nullable|image|max:1024',
            'donated' => 'nullable',
            'ticket_expiry_days' => 'nullable|integer|min:1|max:3650',
            'ticket_types' => 'nullable|array',
            'ticket_types.*.code' => 'required_with:ticket_types|string|max:50',
            'ticket_types.*.name' => 'required_with:ticket_types|string|max:120',
            'ticket_types.*.price' => 'required_with:ticket_types|numeric|min:0',
            'ticket_default_type' => 'nullable|string|max:50',
            'order_sla_timeout_seconds' => 'nullable|integer|min:0|max:86400',
            'order_sla_warning_seconds' => 'nullable|integer|min:0|max:86400',
        ]);

        $setting = AppSetting::first() ?? new AppSetting();

        $setting->fill($validated);

        // Xử lý upload file nếu có
        if ($request->hasFile('logo_path')) {
            $setting->logo_path = $request->file('logo_path')->store('uploads/logo', 'public');
        }
        if ($request->hasFile('banner_path')) {
            $setting->banner_path = $request->file('banner_path')->store('uploads/banner', 'public');
        }
        if ($request->hasFile('favicon_path')) {
            $setting->favicon_path = $request->file('favicon_path')->store('uploads/favicon', 'public');
        }

        $setting->save();

        if (array_key_exists('ticket_types', $validated)) {
            app(TicketPricingService::class)->upsertTicketTypes(
                (array) ($validated['ticket_types'] ?? []),
                $validated['ticket_default_type'] ?? null,
                auth()->id()
            );
        }

        return redirect()->back()->with('success', 'Cập nhật thông tin ứng dụng thành công!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AppSetting $appSetting)
    {
        //
    }

    /**
     * GET /admin/payment-settings
     * Hiển thị form cài đặt thanh toán VietQR
     */
    public function paymentSettings()
    {
        $setting = AppSetting::first() ?? new AppSetting();
        
        // Danh sách các ngân hàng hỗ trợ VietQR
        $banks = [
            'MB' => 'MB Bank',
            'VCB' => 'Vietcombank',
            'ACB' => 'ACB',
            'TCB' => 'Techcombank',
            'BID' => 'BIDV',
            'HDB' => 'HDBank',
            'VIB' => 'VIB',
            'SHB' => 'SHB',
            'OCB' => 'OCB',
            'EXB' => 'EximBank',
            'VPBANK' => 'VP Bank',
            'TPB' => 'TPBank',
            'STB' => 'Sacombank',
            'IVB' => 'International Bank',
            'BAB' => 'Bac A Bank',
            'ABB' => 'An Binh Bank',
            'SEAB' => 'Southeastern Asia Bank',
            'PVCB' => 'PVcomBank',
            'LPB' => 'LienVietPostBank',
            'UNB' => 'United Bank',
            'NBB' => 'Nong Cong Bank',
            'NHB' => 'NH Bank',
            'SANB' => 'Sangam Bank',
            'SCB' => 'Saigon Commercial Bank',
            'NAB' => 'Nam A Bank',
            'MS' => 'Military Bank',
            'BVB' => 'BaoViet Bank',
            'NationalBank' => 'National Bank',
        ];
        
        return view('admin.app_setting.payment_settings', compact('setting', 'banks'));
    }

    /**
     * GET /admin/app-setting/homepage-block
     * Show homepage block settings
     */
    public function homepageBlock()
    {
        $setting = AppSetting::first() ?? new AppSetting();

        $blocks = (array) ($setting->homepage_product_blocks ?? []);

        // Load data for selects
        $flashSales = \App\Models\FlashSale::orderByDesc('created_at')->get();
        $productCategories = \App\Models\ProductCategory::orderBy('name')->get();
        $articleCategories = \App\Models\Category::orderBy('title')->get();

        return view('admin.app_setting.homepage_block', compact(
            'setting',
            'blocks',
            'flashSales',
            'productCategories',
            'articleCategories'
        ));
    }

    /**
     * GET /admin/app-setting/homepage-block/products
     * Return paginated homepage block products as JSON for AJAX pagination.
     */
    public function homepageBlockProducts(Request $request)
    {
        $setting = AppSetting::first() ?? new AppSetting();
        $selectedIds = array_values(array_unique(array_map('intval', (array) $request->input('selected_ids', []))));

        $products = Product::query()
            ->select(['id', 'name', 'price', 'sale_price'])
            ->orderByDesc('updated_at')
            ->paginate(8);

        $html = view('admin.app_setting.partials.homepage_block_products', [
            'products' => $products,
            'selectedIds' => $selectedIds,
        ])->render();

        return response()->json([
            'html' => $html,
            'page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
            'total' => $products->total(),
        ]);
    }

    /**
     * GET /admin/app-setting/homepage-block/articles
     * Get list of articles for homepage news block configuration
     */
    public function homepageBlockArticles(Request $request)
    {
        $selectedIds = array_values(array_unique(array_map('intval', (array) $request->input('selected_ids', []))));
        $page = max(1, (int) $request->input('page', 1));
        $search = trim((string) $request->input('search', ''));

        $query = \App\Models\article::query()
            ->with('category')
            ->select(['id', 'title', 'slug', 'summary', 'image', 'category_id', 'published_at']);

        // Search filter
        if ($search) {
            $query->where('title', 'like', '%' . $search . '%')
                ->orWhere('summary', 'like', '%' . $search . '%');
        }

        $articles = $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(12, ['*'], 'page', $page);

        $articlesData = $articles->map(fn ($article) => [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'summary' => $article->summary,
            'image' => $article->image,
            'category' => $article->category ? [
                'id' => $article->category->id,
                'name' => $article->category->name,
                'slug' => $article->category->slug,
            ] : null,
            'published_at' => $article->published_at ? Carbon::parse($article->published_at)->format('d/m/Y') : null,
            'selected' => in_array($article->id, $selectedIds),
        ])->values()->all();

        return response()->json([
            'articles' => $articlesData,
            'page' => $articles->currentPage(),
            'last_page' => $articles->lastPage(),
            'total' => $articles->total(),
        ]);
    }

    /**
     * GET /admin/app-setting/homepage-block/preview
     * Preview filter results for product blocks
     */
    public function previewHomepageBlockFilters(Request $request)
    {
        $sortBy = $request->input('sort_by', 'by_newest');
        $filters = [
            'filter_name' => $request->input('filter_name', ''),
            'filter_min_sales' => $request->input('filter_min_sales', 0),
            'filter_min_price' => $request->input('filter_min_price', 0),
            'filter_max_price' => $request->input('filter_max_price', 0),
            'filter_min_discount' => $request->input('filter_min_discount', 0),
            'filter_in_stock' => $request->input('filter_in_stock', 0),
        ];

        $products = $this->resolveHomepageProductBlockProducts($sortBy, 10, $filters);

        return response()->json([
            'products' => $products,
            'count' => count($products),
        ]);
    }

    /**
     * PUT /admin/app-setting/homepage-block
     * Save homepage block settings
     */
    public function updateHomepageBlock(Request $request)
    {
        $validated = $request->validate([
            'blocks' => 'nullable|array',
            'blocks.*.title' => 'required|string|max:255',
            'blocks.*.type' => 'required|in:flashsale,featured,criteria,news',
            'blocks.*.value' => 'nullable|string|max:255',
        ]);

        $setting = AppSetting::first() ?? new AppSetting();

        $blocks = [];
        foreach ((array) ($validated['blocks'] ?? []) as $index => $item) {
            $blocks[] = [
                'title' => trim((string) $item['title']),
                'type' => $item['type'],
                'value' => $item['value'] ?? '',
                'order' => $index + 1,
            ];
        }

        $setting->homepage_product_blocks = $blocks;
        $setting->save();

        return redirect()->back()->with('success', 'Cập nhật cấu hình khối Trang chủ thành công.');
    }

    private function resolveHomepageProductBlocks(): array
    {
        $setting = AppSetting::first();
        if (!$setting) {
            return [];
        }

        $rawBlocks = array_values((array) ($setting->homepage_product_blocks ?? []));

        return collect($rawBlocks)
            ->map(function (array $block, int $index) {
                $sortBy = in_array(($block['sort_by'] ?? 'by_newest'), ['by_sales', 'by_newest', 'by_discount', 'by_price_low', 'by_price_high'], true)
                    ? (string) ($block['sort_by'] ?? 'by_newest')
                    : 'by_newest';

                return [
                    'id' => $index + 1,
                    'title' => trim((string) ($block['title'] ?? '')),
                    'description' => trim((string) ($block['description'] ?? '')),
                    'badge' => trim((string) ($block['badge'] ?? '')),
                    'sort_by' => $sortBy,
                    'limit' => 10,
                    'products' => $this->resolveHomepageProductBlockProducts($sortBy, 10, $block),
                ];
            })
            ->filter(fn (array $block) => $block['title'] !== '' || $block['description'] !== '' || $block['badge'] !== '' || !empty($block['products']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    private function resolveHomepageProductBlockProducts(string $sortBy, int $limit = 10, array $filters = []): array
    {
        $limit = max(1, min($limit, 10));

        // Extract filter parameters
        $filterName = trim((string) ($filters['filter_name'] ?? ''));
        $filterMinSales = max(0, (int) ($filters['filter_min_sales'] ?? 0));
        $filterMinPrice = max(0, (float) ($filters['filter_min_price'] ?? 0));
        $filterMaxPrice = (float) ($filters['filter_max_price'] ?? 0);
        $filterMinDiscount = max(0, min(100, (float) ($filters['filter_min_discount'] ?? 0)));
        $filterInStock = (bool) ((int) ($filters['filter_in_stock'] ?? 0));

        if ($sortBy === 'by_sales') {
            if (!Schema::hasColumn('order_items', 'product_id') || !Schema::hasColumn('order_items', 'quantity')) {
                $sortBy = 'by_newest';
            } else {
                $salesQuery = Product::select(
                    'products.id',
                    'products.name',
                    'products.slug',
                    'products.thumbnail',
                    'products.sale_price',
                    'products.stock',
                    'products.price',
                    DB::raw('SUM(order_items.quantity) as total_sold'),
                )
                    ->join('order_items', 'products.id', '=', 'order_items.product_id')
                    ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
                    ->selectRaw('stores.name as store_name')
                    ->selectRaw('stores.logo_url as store_image_url')
                    ->selectRaw('ST_Y(stores.location) as store_lat')
                    ->selectRaw('ST_X(stores.location) as store_lng')
                    ->with('category', 'group', 'variants.attributeValues.attribute', 'combos.bonusProduct')
                    ->whereIn(DB::raw("LOWER(COALESCE(products.status, ''))"), ['published', 'approved', 'active']);

                // Apply filters
                if ($filterName !== '') {
                    $salesQuery->where('products.name', 'like', "%{$filterName}%");
                }
                if ($filterMinPrice > 0) {
                    $salesQuery->where('products.sale_price', '>=', $filterMinPrice);
                }
                if ($filterMaxPrice > 0) {
                    $salesQuery->where('products.sale_price', '<=', $filterMaxPrice);
                }
                if ($filterInStock) {
                    $salesQuery->where('products.stock', '>', 0);
                }
                if ($filterMinDiscount > 0) {
                    $salesQuery->havingRaw('CASE WHEN COALESCE(products.price, 0) > 0 THEN ((COALESCE(products.price, 0) - COALESCE(products.sale_price, 0)) / COALESCE(products.price, 0) * 100) ELSE 0 END >= ?', [$filterMinDiscount]);
                }
                if ($filterMinSales > 0) {
                    $salesQuery->havingRaw('SUM(order_items.quantity) >= ?', [$filterMinSales]);
                }

                return $salesQuery->groupBy(
                    'products.id',
                    'products.name',
                    'products.slug',
                    'products.thumbnail',
                    'products.sale_price',
                    'products.stock',
                    'products.price',
                    'stores.name',
                    'stores.logo_url',
                    'stores.location',
                )
                    ->orderByDesc('total_sold')
                    ->limit($limit)
                    ->get()
                    ->values()
                    ->all();
            }
        }

        $query = Product::query()
            ->leftJoin('stores', 'stores.id', '=', 'products.store_id')
            ->select('products.*')
            ->selectRaw('stores.name as store_name')
            ->selectRaw('stores.logo_url as store_image_url')
            ->selectRaw('ST_Y(stores.location) as store_lat')
            ->selectRaw('ST_X(stores.location) as store_lng')
            ->with('category', 'group', 'variants.attributeValues.attribute', 'combos.bonusProduct')
            ->whereIn(DB::raw("LOWER(COALESCE(products.status, ''))"), ['published', 'approved', 'active']);

        // Apply filters
        if ($filterName !== '') {
            $query->where('products.name', 'like', "%{$filterName}%");
        }
        if ($filterMinPrice > 0) {
            $query->where('products.sale_price', '>=', $filterMinPrice);
        }
        if ($filterMaxPrice > 0) {
            $query->where('products.sale_price', '<=', $filterMaxPrice);
        }
        if ($filterInStock) {
            $query->where('products.stock', '>', 0);
        }
        if ($filterMinDiscount > 0) {
            $query->whereRaw('CASE WHEN COALESCE(products.price, 0) > 0 THEN ((COALESCE(products.price, 0) - COALESCE(products.sale_price, 0)) / COALESCE(products.price, 0) * 100) ELSE 0 END >= ?', [$filterMinDiscount]);
        }

        switch ($sortBy) {
            case 'by_discount':
                $query->orderByRaw('CASE WHEN COALESCE(products.price, 0) > 0 THEN ((COALESCE(products.price, 0) - COALESCE(products.sale_price, 0)) / COALESCE(products.price, 0)) ELSE 0 END DESC');
                break;
            case 'by_price_low':
                $query->orderBy('products.price', 'asc');
                break;
            case 'by_price_high':
                $query->orderBy('products.price', 'desc');
                break;
            case 'by_newest':
            default:
                $query->orderByDesc('products.created_at');
                break;
        }

        return $query
            ->limit($limit)
            ->get()
            ->values()
            ->all();
    }

    /**
     * PUT /admin/payment-settings
     * Lưu cài đặt thanh toán VietQR
     */
    public function updatePaymentSettings(Request $request)
    {
        $request->validate([
            'vietqr_client_id' => 'nullable|string|max:255',
            'vietqr_api_key' => 'nullable|string|max:500',
            'vietqr_bank_id' => 'required|string|max:50',
            'vietqr_account_no' => 'required|string|max:100',
            'vietqr_account_name' => 'required|string|max:255',
        ]);

        $setting = AppSetting::first() ?? new AppSetting();

        $setting->fill([
            'vietqr_client_id' => $request->vietqr_client_id,
            'vietqr_api_key' => $request->vietqr_api_key,
            'vietqr_bank_id' => $request->vietqr_bank_id,
            'vietqr_account_no' => $request->vietqr_account_no,
            'vietqr_account_name' => $request->vietqr_account_name,
        ]);

        $paymentConfigChanged = !$setting->exists || $setting->isDirty([
            'vietqr_client_id',
            'vietqr_api_key',
            'vietqr_bank_id',
            'vietqr_account_no',
            'vietqr_account_name',
        ]);

        $setting->save();

        if ($paymentConfigChanged) {
            event(new PaymentConfigUpdated($setting));
        }

        return redirect()->back()->with('success', 'Cập nhật cài đặt thanh toán thành công!');
    }

    /**
     * GET /admin/topup-security-settings
     * Show the manual top-up security configuration page.
     */
    public function topupSecuritySettings()
    {
        $configs = DB::table('system_configs')
            ->whereIn('config_key', [
                \App\Models\SystemConfig::KEY_MANUAL_TOPUP_ACCESS_CODE,
                \App\Models\SystemConfig::KEY_OTP_EXPIRY_MINUTES,
                \App\Models\SystemConfig::KEY_OTP_LENGTH,
                \App\Models\SystemConfig::KEY_MANUAL_TOPUP_OTP_EMAIL,
            ])
            ->get()
            ->keyBy('config_key');

        $otpExpiryMinutes = (int) ($configs[\App\Models\SystemConfig::KEY_OTP_EXPIRY_MINUTES]->config_value ?? 5);
        $otpLength        = (int) ($configs[\App\Models\SystemConfig::KEY_OTP_LENGTH]->config_value ?? 6);
        $otpEmail         = (string) ($configs[\App\Models\SystemConfig::KEY_MANUAL_TOPUP_OTP_EMAIL]->config_value ?? '');
        $hasAccessCode    = isset($configs[\App\Models\SystemConfig::KEY_MANUAL_TOPUP_ACCESS_CODE]);

        return view('admin.app_setting.topup_security_settings', compact(
            'otpExpiryMinutes',
            'otpLength',
            'otpEmail',
            'hasAccessCode'
        ));
    }

    /**
     * PUT /admin/topup-security-settings
     * Update manual top-up security configuration.
     */
    public function updateTopupSecuritySettings(Request $request)
    {
        $validated = $request->validate([
            'new_access_code'        => ['nullable', 'string', 'min:4', 'max:50'],
            'otp_expiry_minutes'     => ['required', 'integer', 'min:1', 'max:60'],
            'otp_length'             => ['required', 'integer', 'min:4', 'max:10'],
            'manual_topup_otp_email' => ['nullable', 'string', 'email', 'max:100'],
        ]);

        // Update OTP expiry
        DB::table('system_configs')->updateOrInsert(
            ['config_key' => \App\Models\SystemConfig::KEY_OTP_EXPIRY_MINUTES],
            [
                'config_value' => (string) $validated['otp_expiry_minutes'],
                'value_type'   => 'integer',
                'description'  => 'Thời gian hết hạn OTP (phút)',
                'updated_by'   => auth()->id(),
                'updated_at'   => now(),
                'created_at'   => now(),
            ]
        );

        // Update OTP length
        DB::table('system_configs')->updateOrInsert(
            ['config_key' => \App\Models\SystemConfig::KEY_OTP_LENGTH],
            [
                'config_value' => (string) $validated['otp_length'],
                'value_type'   => 'integer',
                'description'  => 'Độ dài mã OTP',
                'updated_by'   => auth()->id(),
                'updated_at'   => now(),
                'created_at'   => now(),
            ]
        );

        // Update OTP email
        DB::table('system_configs')->updateOrInsert(
            ['config_key' => \App\Models\SystemConfig::KEY_MANUAL_TOPUP_OTP_EMAIL],
            [
                'config_value' => (string) ($validated['manual_topup_otp_email'] ?? ''),
                'value_type'   => 'string',
                'description'  => 'Email nhận mã OTP nạp hạn mức thủ công (để trống sẽ gửi về admin thực hiện)',
                'updated_by'   => auth()->id(),
                'updated_at'   => now(),
                'created_at'   => now(),
            ]
        );

        // Update access code only if a new one is provided
        if (! empty($validated['new_access_code'])) {
            DB::table('system_configs')->updateOrInsert(
                ['config_key' => \App\Models\SystemConfig::KEY_MANUAL_TOPUP_ACCESS_CODE],
                [
                    'config_value' => \Illuminate\Support\Facades\Hash::make($validated['new_access_code']),
                    'value_type'   => 'bcrypt',
                    'description'  => 'Mã khóa truy cập chức năng nạp hạn mức thủ công (bcrypt hash)',
                    'updated_by'   => auth()->id(),
                    'updated_at'   => now(),
                    'created_at'   => now(),
                ]
            );
        }

        return redirect()->back()->with('success', 'Cập nhật cài đặt bảo mật nạp hạn mức thành công!');
    }


    public function openApp(Request $request)
    {
        if (AdminCmsAccess::isSale()) {
            return view('admin.dashboard.specialist', app(SpecialistDashboardService::class)->build(
                auth()->user(),
                $request->query()
            ));
        }

        $ordersBaseQuery = Order::query();
        AdminCmsAccess::applyOrderScope($ordersBaseQuery);

        $totalOrders = (clone $ordersBaseQuery)->count();

        $revenueColumn = 'total';
        if (Schema::hasTable('orders')) {
            if (Schema::hasColumn('orders', 'final_amount')) {
                $revenueColumn = 'final_amount';
            } elseif (Schema::hasColumn('orders', 'total_amount')) {
                $revenueColumn = 'total_amount';
            } elseif (Schema::hasColumn('orders', 'total')) {
                $revenueColumn = 'total';
            }
        }

        // Tong doanh thu theo schema hien tai cua bang orders.
        $totalRevenue = (clone $ordersBaseQuery)->sum($revenueColumn);

        $totalUsersQuery = User::query();
        if (AdminCmsAccess::isSale()) {
            $totalUsersQuery->whereHas('orders.store', function ($storeQuery) {
                $storeQuery->where('sale_id', auth()->id());
            });
        }

        $totalUsers = $totalUsersQuery->count();
        $todayRevenue = (clone $ordersBaseQuery)->whereDate('created_at', Carbon::today())->sum($revenueColumn);

        // Lấy tổng số sản phẩm
        $totalSuccessfulOrders = (clone $ordersBaseQuery)->where('status', 'completed')->count();

        $days = 7; // 7 ngày gần nhất

        $revenueDataQuery = Order::query()
            ->selectRaw('DATE(created_at) as date, SUM(' . $revenueColumn . ') as total')
            // ->where('status', 'completed') // hoặc 'paid' tuỳ hệ thống
            ->whereDate('created_at', '>=', Carbon::now()->subDays($days))
            ->groupBy('date')
            ->orderBy('date');

        AdminCmsAccess::applyOrderScope($revenueDataQuery);

        $revenueData = $revenueDataQuery->get();

        // Tạo mảng dữ liệu: labels (ngày), values (doanh thu)
        $labels = [];
        $values = [];

        for ($i = $days; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->toDateString();
            $labels[] = $date;

            $dayData = $revenueData->firstWhere('date', $date);
            $values[] = $dayData ? (int) $dayData->total : 0;
        }
        $topProducts = collect();
        if (Schema::hasTable('order_items')) {
            $itemIdColumn = null;
            if (Schema::hasColumn('order_items', 'menu_item_id')) {
                $itemIdColumn = 'menu_item_id';
            } elseif (Schema::hasColumn('order_items', 'product_id')) {
                $itemIdColumn = 'product_id';
            }

            $itemNameColumn = null;
            if (Schema::hasColumn('order_items', 'item_name')) {
                $itemNameColumn = 'item_name';
            } elseif (Schema::hasColumn('order_items', 'product_name')) {
                $itemNameColumn = 'product_name';
            }

            $quantityColumn = Schema::hasColumn('order_items', 'quantity') ? 'quantity' : null;

            if ($itemNameColumn !== null && $quantityColumn !== null) {
                $query = DB::table('order_items')
                    ->selectRaw($itemNameColumn . ' as product_name')
                    ->selectRaw('SUM(' . $quantityColumn . ') as total_sold')
                    ->groupBy($itemNameColumn)
                    ->orderByDesc('total_sold')
                    ->limit(10);

                if (
                    AdminCmsAccess::isSale()
                    && Schema::hasColumn('order_items', 'order_id')
                    && Schema::hasTable('orders')
                    && Schema::hasTable('stores')
                    && Schema::hasColumn('orders', 'store_id')
                    && Schema::hasColumn('stores', 'sale_id')
                ) {
                    $query->join('orders as scoped_orders', 'scoped_orders.id', '=', 'order_items.order_id')
                        ->join('stores as scoped_stores', 'scoped_stores.id', '=', 'scoped_orders.store_id')
                        ->where('scoped_stores.sale_id', auth()->id());
                }

                if ($itemIdColumn !== null) {
                    $query->selectRaw($itemIdColumn . ' as product_id')
                        ->groupBy($itemIdColumn);
                } else {
                    $query->selectRaw('NULL as product_id');
                }

                $topProducts = $query->get();
            }
        }
        $topUsersQuery = DB::table('users')->where('role', 'user')->orderByDesc('balance')
            ->orderByDesc('created_at')
            ->limit(7);

        if (
            AdminCmsAccess::isSale()
            && Schema::hasTable('orders')
            && Schema::hasTable('stores')
            && Schema::hasColumn('orders', 'user_id')
            && Schema::hasColumn('orders', 'store_id')
            && Schema::hasColumn('stores', 'sale_id')
        ) {
            $topUsersQuery->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('orders as scoped_user_orders')
                    ->join('stores as scoped_user_stores', 'scoped_user_stores.id', '=', 'scoped_user_orders.store_id')
                    ->whereColumn('scoped_user_orders.user_id', 'users.id')
                    ->where('scoped_user_stores.sale_id', auth()->id());
            });
        }

        $topUsers = $topUsersQuery->get();

        $ordersQuery = Order::with('items.product', 'referrer')
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderby('status');

        AdminCmsAccess::applyOrderScope($ordersQuery);

        $orders = $ordersQuery->paginate(7);
        return view('admin.index', compact(
            'totalOrders',
            'totalRevenue',
            'totalUsers',
            'totalSuccessfulOrders',
            'labels',
            'values',
            'todayRevenue',
            'topProducts',
            'topUsers',
            'orders'
        ));
    }
}

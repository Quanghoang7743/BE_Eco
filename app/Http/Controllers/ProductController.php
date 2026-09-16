<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\ProductCategory;
use App\Models\ProductGroup;
use App\Models\Store;
use App\Services\ProductApprovalHistoryService;
use App\Models\Ticket;
use App\Models\Topping;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Traits\ProductToppingSync;

class ProductController extends Controller
{

    use ProductToppingSync;
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $storeId = $request->query('store_id');
        if (empty($storeId)) {
            return redirect()
                ->route('admin.stores.index')
                ->with('error', 'Vui lòng chọn cửa hàng và quản lý sản phẩm tại tab cửa hàng.');
        }

        $selectedStore = Store::query()
            ->select('id', 'name', 'store_code')
            ->find($storeId);

        if (!$selectedStore) {
            return redirect()
                ->route('admin.stores.index')
                ->with('error', 'Cửa hàng không tồn tại hoặc đã bị xóa.');
        }

        return redirect()->route('admin.stores.edit', [
            'store' => $selectedStore->id,
            'active_tab' => 'store-products',
        ]);
    }

    public function create(Request $request)
    {
        $storeId = $request->query('store_id');

        $selectedStore = null;
        if (!empty($storeId)) {
            $selectedStore = Store::query()
                ->select('id', 'name', 'store_code')
                ->find($storeId);
        }
        // dd($storeId);

        $attributes = Attribute::where('store_id', $storeId)

            ->get();
        // dd($attributes);

        $categories = ProductCategory::where('store_id', $storeId)

            ->get();

        $groups = ProductGroup::all();

        $toppings = Topping::where('store_id', $storeId)

            ->get();

        $ticketTypes = [];

        if (class_exists(Ticket::class)) {
            $ticketTypes = Ticket::query()
                ->select(
                    'ticket_type_code',
                    'value_amount'
                )
                ->distinct()
                ->get()
                ->map(fn($t) => (object)[
                    'code' => $t->ticket_type_code,
                    'name' => $t->ticket_type_code,
                    'ticket_value' => $t->value_amount
                ]);
        }
        return view(
            'admin.products.create',
            compact(
                'categories',
                'groups',
                'attributes',
                'selectedStore',
                'toppings',
                'ticketTypes'
            )
        );
    }

    public function store(Request $request)
    {
        $request->merge([
            'summary' => $this->normalizeNullableText($request->summary),
            'content' => $this->normalizeNullableText($request->content),
            'slug' => $this->normalizeNullableText($request->slug),
            'sku' => $this->normalizeNullableText($request->sku),
        ]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'stock' => 'nullable|numeric',
            'summary' => 'nullable|string',
            'content' => 'nullable|string',
            'thumbnail' => 'nullable|image',
            'images.*' => 'nullable|image',
            'price' => 'nullable|numeric',
            'sale_price' => 'nullable|numeric',
            'slug' => 'nullable|string|unique:products,slug',
            'sku' => 'nullable|string|unique:products,sku',
            'store_id' => 'nullable|exists:stores,id',
            'category_id' => 'nullable|exists:product_categories,id',
            'commission_rate' => 'nullable|numeric',
            'ticket_reward_quantity' => 'nullable|integer',
            'ticket_type_code' => 'nullable|string',
        ]);

        $data['summary'] = $this->normalizeNullableText($data['summary'] ?? null);
        $data['content'] = $this->normalizeNullableText($data['content'] ?? null);
        if ($data['summary'] === null && $data['content'] !== null) {
            $summaryText = trim(preg_replace('/\s+/', ' ', strip_tags($data['content'])));
            if (mb_strlen($summaryText) > 250) {
                $summaryText = mb_substr($summaryText, 0, 247) . '...';
            }
            $data['summary'] = $summaryText === '' ? null : $summaryText;
        }

        $data['slug'] = $this->resolveUniqueSlug($data['slug'] ?? '', $data['name']);
        $data['sku'] = $this->resolveUniqueSku($data['sku'] ?? '', $data['name']);
        $data['commission_rate'] = $data['commission_rate'] ?? 0;
        $data['ticket_reward_quantity'] = $request->ticket_reward_quantity ?? 0;
        $data['ticket_type_code'] = $request->ticket_type_code;
        $data['ticket_reward_enabled'] = ($data['ticket_reward_quantity'] ?? 0) > 0;

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail'] =  $request->file('thumbnail')->store('products', 'public');
        }

        if ($request->hasFile('images')) {
            $images = [];

            foreach ($request->file('images') as $img) {

                $images[] =
                    $img->store(
                        'product_images',
                        'public'
                    );
            }

            $data['images'] = $images;
        }


        if (!empty($data['store_id'])) {
            $data['status'] = 'pending_approval';
            $data['approved_by'] = null;
            $data['approved_at'] = null;
            $data['published_at'] = null;
        }


        DB::transaction(function () use (
            &$product,
            $data,
            $request
        ) {

            $product =
                Product::create(
                    $data
                );


            $totalStock = 0;


            /*
        |--------------------------------------------------------------------------
        | Variants
        |--------------------------------------------------------------------------
        */

            if ($request->has('variants') && count($request->variants)) {
                foreach ($request->variants as $variant) {
                    $productVariant = $product->variants()->create([
                        'price' => $variant['price'] ?? null,
                        'sale_price' => $variant['sale_price'] ?? null,
                        'commission_rate' => $variant['commission_rate'] ?? 0,
                        'stock' => $variant['stock'] ?? 0,
                        'ticket_reward_quantity' => $variant['ticket_reward_quantity'] ?? 0,
                        'ticket_type_code' => $variant['ticket_type_code'] ?? null,
                    ]);

                    $totalStock += $variant['stock'] ?? 0;
                    if (isset($variant['image'])) {
                        $path = $variant['image']->store('products', 'public');
                        $productVariant->update(['image' => $path]);
                    }


                    foreach ($variant['attributes'] ?? [] as $attr) {
                        $attributeValue = AttributeValue::firstOrCreate([
                            'attribute_id' => $attr['attribute_id'],
                            'value' => trim($attr['value'])
                        ]);
                        $productVariant->attributeValues()->syncWithoutDetaching([$attributeValue->id]);
                    }
                }
            }

            /*
        |--------------------------------------------------------------------------
        | fallback variant
        |--------------------------------------------------------------------------
        */ else {

                $product->variants()->create([
                    'stock' => $request->stock ?? 0,
                    'price' => $request->price,
                    'sale_price' => $request->sale_price,
                    'commission_rate' => $request->commission_rate ?? 0,
                    'ticket_reward_quantity' => $request->ticket_reward_quantity ?? 0,
                    'ticket_type_code' => $request->ticket_type_code,
                    'image' => $product->thumbnail,
                ]);

                $totalStock = $request->stock ?? 0;
            }


            /*
        |--------------------------------------------------------------------------
        | sync stock
        |--------------------------------------------------------------------------
        */

            if ($request->has('variants') && count($request->variants)) {
                $firstVariant = $product->variants()->first();
                if ($firstVariant) {
                    $product->price = $firstVariant->price;
                    $product->sale_price = $firstVariant->sale_price;
                }
            }

            $product->stock =  $totalStock;
            $product->save();


            /*
        |--------------------------------------------------------------------------
        | toppings
        |--------------------------------------------------------------------------
        */
            // dd($request->toppings);
            $this->syncProductToppings(
                $product,
                $product->store,
                $request->toppings

            );
        });



        return redirect()

            ->route(
                'admin.stores.edit',
                [

                    'store' => $product?->store_id,

                    'active_tab' =>
                    'store-product-approvals'

                ]

            )

            ->with(
                'success',
                'Thêm sản phẩm thành công'
            );
    }

    public function show($slug, Request $request)
    {
        $product = Product::with('variants.attributeValues.attribute')->where('slug', $slug)->firstOrFail();

        $variantData = $product->variants->map(function ($variant) {
            return [
                'attributes' => $variant->attributeValues->pluck('value', 'attribute.name')->toArray(),
                'price' => $variant->price,
                'sale_price' => $variant->sale_price,
                'stock' => $variant->stock,
                'image_url' => $variant->image, // nếu có
            ];
        })->toArray();
        if ($request->has('ref')) {
            session(['referrer_id' => \App\Models\User::resolveReferrerId($request->query('ref'))]);
        }
        return view('product_detail', compact('product', 'variantData'));
    }

    public function edit(Product $product)
    {
        $product->load([
            'variants.attributeValues.attribute',
            'toppings'
        ]);
        // dd($product);
        $variantData = [];

        foreach ($product->variants as $variant) {
            $attributes = [];

            foreach ($variant->attributeValues as $attrValue) {
                if ($attrValue->attribute) {
                    $attributes[$attrValue->attribute->name] =
                        $attrValue->value;
                }
            }

            $variantData[] = [
                'attributes' => $attributes,
                'price' => $variant->price,
                'sale_price' => $variant->sale_price,
                'commission_rate' => $variant->commission_rate,
                'stock' => $variant->stock,
                'image_url' => $variant->image,
                'ticket_reward_quantity' =>
                $variant->ticket_reward_quantity ?? 0,

                'ticket_type_code' =>
                $variant->ticket_type_code,
            ];
        }

        $selectedStore = null;

        if ($product->store_id) {

            $selectedStore = Store::query()
                ->select('id', 'name', 'store_code')
                ->find($product->store_id);
        }

        $attributes = Attribute::where(
            'store_id',
            $product->store_id
        )->get();

        $categories = ProductCategory::where(
            'store_id',
            $product->store_id
        )->get();

        $groups = ProductGroup::all();

        $toppings = Topping::where(
            'store_id',
            $product->store_id
        )->get();

        $selectedToppings =
            $product->toppings
            ->pluck('id')
            ->toArray();
        $ticketTypes = [];

        if (class_exists(Ticket::class)) {
            $ticketTypes = Ticket::query()
                ->select(
                    'ticket_type_code',
                    'value_amount'
                )
                ->distinct()
                ->get()
                ->map(fn($t) => (object)[
                    'code' => $t->ticket_type_code,
                    'name' => $t->ticket_type_code,
                    'ticket_value' => $t->value_amount
                ]);
        }

        return view(
            'admin.products.edit',
            compact(
                'product',
                'categories',
                'groups',
                'variantData',
                'attributes',
                'selectedStore',
                'toppings',
                'selectedToppings',
                'ticketTypes'
            )
        );
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id); // Tìm sản phẩm theo ID

        $request->merge([
            'summary' => $this->normalizeNullableText($request->input('summary')),
            'content' => $this->normalizeNullableText($request->input('content')),
            'slug' => $this->normalizeNullableText($request->input('slug')),
            'sku' => $this->normalizeNullableText($request->input('sku')),
        ]);

        // Validate dữ liệu
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'stock' => 'required',
            'summary' => 'nullable|string|max:500',
            'content' => 'nullable|string',
            'thumbnail' => 'nullable|image',
            'images.*' => 'nullable|image',
            'price' => 'nullable|numeric',
            'sale_price' => 'nullable|numeric',
            'slug' => 'nullable|string|unique:products,slug,' . $product->id, // Không kiểm tra duy nhất với sản phẩm hiện tại
            'sku' => 'nullable|string|unique:products,sku,' . $product->id, // Không kiểm tra duy nhất với sản phẩm hiện tại
            'store_id' => 'nullable|exists:stores,id',
            'category_id' => 'nullable|exists:product_categories,id',
            'group_id' => 'nullable|exists:product_groups,id',
            'commission_rate' => 'nullable|numeric|min:0|max:999.99',
        ]);

        // Chuan hoa input null/undefined va dam bao slug/sku la duy nhat khi cap nhat.
        $data['summary'] = $this->normalizeNullableText($data['summary'] ?? null);
        $data['content'] = $this->normalizeNullableText($data['content'] ?? null);
        if ($data['summary'] === null && $data['content'] !== null) {
            $summaryText = trim(preg_replace('/\s+/', ' ', strip_tags($data['content'])));
            if (mb_strlen($summaryText) > 250) {
                $summaryText = mb_substr($summaryText, 0, 247) . '...';
            }
            $data['summary'] = $summaryText === '' ? null : $summaryText;
        }
        $data['slug'] = $this->resolveUniqueSlug(
            $this->normalizeNullableText($data['slug'] ?? null),
            (string) $data['name'],
            (int) $product->id
        );
        $data['sku'] = $this->resolveUniqueSku(
            $this->normalizeNullableText($data['sku'] ?? null),
            (string) $data['name'],
            (int) $product->id
        );
        $data['commission_rate'] = (isset($data['commission_rate']) && $data['commission_rate'] !== null && $data['commission_rate'] !== '')
            ? (float) $data['commission_rate']
            : (float) ($product->commission_rate ?? 0);

        // Lưu ảnh đại diện nếu có
        if ($request->hasFile('thumbnail')) {
            // Xóa ảnh cũ nếu có
            if ($product->thumbnail && Storage::exists('public/' . $product->thumbnail)) {
                Storage::delete('public/' . $product->thumbnail);
            }
            // Lưu ảnh mới
            $data['thumbnail'] = $request->file('thumbnail')->store('products', 'public');
        }

        $data['store_id'] = $data['store_id'] ?? $product->store_id;

        if (!empty($data['store_id']) && Schema::hasColumn('products', 'status')) {
            $data['status'] = 'pending_approval';
        }
        if (!empty($data['store_id']) && Schema::hasColumn('products', 'approved_by')) {
            $data['approved_by'] = null;
        }
        if (!empty($data['store_id']) && Schema::hasColumn('products', 'approved_at')) {
            $data['approved_at'] = null;
        }
        if (!empty($data['store_id']) && Schema::hasColumn('products', 'published_at')) {
            $data['published_at'] = null;
        }
        if (!empty($data['store_id']) && Schema::hasColumn('products', 'rejection_reason')) {
            $data['rejection_reason'] = null;
        }

        // Cập nhật thông tin sản phẩm
        $product->update($data);

        // Cập nhật ảnh sản phẩm nếu có
        if ($request->hasFile('images')) {
            $paths = [];
            foreach ($request->file('images') as $image) {
                $paths[] = $image->store('product_images', 'public');
            }
            // Cập nhật ảnh mới
            $product->images = json_encode($paths);
            $product->save();
        }
        // return $request->variants;
        // 🔥 BẮT ĐẦU XỬ LÝ BIẾN THỂ 🔥
        if ($request->has('variants')) {
            // 1️⃣ Lưu lại ảnh cũ trước khi xoá
            $oldImages = $product->variants()->pluck('image', 'id')->toArray();

            // 2️⃣ Xoá biến thể cũ
            $product->variants()->delete();

            // 3️⃣ Tạo mới biến thể (giữ ảnh cũ nếu không update)
            foreach ($request->variants as $index => $variant) {
                if (isset($variant['delete']) && $variant['delete'] == 1) {
                    continue; // Bỏ qua biến thể này (không tạo mới!)
                }
                $productVariant = $product->variants()->create([
                    'price' => $variant['price'] ?? null,

                    'sale_price' => $variant['sale_price'] ?? null,

                    'commission_rate' =>
                    isset($variant['commission_rate'])
                        ? (float) $variant['commission_rate']
                        : 0,

                    'stock' =>
                    $variant['stock'] ?? 0,

                    'ticket_reward_quantity' =>
                    $variant['ticket_reward_quantity'] ?? 0,

                    'ticket_type_code' =>
                    $variant['ticket_type_code'] ?? null,
                ]);

                // ✅ Xử lý ảnh biến thể
                if (isset($variant['image']) && $variant['image'] instanceof \Illuminate\Http\UploadedFile) {
                    // Có ảnh mới upload
                    $path = $variant['image']->store('products', 'public');
                    $productVariant->update(['image' =>  $path]);
                } elseif (isset($variant['image']) && is_string($variant['image']) && $variant['image'] !== '') {
                    // Có path ảnh từ form (nếu có)
                    $productVariant->update(['image' => $variant['image']]);
                } else {
                    // Không có ảnh mới → lấy lại ảnh cũ (theo index)
                    $oldImage = $oldImages[array_keys($oldImages)[$index] ?? null] ?? null;
                    if ($oldImage) {
                        $productVariant->update(['image' => $oldImage]);
                    }
                }

                // ✅ Gán lại attributes cho biến thể
                if (isset($variant['attributes'])) {
                    $attributes = is_string($variant['attributes'])
                        ? json_decode($variant['attributes'], true)
                        : $variant['attributes'];

                    if ($attributes && is_array($attributes)) {
                        $attributeValueIds = [];
                        foreach ($attributes as $name => $value) {
                            $attribute = Attribute::firstOrCreate(['name' => $name]);
                            $attributeValue = AttributeValue::firstOrCreate([
                                'attribute_id' => $attribute->id,
                                'value' => is_array($value) ? ($value['value'] ?? '') : $value,
                            ]);
                            $attributeValueIds[] = $attributeValue->id;
                        }
                        $productVariant->attributeValues()->sync($attributeValueIds);
                    }
                }
            }
            // Sync price and sale_price from the first variant if variants exist
            $firstVariant = $product->variants()->first();
            if ($firstVariant) {
                $product->price = $firstVariant->price;
                $product->sale_price = $firstVariant->sale_price;
                $product->save();
            }
        }

        $this->syncProductToppings(

            $product,

            $product->store,

            $request->toppings

        );

        if (!empty($product->store_id)) {
            $this->recordProductApprovalSubmission($product);

            return redirect()->route('admin.stores.edit', [
                'store' => $product->store_id,
                'active_tab' => $request->input('active_tab', 'store-product-approvals'),
            ])->with('success', 'Cập nhật sản phẩm đã được gửi yêu cầu duyệt.');
        }

        return redirect()->route('admin.stores.index')->with('success', 'Cập nhật sản phẩm thành công!');
    }


    public function destroy(Request $request, Product $product)
    {
        if ($product->thumbnail) {
            Storage::disk('public')->delete($product->thumbnail);
        }

        if ($product->images) {
            $images = is_array($product->images)
                ? $product->images
                : json_decode((string) $product->images, true);

            foreach (($images ?? []) as $img) {
                Storage::disk('public')->delete($img);
            }
        }

        $storeId = $request->input('store_id') ?: $product->store_id;
        $product->delete();

        $returnStoreId = $request->input('return_store_id');
        if (!empty($returnStoreId)) {
            return redirect()
                ->route('admin.stores.edit', [
                    'store' => $returnStoreId,
                    'active_tab' => $request->input('active_tab', 'store-products'),
                ])
                ->with('success', 'Xóa sản phẩm thành công!');
        }

        if (!empty($storeId)) {
            return redirect()->route('admin.stores.edit', [
                'store' => $storeId,
                'active_tab' => 'store-products',
            ])->with('success', 'Xóa sản phẩm thành công!');
        }

        return redirect()->route('admin.stores.index')->with('success', 'Xóa sản phẩm thành công!');
    }

    public function upload(Request $request)
    {
        if ($request->hasFile('upload')) {
            $file = $request->file('upload');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/ckeditor', $filename, 'public');

            $url = asset('storage/' . $path);

            return response()->json([
                'uploaded' => true,
                'url' => $url
            ]);
        }

        return response()->json(['uploaded' => false]);
    }

    public function getProduct()
    {
        $products = Product::with('category', 'group')->latest()->get();
        return view('index', compact('products'));
    }

    public function getAllProduct()
    {
        $categories = ProductCategory::all();
        $products = Product::with('category', 'group')->latest()->get();
        return view('product', compact('products', 'categories'));
    }

    public function home()
    {
        return redirect()->route('admin');
    }

    public function indexStock(Request $request)
    {
        $storeId = $request->query('store_id');
        if (empty($storeId)) {
            return redirect()
                ->route('admin.stores.index')
                ->with('error', 'Vui lòng chọn cửa hàng và quản lý tồn kho tại tab cửa hàng.');
        }

        $selectedStore = Store::query()
            ->select('id', 'name', 'store_code')
            ->find($storeId);

        if (!$selectedStore) {
            return redirect()
                ->route('admin.stores.index')
                ->with('error', 'Cửa hàng không tồn tại hoặc đã bị xóa.');
        }

        return redirect()->route('admin.stores.edit', [
            'store' => $selectedStore->id,
            'active_tab' => 'store-inventory',
        ]);
    }

    public function updateStock(Request $request, Product $product)
    {
        $request->validate([
            'stock' => 'required|integer|min:0',
        ]);

        $product->stock = $request->stock;
        $product->save();

        if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Cập nhật tồn kho thành công!',
                'data' => [
                    'product_id' => $product->id,
                    'store_id' => $product->store_id,
                    'stock' => (int) $product->stock,
                ],
            ]);
        }

        $returnStoreId = $request->input('return_store_id');
        if (!empty($returnStoreId)) {
            return redirect()
                ->route('admin.stores.edit', [
                    'store' => $returnStoreId,
                    'active_tab' => $request->input('active_tab', 'store-inventory'),
                ])
                ->with('success', 'Cập nhật tồn kho thành công!');
        }

        if (!empty($product->store_id)) {
            $this->recordProductApprovalSubmission($product);
            return redirect()->route('admin.stores.edit', [
                'store' => $product->store_id,
                'active_tab' => 'store-inventory',
            ])->with('success', 'Cập nhật tồn kho thành công!');
        }

        return redirect()->route('admin.stores.index')->with('success', 'Cập nhật tồn kho thành công!');
    }

    private function normalizeNullableText($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $lowered = mb_strtolower($normalized);
        if (in_array($lowered, ['null', 'undefined'], true)) {
            return null;
        }

        return $normalized;
    }

    private function recordProductApprovalSubmission(Product $product): void
    {
        app(ProductApprovalHistoryService::class)->recordSubmission($product, auth()->id());
    }

    private function resolveUniqueSlug(?string $providedSlug, string $name, ?int $ignoreProductId = null): string
    {
        $baseSlug = Str::slug((string) ($providedSlug ?: $name));
        if ($baseSlug === '') {
            $baseSlug = 'product';
        }

        $candidate = $baseSlug;
        $counter = 2;

        while ($this->slugExists($candidate, $ignoreProductId)) {
            $candidate = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function resolveUniqueSku(?string $providedSku, string $name, ?int $ignoreProductId = null): string
    {
        $baseSku = Str::slug((string) ($providedSku ?: $name));
        if ($baseSku === '') {
            $baseSku = 'sku';
        }

        $candidate = $baseSku;
        $counter = 2;

        while ($this->skuExists($candidate, $ignoreProductId)) {
            $candidate = $baseSku . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function slugExists(string $slug, ?int $ignoreProductId = null): bool
    {
        $query = Product::query()->where('slug', $slug);
        if ($ignoreProductId !== null) {
            $query->where('id', '!=', $ignoreProductId);
        }

        return $query->exists();
    }

    private function skuExists(string $sku, ?int $ignoreProductId = null): bool
    {
        $query = Product::query()->where('sku', $sku);
        if ($ignoreProductId !== null) {
            $query->where('id', '!=', $ignoreProductId);
        }

        return $query->exists();
    }
}

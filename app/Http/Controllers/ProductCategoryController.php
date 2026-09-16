<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProductCategoryController extends Controller
{
    public function index()
    {
        return view('admin.product_categories.index');
    }

    public function homeConfig()
    {
        $categories = ProductCategory::with('store')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $categoryTree = $this->buildConfigTree($categories);
        $stats = [
            'total' => $categories->count(),
            'active' => $categories->filter(fn ($category) => (int) ($category->is_active ?? 1) === 1)->count(),
            'featured' => $categories->filter(fn ($category) => (int) ($category->show_on_home ?? 0) === 1)->count(),
            'max_level' => max(1, (int) $categories->max(fn ($category) => (int) ($category->level ?? 1))),
        ];

        return view('admin.product_categories.home_config', compact('categoryTree', 'stats'));
    }

    public function tree()
    {
        $categories = ProductCategory::with('store')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $tree = $this->buildConfigTree($categories)
            ->map(fn ($category) => $this->formatConfigCategory($category))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $tree,
            'meta' => [
                'total' => $categories->count(),
                'request_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function reorder(Request $request)
    {
        $payload = $request->input('categories', $request->input('items', $request->input('menu', [])));

        if (! is_array($payload)) {
            return response()->json([
                'success' => false,
                'message' => 'Du lieu sap xep khong hop le.',
            ], 422);
        }

        $updates = [];
        if ($this->isFlatReorderPayload($payload)) {
            foreach ($payload as $item) {
                if (! is_array($item) || ! isset($item['id'], $item['sort_order'])) {
                    continue;
                }

                $updates[] = [
                    'id' => (int) $item['id'],
                    'sort_order' => max(0, (int) $item['sort_order']),
                ];
            }
        } else {
            $this->collectNestedCategoryUpdates($payload, null, 1, $updates);
        }

        $ids = collect($updates)->pluck('id')->filter()->values();
        if ($ids->isEmpty() || $ids->unique()->count() !== $ids->count()) {
            return response()->json([
                'success' => false,
                'message' => 'Danh sach danh muc sap xep khong hop le.',
            ], 422);
        }

        if (collect($updates)->max('level') > 3) {
            return response()->json([
                'success' => false,
                'message' => 'Cay danh muc chi ho tro toi da 3 cap.',
            ], 422);
        }

        $existingIds = ProductCategory::whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id);
        if ($existingIds->count() !== $ids->unique()->count()) {
            return response()->json([
                'success' => false,
                'message' => 'Co danh muc khong ton tai trong payload.',
            ], 422);
        }

        DB::transaction(function () use ($updates) {
            foreach ($updates as $update) {
                $data = [
                    'sort_order' => (int) $update['sort_order'],
                    'updated_at' => now(),
                ];

                if (array_key_exists('parent_id', $update)) {
                    $data['parent_id'] = $update['parent_id'];
                    $data['level'] = (int) $update['level'];
                }

                DB::table('product_categories')
                    ->where('id', $update['id'])
                    ->update($data);
            }
        });

        $this->forgetHomeNavCache();

        Log::info('product_categories.reordered', [
            'admin_id' => auth()->id(),
            'count' => count($updates),
            'updates' => $updates,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Da luu thu tu danh muc.',
            'meta' => [
                'updated' => count($updates),
                'request_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function updateDisplay(Request $request, ProductCategory $productCategory)
    {
        $validated = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'show_on_home' => ['sometimes', 'boolean'],
        ]);

        if ($validated === []) {
            return response()->json([
                'success' => false,
                'message' => 'Khong co truong can cap nhat.',
            ], 422);
        }

        $updates = [];
        foreach (['is_active', 'show_on_home'] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = (bool) $validated[$field];
            }
        }

        $productCategory->update($updates);
        $this->forgetHomeNavCache();

        Log::info('product_categories.display_updated', [
            'admin_id' => auth()->id(),
            'category_id' => $productCategory->id,
            'updates' => $updates,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Da cap nhat cau hinh hien thi.',
            'data' => $this->formatConfigCategory($productCategory->fresh('store')),
        ]);
    }

    public function datatable(Request $request)
    {
        $query = ProductCategory::query()
            ->leftJoin('product_categories as parents', 'product_categories.parent_id', '=', 'parents.id')
            ->leftJoin('stores', 'product_categories.store_id', '=', 'stores.id')
            ->select(
                'product_categories.*',
                'parents.name as parent_name',
                'stores.name as store_name'
            );

        $recordsTotal = (clone $query)->count('product_categories.id');

        if ($search = $request->input('search.value')) {
            $query->where(function ($builder) use ($search) {
                $builder->where('product_categories.name', 'like', "%{$search}%")
                    ->orWhere('product_categories.slug', 'like', "%{$search}%")
                    ->orWhere('parents.name', 'like', "%{$search}%")
                    ->orWhere('stores.name', 'like', "%{$search}%");
            });
        }

        $recordsFiltered = (clone $query)->count('product_categories.id');

        $columns = [
            1 => 'product_categories.id',
            2 => 'product_categories.name',
            3 => 'product_categories.slug',
            4 => 'parents.name',
            6 => 'product_categories.sort_order',
        ];

        $orderField = $columns[$request->input('order.0.column', 1)] ?? 'product_categories.id';
        $orderDir   = $request->input('order.0.dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($orderField, $orderDir);

        $start  = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 20);

        $categories = $query->skip($start)->take($length)->get();

        $data = $categories->map(function (ProductCategory $category) {
            $imageUrl = $category->image ? asset('storage/' . $category->image) : null;

            $scopeBadge = $category->store_id
                ? '<span class="badge bg-info">Cửa hàng: ' . e($category->store_name ?? $category->store_id) . '</span>'
                : '<span class="badge bg-secondary">Hệ thống</span>';

            return [
                'checkbox'   => '<input type="checkbox" class="product-category-row-checkbox" value="' . $category->id . '">',
                'id'         => $category->id,
                'name'       => e($category->name),
                'slug'       => e($category->slug),
                'parent'     => e($category->parent_name ?? '-'),
                'scope'      => $scopeBadge,
                'image'      => $imageUrl
                    ? '<img src="' . e($imageUrl) . '" width="50" height="50" style="object-fit:cover" alt="Ảnh danh mục">'
                    : '<span class="text-muted">-</span>',
                'sort_order' => $category->sort_order,
                'action'     => '<a href="' . route('admin.product-categories.edit', $category->id) . '" class="btn btn-warning btn-sm">Sửa</a>'
                    . '<form action="' . route('admin.product-categories.destroy', $category->id) . '" method="POST" style="display:inline-block">'
                    . csrf_field() . method_field('DELETE')
                    . '<button type="submit" class="btn btn-danger btn-sm" onclick="return confirm(\'Xóa danh mục này?\')">Xóa</button>'
                    . '</form>',
            ];
        });

        return response()->json([
            'draw'            => (int) $request->input('draw', 1),
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ]);
    }

    // Show form tạo mới danh mục
    public function create()
    {
        $categories = ProductCategory::all();
        $stores     = \App\Models\Store::orderBy('name')->get(['id', 'name']);
        return view('admin.product_categories.create', compact('categories', 'stores'));
    }

    // Lưu danh mục mới
    public function store(Request $request)
    {
        $request->validate([
            'name'       => 'required|string|max:255',
            'slug'       => 'nullable|string|max:255|unique:product_categories,slug',
            'parent_id'  => 'nullable|exists:product_categories,id',
            'store_id'   => 'nullable|exists:stores,id',
            'image'      => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'show_on_home' => 'nullable|boolean',
        ]);

        $slug      = $request->slug ?? Str::slug($request->name);
        $sortOrder = $request->sort_order ?? ProductCategory::max('sort_order') + 1;
        $level = $this->resolveLevelForParent($request->parent_id);
        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store('category_images', 'public')
            : null;

        ProductCategory::create([
            'name'       => $request->name,
            'slug'       => $slug,
            'parent_id'  => $request->parent_id,
            'store_id'   => $request->store_id ?: null,
            'image'      => $imagePath,
            'sort_order' => $sortOrder,
            'is_active' => $request->boolean('is_active', true),
            'show_on_home' => $request->boolean('show_on_home'),
            'level' => $level,
        ]);

        $this->forgetHomeNavCache();

        return redirect()->route('admin.product-categories.index')->with('success', 'Danh mục đã được thêm thành công!');
    }



    public function edit($id)
    {
        $category   = ProductCategory::findOrFail($id);
        $categories = ProductCategory::all();
        $stores     = \App\Models\Store::orderBy('name')->get(['id', 'name']);
        return view('admin.product_categories.edit', compact('category', 'categories', 'stores'));
    }

// Cập nhật danh mục
public function update(Request $request, $id)
{
    $request->validate([
        'name'       => 'required|string|max:255',
        'slug'       => 'nullable|string|max:255|unique:product_categories,slug,' . $id,
        'parent_id'  => 'nullable|exists:product_categories,id',
        'store_id'   => 'nullable|exists:stores,id',
        'image'      => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        'sort_order' => 'nullable|integer',
        'is_active' => 'nullable|boolean',
        'show_on_home' => 'nullable|boolean',
    ]);

    $category  = ProductCategory::findOrFail($id);
    $slug      = $request->slug ?? Str::slug($request->name);
    $sortOrder = $request->sort_order ?? ProductCategory::max('sort_order') + 1;
    $level = $this->resolveLevelForParent($request->parent_id);

    if ($request->hasFile('image')) {
        if ($category->image && file_exists(storage_path('app/public/' . $category->image))) {
            unlink(storage_path('app/public/' . $category->image));
        }
        $imagePath = $request->file('image')->store('category_images', 'public');
    } else {
        $imagePath = $category->image;
    }

    $category->update([
        'name'       => $request->name,
        'slug'       => $slug,
        'parent_id'  => $request->parent_id,
        'store_id'   => $request->store_id ?: null,
        'image'      => $imagePath,
        'sort_order' => $sortOrder,
        'is_active' => $request->boolean('is_active'),
        'show_on_home' => $request->boolean('show_on_home'),
        'level' => $level,
    ]);

    $this->forgetHomeNavCache();

    return redirect()->route('admin.product-categories.index')->with('success', 'Danh mục đã được cập nhật thành công!');
}


    public function destroy(ProductCategory $productCategory)
    {
        if ($productCategory->id == 2) {
            return redirect()->route('admin.product-categories.index')->with('error', 'Không thể xóa danh mục này');
        }
        $productCategory->delete();
        $this->forgetHomeNavCache();
        return redirect()->route('admin.product-categories.index')->with('success', 'Xóa danh mục thành công');
    }

    public function destroyMultiple(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|distinct|exists:product_categories,id',
        ]);

        $deleted = ProductCategory::whereIn('id', $request->input('ids'))->delete();
        $this->forgetHomeNavCache();

        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => "Đã xóa {$deleted} danh mục.",
            ]);
        }

        return redirect()->route('admin.product-categories.index')
            ->with('success', "Đã xóa {$deleted} danh mục.");
    }

    public function ajaxStore(Request $request)
{
    $request->validate([
        'name' => 'required|max:255'
    ]);
$slug = Str::slug($request->name);

$count = ProductCategory::where(
    'slug',
    'LIKE',
    $slug.'%'
)->count();

if($count){
    $slug .= '-'.($count+1);
}
    $category = ProductCategory::create([
        'name' => $request->name,
            'slug'=>$slug,
        'store_id' => $request->store_id,
        'level' => $this->resolveLevelForParent(null),
    ]);

    $this->forgetHomeNavCache();

    return response()->json([
        'success' => true,
        'category' => $category
    ]);
}

    private function buildConfigTree($categories)
    {
        $ids = $categories->pluck('id')->map(fn ($id) => (int) $id)->all();
        $idLookup = array_flip($ids);
        $grouped = $categories->groupBy(fn ($category) => $category->parent_id ? (string) $category->parent_id : 'root');

        $attachChildren = function ($nodes, int $level) use (&$attachChildren, $grouped) {
            return $nodes->map(function ($category) use (&$attachChildren, $grouped, $level) {
                $category->setAttribute('computed_level', $level);
                $children = $grouped->get((string) $category->id, collect())->values();
                $category->setRelation('children', $attachChildren($children, $level + 1));

                return $category;
            })->values();
        };

        $roots = $categories->filter(function ($category) use ($idLookup) {
            $parentId = $category->parent_id;

            return $parentId === null || (int) $parentId === 0 || ! isset($idLookup[(int) $parentId]);
        })->values();

        return $attachChildren($roots, 1);
    }

    private function formatConfigCategory(ProductCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'parent_id' => $category->parent_id ? (int) $category->parent_id : null,
            'image' => $category->image,
            'image_url' => $category->image ? asset('storage/' . $category->image) : null,
            'sort_order' => (int) ($category->sort_order ?? 0),
            'is_active' => (bool) ($category->is_active ?? true),
            'show_on_home' => (bool) ($category->show_on_home ?? false),
            'level' => (int) ($category->computed_level ?? $category->level ?? 1),
            'scope' => $category->store_id ? 'store' : 'system',
            'store_id' => $category->store_id,
            'store_name' => $category->store?->name,
            'children' => collect($category->children ?? [])
                ->map(fn ($child) => $this->formatConfigCategory($child))
                ->values()
                ->all(),
        ];
    }

    private function isFlatReorderPayload(array $payload): bool
    {
        foreach ($payload as $item) {
            if (! is_array($item) || ! array_key_exists('sort_order', $item) || array_key_exists('children', $item)) {
                return false;
            }
        }

        return true;
    }

    private function collectNestedCategoryUpdates(array $items, ?int $parentId, int $level, array &$updates): void
    {
        foreach (array_values($items) as $index => $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                continue;
            }

            $id = (int) $item['id'];
            $updates[] = [
                'id' => $id,
                'parent_id' => $parentId,
                'sort_order' => $index,
                'level' => $level,
            ];

            if (! empty($item['children']) && is_array($item['children'])) {
                $this->collectNestedCategoryUpdates($item['children'], $id, $level + 1, $updates);
            }
        }
    }

    private function resolveLevelForParent($parentId): int
    {
        if (! $parentId) {
            return 1;
        }

        $parentLevel = ProductCategory::where('id', $parentId)->value('level');

        return max(1, ((int) $parentLevel) + 1);
    }

    private function forgetHomeNavCache(): void
    {
        Cache::forget(ProductCategory::HOME_NAV_CACHE_KEY);
    }
}

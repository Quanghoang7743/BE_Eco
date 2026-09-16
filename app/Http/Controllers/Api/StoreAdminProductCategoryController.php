<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StoreAdminProductCategoryController extends Controller
{
    public function tree(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return $context['error'];
        }

        $store = $context['store'];

        $query = DB::table('product_categories')
            ->select(['id', 'name', 'parent_id', 'sort_order', 'store_id'])
            ->where('store_id', $store->id)
            ->orderBy('sort_order')
            ->orderBy('name');

        $rows = $query->get();

        $childrenMap = [];
        foreach ($rows as $row) {
            $parentKey = $row->parent_id === null ? 'root' : (string) $row->parent_id;
            $childrenMap[$parentKey][] = $row;
        }

        $buildTree = function (string $parentKey, int $level) use (&$buildTree, $childrenMap): array {
            $children = $childrenMap[$parentKey] ?? [];
            $nodes = [];

            foreach ($children as $item) {
                $itemId = (string) $item->id;
                $childNodes = $buildTree($itemId, $level + 1);

                $storeId = property_exists($item, 'store_id') ? $item->store_id : null;

                $nodes[] = [
                    'id' => $item->id,
                    'name' => $item->name,
                    'parent_id' => $item->parent_id,
                    'level' => $level,
                    'scope' => $storeId === null ? 'system' : 'store',
                    'store_id' => $storeId,
                    'is_leaf' => empty($childNodes),
                    'children' => $childNodes,
                ];
            }

            return $nodes;
        };

        $tree = $buildTree('root', 1);

        $maxLevel = 0;
        $countLeaf = 0;
        $countSystem = 0;
        $countStore = 0;
        $walkTree = function (array $nodes) use (&$walkTree, &$maxLevel, &$countLeaf, &$countSystem, &$countStore): void {
            foreach ($nodes as $node) {
                $level = (int) ($node['level'] ?? 0);
                if ($level > $maxLevel) {
                    $maxLevel = $level;
                }

                if (($node['scope'] ?? 'system') === 'system') {
                    $countSystem++;
                } else {
                    $countStore++;
                }

                $children = $node['children'] ?? [];
                if (empty($children)) {
                    $countLeaf++;
                } else {
                    $walkTree($children);
                }
            }
        };
        $walkTree($tree);

        $isThreeLevelReady = $maxLevel >= 3;
        $warnings = [];
        if (!$isThreeLevelReady && count($rows) > 0) {
            $warnings[] = [
                'code' => 'CATEGORY_TREE_SHALLOW',
                'message' => 'Danh muc hien tai chua du 3 cap cho Cascading Dropdown.',
                'details' => [
                    'max_level' => $maxLevel,
                    'required_level' => 3,
                ],
            ];
        }

        return response()->json([
            'data' => $tree,
            'meta' => [
                'has_store' => $store !== null,
                'total_nodes' => count($rows),
                'system_nodes' => $countSystem,
                'store_nodes' => $countStore,
                'leaf_nodes' => $countLeaf,
                'max_level' => $maxLevel,
                'is_three_level_ready' => $isThreeLevelReady,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => $warnings,
        ]);
    }

    private function resolveContext(Request $request): array
    {
        return $this->resolveStoreAdminAccessContext($request);
    }

    private function resolveStoreFromBinding(string $userId, string $storeId): ?Store
    {
        if (!Schema::hasTable('user_role_bindings') || !Schema::hasTable('roles')) {
            return null;
        }

        $bindingQuery = DB::table('user_role_bindings as urb')
            ->join('roles as r', 'r.id', '=', 'urb.role_id')
            ->where('urb.user_id', $userId)
            ->where('urb.entity_type', 'store')
            ->where('urb.entity_id', $storeId)
            ->whereIn('r.name', ['store_staff', 'store_manager', 'admin_store']);

        if (Schema::hasColumn('user_role_bindings', 'status')) {
            $bindingQuery->where('urb.status', 'active');
        }

        if (!$bindingQuery->exists()) {
            return null;
        }

        $storeQuery = Store::query()->where('id', $storeId);
        if (Schema::hasColumn('stores', 'deleted_at')) {
            $storeQuery->whereNull('deleted_at');
        }

        return $storeQuery->first();
    }

    private function errorResponse(string $code, string $message, int $status, bool $authenticated, bool $hasStore, ?array $details = null): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'data' => null,
            'meta' => [
                'authenticated' => $authenticated,
                'has_store' => $hasStore,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [$error],
        ], $status);
    }

    public function store(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return $context['error'];
        }

        $store = $context['store'];

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'image' => ['nullable', 'string', 'max:2000'],
        ]);

        // Validate parent_id to check store scope ownership
        if (!empty($validated['parent_id'])) {
            $parentId = (int) $validated['parent_id'];
            $parentCategory = DB::table('product_categories')
                ->where('id', $parentId)
                ->first();

            if (!$parentCategory) {
                return $this->errorResponse('INVALID_PARENT', 'Danh mục cha không tồn tại.', 422, true, true);
            }

            $parentStoreId = property_exists($parentCategory, 'store_id') ? $parentCategory->store_id : null;
            if ($parentStoreId !== null && (string) $parentStoreId !== (string) $store->id) {
                return $this->errorResponse('FORBIDDEN_PARENT', 'Danh mục cha không thuộc cửa hàng này.', 403, true, true);
            }
        }

        $slug = $this->generateUniqueCategorySlug($validated['name']);

        $insertData = [
            'store_id' => $store->id,
            'name' => $validated['name'],
            'parent_id' => $validated['parent_id'] ?? null,
            'sort_order' => (int) $validated['sort_order'],
            'image' => $validated['image'] ?? null,
            'slug' => $slug,
        ];

        if (Schema::hasColumn('product_categories', 'status')) {
            $insertData['status'] = 'active';
        }
        if (Schema::hasColumn('product_categories', 'created_at')) {
            $insertData['created_at'] = now();
        }
        if (Schema::hasColumn('product_categories', 'updated_at')) {
            $insertData['updated_at'] = now();
        }

        $categoryId = DB::table('product_categories')->insertGetId($insertData);
        $category = DB::table('product_categories')->where('id', $categoryId)->first();

        return response()->json([
            'data' => [
                'id' => $category->id,
                'name' => $category->name,
                'parent_id' => $category->parent_id,
                'sort_order' => property_exists($category, 'sort_order') ? (int) $category->sort_order : 0,
                'image' => property_exists($category, 'image') ? $category->image : null,
                'slug' => $category->slug,
                'store_id' => property_exists($category, 'store_id') ? $category->store_id : null,
                'status' => property_exists($category, 'status') ? $category->status : null,
                'created_at' => property_exists($category, 'created_at') && $category->created_at ? (new \Carbon\Carbon($category->created_at))->toIso8601String() : null,
                'updated_at' => property_exists($category, 'updated_at') && $category->updated_at ? (new \Carbon\Carbon($category->updated_at))->toIso8601String() : null,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return $context['error'];
        }

        $store = $context['store'];

        $category = DB::table('product_categories')
            ->where('id', $id)
            ->first();

        if (!$category) {
            return $this->errorResponse('CATEGORY_NOT_FOUND', 'Không tìm thấy danh mục.', 404, true, true);
        }

        // Check if category belongs to store (prevent editing system categories)
        $storeIdStr = property_exists($category, 'store_id') ? (string) $category->store_id : '';
        if ($storeIdStr === '' || $storeIdStr !== (string) $store->id) {
            return $this->errorResponse('FORBIDDEN', 'Không có quyền chỉnh sửa danh mục này.', 403, true, true);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:product_categories,id'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
            'image' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $updateData = [];

        // Check parent_id validation to avoid circular reference
        if (array_key_exists('parent_id', $validated)) {
            $parentId = $validated['parent_id'];
            if ($parentId !== null) {
                if ((int) $parentId === (int) $id) {
                    return $this->errorResponse('INVALID_PARENT', 'Danh mục cha không thể là chính nó.', 422, true, true);
                }

                // Check that parent_id does not belong to any of the descendants of this category
                if ($this->isDescendant((int) $id, (int) $parentId)) {
                    return $this->errorResponse('CIRCULAR_DEPENDENCY', 'Danh mục cha không thể là một danh mục con/con cháu của danh mục hiện tại.', 422, true, true);
                }
            }
            $updateData['parent_id'] = $parentId;
        }

        if (isset($validated['name'])) {
            $updateData['name'] = trim($validated['name']);
            $updateData['slug'] = $this->generateUniqueCategorySlug($validated['name'], (int) $id);
        }

        if (isset($validated['sort_order'])) {
            $updateData['sort_order'] = (int) $validated['sort_order'];
        }

        if (array_key_exists('image', $validated)) {
            $updateData['image'] = $validated['image'];
        }

        if (Schema::hasColumn('product_categories', 'updated_at')) {
            $updateData['updated_at'] = now();
        }

        DB::table('product_categories')
            ->where('id', $id)
            ->update($updateData);

        $updatedCategory = DB::table('product_categories')->where('id', $id)->first();

        return response()->json([
            'data' => [
                'id' => $updatedCategory->id,
                'name' => $updatedCategory->name,
                'slug' => $updatedCategory->slug,
                'parent_id' => $updatedCategory->parent_id,
                'store_id' => property_exists($updatedCategory, 'store_id') ? $updatedCategory->store_id : null,
                'sort_order' => property_exists($updatedCategory, 'sort_order') ? (int) $updatedCategory->sort_order : 0,
                'image' => property_exists($updatedCategory, 'image') ? $updatedCategory->image : null,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    private function isDescendant(int $categoryId, int $potentialParentId): bool
    {
        $currentParentId = DB::table('product_categories')
            ->where('id', $potentialParentId)
            ->value('parent_id');

        while ($currentParentId !== null) {
            if ((int) $currentParentId === (int) $categoryId) {
                return true;
            }
            $currentParentId = DB::table('product_categories')
                ->where('id', (int) $currentParentId)
                ->value('parent_id');
        }

        return false;
    }

    private function generateUniqueCategorySlug(string $name, ?int $excludeId = null): string
    {
        $base = \Illuminate\Support\Str::slug($name);
        if ($base === '') {
            $base = 'category';
        }

        $slug = $base;
        $suffix = 1;

        while (
            DB::table('product_categories')
                ->where('slug', $slug)
                ->where('id', '<>', $excludeId)
                ->exists()
        ) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }
}

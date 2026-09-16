<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\Category;
use App\Models\article;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MenuController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $menus = Menu::orderBy('order')->get();

        // Lấy tất cả danh mục cùng với con của chúng
        $allCategories = Category::with([
            'articles' => fn ($query) => $query->orderBy('sort_order')->orderByDesc('id'),
            'children.articles' => fn ($query) => $query->orderBy('sort_order')->orderByDesc('id'),
            'children.children.articles' => fn ($query) => $query->orderBy('sort_order')->orderByDesc('id'),
            'children',
            'children.children',
        ])->orderBy('id', 'asc')->get();

        // Chỉ lấy các danh mục gốc (không có parent_category_id hoặc parent_category_id = 0)
        $categories = $allCategories->filter(function ($cat) {
            return is_null($cat->parent_category_id) || $cat->parent_category_id == 0;
        })->values();

        $allCategoriesFlat = $allCategories;
        $totalMenus = $menus->count();
        $posts = article::orderBy('id', 'desc')->get();
        $menuTree = $this->buildTree($menus);

        $selectedCategories = [];
        $selectedPosts = [];
        $this->collectReferenceIds($menuTree, $selectedCategories, $selectedPosts);
        $socials = $this->getSocials()->map(fn ($social) => (object) $social);

        $map_iframe = Setting::where('type', 'map_iframe')->value('value');
        return view('admin.menus.index', compact(
            'menuTree',
            'categories',
            'allCategoriesFlat',
            'posts',
            'selectedCategories',
            'selectedPosts',
            'totalMenus',
            'socials',      // ✅ FIX ERROR
            'map_iframe'
        ));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Thêm nhiều items (dạng danh sách khi chọn từ checkbox danh mục/bài viết)
        if ($request->has('items')) {
            foreach ($request->items as $item) {
                Menu::create([
                    'title'        => $item['title'],
                    'type'         => $item['type'],
                    'reference_id' => $item['reference_id'],
                    'url'          => $item['url'] ?? '',
                    'parent_id'    => $item['parent_id'] ?? 0,
                    'order'        => $item['order'] ?? 0,
                    'active'       => 1,
                ]);
            }

            return response()->json(['success' => true, 'message' => 'Thêm menu thành công!']);
        }

        // Thêm một item đơn (ví dụ custom link)
        $menu = Menu::create([
            'title'        => $request->title,
            'type'         => $request->type,
            'reference_id' => $request->reference_id,
            'url'          => $request->url ?? '',
            'parent_id'    => $request->parent_id ?? 0,
            'order'        => $request->order ?? 0,
            'active'       => 1,
        ]);

        if ($request->ajax()) {
            return response()->json(['success' => true, 'data' => $menu]);
        }

        return back()->with('success', 'Thêm menu thành công!');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Menu $menu)
    {
        $menu->update([
            'title'        => $request->title,
            'parent_id'    => $request->parent_id ?? 0,
            'order'        => $request->order ?? 0,
            'active'       => $request->active ?? 1,
            'type'         => $request->type,
            'reference_id' => $request->reference_id,
            'url'          => $request->url ?? '',
        ]);

        if ($request->ajax()) {
            return response()->json(['success' => true, 'message' => 'Cập nhật menu thành công!']);
        }

        return redirect()->route('admin.menus.index')->with('success', 'Cập nhật menu thành công!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Menu $menu)
    {
        $this->deleteChildren($menu);
        $menu->delete();

        return redirect()->route('admin.menus.index')->with('success', 'Xóa menu thành công!');
    }

    /**
     * Delete children menus recursively.
     */
    private function deleteChildren($menu)
    {
        foreach ($menu->children as $child) {
            $this->deleteChildren($child);
            $child->delete();
        }
    }

    /**
     * Remove menus by their linked category/post.
     */
    public function destroyByReference($type, $reference)
    {
        $menus = Menu::where('type', $type)->where('reference_id', $reference)->get();
        foreach ($menus as $menu) {
            $this->deleteChildren($menu);
            $menu->delete();
        }
        return response()->json(['success' => true]);
    }

    /**
     * Update the ordering/hierarchy from Nestable2.
     */
    public function sort(Request $request)
    {
        $data = $request->input('menu');
        $this->updateMenuOrder($data);
        return response()->json(['status' => 'success']);
    }

    public function updateMap(Request $request)
    {
        $validated = $request->validate([
            'map_iframe' => ['nullable', 'string', 'max:10000'],
        ]);

        $mapIframe = trim((string) ($validated['map_iframe'] ?? ''));

        if ($mapIframe !== '' && preg_match('/<\s*script|javascript\s*:/i', $mapIframe)) {
            return response()->json([
                'success' => false,
                'message' => 'Mã nhúng bản đồ không hợp lệ.',
            ], 422);
        }

        Setting::updateOrCreate(
            ['type' => 'map_iframe'],
            ['value' => $mapIframe]
        );

        return response()->json([
            'success' => true,
            'message' => 'Lưu bản đồ thành công!',
        ]);
    }

    public function storeSocial(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url', 'max:500'],
            'status' => ['nullable', 'in:0,1'],
            'icon' => ['nullable', 'image', 'max:2048'],
        ]);

        $socials = $this->getSocials();
        $iconUrl = $request->hasFile('icon')
            ? $this->storeSocialIcon($request)
            : '';

        $socials->push([
            'id' => (string) Str::uuid(),
            'name' => $validated['name'],
            'url' => $validated['url'],
            'icon' => $iconUrl,
            'status' => (int) ($validated['status'] ?? 1),
        ]);

        $this->saveSocials($socials);

        return response()->json([
            'success' => true,
            'message' => 'Thêm social thành công!',
        ]);
    }

    public function updateSocial(Request $request, string $id)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url', 'max:500'],
            'status' => ['nullable', 'in:0,1'],
            'icon' => ['nullable', 'image', 'max:2048'],
        ]);

        $found = false;
        $socials = $this->getSocials()->map(function ($social) use ($request, $validated, $id, &$found) {
            if ((string) ($social['id'] ?? '') !== (string) $id) {
                return $social;
            }

            $found = true;
            $oldIcon = $social['icon'] ?? '';
            $social['name'] = $validated['name'];
            $social['url'] = $validated['url'];
            $social['status'] = (int) ($validated['status'] ?? 1);

            if ($request->hasFile('icon')) {
                $social['icon'] = $this->storeSocialIcon($request);
                $this->deleteSocialIcon($oldIcon);
            }

            return $social;
        });

        if (! $found) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy social.',
            ], 404);
        }

        $this->saveSocials($socials);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật social thành công!',
        ]);
    }

    public function destroySocial(string $id)
    {
        $deletedIcon = null;
        $currentSocials = $this->getSocials();
        $originalCount = $currentSocials->count();
        $socials = $currentSocials
            ->reject(function ($social) use ($id, &$deletedIcon) {
                if ((string) ($social['id'] ?? '') !== (string) $id) {
                    return false;
                }

                $deletedIcon = $social['icon'] ?? null;
                return true;
            })
            ->values();

        if ($socials->count() === $originalCount) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy social.',
            ], 404);
        }

        $this->saveSocials($socials);
        $this->deleteSocialIcon($deletedIcon);

        return response()->json([
            'success' => true,
            'message' => 'Xóa social thành công!',
        ]);
    }

    public function toggleSocial(string $id)
    {
        $found = false;
        $socials = $this->getSocials()->map(function ($social) use ($id, &$found) {
            if ((string) ($social['id'] ?? '') !== (string) $id) {
                return $social;
            }

            $found = true;
            $social['status'] = empty($social['status']) ? 1 : 0;
            return $social;
        });

        if (! $found) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy social.',
            ], 404);
        }

        $this->saveSocials($socials);

        return response()->json([
            'success' => true,
            'message' => 'Đổi trạng thái social thành công!',
        ]);
    }

    private function updateMenuOrder($items, $parentId = 0)
    {
        foreach ($items as $index => $item) {
            Menu::where('id', $item['id'])->update([
                'order' => $index,
                'parent_id' => $parentId ?? 0,
            ]);
            if (isset($item['children'])) {
                $this->updateMenuOrder($item['children'], $item['id']);
            }
        }
    }

    private function getSocials()
    {
        $decoded = json_decode(Setting::where('type', 'socials')->value('value') ?: '[]', true);

        if (! is_array($decoded)) {
            return collect();
        }

        return collect($decoded)->map(function ($social) {
            $social = is_array($social) ? $social : (array) $social;
            $fallbackId = 'social-' . md5(json_encode([
                $social['name'] ?? '',
                $social['url'] ?? '',
                $social['icon'] ?? '',
            ]));

            return [
                'id' => (string) ($social['id'] ?? $fallbackId),
                'name' => (string) ($social['name'] ?? ''),
                'url' => (string) ($social['url'] ?? ''),
                'icon' => (string) ($social['icon'] ?? ''),
                'status' => (int) ($social['status'] ?? 1),
            ];
        })->values();
    }

    private function saveSocials($socials): void
    {
        Setting::updateOrCreate(
            ['type' => 'socials'],
            ['value' => json_encode(collect($socials)->values()->all(), JSON_UNESCAPED_UNICODE)]
        );
    }

    private function storeSocialIcon(Request $request): string
    {
        $path = $request->file('icon')->store('socials', 'public');

        return asset('storage/' . $path);
    }

    private function deleteSocialIcon(?string $iconUrl): void
    {
        $path = $this->extractPublicStoragePath($iconUrl);

        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function extractPublicStoragePath(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $needle = '/storage/';
        $position = strpos($path, $needle);

        if ($position === false) {
            return null;
        }

        return ltrim(substr($path, $position + strlen($needle)), '/');
    }

    /**
     * Helper to collect referenced category and post IDs for active menus.
     */
    private function collectReferenceIds($menus, &$selectedCategories, &$selectedPosts)
    {
        foreach ($menus as $menu) {
            if ($menu->type === 'category') {
                $selectedCategories[] = (int) $menu->reference_id;
            } elseif ($menu->type === 'post') {
                $selectedPosts[] = (int) $menu->reference_id;
            }

            if ($menu->children && $menu->children->isNotEmpty()) {
                $this->collectReferenceIds($menu->children, $selectedCategories, $selectedPosts);
            }
        }
    }

    /**
     * Helper to build tree structure of menus.
     */
    private function buildTree($items, $parentId = 0)
    {
        $branch = collect();

        foreach ($items as $item) {
            $parentVal = $item->parent_id ?? 0;
            if ($parentVal == $parentId) {
                $children = $this->buildTree($items, $item->id);
                if ($children->isNotEmpty()) {
                    $item->setRelation('children', $children);
                } else {
                    $item->setRelation('children', collect());
                }
                $branch->push($item);
            }
        }

        return $branch;
    }
}

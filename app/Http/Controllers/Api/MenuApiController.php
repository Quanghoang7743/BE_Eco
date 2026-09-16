<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\Setting;

class MenuApiController extends Controller
{
    public function footerMenus()
    {
        $menus = Menu::with([
                'article:id,slug',
                'category:id,slug',
                'children' => fn($query) => $query->select('id', 'title', 'url', 'parent_id', 'type', 'reference_id')
                    ->with(['article:id,slug', 'category:id,slug'])
            ])
            ->where(function($query) {
                $query->whereNull('parent_id')->orWhere('parent_id', 0);
            })
            ->where('active', true)
            ->orderBy('order')
            ->select('id', 'title', 'url', 'parent_id', 'type', 'reference_id')
            ->get();

        $formattedMenus = $menus->map(fn($menu) => $this->formatMenu($menu));
        $socials = $this->getActiveSocials();
        $mapIframe = Setting::where('type', 'map_iframe')->value('value') ?? '';

        return response()->json([
            'success' => true,
            'data' => $formattedMenus,
            'map_iframe' => $mapIframe,
            'socials' => $socials,
        ]);
    }

    private function formatMenu($menu)
    {
        $slug = null;
        if ($menu->type === 'post') {
            $slug = $menu->article?->slug;
        } elseif ($menu->type === 'category') {
            $slug = $menu->category?->slug;
        }

        return [
            'id' => $menu->id,
            'title' => $menu->title,
            'url' => $menu->url ?? '',
            'parent_id' => is_null($menu->parent_id) ? 0 : (int) $menu->parent_id,
            'type' => $menu->type,
            'reference_id' => is_null($menu->reference_id) ? null : (int) $menu->reference_id,
            'slug' => $slug,
            'children' => collect($menu->children)->map(fn($child) => $this->formatMenu($child))->toArray()
        ];
    }

    private function getActiveSocials()
    {
        $decoded = json_decode(Setting::where('type', 'socials')->value('value') ?: '[]', true);

        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->map(fn($social) => is_array($social) ? $social : (array) $social)
            ->filter(fn($social) => (int) ($social['status'] ?? 1) === 1)
            ->map(fn($social) => [
                'id' => (string) ($social['id'] ?? ''),
                'name' => (string) ($social['name'] ?? ''),
                'url' => (string) ($social['url'] ?? ''),
                'icon' => (string) ($social['icon'] ?? ''),
                'status' => 1,
            ])
            ->values()
            ->all();
    }
}

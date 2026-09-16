<?php

namespace App\Http\Controllers\Api;

use App\Models\Store;
use App\Models\UserStorePreference;
use App\Support\StoreMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ClientStorePreferenceController extends ClientController
{
    public function getPreference(string $storeId, Request $request)
    {
        $user = $request->user() ?: $this->resolveApiUser($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $preference = UserStorePreference::query()
            ->where('user_id', $user->id)
            ->where('store_id', $storeId)
            ->first();
        $followersCount = $this->countStoreFollowers($storeId);

        return response()->json([
            'store_id' => $storeId,
            'is_following' => (bool) ($preference?->is_following ?? false),
            'is_favorite' => (bool) ($preference?->is_favorite ?? false),
            'followers_count' => $followersCount,
            'followersCount' => $followersCount,
        ]);
    }

    public function savePreference(string $storeId, Request $request)
    {
        $user = $request->user() ?: $this->resolveApiUser($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $storeExists = Store::query()->whereKey($storeId)->exists();
        if (! $storeExists) {
            return response()->json(['message' => 'Không tìm thấy cửa hàng.'], 404);
        }

        $validated = $request->validate([
            'is_following' => 'required|boolean',
            'is_favorite' => 'required|boolean',
        ]);

        $now = Carbon::now();

        $preference = UserStorePreference::query()->firstOrNew([
            'user_id' => $user->id,
            'store_id' => $storeId,
        ]);

        $preference->is_following = (bool) $validated['is_following'];
        $preference->followed_at = $preference->is_following ? ($preference->followed_at ?? $now) : null;
        $preference->is_favorite = (bool) $validated['is_favorite'];
        $preference->favorited_at = $preference->is_favorite ? ($preference->favorited_at ?? $now) : null;
        $preference->save();
        $followersCount = $this->countStoreFollowers($storeId);

        return response()->json([
            'store_id' => $storeId,
            'is_following' => (bool) $preference->is_following,
            'is_favorite' => (bool) $preference->is_favorite,
            'followers_count' => $followersCount,
            'followersCount' => $followersCount,
        ]);
    }

    public function followedStores(Request $request)
    {
        return $this->listPreferredStores($request, 'is_following');
    }

    public function favoriteStores(Request $request)
    {
        return $this->listPreferredStores($request, 'is_favorite');
    }

    private function listPreferredStores(Request $request, string $column)
    {
        $user = $request->user() ?: $this->resolveApiUser($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $rows = Store::query()
            ->select([
                'stores.id',
                'stores.name',
                'stores.address',
                'stores.logo_url',
                'stores.cover_image',
                'stores.banner_url',
                'stores.is_open',
                'stores.open_time',
                'stores.close_time',
            ])
            ->leftJoin('user_store_preferences as usp', 'usp.store_id', '=', 'stores.id')
            ->where('usp.user_id', $user->id)
            ->where('usp.' . $column, true)
            ->orderByDesc('usp.updated_at')
            ->get()
            ->map(fn (Store $store) => $this->serializePreferredStore($store))
            ->values();

        return response()->json([
            'data' => $rows,
        ]);
    }

    private function serializePreferredStore(Store $store): array
    {
        $bannerUrls = StoreMedia::urls($store->banner_url);

        return [
            'id' => (string) $store->id,
            'name' => (string) $store->name,
            'address' => (string) ($store->address ?? ''),
            'logo_url' => $store->logo_url,
            'cover_image' => $store->cover_image,
            'cover_image_url' => $store->cover_image,
            'banner_url' => $bannerUrls[0] ?? null,
            'banner_urls' => $bannerUrls,
            'is_open' => (bool) $store->is_open,
            'status' => (bool) $store->is_open ? 'mở' : 'đóng',
            'open_time' => (string) ($store->open_time ?? ''),
            'close_time' => (string) ($store->close_time ?? ''),
        ];
    }

    private function countStoreFollowers(string $storeId): int
    {
        return (int) UserStorePreference::query()
            ->where('store_id', $storeId)
            ->where('is_following', true)
            ->count();
    }
}

<?php

namespace App\Services;

use App\Models\Bank;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class BankService
{
    public const CACHE_KEY = 'banks.active';
    private const CACHE_VERSION_KEY = 'banks.cache_version';

    public function getActiveBanks(): Collection
    {
        return Cache::remember(self::makeCacheKey(self::CACHE_KEY), now()->addMinutes(60), function () {
            return Bank::active()->orderBy('name')->select('id', 'code', 'name', 'image')->get();
        });
    }

    public static function clearActiveBanksCache(): void
    {
        $currentVersion = self::cacheVersion();
        Cache::forget(self::CACHE_KEY . '.v' . $currentVersion);
        self::bumpCacheVersion($currentVersion);
    }

    public static function clearBankCaches(): void
    {
        self::clearActiveBanksCache();
    }

    public static function makeCacheKey(string $baseKey): string
    {
        return $baseKey . '.v' . self::cacheVersion();
    }

    private static function cacheVersion(): int
    {
        return (int) Cache::get(self::CACHE_VERSION_KEY, 1);
    }

    private static function bumpCacheVersion(int $currentVersion = 0): void
    {
        $version = $currentVersion > 0 ? $currentVersion : self::cacheVersion();
        Cache::forever(self::CACHE_VERSION_KEY, $version + 1);
    }
}

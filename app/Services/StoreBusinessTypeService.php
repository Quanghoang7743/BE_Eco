<?php

namespace App\Services;

use App\Models\StoreBusinessType;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StoreBusinessTypeService
{
    public function format(StoreBusinessType $businessType, bool $withStoreCount = false): array
    {
        $data = [
            'id' => (int) $businessType->id,
            'name' => (string) $businessType->name,
            'slug' => (string) $businessType->slug,
            'icon_url' => $businessType->icon_url,
            'is_active' => (bool) $businessType->is_active,
            'sort_order' => (int) $businessType->sort_order,
            'created_at' => optional($businessType->created_at)->toIso8601String(),
            'updated_at' => optional($businessType->updated_at)->toIso8601String(),
        ];

        if ($withStoreCount) {
            $data['stores_count'] = (int) ($businessType->stores_count ?? 0);
        }

        return $data;
    }

    public function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($value);
        if ($baseSlug === '') {
            $baseSlug = 'loai-hinh-' . Str::lower(Str::random(6));
        }

        $slug = $baseSlug;
        $suffix = 2;

        while ($this->slugExists($slug, $ignoreId)) {
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    public function resolveFromInput(array $input): ?StoreBusinessType
    {
        if (! Schema::hasTable('store_business_types')) {
            return null;
        }

        $businessTypeId = $input['business_type_id'] ?? null;
        if ($businessTypeId !== null && $businessTypeId !== '') {
            $type = StoreBusinessType::query()->find((int) $businessTypeId);
            if ($type instanceof StoreBusinessType) {
                return $type;
            }
        }

        $name = trim((string) ($input['business_type'] ?? ''));
        if ($name === '') {
            return null;
        }

        $slug = Str::slug($name);
        $normalizedName = Str::lower($name);

        return StoreBusinessType::query()
            ->where(function ($query) use ($slug, $normalizedName): void {
                if ($slug !== '') {
                    $query->where('slug', $slug);
                }

                $query->orWhereRaw('LOWER(name) = ?', [$normalizedName]);
            })
            ->first();
    }

    public function storePayloadFromInput(array $input): array
    {
        $hasBusinessTypeId = array_key_exists('business_type_id', $input);
        $hasBusinessTypeName = array_key_exists('business_type', $input);

        if (! $hasBusinessTypeId && ! $hasBusinessTypeName) {
            return [];
        }

        $type = $this->resolveFromInput($input);
        if ($type instanceof StoreBusinessType) {
            return $this->storePayloadForBusinessType($type);
        }

        $name = trim((string) ($input['business_type'] ?? ''));
        $payload = [
            'business_type' => $name !== '' ? $name : null,
        ];

        if (Schema::hasTable('stores') && Schema::hasColumn('stores', 'business_type_id')) {
            $payload['business_type_id'] = null;
        }

        return $payload;
    }

    public function storePayloadForBusinessType(StoreBusinessType $businessType): array
    {
        $payload = [
            'business_type' => (string) $businessType->name,
        ];

        if (Schema::hasTable('stores') && Schema::hasColumn('stores', 'business_type_id')) {
            $payload['business_type_id'] = (int) $businessType->id;
        }

        return $payload;
    }

    private function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        return StoreBusinessType::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}

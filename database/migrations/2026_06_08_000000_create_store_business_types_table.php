<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $defaultTypes = [
        'Quán ăn',
        'Cafe',
        'Nhà hàng',
        'Tiệm bánh',
        'Trà sữa',
        'Quán nước',
        'Cửa hàng tiện lợi',
        'Siêu thị mini',
        'Shop thời trang',
        'Mỹ phẩm',
        'Spa / Làm đẹp',
        'Nhà thuốc',
        'Phòng khám',
        'Giáo dục / Đào tạo',
        'Dịch vụ sửa chữa',
        'Khác',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('store_business_types')) {
            Schema::create('store_business_types', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->unique();
                $table->string('slug')->unique();
                $table->string('icon_url', 2048)->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        $this->seedDefaultBusinessTypes();
        $this->seedBusinessTypesFromStores();

        if (Schema::hasTable('stores') && ! Schema::hasColumn('stores', 'business_type_id')) {
            Schema::table('stores', function (Blueprint $table): void {
                $table->foreignId('business_type_id')
                    ->nullable()
                    ->after('business_type')
                    ->constrained('store_business_types')
                    ->nullOnDelete();
            });
        }

        $this->backfillStoresBusinessTypeId();
    }

    public function down(): void
    {
        if (Schema::hasTable('stores') && Schema::hasColumn('stores', 'business_type_id')) {
            Schema::table('stores', function (Blueprint $table): void {
                try {
                    $table->dropForeign(['business_type_id']);
                } catch (Throwable) {
                }

                $table->dropColumn('business_type_id');
            });
        }

        Schema::dropIfExists('store_business_types');
    }

    private function seedDefaultBusinessTypes(): void
    {
        foreach ($this->defaultTypes as $index => $name) {
            $this->firstOrCreateBusinessType($name, $index * 10);
        }
    }

    private function seedBusinessTypesFromStores(): void
    {
        if (! Schema::hasTable('stores') || ! Schema::hasColumn('stores', 'business_type')) {
            return;
        }

        $names = DB::table('stores')
            ->whereNotNull('business_type')
            ->where('business_type', '<>', '')
            ->distinct()
            ->pluck('business_type');

        foreach ($names as $name) {
            $this->firstOrCreateBusinessType((string) $name, 1000);
        }
    }

    private function backfillStoresBusinessTypeId(): void
    {
        if (
            ! Schema::hasTable('stores')
            || ! Schema::hasColumn('stores', 'business_type')
            || ! Schema::hasColumn('stores', 'business_type_id')
        ) {
            return;
        }

        DB::table('stores')
            ->select(['id', 'business_type'])
            ->whereNull('business_type_id')
            ->whereNotNull('business_type')
            ->where('business_type', '<>', '')
            ->orderBy('id')
            ->chunk(200, function ($stores): void {
                foreach ($stores as $store) {
                    $typeId = $this->findBusinessTypeId((string) $store->business_type);
                    if (! $typeId) {
                        continue;
                    }

                    DB::table('stores')
                        ->where('id', $store->id)
                        ->update([
                            'business_type_id' => $typeId,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    private function firstOrCreateBusinessType(string $name, int $sortOrder): int
    {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }

        $existingId = $this->findBusinessTypeId($name);
        if ($existingId) {
            return $existingId;
        }

        $now = now();
        $slug = $this->uniqueSlug($name);

        return (int) DB::table('store_business_types')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'icon_url' => null,
            'is_active' => true,
            'sort_order' => $sortOrder,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function findBusinessTypeId(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $slug = Str::slug($name);
        $normalizedName = Str::lower($name);

        $row = DB::table('store_business_types')
            ->where(function ($query) use ($slug, $normalizedName): void {
                if ($slug !== '') {
                    $query->where('slug', $slug);
                }

                $query->orWhereRaw('LOWER(name) = ?', [$normalizedName]);
            })
            ->first();

        return $row ? (int) $row->id : null;
    }

    private function uniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name);
        if ($baseSlug === '') {
            $baseSlug = 'loai-hinh-' . Str::lower(Str::random(6));
        }

        $slug = $baseSlug;
        $suffix = 2;

        while (DB::table('store_business_types')->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }
};

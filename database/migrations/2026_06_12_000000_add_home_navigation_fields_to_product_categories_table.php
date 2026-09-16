<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_categories')) {
            return;
        }

        Schema::table('product_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('product_categories', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('sort_order');
            }

            if (! Schema::hasColumn('product_categories', 'show_on_home')) {
                $table->boolean('show_on_home')->default(false)->after('is_active');
            }

            if (! Schema::hasColumn('product_categories', 'level')) {
                $table->unsignedTinyInteger('level')->default(1)->after('show_on_home');
            }
        });

        Schema::table('product_categories', function (Blueprint $table) {
            if (Schema::hasColumn('product_categories', 'sort_order') && ! $this->hasIndex('product_categories', 'product_categories_sort_order_idx')) {
                $table->index('sort_order', 'product_categories_sort_order_idx');
            }

            if (
                Schema::hasColumn('product_categories', 'is_active') &&
                Schema::hasColumn('product_categories', 'show_on_home') &&
                Schema::hasColumn('product_categories', 'sort_order') &&
                ! $this->hasIndex('product_categories', 'product_categories_home_nav_idx')
            ) {
                $table->index(['is_active', 'show_on_home', 'sort_order'], 'product_categories_home_nav_idx');
            }
        });

        $this->syncExistingLevels();
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_categories')) {
            return;
        }

        Schema::table('product_categories', function (Blueprint $table) {
            if ($this->hasIndex('product_categories', 'product_categories_home_nav_idx')) {
                $table->dropIndex('product_categories_home_nav_idx');
            }

            if ($this->hasIndex('product_categories', 'product_categories_sort_order_idx')) {
                $table->dropIndex('product_categories_sort_order_idx');
            }
        });

        Schema::table('product_categories', function (Blueprint $table) {
            if (Schema::hasColumn('product_categories', 'level')) {
                $table->dropColumn('level');
            }

            if (Schema::hasColumn('product_categories', 'show_on_home')) {
                $table->dropColumn('show_on_home');
            }

            if (Schema::hasColumn('product_categories', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }

    private function syncExistingLevels(): void
    {
        if (! Schema::hasColumn('product_categories', 'level') || ! Schema::hasColumn('product_categories', 'parent_id')) {
            return;
        }

        $rows = DB::table('product_categories')->select('id', 'parent_id')->get();
        $parentById = $rows->pluck('parent_id', 'id')->all();
        $levels = [];

        $resolveLevel = function (int $id, array $visited = []) use (&$resolveLevel, &$levels, $parentById): int {
            if (isset($levels[$id])) {
                return $levels[$id];
            }

            if (isset($visited[$id])) {
                return $levels[$id] = 1;
            }

            $parentId = $parentById[$id] ?? null;
            if ($parentId === null || ! isset($parentById[(int) $parentId])) {
                return $levels[$id] = 1;
            }

            $visited[$id] = true;
            return $levels[$id] = min($resolveLevel((int) $parentId, $visited) + 1, 255);
        };

        foreach ($rows as $row) {
            $level = $resolveLevel((int) $row->id);
            DB::table('product_categories')->where('id', $row->id)->update(['level' => $level]);
        }
    }

    private function hasIndex(string $tableName, string $indexName): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $tableName)
            ->where('index_name', $indexName)
            ->exists();
    }
};

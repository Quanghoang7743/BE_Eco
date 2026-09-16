<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_menu')) {
            Schema::table('store_menu', function (Blueprint $table) {
                if (!Schema::hasColumn('store_menu', 'product_id')) {
                    $table->unsignedBigInteger('product_id')->nullable()->after('store_id');
                }

                if (!Schema::hasColumn('store_menu', 'variant_id')) {
                    $table->unsignedBigInteger('variant_id')->nullable()->after('product_id');
                }
            });

            Schema::table('store_menu', function (Blueprint $table) {
                if (Schema::hasColumn('store_menu', 'product_id') && !$this->hasIndex('store_menu', 'store_menu_product_id_idx')) {
                    $table->index('product_id', 'store_menu_product_id_idx');
                }

                if (Schema::hasColumn('store_menu', 'variant_id') && !$this->hasIndex('store_menu', 'store_menu_variant_id_idx')) {
                    $table->index('variant_id', 'store_menu_variant_id_idx');
                }

                if (
                    Schema::hasColumn('store_menu', 'product_id')
                    && Schema::hasColumn('store_menu', 'variant_id')
                    && !$this->hasIndex('store_menu', 'store_menu_product_variant_idx')
                ) {
                    $table->index(['product_id', 'variant_id'], 'store_menu_product_variant_idx');
                }
            });

            if (Schema::hasColumn('store_menu', 'product_id')) {
                DB::table('store_menu')->where('product_id', 0)->update(['product_id' => null]);
            }

            if (Schema::hasColumn('store_menu', 'variant_id')) {
                DB::table('store_menu')->where('variant_id', 0)->update(['variant_id' => null]);
            }
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (!Schema::hasColumn('order_items', 'product_id')) {
                    $table->unsignedBigInteger('product_id')->nullable()->after('order_id');
                }

                if (!Schema::hasColumn('order_items', 'variant_id')) {
                    $table->unsignedBigInteger('variant_id')->nullable()->after('product_id');
                }
            });

            Schema::table('order_items', function (Blueprint $table) {
                if (Schema::hasColumn('order_items', 'product_id') && !$this->hasIndex('order_items', 'order_items_product_id_idx')) {
                    $table->index('product_id', 'order_items_product_id_idx');
                }

                if (Schema::hasColumn('order_items', 'variant_id') && !$this->hasIndex('order_items', 'order_items_variant_id_idx')) {
                    $table->index('variant_id', 'order_items_variant_id_idx');
                }

                if (
                    Schema::hasColumn('order_items', 'product_id')
                    && Schema::hasColumn('order_items', 'variant_id')
                    && !$this->hasIndex('order_items', 'order_items_product_variant_idx')
                ) {
                    $table->index(['product_id', 'variant_id'], 'order_items_product_variant_idx');
                }
            });

            if (Schema::hasColumn('order_items', 'product_id')) {
                DB::table('order_items')->where('product_id', 0)->update(['product_id' => null]);
            }

            if (Schema::hasColumn('order_items', 'variant_id')) {
                DB::table('order_items')->where('variant_id', 0)->update(['variant_id' => null]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if ($this->hasIndex('order_items', 'order_items_product_variant_idx')) {
                    $table->dropIndex('order_items_product_variant_idx');
                }

                if ($this->hasIndex('order_items', 'order_items_variant_id_idx')) {
                    $table->dropIndex('order_items_variant_id_idx');
                }

                if ($this->hasIndex('order_items', 'order_items_product_id_idx')) {
                    $table->dropIndex('order_items_product_id_idx');
                }

                if (Schema::hasColumn('order_items', 'variant_id')) {
                    $table->dropColumn('variant_id');
                }

                if (Schema::hasColumn('order_items', 'product_id')) {
                    $table->dropColumn('product_id');
                }
            });
        }

        if (Schema::hasTable('store_menu')) {
            Schema::table('store_menu', function (Blueprint $table) {
                if ($this->hasIndex('store_menu', 'store_menu_product_variant_idx')) {
                    $table->dropIndex('store_menu_product_variant_idx');
                }

                if ($this->hasIndex('store_menu', 'store_menu_variant_id_idx')) {
                    $table->dropIndex('store_menu_variant_id_idx');
                }

                if ($this->hasIndex('store_menu', 'store_menu_product_id_idx')) {
                    $table->dropIndex('store_menu_product_id_idx');
                }

                if (Schema::hasColumn('store_menu', 'variant_id')) {
                    $table->dropColumn('variant_id');
                }

                if (Schema::hasColumn('store_menu', 'product_id')) {
                    $table->dropColumn('product_id');
                }
            });
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

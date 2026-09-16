<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'status')) {
                $table->string('status', 50)->default('pending_approval')->after('store_id');
            }

            if (!Schema::hasColumn('products', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('status');
            }

            if (!Schema::hasColumn('products', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('published_at');
            }

            if (!Schema::hasColumn('products', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'status') && !$this->hasIndex('products', 'products_status_idx')) {
                $table->index('status', 'products_status_idx');
            }

            if (Schema::hasColumn('products', 'approved_by') && !$this->hasIndex('products', 'products_approved_by_idx')) {
                $table->index('approved_by', 'products_approved_by_idx');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if ($this->hasIndex('products', 'products_approved_by_idx')) {
                $table->dropIndex('products_approved_by_idx');
            }

            if ($this->hasIndex('products', 'products_status_idx')) {
                $table->dropIndex('products_status_idx');
            }

            if (Schema::hasColumn('products', 'approved_at')) {
                $table->dropColumn('approved_at');
            }

            if (Schema::hasColumn('products', 'approved_by')) {
                $table->dropColumn('approved_by');
            }

            if (Schema::hasColumn('products', 'published_at')) {
                $table->dropColumn('published_at');
            }

            if (Schema::hasColumn('products', 'status')) {
                $table->dropColumn('status');
            }
        });
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

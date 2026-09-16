<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureStoresColumns();
        $this->ensureProductsColumns();
        $this->ensureProductVariantsColumns();
    }

    public function down(): void
    {
        // Production schema repair only. Do not drop columns or data on rollback.
    }

    private function ensureStoresColumns(): void
    {
        if (! Schema::hasTable('stores')) {
            return;
        }

        if (! Schema::hasColumn('stores', 'location')) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE `stores` ADD COLUMN `location` POINT NULL');
            } else {
                Schema::table('stores', function (Blueprint $table): void {
                    $table->json('location')->nullable();
                });
            }
        }

        Schema::table('stores', function (Blueprint $table): void {
            if (! Schema::hasColumn('stores', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }

            if (! Schema::hasColumn('stores', 'cover_image')) {
                $table->text('cover_image')->nullable();
            }

            if (! Schema::hasColumn('stores', 'banner_url')) {
                $table->text('banner_url')->nullable();
            }

            if (! Schema::hasColumn('stores', 'banner_urls')) {
                $table->json('banner_urls')->nullable();
            }

            if (! Schema::hasColumn('stores', 'rating_avg')) {
                $table->decimal('rating_avg', 3, 2)->default(0);
            }

            if (! Schema::hasColumn('stores', 'rating_count')) {
                $table->integer('rating_count')->default(0);
            }
        });
    }

    private function ensureProductsColumns(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'content')) {
                $table->longText('content')->nullable();
            }

            if (! Schema::hasColumn('products', 'summary')) {
                $table->text('summary')->nullable();
            }

            if (! Schema::hasColumn('products', 'ticket_reward_quantity')) {
                $table->unsignedInteger('ticket_reward_quantity')->default(0);
            }

            if (! Schema::hasColumn('products', 'ticket_reward_enabled')) {
                $table->boolean('ticket_reward_enabled')->default(false);
            }

            if (! Schema::hasColumn('products', 'ticket_type_code')) {
                $table->string('ticket_type_code', 50)->nullable();
            }

            if (! Schema::hasColumn('products', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }

            if (! Schema::hasColumn('products', 'view_count')) {
                $table->unsignedBigInteger('view_count')->default(0);
            }
        });
    }

    private function ensureProductVariantsColumns(): void
    {
        if (! Schema::hasTable('product_variants')) {
            return;
        }

        Schema::table('product_variants', function (Blueprint $table): void {
            if (! Schema::hasColumn('product_variants', 'ticket_reward_quantity')) {
                $table->integer('ticket_reward_quantity')->default(0);
            }

            if (! Schema::hasColumn('product_variants', 'ticket_type_code')) {
                $table->string('ticket_type_code', 50)->nullable();
            }

            if (! Schema::hasColumn('product_variants', 'enabled')) {
                $table->boolean('enabled')->default(true);
            }
        });
    }
};

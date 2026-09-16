<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'base_ranking_score')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('base_ranking_score', 8, 2)
                    ->default(0)
                    ->after('ticket_type_code');

                $table->index('base_ranking_score');
            });
        }

        if (! Schema::hasTable('ranking_configs')) {
            Schema::create('ranking_configs', function (Blueprint $table) {
                $table->id();
                $table->string('screen_key', 100)->unique();
                $table->decimal('weight_distance', 6, 2)->default(0);
                $table->decimal('weight_keyword', 6, 2)->default(0);
                $table->decimal('weight_lucky_ticket', 6, 2)->default(0);
                $table->decimal('weight_commission', 6, 2)->default(0);
                $table->decimal('weight_purchase', 6, 2)->default(0);
                $table->decimal('weight_rating', 6, 2)->default(0);
                $table->decimal('weight_history', 6, 2)->default(0);
                $table->decimal('weight_reputation', 6, 2)->default(0);
                $table->decimal('weight_risk_penalty', 6, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('product_ranking_scores')) {
            Schema::create('product_ranking_scores', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->uuid('store_id')->nullable();
                $table->decimal('base_ranking_score', 8, 2)->default(0);
                $table->decimal('commission_rate', 6, 2)->default(0);
                $table->decimal('commission_score', 6, 2)->default(0);
                $table->integer('lucky_ticket_quantity')->default(0);
                $table->decimal('lucky_ticket_score', 6, 2)->default(0);
                $table->integer('purchase_qty')->default(0);
                $table->decimal('purchase_score', 6, 2)->default(0);
                $table->decimal('rating_value', 10, 2)->default(0);
                $table->decimal('rating_score', 6, 2)->default(0);
                $table->decimal('reputation_score', 6, 2)->default(0);
                $table->decimal('risk_penalty_score', 6, 2)->default(0);
                $table->timestamp('last_calculated_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique('product_id');
                $table->index('store_id');
                $table->index('base_ranking_score');
            });
        }

        if (Schema::hasTable('ranking_configs')) {
            $now = now();
            DB::table('ranking_configs')->updateOrInsert(
                ['screen_key' => 'search'],
                [
                    'weight_distance' => 20,
                    'weight_keyword' => 25,
                    'weight_lucky_ticket' => 8,
                    'weight_commission' => 5,
                    'weight_purchase' => 10,
                    'weight_rating' => 10,
                    'weight_history' => 7,
                    'weight_reputation' => 15,
                    'weight_risk_penalty' => 10,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_ranking_scores')) {
            Schema::dropIfExists('product_ranking_scores');
        }

        if (Schema::hasTable('ranking_configs')) {
            Schema::dropIfExists('ranking_configs');
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'base_ranking_score')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(['base_ranking_score']);
                $table->dropColumn('base_ranking_score');
            });
        }
    }
};

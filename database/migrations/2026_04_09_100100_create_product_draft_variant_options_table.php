<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_draft_variant_options')) {
            $this->ensureCompositeIndex();
            return;
        }

        Schema::create('product_draft_variant_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('config_id');
            $table->unsignedTinyInteger('group_no');
            $table->string('option_value', 120);
            $table->string('image_url', 2048)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['config_id', 'group_no', 'sort_order'], 'pdvo_cfg_grp_sort_idx');

            if (Schema::hasTable('product_draft_variant_configs')) {
                $table->foreign('config_id')
                    ->references('id')
                    ->on('product_draft_variant_configs')
                    ->onDelete('cascade');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_draft_variant_options');
    }

    private function ensureCompositeIndex(): void
    {
        $newIndex = 'pdvo_cfg_grp_sort_idx';
        $oldIndex = 'product_draft_variant_options_config_id_group_no_sort_order_index';

        $hasNewIndex = DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'product_draft_variant_options')
            ->where('index_name', $newIndex)
            ->exists();

        if ($hasNewIndex) {
            return;
        }

        $hasOldIndex = DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'product_draft_variant_options')
            ->where('index_name', $oldIndex)
            ->exists();

        if ($hasOldIndex) {
            return;
        }

        Schema::table('product_draft_variant_options', function (Blueprint $table) use ($newIndex) {
            $table->index(['config_id', 'group_no', 'sort_order'], $newIndex);
        });
    }
};

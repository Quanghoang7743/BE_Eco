<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_draft_variant_configs')) {
            return;
        }

        Schema::create('product_draft_variant_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('draft_id');
            $table->boolean('enabled')->default(false);
            $table->string('group_1_name', 120)->nullable();
            $table->boolean('group_1_use_image')->default(false);
            $table->string('group_2_name', 120)->nullable();
            $table->timestamps();

            $table->unique('draft_id');
            $table->index('updated_at');

            if (Schema::hasTable('product_drafts')) {
                $table->foreign('draft_id')
                    ->references('id')
                    ->on('product_drafts')
                    ->onDelete('cascade');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_draft_variant_configs');
    }
};

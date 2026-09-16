<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Disable foreign key checks to avoid constraint errors
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            // Drop inbound foreign keys currently referencing `order_items`
            // (legacy schema references BIGINT id and blocks UUID recreation).
            $foreignKeys = DB::table('information_schema.KEY_COLUMN_USAGE')
                ->select('TABLE_NAME', 'CONSTRAINT_NAME')
                ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
                ->where('REFERENCED_TABLE_NAME', 'order_items')
                ->whereNotNull('CONSTRAINT_NAME')
                ->get();

            foreach ($foreignKeys as $foreignKey) {
                DB::statement(sprintf(
                    'ALTER TABLE `%s` DROP FOREIGN KEY `%s`',
                    $foreignKey->TABLE_NAME,
                    $foreignKey->CONSTRAINT_NAME
                ));
            }

            // Recreate order_items table with correct schema
            if (Schema::hasTable('order_items')) {
                Schema::dropIfExists('order_items');
            }

            Schema::create('order_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('order_id')->nullable();
                $table->uuid('menu_item_id')->nullable();
                $table->string('item_name', 255)->nullable();
                $table->integer('quantity')->default(1);
                $table->decimal('unit_price', 15, 2)->nullable();
                $table->decimal('total_price', 15, 2)->nullable();
                $table->text('special_instructions')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        } finally {
            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Disable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        
        if (Schema::hasTable('order_items')) {
            Schema::dropIfExists('order_items');
        }

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};

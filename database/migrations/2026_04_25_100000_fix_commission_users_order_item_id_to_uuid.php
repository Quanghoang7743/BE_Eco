<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            if (! Schema::hasTable('commission_users')) {
                return;
            }

            $constraint = DB::selectOne(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE " .
                "WHERE TABLE_SCHEMA = DATABASE() " .
                "AND TABLE_NAME = 'commission_users' " .
                "AND COLUMN_NAME = 'order_item_id' " .
                "AND REFERENCED_TABLE_NAME = 'order_items'"
            );

            if ($constraint?->CONSTRAINT_NAME) {
                DB::statement(sprintf(
                    'ALTER TABLE `commission_users` DROP FOREIGN KEY `%s`',
                    $constraint->CONSTRAINT_NAME
                ));
            }

            DB::statement("ALTER TABLE `commission_users` MODIFY `order_item_id` CHAR(36) NOT NULL");
            DB::statement(
                'ALTER TABLE `commission_users` ADD CONSTRAINT `commission_users_order_item_id_foreign` FOREIGN KEY (`order_item_id`) REFERENCES `order_items`(`id`) ON DELETE CASCADE'
            );
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            if (! Schema::hasTable('commission_users')) {
                return;
            }

            $constraint = DB::selectOne(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE " .
                "WHERE TABLE_SCHEMA = DATABASE() " .
                "AND TABLE_NAME = 'commission_users' " .
                "AND COLUMN_NAME = 'order_item_id' " .
                "AND REFERENCED_TABLE_NAME = 'order_items'"
            );

            if ($constraint?->CONSTRAINT_NAME) {
                DB::statement(sprintf(
                    'ALTER TABLE `commission_users` DROP FOREIGN KEY `%s`',
                    $constraint->CONSTRAINT_NAME
                ));
            }

            DB::statement('ALTER TABLE `commission_users` MODIFY `order_item_id` BIGINT UNSIGNED NOT NULL');
            DB::statement(
                'ALTER TABLE `commission_users` ADD CONSTRAINT `commission_users_order_item_id_foreign` FOREIGN KEY (`order_item_id`) REFERENCES `order_items`(`id`) ON DELETE CASCADE'
            );
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
};

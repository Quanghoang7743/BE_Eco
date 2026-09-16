<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_role_bindings') && !Schema::hasColumn('user_role_bindings', 'status')) {
            Schema::table('user_role_bindings', function (Blueprint $table) {
                $table->string('status', 50)->default('active')->after('entity_id');
                $table->index('status', 'idx_urb_status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('user_role_bindings') && Schema::hasColumn('user_role_bindings', 'status')) {
            Schema::table('user_role_bindings', function (Blueprint $table) {
                $table->dropIndex('idx_urb_status');
                $table->dropColumn('status');
            });
        }
    }
};

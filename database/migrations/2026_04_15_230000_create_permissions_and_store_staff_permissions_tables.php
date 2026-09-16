<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->unique();
                $table->string('name', 150);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('store_staff_permissions')) {
            Schema::create('store_staff_permissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('store_id', 64);
                $table->string('permission_code', 50);
                $table->timestamps();

                $table->index(['store_id', 'user_id'], 'idx_ssp_store_user');
                $table->index(['store_id', 'permission_code'], 'idx_ssp_store_permission');
                $table->unique(['user_id', 'store_id', 'permission_code'], 'uq_ssp_user_store_permission');
                $table->foreign('permission_code')->references('code')->on('permissions')->cascadeOnDelete();
            });
        }

        $now = now();

        foreach ([
            ['name' => 'store_staff', 'description' => 'Store staff access in store context'],
            ['name' => 'store_manager', 'description' => 'Store manager access in store context'],
        ] as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role['name']],
                [
                    'description' => $role['description'],
                    'updated_at' => $now,
                    'created_at' => DB::raw('COALESCE(created_at, NOW())'),
                ]
            );
        }

        $permissions = [
            ['code' => 'VIEW_ORDER', 'name' => 'Xem don hang'],
            ['code' => 'ACCEPT_ORDER', 'name' => 'Xac nhan don hang'],
            ['code' => 'CANCEL_ORDER', 'name' => 'Huy don hang'],
            ['code' => 'REFUND_ORDER', 'name' => 'Hoan ve don hang'],
            ['code' => 'VIEW_REPORT', 'name' => 'Xem bao cao'],
            ['code' => 'EDIT_MENU', 'name' => 'Chinh sua menu'],
            ['code' => 'EDIT_PRICE', 'name' => 'Chinh sua gia'],
            ['code' => 'EDIT_PROFILE', 'name' => 'Chinh sua ho so cua hang'],
            ['code' => 'MANAGE_STAFF', 'name' => 'Quan ly nhan vien va phan quyen'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $permission['code']],
                [
                    'name' => $permission['name'],
                    'updated_at' => $now,
                    'created_at' => DB::raw('COALESCE(created_at, NOW())'),
                ]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('store_staff_permissions')) {
            Schema::dropIfExists('store_staff_permissions');
        }

        if (Schema::hasTable('permissions')) {
            Schema::dropIfExists('permissions');
        }
    }
};

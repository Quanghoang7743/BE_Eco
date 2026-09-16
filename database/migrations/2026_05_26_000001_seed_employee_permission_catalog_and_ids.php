<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        ['code' => 'VIEW_DASHBOARD', 'name' => 'Xem tong quan'],
        ['code' => 'VIEW_DASHBOARD_DETAIL', 'name' => 'Xem chi tiet tong quan'],
        ['code' => 'EXPORT_DASHBOARD', 'name' => 'Xuat tong quan'],
        ['code' => 'VIEW_ORDER', 'name' => 'Xem don hang'],
        ['code' => 'VIEW_ORDER_DETAIL', 'name' => 'Xem chi tiet don hang'],
        ['code' => 'ACCEPT_ORDER', 'name' => 'Nhan don hang'],
        ['code' => 'UPDATE_ORDER', 'name' => 'Cap nhat don hang'],
        ['code' => 'CANCEL_ORDER', 'name' => 'Huy don hang'],
        ['code' => 'EXPORT_ORDER', 'name' => 'Xuat don hang'],
        ['code' => 'VIEW_MENU', 'name' => 'Xem san pham'],
        ['code' => 'VIEW_PRODUCT_DETAIL', 'name' => 'Xem chi tiet san pham'],
        ['code' => 'CREATE_PRODUCT', 'name' => 'Tao san pham'],
        ['code' => 'EDIT_MENU', 'name' => 'Chinh sua san pham'],
        ['code' => 'EDIT_PRICE', 'name' => 'Chinh gia san pham'],
        ['code' => 'EXPORT_PRODUCT', 'name' => 'Xuat san pham'],
        ['code' => 'VIEW_CUSTOMER', 'name' => 'Xem khach hang'],
        ['code' => 'VIEW_CUSTOMER_DETAIL', 'name' => 'Xem chi tiet khach hang'],
        ['code' => 'CREATE_CUSTOMER', 'name' => 'Tao khach hang'],
        ['code' => 'EDIT_CUSTOMER', 'name' => 'Chinh sua khach hang'],
        ['code' => 'EXPORT_CUSTOMER', 'name' => 'Xuat khach hang'],
        ['code' => 'VIEW_RECONCILIATION', 'name' => 'Xem doi soat'],
        ['code' => 'VIEW_RECONCILIATION_DETAIL', 'name' => 'Xem chi tiet doi soat'],
        ['code' => 'CONFIRM_RECONCILIATION', 'name' => 'Xac nhan doi soat'],
        ['code' => 'EXPORT_RECONCILIATION', 'name' => 'Xuat doi soat'],
        ['code' => 'VIEW_STORE', 'name' => 'Xem cua hang'],
        ['code' => 'VIEW_STORE_DETAIL', 'name' => 'Xem chi tiet cua hang'],
        ['code' => 'EDIT_PROFILE', 'name' => 'Chinh sua ho so cua hang'],
        ['code' => 'EXPORT_STORE', 'name' => 'Xuat cua hang'],
        ['code' => 'VIEW_EMPLOYEE', 'name' => 'Xem nhan vien'],
        ['code' => 'VIEW_EMPLOYEE_DETAIL', 'name' => 'Xem chi tiet nhan vien'],
        ['code' => 'CREATE_EMPLOYEE', 'name' => 'Tao nhan vien'],
        ['code' => 'UPDATE_EMPLOYEE', 'name' => 'Cap nhat nhan vien'],
        ['code' => 'ASSIGN_EMPLOYEE_PERMISSION', 'name' => 'Phan quyen nhan vien'],
        ['code' => 'VIEW_EMPLOYEE_ACTIVITY_LOG', 'name' => 'Xem nhat ky nhan vien'],
        ['code' => 'VIEW_REPORT', 'name' => 'Xem bao cao legacy'],
        ['code' => 'MANAGE_STAFF', 'name' => 'Quan ly nhan vien legacy'],
        ['code' => 'RECONCILIATION_CREATE', 'name' => 'Lap doi soat legacy'],
        ['code' => 'UPDATE_ORDER_STATUS', 'name' => 'Sua trang thai don legacy'],
        ['code' => 'REFUND_ORDER', 'name' => 'Hoan ve don hang legacy'],
        ['code' => 'EXPORT_DATA', 'name' => 'Xuat du lieu legacy'],
        ['code' => 'PRODUCT_CREATE', 'name' => 'Tao san pham legacy'],
        ['code' => 'VIEW_NOTIFICATION', 'name' => 'Xem thong bao legacy'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $this->seedRoles();
        $this->seedPermissions();
        $this->addPermissionIdColumn();
        $this->backfillPermissionIds();
    }

    public function down(): void
    {
        if (!Schema::hasTable('store_staff_permissions') || !Schema::hasColumn('store_staff_permissions', 'permission_id')) {
            return;
        }

        Schema::table('store_staff_permissions', function (Blueprint $table) {
            $table->dropIndex('idx_ssp_store_user_permission_id');
            $table->dropConstrainedForeignId('permission_id');
        });
    }

    private function seedRoles(): void
    {
        if (!Schema::hasTable('roles')) {
            return;
        }

        $now = now();
        foreach (['store_staff', 'store_manager', 'store_custom'] as $roleName) {
            DB::table('roles')->updateOrInsert(
                ['name' => $roleName],
                [
                    'description' => 'Store staff role: ' . $roleName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function seedPermissions(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $permission['code']],
                [
                    'name' => $permission['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function addPermissionIdColumn(): void
    {
        if (!Schema::hasTable('store_staff_permissions') || Schema::hasColumn('store_staff_permissions', 'permission_id')) {
            return;
        }

        Schema::table('store_staff_permissions', function (Blueprint $table) {
            $table->foreignId('permission_id')
                ->nullable()
                ->after('permission_code')
                ->constrained('permissions')
                ->nullOnDelete();
            $table->index(['store_id', 'user_id', 'permission_id'], 'idx_ssp_store_user_permission_id');
        });
    }

    private function backfillPermissionIds(): void
    {
        if (!Schema::hasTable('store_staff_permissions') || !Schema::hasColumn('store_staff_permissions', 'permission_id')) {
            return;
        }

        $permissionIds = DB::table('permissions')->pluck('id', 'code');
        DB::table('store_staff_permissions')
            ->whereNull('permission_id')
            ->orderBy('id')
            ->get(['id', 'permission_code'])
            ->each(function ($row) use ($permissionIds) {
                $permissionId = $permissionIds->get((string) $row->permission_code);
                if ($permissionId) {
                    DB::table('store_staff_permissions')
                        ->where('id', $row->id)
                        ->update(['permission_id' => $permissionId]);
                }
            });
    }
};

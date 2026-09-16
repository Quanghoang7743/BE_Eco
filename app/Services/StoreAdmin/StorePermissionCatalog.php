<?php

namespace App\Services\StoreAdmin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StorePermissionCatalog
{
    public const DEFAULT_ROLE = 'store_staff';

    public const STAFF_ROLES = [
        'store_staff',
        'store_manager',
        'store_custom',
    ];

    private const SENSITIVE_CODES = [
        'EDIT_PRICE',
        'EDIT_PROFILE',
        'CREATE_FLASH_SALE',
        'UPDATE_FLASH_SALE',
        'DELETE_FLASH_SALE',
        'CREATE_STORE',
        'MANAGE_STORE_LICENSE',
        'UPDATE_OPERATING_STATE',
        'CREATE_EMPLOYEE',
        'UPDATE_EMPLOYEE',
        'ASSIGN_EMPLOYEE_PERMISSION',
        'VIEW_EMPLOYEE_ACTIVITY_LOG',
        'MARK_ALL_NOTIFICATION_READ',
        'UPDATE_COMPLAINT',
        'DELETE_REVIEW',
        'UPDATE_NOTIFICATION_SETTINGS',
        'UPDATE_LANGUAGE_SETTINGS',
        'UPDATE_SECURITY_SETTINGS',
        'UPDATE_OPERATING_SCHEDULE',
        'TOGGLE_OPERATING_HOURS',
        'UPDATE_SLA_CONFIG',
        'PROCESS_RETURN',
        'WITHDRAW_WALLET',
    ];

    private const DISPLAY_PERMISSIONS = [
        ['code' => 'VIEW_DASHBOARD', 'name' => 'Xem tong quan', 'module' => 'overview', 'action' => 'dashboard.read'],
        ['code' => 'VIEW_DASHBOARD_DETAIL', 'name' => 'Xem chi tiet tong quan', 'module' => 'overview', 'action' => 'dashboard.detail.read'],
        ['code' => 'EXPORT_DASHBOARD', 'name' => 'Xuat tong quan', 'module' => 'overview', 'action' => 'dashboard.export'],
        ['code' => 'VIEW_ORDER', 'name' => 'Xem don hang', 'module' => 'orders', 'action' => 'orders.read'],
        ['code' => 'VIEW_ORDER_DETAIL', 'name' => 'Xem chi tiet don hang', 'module' => 'orders', 'action' => 'orders.detail.read'],
        ['code' => 'ACCEPT_ORDER', 'name' => 'Nhan don hang', 'module' => 'orders', 'action' => 'orders.accept'],
        ['code' => 'UPDATE_ORDER', 'name' => 'Cap nhat don hang', 'module' => 'orders', 'action' => 'orders.update'],
        ['code' => 'CANCEL_ORDER', 'name' => 'Huy don hang', 'module' => 'orders', 'action' => 'orders.cancel'],
        ['code' => 'EXPORT_ORDER', 'name' => 'Xuat don hang', 'module' => 'orders', 'action' => 'orders.export'],
        ['code' => 'IMPORT_ORDER', 'name' => 'Import don hang', 'module' => 'orders', 'action' => 'orders.import'],
        ['code' => 'VIEW_RETURN', 'name' => 'Xem trang', 'module' => 'returns', 'action' => 'returns.read'],
        ['code' => 'PROCESS_RETURN', 'name' => 'Xu ly yeu cau', 'module' => 'returns', 'action' => 'returns.process', 'sensitive' => true],
        ['code' => 'VIEW_MENU', 'name' => 'Xem san pham', 'module' => 'products', 'action' => 'products.read'],
        ['code' => 'VIEW_PRODUCT_DETAIL', 'name' => 'Xem chi tiet san pham', 'module' => 'products', 'action' => 'products.detail.read'],
        ['code' => 'CREATE_PRODUCT', 'name' => 'Tao san pham', 'module' => 'products', 'action' => 'products.create'],
        ['code' => 'IMPORT_PRODUCT', 'name' => 'Import san pham', 'module' => 'products', 'action' => 'products.import'],
        ['code' => 'EDIT_MENU', 'name' => 'Chinh sua san pham', 'module' => 'products', 'action' => 'products.update'],
        ['code' => 'EDIT_PRICE', 'name' => 'Chinh gia san pham', 'module' => 'products', 'action' => 'products.price.update', 'sensitive' => true],
        ['code' => 'TOGGLE_PRODUCT_SALE', 'name' => 'Bat tat ban san pham', 'module' => 'products', 'action' => 'products.sale_status.update'],
        ['code' => 'EXPORT_PRODUCT', 'name' => 'Xuat san pham', 'module' => 'products', 'action' => 'products.export'],
        ['code' => 'VIEW_FLASH_SALE', 'name' => 'Xem flash sale', 'module' => 'products', 'action' => 'flash_sales.read'],
        ['code' => 'CREATE_FLASH_SALE', 'name' => 'Tao flash sale', 'module' => 'products', 'action' => 'flash_sales.create', 'sensitive' => true],
        ['code' => 'UPDATE_FLASH_SALE', 'name' => 'Cap nhat flash sale', 'module' => 'products', 'action' => 'flash_sales.update', 'sensitive' => true],
        ['code' => 'DELETE_FLASH_SALE', 'name' => 'Xoa flash sale', 'module' => 'products', 'action' => 'flash_sales.delete', 'sensitive' => true],
        ['code' => 'DELETE_PRODUCT', 'name' => 'Xoa san pham', 'module' => 'products', 'action' => 'products.delete', 'sensitive' => true],
        ['code' => 'VIEW_CUSTOMER', 'name' => 'Xem khach hang', 'module' => 'customers', 'action' => 'customers.read'],
        ['code' => 'VIEW_CUSTOMER_DETAIL', 'name' => 'Xem chi tiet khach hang', 'module' => 'customers', 'action' => 'customers.detail.read'],
        ['code' => 'CREATE_CUSTOMER', 'name' => 'Tao khach hang', 'module' => 'customers', 'action' => 'customers.create'],
        ['code' => 'EDIT_CUSTOMER', 'name' => 'Chinh sua khach hang', 'module' => 'customers', 'action' => 'customers.update'],
        ['code' => 'EXPORT_CUSTOMER', 'name' => 'Xuat khach hang', 'module' => 'customers', 'action' => 'customers.export'],
        ['code' => 'VIEW_REPORT', 'name' => 'Xem bao cao', 'module' => 'reconciliation', 'action' => 'reports.read'],
        ['code' => 'VIEW_TICKET_REPORT', 'name' => 'Xem bao cao ticket', 'module' => 'reconciliation', 'action' => 'reports.ticket.read'],
        ['code' => 'VIEW_RECONCILIATION', 'name' => 'Xem doi soat', 'module' => 'reconciliation', 'action' => 'reconciliation.read'],
        ['code' => 'VIEW_RECONCILIATION_DETAIL', 'name' => 'Xem chi tiet doi soat', 'module' => 'reconciliation', 'action' => 'reconciliation.detail.read'],
        ['code' => 'CONFIRM_RECONCILIATION', 'name' => 'Xac nhan doi soat', 'module' => 'reconciliation', 'action' => 'reconciliation.confirm'],
        ['code' => 'WITHDRAW_WALLET', 'name' => 'Rut han muc hoat dong', 'module' => 'reconciliation', 'action' => 'wallet.withdraw', 'sensitive' => true],
        ['code' => 'EXPORT_REPORT', 'name' => 'Xuat bao cao', 'module' => 'reconciliation', 'action' => 'reports.export'],
        ['code' => 'EXPORT_RECONCILIATION', 'name' => 'Xuat doi soat', 'module' => 'reconciliation', 'action' => 'reconciliation.export'],
        ['code' => 'VIEW_STORE', 'name' => 'Xem cua hang', 'module' => 'stores', 'action' => 'stores.read'],
        ['code' => 'VIEW_STORE_DETAIL', 'name' => 'Xem chi tiet cua hang', 'module' => 'stores', 'action' => 'stores.detail.read'],
        ['code' => 'CREATE_STORE', 'name' => 'Them cua hang', 'module' => 'stores', 'action' => 'stores.create', 'sensitive' => true],
        ['code' => 'EDIT_PROFILE', 'name' => 'Chinh sua ho so cua hang', 'module' => 'stores', 'action' => 'stores.profile.update', 'sensitive' => true],
        ['code' => 'MANAGE_STORE_LICENSE', 'name' => 'Quan ly giay phep', 'module' => 'stores', 'action' => 'stores.licenses.manage', 'sensitive' => true],
        ['code' => 'UPDATE_OPERATING_STATE', 'name' => 'Bat tat nhan don', 'module' => 'stores', 'action' => 'stores.operating_state.update', 'sensitive' => true],
        ['code' => 'EXPORT_STORE', 'name' => 'Xuat cua hang', 'module' => 'stores', 'action' => 'stores.export'],
        ['code' => 'VIEW_WALLET', 'name' => 'Xem trang', 'module' => 'wallet', 'action' => 'wallet.read'],
        ['code' => 'VIEW_EMPLOYEE', 'name' => 'Xem nhan vien', 'module' => 'employees', 'action' => 'employees.read'],
        ['code' => 'VIEW_EMPLOYEE_DETAIL', 'name' => 'Xem chi tiet nhan vien', 'module' => 'employees', 'action' => 'employees.detail.read'],
        ['code' => 'CREATE_EMPLOYEE', 'name' => 'Tao nhan vien', 'module' => 'employees', 'action' => 'employees.create', 'sensitive' => true],
        ['code' => 'UPDATE_EMPLOYEE', 'name' => 'Cap nhat nhan vien', 'module' => 'employees', 'action' => 'employees.update', 'sensitive' => true],
        ['code' => 'ASSIGN_EMPLOYEE_PERMISSION', 'name' => 'Phan quyen nhan vien', 'module' => 'employees', 'action' => 'employees.permissions.update', 'sensitive' => true],
        ['code' => 'VIEW_EMPLOYEE_ACTIVITY_LOG', 'name' => 'Xem nhat ky nhan vien', 'module' => 'employees', 'action' => 'employees.audit.read', 'sensitive' => true],
        ['code' => 'VIEW_NOTIFICATION', 'name' => 'Xem thong bao', 'module' => 'notifications', 'action' => 'notifications.read'],
        ['code' => 'MARK_NOTIFICATION_READ', 'name' => 'Danh dau da doc', 'module' => 'notifications', 'action' => 'notifications.read.update'],
        ['code' => 'MARK_ALL_NOTIFICATION_READ', 'name' => 'Danh dau tat ca', 'module' => 'notifications', 'action' => 'notifications.read_all.update', 'sensitive' => true],
        ['code' => 'VIEW_COMPLAINT', 'name' => 'Xem khieu nai', 'module' => 'complaints', 'action' => 'complaints.read'],
        ['code' => 'VIEW_COMPLAINT_DETAIL', 'name' => 'Xem chi tiet khieu nai', 'module' => 'complaints', 'action' => 'complaints.detail.read'],
        ['code' => 'MARK_COMPLAINT_READ', 'name' => 'Danh dau khieu nai da doc', 'module' => 'complaints', 'action' => 'complaints.read.update'],
        ['code' => 'UPDATE_COMPLAINT', 'name' => 'Cap nhat khieu nai', 'module' => 'complaints', 'action' => 'complaints.status.update', 'sensitive' => true],
        ['code' => 'REPLY_COMPLAINT', 'name' => 'Phan hoi khieu nai', 'module' => 'complaints', 'action' => 'complaints.message.create'],
        ['code' => 'EXPORT_COMPLAINT', 'name' => 'Xuat khieu nai', 'module' => 'complaints', 'action' => 'complaints.export'],
        ['code' => 'VIEW_REVIEW', 'name' => 'Xem danh gia', 'module' => 'reviews', 'action' => 'reviews.read'],
        ['code' => 'VIEW_REVIEW_DETAIL', 'name' => 'Xem chi tiet danh gia', 'module' => 'reviews', 'action' => 'reviews.detail.read'],
        ['code' => 'REPLY_REVIEW', 'name' => 'Phan hoi danh gia', 'module' => 'reviews', 'action' => 'reviews.reply.create'],
        ['code' => 'DELETE_REVIEW', 'name' => 'Xoa danh gia', 'module' => 'reviews', 'action' => 'reviews.delete', 'sensitive' => true],
        ['code' => 'VIEW_SETTINGS', 'name' => 'Xem cai dat', 'module' => 'settings', 'action' => 'settings.read'],
        ['code' => 'UPDATE_NOTIFICATION_SETTINGS', 'name' => 'Cap nhat cai dat thong bao', 'module' => 'settings', 'action' => 'settings.notifications.update', 'sensitive' => true],
        ['code' => 'UPDATE_LANGUAGE_SETTINGS', 'name' => 'Cap nhat ngon ngu', 'module' => 'settings', 'action' => 'settings.language.update', 'sensitive' => true],
        ['code' => 'UPDATE_SECURITY_SETTINGS', 'name' => 'Cap nhat bao mat', 'module' => 'settings', 'action' => 'settings.security.update', 'sensitive' => true],
        ['code' => 'UPDATE_OPERATING_SCHEDULE', 'name' => 'Cap nhat gio hoat dong', 'module' => 'settings', 'action' => 'settings.operating_schedule.update', 'sensitive' => true],
        ['code' => 'TOGGLE_OPERATING_HOURS', 'name' => 'Bat tat gio hoat dong', 'module' => 'settings', 'action' => 'settings.operating_schedule.toggle', 'sensitive' => true],
        ['code' => 'UPDATE_SLA_CONFIG', 'name' => 'Cap nhat SLA', 'module' => 'settings', 'action' => 'settings.sla.update', 'sensitive' => true],
    ];

    private const LEGACY_PERMISSIONS = [
        ['code' => 'VIEW_REPORT', 'name' => 'Xem bao cao'],
        ['code' => 'MANAGE_STAFF', 'name' => 'Quan ly nhan vien va phan quyen'],
        ['code' => 'RECONCILIATION_CREATE', 'name' => 'Lap doi soat'],
        ['code' => 'UPDATE_ORDER_STATUS', 'name' => 'Sua trang thai don'],
        ['code' => 'REFUND_ORDER', 'name' => 'Hoan ve don hang'],
        ['code' => 'EXPORT_DATA', 'name' => 'Xuat du lieu'],
        ['code' => 'PRODUCT_CREATE', 'name' => 'Tao san pham legacy'],
        ['code' => 'VIEW_NOTIFICATION', 'name' => 'Xem thong bao'],
    ];

    private const PERMISSION_DEPENDENCIES = [
        'VIEW_DASHBOARD_DETAIL' => ['VIEW_DASHBOARD'],
        'EXPORT_DASHBOARD' => ['VIEW_DASHBOARD'],
        'VIEW_ORDER_DETAIL' => ['VIEW_ORDER'],
        'ACCEPT_ORDER' => ['VIEW_ORDER'],
        'UPDATE_ORDER' => ['VIEW_ORDER'],
        'CANCEL_ORDER' => ['VIEW_ORDER'],
        'EXPORT_ORDER' => ['VIEW_ORDER'],
        'IMPORT_ORDER' => ['VIEW_ORDER'],
        'VIEW_RETURN' => ['VIEW_ORDER'],
        'PROCESS_RETURN' => ['VIEW_RETURN'],
        'VIEW_PRODUCT_DETAIL' => ['VIEW_MENU'],
        'CREATE_PRODUCT' => ['VIEW_MENU'],
        'IMPORT_PRODUCT' => ['VIEW_MENU'],
        'EDIT_MENU' => ['VIEW_MENU'],
        'EDIT_PRICE' => ['VIEW_MENU'],
        'TOGGLE_PRODUCT_SALE' => ['VIEW_MENU'],
        'EXPORT_PRODUCT' => ['VIEW_MENU'],
        'VIEW_FLASH_SALE' => ['VIEW_MENU'],
        'CREATE_FLASH_SALE' => ['VIEW_MENU', 'VIEW_FLASH_SALE'],
        'UPDATE_FLASH_SALE' => ['VIEW_MENU', 'VIEW_FLASH_SALE'],
        'DELETE_FLASH_SALE' => ['VIEW_MENU', 'VIEW_FLASH_SALE'],
        'DELETE_PRODUCT' => ['VIEW_MENU'],
        'VIEW_CUSTOMER_DETAIL' => ['VIEW_CUSTOMER'],
        'CREATE_CUSTOMER' => ['VIEW_CUSTOMER'],
        'EDIT_CUSTOMER' => ['VIEW_CUSTOMER'],
        'EXPORT_CUSTOMER' => ['VIEW_CUSTOMER'],
        'VIEW_TICKET_REPORT' => ['VIEW_REPORT'],
        'EXPORT_REPORT' => ['VIEW_REPORT'],
        'VIEW_RECONCILIATION' => ['VIEW_REPORT'],
        'VIEW_RECONCILIATION_DETAIL' => ['VIEW_REPORT', 'VIEW_RECONCILIATION'],
        'CONFIRM_RECONCILIATION' => ['VIEW_REPORT', 'VIEW_RECONCILIATION'],
        'EXPORT_RECONCILIATION' => ['VIEW_REPORT', 'VIEW_RECONCILIATION'],
        'VIEW_STORE_DETAIL' => ['VIEW_STORE'],
        'CREATE_STORE' => ['VIEW_STORE'],
        'EDIT_PROFILE' => ['VIEW_STORE'],
        'MANAGE_STORE_LICENSE' => ['VIEW_STORE'],
        'UPDATE_OPERATING_STATE' => ['VIEW_STORE'],
        'EXPORT_STORE' => ['VIEW_STORE'],
        'VIEW_EMPLOYEE_DETAIL' => ['VIEW_EMPLOYEE'],
        'CREATE_EMPLOYEE' => ['VIEW_EMPLOYEE'],
        'UPDATE_EMPLOYEE' => ['VIEW_EMPLOYEE'],
        'ASSIGN_EMPLOYEE_PERMISSION' => ['VIEW_EMPLOYEE'],
        'VIEW_EMPLOYEE_ACTIVITY_LOG' => ['VIEW_EMPLOYEE'],
        'MARK_NOTIFICATION_READ' => ['VIEW_NOTIFICATION'],
        'MARK_ALL_NOTIFICATION_READ' => ['VIEW_NOTIFICATION'],
        'VIEW_COMPLAINT_DETAIL' => ['VIEW_COMPLAINT'],
        'MARK_COMPLAINT_READ' => ['VIEW_COMPLAINT'],
        'UPDATE_COMPLAINT' => ['VIEW_COMPLAINT'],
        'REPLY_COMPLAINT' => ['VIEW_COMPLAINT'],
        'EXPORT_COMPLAINT' => ['VIEW_COMPLAINT'],
        'VIEW_REVIEW_DETAIL' => ['VIEW_REVIEW'],
        'REPLY_REVIEW' => ['VIEW_REVIEW'],
        'DELETE_REVIEW' => ['VIEW_REVIEW'],
        'UPDATE_NOTIFICATION_SETTINGS' => ['VIEW_SETTINGS'],
        'UPDATE_LANGUAGE_SETTINGS' => ['VIEW_SETTINGS'],
        'UPDATE_SECURITY_SETTINGS' => ['VIEW_SETTINGS'],
        'UPDATE_OPERATING_SCHEDULE' => ['VIEW_SETTINGS'],
        'TOGGLE_OPERATING_HOURS' => ['VIEW_SETTINGS'],
        'UPDATE_SLA_CONFIG' => ['VIEW_SETTINGS'],
        'VIEW_WALLET' => ['VIEW_STORE'],
        'WITHDRAW_WALLET' => ['VIEW_RECONCILIATION'],
    ];

    private const MIDDLEWARE_ALIASES = [
        'CREATE_EMPLOYEE' => ['MANAGE_STAFF'],
        'UPDATE_EMPLOYEE' => ['MANAGE_STAFF'],
        'ASSIGN_EMPLOYEE_PERMISSION' => ['MANAGE_STAFF'],
        'CONFIRM_RECONCILIATION' => ['RECONCILIATION_CREATE'],
        'UPDATE_ORDER' => ['UPDATE_ORDER_STATUS'],
        'CANCEL_ORDER' => ['REFUND_ORDER'],
        'CREATE_PRODUCT' => ['PRODUCT_CREATE'],
        'EXPORT_DASHBOARD' => ['EXPORT_DATA'],
        'EXPORT_ORDER' => ['EXPORT_DATA'],
        'EXPORT_PRODUCT' => ['EXPORT_DATA'],
        'EXPORT_CUSTOMER' => ['EXPORT_DATA'],
        'EXPORT_REPORT' => ['EXPORT_DATA'],
        'EXPORT_RECONCILIATION' => ['EXPORT_DATA'],
        'EXPORT_STORE' => ['EXPORT_DATA'],
    ];

    public function hasPermissionTable(): bool
    {
        return Schema::hasTable('permissions');
    }

    public function ensureSeeded(): void
    {
        if (!$this->hasPermissionTable()) {
            return;
        }

        $now = now();
        if (Schema::hasTable('roles')) {
            foreach (self::STAFF_ROLES as $roleName) {
                Role::query()->firstOrCreate(
                    ['name' => $roleName],
                    ['description' => 'Store staff role: ' . $roleName]
                );
            }
        }

        foreach (array_merge(self::DISPLAY_PERMISSIONS, self::LEGACY_PERMISSIONS) as $permission) {
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

    public function catalogPayload(Store $store): array
    {
        $this->ensureSeeded();
        $rowsByCode = $this->permissionRowsByCode();
        $permissions = [];

        foreach (self::DISPLAY_PERMISSIONS as $index => $permission) {
            $code = $permission['code'];
            $row = $rowsByCode->get($code);
            if (!$row) {
                continue;
            }

            $permissions[] = [
                'id' => (int) $row->id,
                'permission_id' => (int) $row->id,
                'code' => $code,
                'name' => (string) ($row->name ?: $permission['name']),
                'module' => $permission['module'],
                'module_key' => $permission['module'],
                'action' => $permission['action'],
                'sensitive' => (bool) ($permission['sensitive'] ?? false),
                'sort_order' => $index + 1,
            ];
        }

        return [
            'store_id' => (string) $store->id,
            'permissions' => $permissions,
            'presets' => [
                'store_manager' => $this->managerPresetCodes(),
                'store_staff' => $this->staffPresetCodes(),
                'store_manager_ids' => $this->idsForCodes($this->managerPresetCodes()),
                'store_staff_ids' => $this->idsForCodes($this->staffPresetCodes()),
            ],
        ];
    }

    public function resolvePermissionInput(array $input, string $roleName): ?array
    {
        $this->ensureSeeded();
        $rowsByCode = $this->permissionRowsByCode();
        $rowsById = $rowsByCode->keyBy(fn ($row) => (int) $row->id);

        $incomingIds = $this->readArray($input, ['permission_ids', 'permissionIds', 'permission_id', 'permissionId']);
        $incomingCodes = $this->readArray($input, ['permission_codes', 'permissionCodes', 'codes']);

        foreach ($this->readArray($input, ['permissions']) as $value) {
            if (is_numeric($value)) {
                $incomingIds[] = $value;
            } else {
                $incomingCodes[] = $value;
            }
        }

        if (empty($incomingIds) && empty($incomingCodes)) {
            $incomingCodes = $this->defaultPresetCodes($roleName);
        }

        $resolvedIds = [];
        foreach ($incomingIds as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0 || !$rowsById->has($id)) {
                return null;
            }
            $resolvedIds[] = $id;
        }

        foreach ($incomingCodes as $rawCode) {
            $code = strtoupper(trim((string) $rawCode));
            if ($code === '') {
                continue;
            }
            $row = $rowsByCode->get($code);
            if (!$row) {
                return null;
            }
            $resolvedIds[] = (int) $row->id;
        }

        $resolvedCodes = $this->normalizePermissionCodes($this->codesForIds(array_values(array_unique($resolvedIds))));
        $resolvedIds = $this->sortIdsByCatalog($this->idsForCodes($resolvedCodes));
        $resolvedCodes = $this->codesForIds($resolvedIds);

        return [
            'ids' => $resolvedIds,
            'codes' => $resolvedCodes,
        ];
    }

    public function normalizeRoleName(?string $roleName): string
    {
        $normalized = strtolower(trim((string) $roleName));
        $normalized = str_replace(['-', ' '], '_', $normalized);

        return match ($normalized) {
            'manager', 'storemanager' => 'store_manager',
            'custom', 'custom_role' => 'store_custom',
            'staff', 'employee', '' => self::DEFAULT_ROLE,
            default => in_array($normalized, self::STAFF_ROLES, true) ? $normalized : self::DEFAULT_ROLE,
        };
    }

    public function middlewareCodes(string $code): array
    {
        $normalized = strtoupper(trim($code));
        $aliases = self::MIDDLEWARE_ALIASES[$normalized] ?? [];

        return array_values(array_unique(array_merge([$normalized], $aliases)));
    }

    public function requiredMiddlewareCodeGroups(string $code): array
    {
        $normalized = strtoupper(trim($code));
        $requiredCodes = array_values(array_unique(array_merge(
            $this->dependenciesForCode($normalized),
            [$normalized]
        )));

        return array_map(
            fn (string $requiredCode) => $this->middlewareCodes($requiredCode),
            $requiredCodes
        );
    }

    public function normalizePermissionCodes(array $codes): array
    {
        $selected = [];
        foreach ($codes as $code) {
            $normalized = strtoupper(trim((string) $code));
            if ($normalized !== '') {
                $selected[$normalized] = true;
            }
        }

        $changed = true;
        while ($changed) {
            $changed = false;

            foreach (array_keys($selected) as $code) {
                foreach ($this->dependenciesForCode($code) as $dependency) {
                    if (!isset($selected[$dependency])) {
                        unset($selected[$code]);
                        $changed = true;
                        break;
                    }
                }
            }
        }

        return array_values(array_keys($selected));
    }

    public function idsForCodes(array $codes): array
    {
        $lookup = $this->permissionRowsByCode();

        return collect($codes)
            ->map(fn ($code) => $lookup->get(strtoupper(trim((string) $code)))?->id)
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function dependenciesForCode(string $code): array
    {
        return self::PERMISSION_DEPENDENCIES[strtoupper(trim($code))] ?? [];
    }

    public function codesForIds(array $ids): array
    {
        $lookup = $this->permissionRowsByCode()->keyBy(fn ($row) => (int) $row->id);

        return collect($ids)
            ->map(fn ($id) => $lookup->get((int) $id)?->code)
            ->filter(fn ($code) => $code !== null)
            ->map(fn ($code) => (string) $code)
            ->values()
            ->all();
    }

    public function staffPresetCodes(): array
    {
        return array_values(array_filter(
            $this->displayCodes(),
            fn (string $code) => !in_array($code, self::SENSITIVE_CODES, true)
        ));
    }

    public function managerPresetCodes(): array
    {
        return $this->displayCodes();
    }

    public function permissionRowsByCode(): Collection
    {
        if (!$this->hasPermissionTable()) {
            return collect();
        }

        return Permission::query()
            ->get(['id', 'code', 'name'])
            ->keyBy(fn (Permission $permission) => strtoupper((string) $permission->code));
    }

    private function displayCodes(): array
    {
        return array_map(fn (array $permission) => $permission['code'], self::DISPLAY_PERMISSIONS);
    }

    private function defaultPresetCodes(string $roleName): array
    {
        return $roleName === 'store_manager'
            ? $this->managerPresetCodes()
            : $this->staffPresetCodes();
    }

    private function sortIdsByCatalog(array $ids): array
    {
        $orderedIds = $this->idsForCodes($this->displayCodes());
        $rank = array_flip($orderedIds);

        usort($ids, fn (int $left, int $right) => ($rank[$left] ?? 9999) <=> ($rank[$right] ?? 9999));

        return $ids;
    }

    private function readArray(array $input, array $keys): array
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];
            if ($value === null || $value === '') {
                return [];
            }

            return is_array($value) ? array_values($value) : [$value];
        }

        return [];
    }
}

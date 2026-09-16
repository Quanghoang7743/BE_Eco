<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminPermissionController extends Controller
{
    private const GUARD = 'web';

    private const CMS_ROLE_NAMES = [
        'super_admin',
        'admin',
        'operation_admin',
        'finance_admin',
        'sale',
    ];

    // Chỉ dùng để ẩn các role cũ đã từng auto seed trong DB.
    private const OLD_DEFAULT_ROLE_NAMES = [
        'admin_store',
        'user',
    ];

    private const LEGACY_ROLE_PRIORITY = [
        'super_admin',
        'admin',
        'operation_admin',
        'finance_admin',
        'sale',
    ];

    private const LEGACY_ROLE_MAP = [
        'super_admin' => 'super_admin',
        'admin' => 'admin',
        'operation_admin' => 'admin',
        'finance_admin' => 'admin',
        'sale' => 'sale',
    ];

    private const ROLE_DEFINITIONS = [
        'super_admin' => [
            'label' => 'Super Admin',
            'description' => 'Toàn quyền quản trị hệ thống.',
            'permissions' => '*',
        ],
        'admin' => [
            'label' => 'Admin',
            'description' => 'Quản trị vận hành và nội dung.',
            'permissions' => [
                'DASHBOARD_VIEW',
                'USER_MANAGE',
                'ROLE_PERMISSION_MANAGE',
                'ORDER_VIEW',
                'ORDER_UPDATE',
                'PRODUCT_MANAGE',
                'USER_VIEW',
                'STORE_VIEW',
                'STORE_CREATE',
                'STORE_ASSIGN_SALE',
                'STORE_PROFILE_EDIT',
                'STORE_LOCK',
                'STORE_APPROVE',
                'RECONCILIATION_MANAGE',
                'REPORT_VIEW',
                'SETTING_MANAGE',
            ],
        ],
        'operation_admin' => [
            'label' => 'Admin van hanh',
            'description' => 'Quan tri van hanh, duyet ho so va phan cong cua hang.',
            'permissions' => [
                'DASHBOARD_VIEW',
                'USER_VIEW',
                'USER_MANAGE',
                'SALE_MANAGE',
                'ORDER_VIEW',
                'ORDER_UPDATE',
                'PRODUCT_VIEW',
                'PRODUCT_APPROVE',
                'STORE_VIEW',
                'STORE_CREATE',
                'STORE_APPROVE',
                'STORE_ASSIGN_SALE',
                'STORE_PROFILE_EDIT',
                'STORE_LOCK',
                'REPORT_VIEW',
            ],
        ],
        'finance_admin' => [
            'label' => 'Admin tai chinh',
            'description' => 'Quan tri doi soat, thanh toan va bao cao tai chinh.',
            'permissions' => [
                'DASHBOARD_VIEW',
                'ORDER_VIEW',
                'STORE_VIEW',
                'REPORT_VIEW',
                'REPORT_EXPORT',
                'RECONCILIATION_MANAGE',
                'PAYMENT_SETTING_MANAGE',
                'BANK_ACCOUNT_MANAGE',
            ],
        ],
        'sale' => [
            'label' => 'Sale',
            'description' => 'Chuyen vien cua hang, chi xem du lieu trong pham vi duoc phan cong.',
            'permissions' => [
                'DASHBOARD_VIEW',
                'STORE_VIEW',
                'ORDER_VIEW',
                'LUCKY_TICKET_VIEW',
            ],
        ],
    ];

    private const PERMISSION_GROUPS = [
        'system' => ['label' => 'Hệ thống', 'icon' => 'shield-alt'],
        'users' => ['label' => 'Tài khoản', 'icon' => 'users-cog'],
        'commerce' => ['label' => 'Bán hàng', 'icon' => 'shopping-bag'],
        'catalog' => ['label' => 'Sản phẩm', 'icon' => 'boxes'],
        'store' => ['label' => 'Cửa hàng', 'icon' => 'store'],
        'ticket' => ['label' => 'Lucky Ticket', 'icon' => 'ticket-alt'],
        'reward' => ['label' => 'Phòng thưởng', 'icon' => 'gamepad'],
        'affiliate' => ['label' => 'Affiliate', 'icon' => 'project-diagram'],
        'reports' => ['label' => 'Báo cáo', 'icon' => 'chart-line'],
        'content' => ['label' => 'Nội dung', 'icon' => 'newspaper'],
        'finance' => ['label' => 'Tài chính', 'icon' => 'wallet'],
        'risk' => ['label' => 'Risk/Fraud', 'icon' => 'shield-alt'],
        'other' => ['label' => 'Khác', 'icon' => 'ellipsis-h'],
    ];

    private const PERMISSION_CATALOG = [
        ['name' => 'DASHBOARD_VIEW', 'label' => 'Xem dashboard', 'group' => 'system'],
        ['name' => 'SETTING_MANAGE', 'label' => 'Quản lý cấu hình hệ thống', 'group' => 'system'],
        ['name' => 'ROLE_PERMISSION_MANAGE', 'label' => 'Quản lý vai trò và phân quyền', 'group' => 'system'],
        ['name' => 'USER_VIEW', 'label' => 'Xem tài khoản', 'group' => 'users'],
        ['name' => 'USER_MANAGE', 'label' => 'Thêm/sửa/xóa tài khoản', 'group' => 'users'],
        ['name' => 'SALE_MANAGE', 'label' => 'Quản lý nhân viên sale', 'group' => 'users'],
        ['name' => 'ORDER_VIEW', 'label' => 'Xem đơn hàng', 'group' => 'commerce'],
        ['name' => 'ORDER_UPDATE', 'label' => 'Cập nhật đơn hàng', 'group' => 'commerce'],
        ['name' => 'ORDER_CANCEL', 'label' => 'Hủy đơn hàng', 'group' => 'commerce'],
        ['name' => 'ORDER_REFUND', 'label' => 'Hoàn tiền đơn hàng', 'group' => 'commerce'],
        ['name' => 'PRODUCT_VIEW', 'label' => 'Xem sản phẩm', 'group' => 'catalog'],
        ['name' => 'PRODUCT_MANAGE', 'label' => 'Quản lý sản phẩm', 'group' => 'catalog'],
        ['name' => 'PRODUCT_APPROVE', 'label' => 'Duyệt sản phẩm', 'group' => 'catalog'],
        ['name' => 'CATEGORY_MANAGE', 'label' => 'Quản lý danh mục', 'group' => 'catalog'],
        ['name' => 'STORE_VIEW', 'label' => 'Xem cửa hàng', 'group' => 'store'],
        ['name' => 'STORE_CREATE', 'label' => 'Tạo hồ sơ cửa hàng', 'group' => 'store'],
        ['name' => 'STORE_APPROVE', 'label' => 'Duyệt cửa hàng', 'group' => 'store'],
        ['name' => 'STORE_ASSIGN_SALE', 'label' => 'Gán sale phụ trách cửa hàng', 'group' => 'store'],
        ['name' => 'STORE_LOCK', 'label' => 'Tạm khóa cửa hàng', 'group' => 'store'],
        ['name' => 'STORE_PROFILE_EDIT', 'label' => 'Sửa hồ sơ cửa hàng', 'group' => 'store'],
        ['name' => 'STORE_STAFF_MANAGE', 'label' => 'Quản lý nhân sự cửa hàng', 'group' => 'store'],
        ['name' => 'MENU_VIEW', 'label' => 'Xem menu', 'group' => 'store'],
        ['name' => 'MENU_EDIT', 'label' => 'Chỉnh sửa menu', 'group' => 'store'],
        ['name' => 'PRICE_EDIT', 'label' => 'Chỉnh sửa giá', 'group' => 'store'],
        ['name' => 'LUCKY_TICKET_VIEW', 'label' => 'Tra cứu Lucky Ticket', 'group' => 'ticket'],
        ['name' => 'LUCKY_TICKET_FREEZE', 'label' => 'Đóng băng ticket', 'group' => 'ticket'],
        ['name' => 'LUCKY_TICKET_CANCEL', 'label' => 'Hủy ticket', 'group' => 'ticket'],
        ['name' => 'LUCKY_TICKET_HISTORY', 'label' => 'Xem lịch sử ticket', 'group' => 'ticket'],
        ['name' => 'REWARD_ROOM_CREATE', 'label' => 'Tạo phòng thưởng nháp', 'group' => 'reward'],
        ['name' => 'REWARD_ROOM_ACTIVATE', 'label' => 'Kích hoạt phòng thưởng', 'group' => 'reward'],
        ['name' => 'REWARD_ROOM_LOCK', 'label' => 'Khóa phòng thưởng', 'group' => 'reward'],
        ['name' => 'REWARD_RESULT_PUBLISH', 'label' => 'Công bố kết quả', 'group' => 'reward'],
        ['name' => 'AFFILIATE_VIEW', 'label' => 'Xem dữ liệu affiliate', 'group' => 'affiliate'],
        ['name' => 'AFFILIATE_MANAGE', 'label' => 'Quản lý affiliate', 'group' => 'affiliate'],
        ['name' => 'AFFILIATE_COMMISSION_MANAGE', 'label' => 'Cấu hình hoa hồng affiliate', 'group' => 'affiliate'],
        ['name' => 'REPORT_VIEW', 'label' => 'Xem báo cáo', 'group' => 'reports'],
        ['name' => 'REPORT_EXPORT', 'label' => 'Xuất báo cáo', 'group' => 'reports'],
        ['name' => 'ARTICLE_MANAGE', 'label' => 'Quản lý bài viết/chính sách', 'group' => 'content'],
        ['name' => 'BANNER_MANAGE', 'label' => 'Quản lý banner', 'group' => 'content'],
        ['name' => 'NOTIFICATION_MANAGE', 'label' => 'Quản lý thông báo', 'group' => 'content'],
        ['name' => 'RECONCILIATION_MANAGE', 'label' => 'Quản lý đối soát', 'group' => 'finance'],
        ['name' => 'PAYMENT_SETTING_MANAGE', 'label' => 'Quản lý cấu hình thanh toán', 'group' => 'finance'],
        ['name' => 'BANK_ACCOUNT_MANAGE', 'label' => 'Quản lý tài khoản ngân hàng', 'group' => 'finance'],
        ['name' => 'RISK_ALERT_VIEW', 'label' => 'Xem cảnh báo rủi ro', 'group' => 'risk'],
        ['name' => 'RISK_CASE_MANAGE', 'label' => 'Quản lý hồ sơ rủi ro', 'group' => 'risk'],
    ];

    public function index(Request $request): View
    {
        $this->authorizePermissionManagement($request);

        if (! $this->spatieTablesReady()) {
            return view('admin.permissions.index', [
                'setupRequired' => true,
                'missingTables' => $this->missingSpatieTables(),
            ]);
        }

        $this->ensureCatalog();
        $this->syncLegacyRoles();

        $roles = Role::query()
            ->with('permissions')
            ->withCount('users')
            ->where('guard_name', self::GUARD)
            ->where(function ($query) {
                $query->whereIn('name', self::CMS_ROLE_NAMES);

                if ($this->roleMetadataColumnsReady()) {
                    $query->orWhere(function ($scopeQuery) {
                        $scopeQuery
                            ->where('scope', 'admin_cms')
                            ->whereNotIn('name', self::OLD_DEFAULT_ROLE_NAMES);
                    });
                }
            })
            ->get()
            ->sortBy(fn (Role $role) => array_search($role->name, array_keys(self::ROLE_DEFINITIONS), true) === false ? 999 : array_search($role->name, array_keys(self::ROLE_DEFINITIONS), true))
            ->values();

        $users = User::query()
            ->with('roles')
            ->where(function ($query) {
                $query
                    ->whereIn('role', array_values(self::LEGACY_ROLE_MAP))
                    ->orWhereHas('roles');
            })
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('admin.permissions.index', [
            'setupRequired' => false,
            'permissionGroups' => $this->permissionGroups(),
            'roles' => $roles,
            'roleDefinitions' => self::ROLE_DEFINITIONS,
            'users' => $users,
            'stats' => [
                'roles' => $roles->count(),
                'permissions' => Permission::query()->where('guard_name', self::GUARD)->count(),
                'users' => User::query()->whereHas('roles')->count(),
                'assigned' => $roles->sum(fn (Role $role) => $role->permissions->count()),
            ],
        ]);
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $this->authorizePermissionManagement($request);
        abort_unless($this->spatieTablesReady(), 409, 'Các bảng phân quyền Spatie chưa sẵn sàng.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'display_name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ], [
            'name.regex' => 'Mã vai trò chỉ được dùng chữ cái, số, dấu gạch ngang hoặc gạch dưới.',
        ]);

        $name = Str::of($validated['name'])->lower()->replace('-', '_')->toString();

        if (Role::query()->where('name', $name)->where('guard_name', self::GUARD)->exists()) {
            return back()->withErrors(['name' => 'Vai trò này đã tồn tại.'])->withInput();
        }

        $attributes = [
            'name' => $name,
            'guard_name' => self::GUARD,
        ];

        if ($this->roleMetadataColumnsReady()) {
            $attributes['display_name'] = $validated['display_name'];
            $attributes['description'] = $validated['description'] ?? null;
            $attributes['scope'] = 'admin_cms';
        }

        Role::query()->create($attributes);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('admin.permissions.index')->with('success', 'Đã tạo vai trò mới.');
    }

    public function updateRole(Request $request, string $role): RedirectResponse
    {
        $this->authorizePermissionManagement($request);
        abort_unless($this->spatieTablesReady(), 409, 'Các bảng phân quyền Spatie chưa sẵn sàng.');

        $validated = $request->validate([
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:' . config('permission.table_names.permissions') . ',name'],
        ]);

        $roleRecord = Role::query()
            ->where('name', $role)
            ->where('guard_name', self::GUARD)
            ->firstOrFail();

        $roleRecord->syncPermissions($validated['permissions'] ?? []);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()
            ->route('admin.permissions.index')
            ->with('success', 'Đã cập nhật quyền cho vai trò ' . $roleRecord->name . '.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $this->authorizePermissionManagement($request);
        abort_unless($this->spatieTablesReady(), 409, 'Các bảng phân quyền Spatie chưa sẵn sàng.');

        $validated = $request->validate([
            'role' => ['required', 'string', 'exists:' . config('permission.table_names.roles') . ',name'],
        ]);

        $role = strtolower($validated['role']);

        if (! $this->isCmsRole($role)) {
            return back()->withErrors(['role' => 'Vai trò này không thuộc nhóm Admin CMS.']);
        }

        if ($user->is($request->user()) && ! in_array($role, ['super_admin', 'admin'], true)) {
            return back()->withErrors(['roles' => 'Không thể gỡ vai trò admin của chính bạn.']);
        }

        $roles = [$role];
        $user->syncRoles($roles);
        $this->syncLegacyRoleColumn($user, $roles);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()
            ->route('admin.users.index', ['tab' => 'accounts'])
            ->with('success', 'Đã cập nhật vai trò cho ' . ($user->full_name ?: $user->name ?: $user->email ?: 'tài khoản') . '.');
    }

    private function authorizePermissionManagement(Request $request): void
    {
        $user = $request->user();

        abort_unless($user && in_array((string) $user->role, ['admin', 'super_admin'], true), 403);
    }

    private function spatieTablesReady(): bool
    {
        return empty($this->missingSpatieTables());
    }

    private function missingSpatieTables(): array
    {
        $missing = collect([
            config('permission.table_names.permissions'),
            config('permission.table_names.roles'),
            config('permission.table_names.model_has_permissions'),
            config('permission.table_names.model_has_roles'),
            config('permission.table_names.role_has_permissions'),
        ])
            ->filter(fn (?string $table) => ! $table || ! Schema::hasTable($table))
            ->values();

        $rolesTable = config('permission.table_names.roles');

        if ($rolesTable && Schema::hasTable($rolesTable)) {
            foreach (['display_name', 'description', 'scope'] as $column) {
                if (! Schema::hasColumn($rolesTable, $column)) {
                    $missing->push($rolesTable . '.' . $column);
                }
            }
        }

        return $missing->values()->all();
    }

    private function ensureCatalog(): void
    {
        foreach (self::PERMISSION_CATALOG as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission['name'],
                'guard_name' => self::GUARD,
            ]);
        }

        $allPermissions = Permission::query()
            ->where('guard_name', self::GUARD)
            ->pluck('name')
            ->all();

        foreach (self::ROLE_DEFINITIONS as $name => $definition) {
            $role = Role::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => self::GUARD,
            ]);

            if ($this->roleMetadataColumnsReady()) {
                $role->forceFill([
                    'display_name' => $role->display_name ?: $definition['label'],
                    'description' => $role->description ?: $definition['description'],
                    'scope' => in_array($name, self::CMS_ROLE_NAMES, true) ? 'admin_cms' : $role->scope,
                ])->save();
            }

            $permissions = $definition['permissions'] === '*' ? $allPermissions : $definition['permissions'];

            if ($definition['permissions'] === '*' || $name === 'sale') {
                $role->syncPermissions($permissions);
            } else {
                $missingPermissions = array_values(array_diff($permissions, $role->permissions()->pluck('name')->all()));
                if (!empty($missingPermissions)) {
                    $role->givePermissionTo($missingPermissions);
                }
            }
        }

        $this->hideOldDefaultRolesFromCms();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function hideOldDefaultRolesFromCms(): void
    {
        if (! $this->roleMetadataColumnsReady()) {
            return;
        }

        Role::query()
            ->where('guard_name', self::GUARD)
            ->whereIn('name', self::OLD_DEFAULT_ROLE_NAMES)
            ->where('scope', 'admin_cms')
            ->update(['scope' => null]);
    }

    private function syncLegacyRoles(): void
    {
        User::query()
            ->whereIn('role', array_values(self::LEGACY_ROLE_MAP))
            ->whereDoesntHave('roles')
            ->select(['id', 'role'])
            ->chunkById(100, function (Collection $users) {
                foreach ($users as $user) {
                    $role = (string) $user->role;

                    if (Role::query()->where('name', $role)->where('guard_name', self::GUARD)->exists()) {
                        $user->assignRole($role);
                    }
                }
            });
    }

    private function syncLegacyRoleColumn(User $user, array $roles): void
    {
        $primaryRole = $roles[0] ?? null;

        if ($primaryRole && isset(self::LEGACY_ROLE_MAP[$primaryRole])) {
            $user->forceFill(['role' => self::LEGACY_ROLE_MAP[$primaryRole]])->save();
            return;
        }

        if ($primaryRole && $this->isCmsRole($primaryRole)) {
            $user->forceFill(['role' => 'admin'])->save();
            return;
        }

        foreach (self::LEGACY_ROLE_PRIORITY as $role) {
            if (in_array($role, $roles, true)) {
                $user->forceFill(['role' => self::LEGACY_ROLE_MAP[$role] ?? $role])->save();
                return;
            }
        }

        $user->forceFill(['role' => 'user'])->save();
    }

    private function permissionGroups(): array
    {
        $catalog = collect(self::PERMISSION_CATALOG)->keyBy('name');
        $permissions = Permission::query()
            ->where('guard_name', self::GUARD)
            ->orderBy('name')
            ->get()
            ->map(function (Permission $permission) use ($catalog) {
                $meta = $catalog->get($permission->name, []);

                return [
                    'name' => $permission->name,
                    'label' => $meta['label'] ?? Str::headline(strtolower(str_replace('_', ' ', $permission->name))),
                    'group' => $meta['group'] ?? 'other',
                ];
            });

        return collect(self::PERMISSION_GROUPS)
            ->map(function (array $group, string $key) use ($permissions) {
                return [
                    'key' => $key,
                    'label' => $group['label'],
                    'icon' => $group['icon'],
                    'permissions' => $permissions->where('group', $key)->values()->all(),
                ];
            })
            ->filter(fn (array $group) => ! empty($group['permissions']))
            ->values()
            ->all();
    }

    private function roleMetadataColumnsReady(): bool
    {
        $table = config('permission.table_names.roles');

        return $table
            && Schema::hasTable($table)
            && Schema::hasColumn($table, 'display_name')
            && Schema::hasColumn($table, 'description')
            && Schema::hasColumn($table, 'scope');
    }

    private function isCmsRole(string $role): bool
    {
        if (in_array($role, self::CMS_ROLE_NAMES, true)) {
            return true;
        }

        if (! $this->roleMetadataColumnsReady()) {
            return false;
        }

        return Role::query()
            ->where('name', $role)
            ->where('guard_name', self::GUARD)
            ->where('scope', 'admin_cms')
            ->whereNotIn('name', self::OLD_DEFAULT_ROLE_NAMES)
            ->exists();
    }
}

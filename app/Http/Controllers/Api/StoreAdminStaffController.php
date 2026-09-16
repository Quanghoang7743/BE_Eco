<?php

namespace App\Http\Controllers\Api;

use App\Events\StoreStaffPermissionsChanged;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StoreStaffPermission;
use App\Models\User;
use App\Models\UserRoleBinding;
use App\Services\NotificationService;
use App\Services\StoreAdmin\StoreAdminStaffAccess;
use App\Services\StoreAdmin\StorePermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class StoreAdminStaffController extends Controller
{
    public function __construct(
        private readonly StorePermissionCatalog $catalog,
        private readonly StoreAdminStaffAccess $access,
        private readonly NotificationService $notificationService
    ) {
    }

    public function permissions(Request $request): JsonResponse
    {
        [$user, $store, $error] = $this->access->resolveStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!$this->catalog->hasPermissionTable()) {
            return $this->access->errorResponse('PERMISSION_NOT_SUPPORTED', 'Bang permissions chua duoc tao.', 422, true, true);
        }

        if ($request->boolean('mine')) {
            $permissionIds = $this->permissionIdsForUserStore((int) $user->id, (string) $store->id);
            $permissionCodes = $this->catalog->codesForIds($permissionIds);

            return $this->success([
                'store_id' => (string) $store->id,
                'user_id' => (string) $user->id,
                'permission_ids' => $permissionIds,
                'permission_codes' => $permissionCodes,
                'permissions' => $this->permissionRecords($permissionIds),
            ]);
        }

        if (!$this->access->canManageStaff((int) $user->id, (string) $store->id)) {
            return $this->access->errorResponse('FORBIDDEN_PERMISSION', 'Ban khong co quyen xem danh muc phan quyen cua cua hang nay.', 403, true, true);
        }

        return $this->success($this->catalog->catalogPayload($store));
    }

    public function createStaff(Request $request): JsonResponse
    {
        [$actor, $store, $error] = $this->access->resolveStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!$this->access->canManageStaff((int) $actor->id, (string) $store->id)) {
            return $this->access->errorResponse('FORBIDDEN_ROLE', 'Ban khong co quyen quan ly nhan vien cua cua hang nay.', 403, true, true);
        }

        $payload = $this->normalizeStaffPayload($request);
        if ($payload['email'] === '') {
            $payload['email'] = $this->systemEmailForPhone($payload['phone']);
        }

        // Check if user already exists
        $existingUser = User::query()
            ->where('phone', $payload['phone'])
            ->orWhere('email', $payload['email'])
            ->first();

        $roleName = $this->catalog->normalizeRoleName($payload['role_name'] ?? null);
        $permissionInput = $this->catalog->resolvePermissionInput($request->all(), $roleName);
        if ($permissionInput === null) {
            return $this->invalidPermissionsResponse();
        }

        $storeIds = $this->access->resolveTargetStoreIds($request, $store);
        if (empty($storeIds)) {
            return $this->access->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang ap dung hop le.', 404, true, true);
        }
        if (!$this->canManageAllStores((int) $actor->id, $storeIds)) {
            return $this->access->errorResponse('FORBIDDEN_STORE_ACCESS', 'Ban khong co quyen gan nhan vien vao mot hoac nhieu cua hang da chon.', 403, true, true);
        }

        if ($existingUser) {
            $storesToAssign = [];
            foreach ($storeIds as $storeId) {
                $status = $this->staffBindingStatus((int) $existingUser->id, $storeId);
                if ($status === null) {
                    $storesToAssign[] = $storeId;
                }
            }

            if (empty($storesToAssign)) {
                return $this->access->errorResponse('STAFF_ALREADY_ASSIGNED', 'Tai khoan nay da la nhan vien cua tat ca cac cua hang da chon.', 409, true, true);
            }

            DB::transaction(function () use ($existingUser, $roleName, $storesToAssign, $permissionInput, $payload) {
                if (empty($existingUser->full_name) || $existingUser->name === 'system') {
                    $existingUser->update([
                        'name' => $existingUser->name ?: $payload['full_name'],
                        'full_name' => $existingUser->full_name ?: $payload['full_name'],
                    ]);
                }

                foreach ($storesToAssign as $storeId) {
                    $this->upsertStaffBinding((int) $existingUser->id, $roleName, $storeId, 'active');
                    $this->syncStaffPermissions((int) $existingUser->id, $storeId, $permissionInput['ids']);
                }
            });

            return $this->success(
                $this->formatStaff($existingUser, $roleName, $permissionInput['ids'], $this->getStaffStoreBindings((int) $existingUser->id)),
                200
            );
        }

        $validator = Validator::make($payload, [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'role_name' => ['nullable', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            return $this->access->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        if (!$this->staffTablesReady()) {
            return $this->access->errorResponse('PERMISSION_NOT_SUPPORTED', 'Bang du lieu phan quyen chua san sang.', 422, true, true);
        }

        $staff = DB::transaction(function () use ($payload, $roleName, $storeIds, $permissionInput) {
            $staff = User::query()->create([
                'name' => $payload['full_name'],
                'full_name' => $payload['full_name'],
                'email' => $payload['email'],
                'phone' => $payload['phone'],
                'role' => 'user',
                'password' => $payload['password'],
            ]);

            foreach ($storeIds as $storeId) {
                $this->upsertStaffBinding((int) $staff->id, $roleName, $storeId);
                $this->syncStaffPermissions((int) $staff->id, $storeId, $permissionInput['ids']);
            }

            return $staff;
        });

        return $this->success(
            $this->formatStaff($staff, $roleName, $permissionInput['ids'], $this->getStaffStoreBindings((int) $staff->id)),
            201
        );
    }

    public function staffList(Request $request): JsonResponse
    {
        [$user, $store, $error] = $this->access->resolveStoreContext($request);
        if ($error) {
            return $error;
        }

        $bindingSelect = [
            'user_role_bindings.user_id',
            'roles.name as role_name',
        ];
        if (Schema::hasColumn('user_role_bindings', 'status')) {
            $bindingSelect[] = 'user_role_bindings.status as binding_status';
        }

        $bindings = UserRoleBinding::query()
            ->join('roles', 'roles.id', '=', 'user_role_bindings.role_id')
            ->where('user_role_bindings.entity_type', 'store')
            ->where('user_role_bindings.entity_id', (string) $store->id)
            ->whereIn('roles.name', StorePermissionCatalog::STAFF_ROLES)
            ->select($bindingSelect)
            ->orderByDesc('user_role_bindings.id')
            ->get();

        $userIds = $bindings->pluck('user_id')->unique()->values()->all();
        $users = User::query()
            ->whereIn('id', $userIds)
            ->get($this->userListColumns())
            ->keyBy('id');

        $permissionsByUser = $this->permissionIdsByUser((string) $store->id, $userIds);

        // Fetch all store assignments for these user IDs in a single query
        $allStoreBindings = [];
        if (!empty($userIds) && Schema::hasTable('user_role_bindings') && Schema::hasTable('stores') && Schema::hasTable('roles')) {
            $allStoreBindings = DB::table('user_role_bindings as urb')
                ->join('roles as r', 'r.id', '=', 'urb.role_id')
                ->join('stores as s', 's.id', '=', 'urb.entity_id')
                ->whereIn('urb.user_id', $userIds)
                ->where('urb.entity_type', 'store')
                ->whereIn('r.name', StorePermissionCatalog::STAFF_ROLES)
                ->select('urb.user_id', 's.id as store_id', 's.name as store_name', 'urb.status')
                ->get()
                ->groupBy('user_id')
                ->all();
        }

        $items = [];

        foreach ($bindings as $binding) {
            $staff = $users->get($binding->user_id);
            if (!$staff) {
                continue;
            }

            $userStoreBindings = isset($allStoreBindings[$binding->user_id]) 
                ? $allStoreBindings[$binding->user_id]->all() 
                : [];

            $items[] = $this->formatStaff(
                $staff,
                (string) $binding->role_name,
                $permissionsByUser[(int) $binding->user_id] ?? [],
                $userStoreBindings,
                (string) ($binding->binding_status ?? 'active')
            );
        }

        return $this->success([
            'store_id' => (string) $store->id,
            'items' => $items,
            'staff' => $items,
        ], 200, ['total' => count($items)]);
    }

    public function updateStaffPermissions(Request $request, int $id): JsonResponse
    {
        [$actor, $store, $error] = $this->access->resolveStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!$this->access->canManageStaff((int) $actor->id, (string) $store->id)) {
            return $this->access->errorResponse('FORBIDDEN_ROLE', 'Ban khong co quyen cap nhat quyen nhan vien.', 403, true, true);
        }

        $staff = User::query()->find($id);
        $currentRoleName = $this->staffBindingRole($id, (string) $store->id);
        $currentStatus = $this->staffBindingStatus($id, (string) $store->id);
        if (!$staff || $currentRoleName === null) {
            return $this->access->errorResponse('STAFF_NOT_FOUND', 'Nhan vien khong thuoc cua hang hien tai.', 404, true, true);
        }

        $previousPermissionIds = $this->permissionIdsForUserStore($id, (string) $store->id);
        $roleName = $this->catalog->normalizeRoleName($request->input('role_name', $request->input('role', $currentRoleName)));
        $permissionInput = $this->catalog->resolvePermissionInput($request->all(), $roleName);
        if ($permissionInput === null) {
            return $this->invalidPermissionsResponse();
        }

        $basicPayload = $this->normalizeStaffPayload($request, false);
        $validator = Validator::make($basicPayload, [
            'full_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($staff->id)],
        ]);

        if ($validator->fails()) {
            return $this->access->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 422, true, true, $validator->errors()->toArray());
        }

        DB::transaction(function () use ($staff, $basicPayload, $roleName, $store, $permissionInput, $currentStatus) {
            $updates = array_filter([
                'name' => $basicPayload['full_name'] ?? null,
                'full_name' => $basicPayload['full_name'] ?? null,
                'phone' => $basicPayload['phone'] ?? null,
                'email' => $basicPayload['email'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            if (!empty($updates)) {
                $staff->forceFill($updates)->save();
            }

            $this->upsertStaffBinding((int) $staff->id, $roleName, (string) $store->id, $currentStatus ?? 'active');
            $this->syncStaffPermissions((int) $staff->id, (string) $store->id, $permissionInput['ids']);
        });

        $staff->refresh();

        if ($this->staffAccessChanged($currentRoleName, $previousPermissionIds, $roleName, $permissionInput['ids'])) {
            event(new StoreStaffPermissionsChanged(
                (int) $staff->id,
                (string) $store->id,
                (int) $actor->id,
                $roleName,
                $this->catalog->codesForIds($permissionInput['ids']),
            ));
        }

        return $this->success($this->formatStaff($staff, $roleName, $permissionInput['ids'], $this->getStaffStoreBindings((int) $staff->id)));
    }

    public function deleteStaff(Request $request, int $id): JsonResponse
    {
        [$actor, $store, $error] = $this->access->resolveStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!$this->access->canManageStaff((int) $actor->id, (string) $store->id)) {
            return $this->access->errorResponse('FORBIDDEN_ROLE', 'Ban khong co quyen xoa nhan vien khoi cua hang.', 403, true, true);
        }

        if (!$this->staffTablesReady()) {
            return $this->access->errorResponse('PERMISSION_NOT_SUPPORTED', 'Bang du lieu phan quyen chua san sang.', 422, true, true);
        }

        $staff = User::query()->find($id);
        $binding = UserRoleBinding::query()
            ->join('roles', 'roles.id', '=', 'user_role_bindings.role_id')
            ->where('user_role_bindings.user_id', $id)
            ->where('user_role_bindings.entity_type', 'store')
            ->where('user_role_bindings.entity_id', (string) $store->id)
            ->whereIn('roles.name', StorePermissionCatalog::STAFF_ROLES)
            ->select('user_role_bindings.id', 'roles.name as role_name')
            ->first();

        if (!$staff || !$binding) {
            return $this->access->errorResponse('STAFF_NOT_FOUND', 'Nhan vien khong thuoc cua hang hien tai.', 404, true, true);
        }

        $currentRoleName = (string) $binding->role_name;
        $bindingId = (int) $binding->id;

        DB::transaction(function () use ($id, $store, $bindingId) {
            $storeId = (string) $store->id;

            StoreStaffPermission::query()
                ->where('user_id', $id)
                ->where('store_id', $storeId)
                ->delete();

            UserRoleBinding::query()
                ->where('user_id', $id)
                ->where('entity_type', 'store')
                ->where('entity_id', $storeId)
                ->whereIn('role_id', Role::query()->whereIn('name', StorePermissionCatalog::STAFF_ROLES)->pluck('id')->all())
                ->delete();

            $this->markStoreInvitationNotificationRead($bindingId);
        });

        event(new StoreStaffPermissionsChanged(
            $id,
            (string) $store->id,
            (int) $actor->id,
            $currentRoleName,
            []
        ));

        $this->notificationService->notifyStoreAccountRemoved($staff, $store);

        return $this->success([
            'deleted' => true,
            'user_id' => (string) $id,
            'store_id' => (string) $store->id,
        ]);
    }

    public function searchUsers(Request $request): JsonResponse
    {
        [$actor, $store, $error] = $this->access->resolveStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!$this->access->canManageStaff((int) $actor->id, (string) $store->id)) {
            return $this->access->errorResponse('FORBIDDEN_ROLE', 'Ban khong co quyen quan ly nhan vien cua cua hang nay.', 403, true, true);
        }

        $validator = Validator::make($request->query(), [
            'phone' => ['required', 'string', 'max:30'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        if ($validator->fails()) {
            return $this->access->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 400, true, true, $validator->errors()->toArray());
        }

        $phone = trim((string) $request->query('phone'));
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return $this->access->errorResponse('VALIDATION_ERROR', 'So dien thoai khong hop le.', 400, true, true);
        }

        $users = User::query()
            ->select($this->searchUserColumns())
            ->when(Schema::hasColumn('users', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->when(Schema::hasColumn('users', 'status'), fn ($query) => $query->where(fn ($builder) => $builder->whereNull('status')->orWhere('status', 'active')))
            ->where(fn ($query) => $query->where('phone', 'like', '%' . $digits . '%')->orWhere('phone', 'like', '%' . $phone . '%'))
            ->orderByDesc('id')
            ->limit(max(1, min((int) $request->query('limit', 10), 10)))
            ->get()
            ->map(fn (User $user) => $this->formatLookupUser($user))
            ->values()
            ->all();

        return $this->success(['items' => $users, 'users' => $users], 200, ['total' => count($users)]);
    }

    public function assignStaff(Request $request): JsonResponse
    {
        [$actor, $store, $error] = $this->access->resolveStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!$this->access->canManageStaff((int) $actor->id, (string) $store->id)) {
            return $this->access->errorResponse('FORBIDDEN_ROLE', 'Ban khong co quyen quan ly nhan vien cua cua hang nay.', 403, true, true);
        }

        $userId = (int) $request->input('user_id', $request->input('userId'));
        $roleName = $this->catalog->normalizeRoleName($request->input('role', $request->input('role_name')));
        $validator = Validator::make(['user_id' => $userId], [
            'user_id' => ['required', 'integer', 'min:1', 'exists:users,id'],
        ]);

        if ($validator->fails()) {
            return $this->access->errorResponse('VALIDATION_ERROR', 'Du lieu dau vao khong hop le.', 400, true, true, $validator->errors()->toArray());
        }

        $permissionInput = $this->catalog->resolvePermissionInput($request->all(), $roleName);
        if ($permissionInput === null) {
            return $this->invalidPermissionsResponse();
        }

        $storeIds = $this->access->resolveTargetStoreIds($request, $store);
        if (empty($storeIds)) {
            return $this->access->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang ap dung hop le.', 404, true, true);
        }
        if (!$this->canManageAllStores((int) $actor->id, $storeIds)) {
            return $this->access->errorResponse('FORBIDDEN_STORE_ACCESS', 'Ban khong co quyen gan nhan vien vao mot hoac nhieu cua hang da chon.', 403, true, true);
        }

        $storesToAssign = [];
        foreach ($storeIds as $storeId) {
            $status = $this->staffBindingStatus($userId, $storeId);
            if ($status !== 'active' && $status !== 'pending') {
                $storesToAssign[] = $storeId;
            }
        }

        if (empty($storesToAssign)) {
            return $this->access->errorResponse('STAFF_ALREADY_ASSIGNED', 'Tai khoan nay da la nhan vien dang hoat dong hoac dang cho xac nhan tai tat ca cac cua hang da chon.', 409, true, true);
        }

        DB::transaction(function () use ($userId, $roleName, $storesToAssign, $permissionInput, $actor) {
            foreach ($storesToAssign as $storeId) {
                $binding = $this->upsertStaffBinding($userId, $roleName, $storeId, 'pending');
                $this->syncStaffPermissions($userId, $storeId, $permissionInput['ids']);
                $this->createStoreInvitationNotification(
                    $userId,
                    $storeId,
                    $roleName,
                    (int) $binding->id
                );
            }
        });

        $targetUser = User::query()->findOrFail($userId);

        return $this->success(
            $this->formatStaff($targetUser, $roleName, $permissionInput['ids'], $this->getStaffStoreBindings($userId)),
            201
        );
    }

    private function normalizeStaffPayload(Request $request, bool $includePassword = true): array
    {
        $payload = [
            'full_name' => trim((string) $request->input('full_name', $request->input('fullName', $request->input('name', '')))),
            'email' => strtolower(trim((string) $request->input('email', $request->input('Email', '')))),
            'phone' => trim((string) $request->input('phone', $request->input('phoneNumber', $request->input('phone_number', $request->input('username', ''))))),
            'role_name' => $request->input('role_name', $request->input('role')),
        ];

        if ($includePassword) {
            $payload['password'] = (string) $request->input('password', $request->input('pass', ''));
        }

        return $payload;
    }

    private function canManageAllStores(int $actorId, array $storeIds): bool
    {
        foreach ($storeIds as $storeId) {
            if (!$this->access->canManageStaff($actorId, (string) $storeId)) {
                return false;
            }
        }

        return true;
    }

    private function upsertStaffBinding(int $userId, string $roleName, string $storeId, string $status = 'active'): UserRoleBinding
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $roleName],
            ['description' => 'Store staff role: ' . $roleName]
        );

        $binding = UserRoleBinding::query()->firstOrNew([
            'user_id' => $userId,
            'entity_type' => 'store',
            'entity_id' => $storeId,
        ]);

        $binding->role_id = $role->id;
        if (Schema::hasColumn('user_role_bindings', 'status')) {
            $binding->status = $status;
        }
        $binding->save();

        return $binding;
    }

    private function syncStaffPermissions(int $userId, string $storeId, array $permissionIds): void
    {
        $rowsById = $this->catalog->permissionRowsByCode()->keyBy(fn ($row) => (int) $row->id);

        StoreStaffPermission::query()
            ->where('user_id', $userId)
            ->where('store_id', $storeId)
            ->delete();

        $now = now();
        $rows = [];
        foreach ($permissionIds as $permissionId) {
            $permission = $rowsById->get((int) $permissionId);
            if (!$permission) {
                continue;
            }

            $row = [
                'user_id' => $userId,
                'store_id' => $storeId,
                'permission_code' => (string) $permission->code,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('store_staff_permissions', 'permission_id')) {
                $row['permission_id'] = (int) $permission->id;
            }

            $rows[] = $row;
        }

        if (!empty($rows)) {
            StoreStaffPermission::query()->insert($rows);
        }
    }

    private function permissionIdsByUser(string $storeId, array $userIds): array
    {
        if (empty($userIds) || !Schema::hasTable('store_staff_permissions')) {
            return [];
        }

        $columns = ['user_id', 'permission_code'];
        if (Schema::hasColumn('store_staff_permissions', 'permission_id')) {
            $columns[] = 'permission_id';
        }

        $rows = StoreStaffPermission::query()
            ->where('store_id', $storeId)
            ->whereIn('user_id', $userIds)
            ->get($columns);

        $codeToId = $this->catalog->permissionRowsByCode()->map(fn ($row) => (int) $row->id);
        $result = [];
        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $permissionId = (int) (($row->permission_id ?? null) ?: ($codeToId->get(strtoupper((string) $row->permission_code)) ?? 0));
            if ($permissionId > 0) {
                $result[$userId][] = $permissionId;
            }
        }

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $result);
    }

    private function permissionIdsForUserStore(int $userId, string $storeId): array
    {
        return $this->permissionIdsByUser($storeId, [$userId])[$userId] ?? [];
    }

    private function staffAccessChanged(string $previousRoleName, array $previousPermissionIds, string $nextRoleName, array $nextPermissionIds): bool
    {
        $normalizeIds = fn (array $ids) => collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $previousRoleName !== $nextRoleName
            || $normalizeIds($previousPermissionIds) !== $normalizeIds($nextPermissionIds);
    }

    private function permissionRecords(array $permissionIds): array
    {
        $rowsById = $this->catalog->permissionRowsByCode()->keyBy(fn ($row) => (int) $row->id);

        return collect($permissionIds)
            ->map(fn ($id) => $rowsById->get((int) $id))
            ->filter()
            ->map(fn (Permission $permission) => [
                'id' => (int) $permission->id,
                'permission_id' => (int) $permission->id,
                'code' => (string) $permission->code,
                'name' => (string) $permission->name,
            ])
            ->values()
            ->all();
    }

    private function createStoreInvitationNotification(
        int $userId,
        string $storeId,
        string $roleName,
        int $bindingId
    ): void {
        if (!Schema::hasTable('notifications')) {
            return;
        }

        $store = \App\Models\Store::query()->where('id', $storeId)->first();
        $storeName = $store ? (string) $store->name : 'cửa hàng';
        $roleLabel = $roleName === 'store_manager' ? 'quản lý' : 'nhân viên';
        $title = 'Lời mời làm nhân viên cửa hàng';
        $body = sprintf('Cửa hàng %s muốn mời bạn tham gia làm %s.', $storeName, $roleLabel);
        $actionUrl = '/notifications?action=store_invite&inviteId='
            . rawurlencode((string) $bindingId)
            . '&storeName=' . rawurlencode($storeName)
            . '&role=' . rawurlencode($roleName);

        $payload = [
            'message' => $body,
        ];

        foreach ([
            'title' => $title,
            'body' => $body,
            'type' => 'store_invitation',
            'audience_scope' => 'user_private',
            'action_url' => $actionUrl,
            'user_id' => (string) $userId,
            'store_id' => (string) $storeId,
            'is_read' => false,
        ] as $column => $value) {
            if (Schema::hasColumn('notifications', $column)) {
                $payload[$column] = $value;
            }
        }

        $notification = Notification::query()->create($payload);

        event(new \App\Events\UserNotificationCreated($notification));
    }

    private function markStoreInvitationNotificationRead(int $bindingId): void
    {
        if (
            !Schema::hasTable('notifications') ||
            !Schema::hasColumn('notifications', 'is_read') ||
            !Schema::hasColumn('notifications', 'action_url')
        ) {
            return;
        }

        Notification::query()
            ->when(
                Schema::hasColumn('notifications', 'type'),
                fn ($query) => $query->where('type', 'store_invitation')
            )
            ->where(function ($query) use ($bindingId) {
                $query
                    ->where('action_url', 'like', '%inviteId=' . $bindingId . '&%')
                    ->orWhere('action_url', 'like', '%inviteId=' . $bindingId);
            })
            ->update(['is_read' => true]);
    }

    private function getStaffStoreBindings(int $userId): array
    {
        if (!Schema::hasTable('user_role_bindings') || !Schema::hasTable('stores') || !Schema::hasTable('roles')) {
            return [];
        }

        return DB::table('user_role_bindings as urb')
            ->join('roles as r', 'r.id', '=', 'urb.role_id')
            ->join('stores as s', 's.id', '=', 'urb.entity_id')
            ->where('urb.user_id', $userId)
            ->where('urb.entity_type', 'store')
            ->whereIn('r.name', StorePermissionCatalog::STAFF_ROLES)
            ->select('s.id as store_id', 's.name as store_name', 'urb.status')
            ->get()
            ->all();
    }

    private function formatStaff(User $staff, string $roleName, array $permissionIds, array $storeBindings, ?string $status = null): array
    {
        $permissionCodes = $this->catalog->codesForIds($permissionIds);
        $name = (string) ($staff->full_name ?: $staff->name);

        $storeIds = [];
        $storesInfo = [];
        foreach ($storeBindings as $binding) {
            $bindingArray = (array) $binding;
            $storeId = (string) ($bindingArray['store_id'] ?? $bindingArray['id'] ?? '');
            $storeName = (string) ($bindingArray['store_name'] ?? $bindingArray['name'] ?? '');
            $bindingStatus = (string) ($bindingArray['status'] ?? 'active');
            if ($storeId !== '') {
                $storeIds[] = $storeId;
                $storesInfo[] = [
                    'id' => $storeId,
                    'store_id' => $storeId,
                    'name' => $storeName,
                    'store_name' => $storeName,
                    'status' => $bindingStatus,
                ];
            }
        }

        return [
            'id' => (string) $staff->id,
            'user_id' => (string) $staff->id,
            'name' => $name,
            'full_name' => $name,
            'email' => (string) ($staff->email ?? ''),
            'phone' => (string) ($staff->phone ?? ''),
            'status' => $status ?: ($staff->status ?? 'active'),
            'role' => $roleName,
            'role_name' => $roleName,
            'store_ids' => array_values(array_unique($storeIds)),
            'stores' => $storesInfo,
            'permissions' => $permissionCodes,
            'permission_codes' => $permissionCodes,
            'permission_ids' => array_values($permissionIds),
            'created_at' => optional($staff->created_at)->toIso8601String(),
        ];
    }

    private function formatLookupUser(User $user): array
    {
        $name = (string) ($user->full_name ?: $user->name);
        $avatar = $user->avatar_url ?? $user->avatar ?? null;

        return [
            'id' => (string) $user->id,
            'user_id' => (string) $user->id,
            'name' => $name,
            'full_name' => $name,
            'phone' => (string) ($user->phone ?? ''),
            'email' => (string) ($user->email ?? ''),
            'avatar_url' => $avatar,
            'avatarUrl' => $avatar,
        ];
    }

    private function staffBindingRole(int $userId, string $storeId): ?string
    {
        $binding = UserRoleBinding::query()
            ->join('roles', 'roles.id', '=', 'user_role_bindings.role_id')
            ->where('user_role_bindings.user_id', $userId)
            ->where('user_role_bindings.entity_type', 'store')
            ->where('user_role_bindings.entity_id', $storeId)
            ->whereIn('roles.name', StorePermissionCatalog::STAFF_ROLES)
            ->select('roles.name as role_name')
            ->first();

        return $binding ? (string) $binding->role_name : null;
    }

    private function staffBindingStatus(int $userId, string $storeId): ?string
    {
        if (!Schema::hasColumn('user_role_bindings', 'status')) {
            return 'active';
        }

        $binding = UserRoleBinding::query()
            ->join('roles', 'roles.id', '=', 'user_role_bindings.role_id')
            ->where('user_role_bindings.user_id', $userId)
            ->where('user_role_bindings.entity_type', 'store')
            ->where('user_role_bindings.entity_id', $storeId)
            ->whereIn('roles.name', StorePermissionCatalog::STAFF_ROLES)
            ->select('user_role_bindings.status')
            ->first();

        return $binding ? (string) $binding->status : null;
    }

    private function staffTablesReady(): bool
    {
        return Schema::hasTable('roles')
            && Schema::hasTable('user_role_bindings')
            && Schema::hasTable('permissions')
            && Schema::hasTable('store_staff_permissions');
    }

    private function userListColumns(): array
    {
        $columns = ['id', 'name', 'full_name', 'email', 'phone', 'created_at'];
        if (Schema::hasColumn('users', 'status')) {
            $columns[] = 'status';
        }

        return $columns;
    }

    private function searchUserColumns(): array
    {
        $columns = ['id', 'name', 'full_name', 'phone', 'email', 'avatar'];
        if (Schema::hasColumn('users', 'avatar_url')) {
            $columns[] = 'avatar_url';
        }

        return $columns;
    }

    private function systemEmailForPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: 'unknown';

        return 'staff-' . $digits . '@staff.ecoluck.local';
    }

    private function invalidPermissionsResponse(): JsonResponse
    {
        return $this->access->errorResponse('VALIDATION_ERROR', 'Danh sach quyen khong hop le.', 422, true, true, [
            'permissions' => ['permission_ids hoac permission_codes co gia tri khong ton tai.'],
        ]);
    }

    private function success(array $data, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => array_merge([
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ], $meta),
            'errors' => [],
        ], $status);
    }
}

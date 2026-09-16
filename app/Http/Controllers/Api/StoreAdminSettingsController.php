<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\appSetting;
use App\Models\Store;
use App\Models\StoreHour;
use App\Models\StoreOperatingHistory;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\StoreAdmin\StoreAdminStaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StoreAdminSettingsController extends Controller
{
    public function __construct(private readonly StoreAdminStaffAccess $access)
    {
    }

    public function slaConfig(Request $request): JsonResponse
    {
        [$user, $store, $error] = $this->resolveStoreAdminStoreContext($request);
        if ($error) {
            return $error;
        }

        $customMinutes = $this->resolveStoreCustomSlaMinutes($store);
        $defaultMinutes = $this->resolveDefaultSlaMinutes();
        $slaMinutes = $customMinutes ?? $defaultMinutes;

        return response()->json([
            'data' => [
                'store_id' => (string) $store->id,
                'sla_timeout_minutes' => $slaMinutes,
                'custom_sla_minutes' => $customMinutes,
                'default_sla_minutes' => $defaultMinutes,
                'description' => 'Xac nhan don trong vong ' . $slaMinutes . ' phut.',
                'read_only' => false,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function updateSlaConfig(Request $request): JsonResponse
    {
        [$user, $store, $error] = $this->resolveStoreAdminStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasColumn('stores', 'custom_sla_minutes')) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'SLA_NOT_SUPPORTED',
                    'message' => 'He thong chua ho tro cau hinh SLA rieng cho cua hang.',
                ]],
            ], 422);
        }

        $validated = $request->validate([
            'sla_timeout_minutes' => ['required', 'integer', 'min:1', 'max:120'],
        ]);

        $store->custom_sla_minutes = (int) $validated['sla_timeout_minutes'];
        $store->save();
        $store->refresh();

        $customMinutes = $this->resolveStoreCustomSlaMinutes($store);
        $defaultMinutes = $this->resolveDefaultSlaMinutes();
        $slaMinutes = $customMinutes ?? $defaultMinutes;

        return response()->json([
            'data' => [
                'store_id' => (string) $store->id,
                'sla_timeout_minutes' => $slaMinutes,
                'custom_sla_minutes' => $customMinutes,
                'default_sla_minutes' => $defaultMinutes,
                'description' => 'Xac nhan don trong vong ' . $slaMinutes . ' phut.',
                'read_only' => false,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function slaTimeouts(Request $request): JsonResponse
    {
        [$user, $store, $error] = $this->resolveStoreAdminStoreContext($request);
        if ($error) {
            return $error;
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['page_size'] ?? 20);
        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        if (!Schema::hasTable('orders')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => 0,
                    'total_pages' => 0,
                    'filters' => [
                        'from' => $from,
                        'to' => $to,
                    ],
                ],
                'errors' => [],
            ]);
        }

        $query = DB::table('orders as o')
            ->where('o.store_id', $store->id);

        if (Schema::hasColumn('orders', 'status')) {
            $query->whereRaw('LOWER(o.status) = ?', ['timeout']);
        }

        $dateColumn = Schema::hasColumn('orders', 'timeout_at') ? 'o.timeout_at' : 'o.created_at';

        if ($from) {
            $query->whereDate($dateColumn, '>=', $from);
        }

        if ($to) {
            $query->whereDate($dateColumn, '<=', $to);
        }

        $total = (int) (clone $query)->count('o.id');
        $offset = ($page - 1) * $pageSize;

        $rows = $query
            ->orderByDesc($dateColumn)
            ->offset($offset)
            ->limit($pageSize)
            ->select([
                'o.id',
                DB::raw(Schema::hasColumn('orders', 'order_code') ? 'o.order_code' : 'NULL as order_code'),
                'o.store_id',
                DB::raw(Schema::hasColumn('orders', 'status') ? 'o.status' : "'timeout' as status"),
                DB::raw(Schema::hasColumn('orders', 'sla_timeout_minutes') ? 'o.sla_timeout_minutes' : 'NULL as sla_timeout_minutes'),
                DB::raw(Schema::hasColumn('orders', 'timeout_at') ? 'o.timeout_at' : 'NULL as timeout_at'),
                DB::raw(Schema::hasColumn('orders', 'cancel_reason') ? 'o.cancel_reason' : 'NULL as cancel_reason'),
                DB::raw(Schema::hasColumn('orders', 'total_tickets_used') ? 'o.total_tickets_used' : 'NULL as total_tickets_used'),
                DB::raw(Schema::hasColumn('orders', 'final_amount') ? 'o.final_amount' : (Schema::hasColumn('orders', 'total_amount') ? 'o.total_amount' : (Schema::hasColumn('orders', 'total') ? 'o.total' : 'NULL')) . ' as amount'),
                'o.created_at',
            ])
            ->get();

        $orderIds = $rows->pluck('id')->filter()->values()->all();
        $historyMap = [];

        if (!empty($orderIds) && Schema::hasTable('order_status_history')) {
            $historyRows = DB::table('order_status_history')
                ->whereIn('order_id', $orderIds)
                ->whereRaw('LOWER(new_status) = ?', ['timeout'])
                ->orderByDesc('created_at')
                ->get(['order_id', 'old_status', 'new_status', 'reason', 'changed_by_role', 'created_at']);

            foreach ($historyRows as $row) {
                $oid = (string) $row->order_id;
                if (isset($historyMap[$oid])) {
                    continue;
                }

                $historyMap[$oid] = [
                    'from_status' => $row->old_status,
                    'to_status' => $row->new_status,
                    'reason' => $row->reason,
                    'changed_by_role' => $row->changed_by_role,
                    'changed_at' => optional($row->created_at ? now()->parse($row->created_at) : null)?->toIso8601String(),
                ];
            }
        }

        $items = $rows->map(function ($row) use ($historyMap) {
            $amount = property_exists($row, 'amount') ? $row->amount : null;

            return [
                'id' => (string) $row->id,
                'order_code' => $row->order_code,
                'store_id' => (string) $row->store_id,
                'status' => (string) $row->status,
                'sla_timeout_minutes' => $row->sla_timeout_minutes !== null ? (int) $row->sla_timeout_minutes : null,
                'timeout_at' => $row->timeout_at ? now()->parse($row->timeout_at)->toIso8601String() : null,
                'cancel_reason' => $row->cancel_reason,
                'total_tickets_used' => $row->total_tickets_used !== null ? (int) $row->total_tickets_used : null,
                'amount' => $amount !== null ? (float) $amount : null,
                'created_at' => $row->created_at ? now()->parse($row->created_at)->toIso8601String() : null,
                'status_history' => $historyMap[(string) $row->id] ?? null,
            ];
        })->values()->all();

        $totalPages = (int) ceil($total / max(1, $pageSize));

        return response()->json([
            'data' => $items,
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
                'filters' => [
                    'from' => $from,
                    'to' => $to,
                ],
            ],
            'errors' => [],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        [$user, $store, $error] = $this->resolveStoreAdminContext($request);
        if ($error) {
            return $error;
        }

        $resolved = $this->resolveSettings($user->id);

        return response()->json([
            'data' => [
                'settings' => $this->serializeSettings($resolved),
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => $store !== null,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        [$user, $store, $error] = $this->resolveStoreAdminContext($request);
        if ($error) {
            return $error;
        }

        // 1. Sửa đoạn Validate bằng Dot Notation
        $validated = $request->validate([
            'settings.notifications.notif_order' => ['sometimes', 'boolean'],
            'settings.notifications.notif_via_zalo_oa' => ['sometimes', 'boolean'],
            'settings.notifications.notif_via_zalo_miniapp' => ['sometimes', 'boolean'],
            'settings.language' => ['sometimes', 'string', 'max:10'],
            'settings.theme' => ['sometimes', 'string', 'in:light,dark'],
            'settings.hide_review_banner' => ['sometimes', 'boolean'],
        ]);

        // 2. Extract giá trị để map vào Payload phẳng của DB
        $payload = [];
        $input_settings = $request->input('settings', []);
        $input_notifications = $request->input('settings.notifications', []);

        // Duyệt mapping các trường root của settings
        foreach (['language', 'theme', 'hide_review_banner'] as $field) {
            if (array_key_exists($field, $input_settings)) {
                $payload[$field] = $input_settings[$field];
            }
        }

        // Duyệt mapping các trường của notifications
        foreach (['notif_order', 'notif_via_zalo_oa', 'notif_via_zalo_miniapp'] as $field) {
            if (array_key_exists($field, $input_notifications)) {
                $payload[$field] = $input_notifications[$field];
            }
        }

        $permissionError = $this->authorizeSettingsUpdate($user, $store, $input_settings, $input_notifications);
        if ($permissionError) {
            return $permissionError;
        }

        // 3. Update DB
        if (!empty($payload)) {
            UserSetting::query()->updateOrCreate(
                ['user_id' => $user->id],
                $payload
            );
        }

        $resolved = $this->resolveSettings($user->id);

        return response()->json([
            'data' => [
                'settings' => $this->serializeSettings($resolved),
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => $store !== null,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function operatingPanel(Request $request): JsonResponse
    {
        [$user, $store, $error] = $this->resolveStoreAdminStoreContext($request);
        if ($error) {
            return $error;
        }

        $history = $this->queryOperatingHistory($store->id, 20, 1);

        return response()->json([
            'data' => [
                'store' => $this->serializeOperatingStore($store),
                'history' => $history['items'],
                'quick_actions' => [
                    ['code' => 'overloaded', 'label' => 'Qua tai'],
                    ['code' => 'out_of_stock', 'label' => 'Het nguyen lieu'],
                    ['code' => 'technical_issue', 'label' => 'Su co ky thuat'],
                    ['code' => 'auto_reopen', 'label' => 'Hen gio mo'],
                    ['code' => 'resume', 'label' => 'Mo lai ngay'],
                ],
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
                'history_total' => $history['total'],
                'history_page' => 1,
            ],
            'errors' => [],
        ]);
    }

    public function updateOperatingPanelState(Request $request): JsonResponse
    {
        [$user, $store, $error] = $this->resolveStoreAdminStoreContext($request);
        if ($error) {
            return $error;
        }

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:manual_toggle,overloaded,out_of_stock,technical_issue,auto_reopen,resume'],
            'is_open' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
            'reopen_time' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        $action = (string) $validated['action'];
        $newIsOpen = (bool) $store->is_open;
        $reason = (string) ($validated['note'] ?? '');
        $duration = isset($validated['duration_minutes']) ? (int) $validated['duration_minutes'] : null;

        if ($action === 'manual_toggle') {
            if (!array_key_exists('is_open', $validated)) {
                return response()->json([
                    'data' => null,
                    'meta' => [
                        'authenticated' => true,
                        'has_store' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [[
                        'code' => 'MISSING_IS_OPEN',
                        'message' => 'is_open la bat buoc khi action = manual_toggle.',
                    ]],
                ], 422);
            }

            $newIsOpen = (bool) $validated['is_open'];
            if ($reason === '') {
                $reason = $newIsOpen ? 'manual_resume' : 'manual_pause';
            }
        }

        if ($action === 'resume') {
            $newIsOpen = true;
            if ($reason === '') {
                $reason = 'resume';
            }
        }

        if (in_array($action, ['overloaded', 'out_of_stock', 'technical_issue', 'auto_reopen'], true)) {
            $newIsOpen = false;
            if ($reason === '') {
                $reason = $action;
            }
        }

        $store->is_open = $newIsOpen;

        if (Schema::hasColumn('stores', 'temporary_close_reason')) {
            $store->temporary_close_reason = $newIsOpen ? null : $reason;
        }

        if (Schema::hasColumn('stores', 'out_of_stock_reason') && $action === 'out_of_stock') {
            $store->out_of_stock_reason = $reason;
        }

        if ($action === 'auto_reopen' && Schema::hasColumn('stores', 'open_time') && !empty($validated['reopen_time'])) {
            $store->open_time = (string) $validated['reopen_time'] . ':00';
        }

        $store->save();
        $store->refresh();

        $historyItem = $this->storeOperatingHistory(
            $store->id,
            $user->id,
            $action,
            $newIsOpen,
            $reason,
            [
                'duration_minutes' => $duration,
                'reopen_time' => $validated['reopen_time'] ?? null,
            ],
        );

        return response()->json([
            'data' => [
                'store' => $this->serializeOperatingStore($store),
                'history_item' => $historyItem,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function operatingPanelHistory(Request $request): JsonResponse
    {
        [$user, $store, $error] = $this->resolveStoreAdminStoreContext($request);
        if ($error) {
            return $error;
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['page_size'] ?? 20);
        $history = $this->queryOperatingHistory($store->id, $pageSize, $page);

        return response()->json([
            'data' => $history['items'],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $history['total'],
            ],
            'errors' => [],
        ]);
    }

    private function resolveStoreAdminStoreContext(Request $request): array
    {
        [$user, $store, $error] = $this->access->resolveStoreContext($request);

        return [$user, $store, $error];
    }

    private function serializeOperatingStore(Store $store): array
    {
        $todaySchedule = null;
        if (Schema::hasTable('store_hours')) {
            $today = (int) now()->dayOfWeek;
            $row = StoreHour::query()
                ->where('store_id', $store->id)
                ->where('day_of_week', $today)
                ->first();

            if ($row) {
                $todaySchedule = [
                    'day_of_week' => (int) $row->day_of_week,
                    'is_closed' => (bool) $row->is_closed,
                    'open_time' => $row->open_time,
                    'close_time' => $row->close_time,
                ];
            }
        }

        return [
            'id' => (string) $store->id,
            'name' => (string) ($store->name ?? ''),
            'is_open' => (bool) $store->is_open,
            'status' => (bool) $store->is_open ? 'mo' : 'dong',
            'address' => (string) ($store->address ?? ''),
            'open_time' => $store->open_time,
            'close_time' => $store->close_time,
            'today_schedule' => $todaySchedule,
        ];
    }

    private function storeOperatingHistory(
        string $storeId,
        string $changedBy,
        string $action,
        bool $isOpenAfter,
        string $reason,
        array $meta = []
    ): array {
        $labelMap = [
            'manual_toggle' => 'Cap nhat thu cong',
            'overloaded' => 'Qua tai',
            'out_of_stock' => 'Het nguyen lieu',
            'technical_issue' => 'Su co ky thuat',
            'auto_reopen' => 'Hen gio mo',
            'resume' => 'Mo lai ngay',
        ];

        $item = [
            'id' => null,
            'action_code' => $action,
            'action_label' => $labelMap[$action] ?? $action,
            'status_after' => $isOpenAfter ? 'mo' : 'dong',
            'reason' => $reason,
            'changed_by' => $changedBy,
            'changed_at' => now()->toIso8601String(),
            'meta' => $meta,
        ];

        if (!Schema::hasTable('store_operating_histories')) {
            return $item;
        }

        $row = StoreOperatingHistory::query()->create([
            'store_id' => $storeId,
            'action_code' => $item['action_code'],
            'action_label' => $item['action_label'],
            'status_after' => $item['status_after'],
            'reason' => $item['reason'],
            'changed_by' => $changedBy,
            'meta' => $meta,
        ]);

        return [
            'id' => (string) $row->id,
            'action_code' => (string) $row->action_code,
            'action_label' => (string) $row->action_label,
            'status_after' => (string) $row->status_after,
            'reason' => (string) ($row->reason ?? ''),
            'changed_by' => (string) ($row->changed_by ?? ''),
            'changed_at' => optional($row->created_at)?->toIso8601String(),
            'meta' => is_array($row->meta) ? $row->meta : [],
        ];
    }

    private function queryOperatingHistory(string $storeId, int $pageSize, int $page): array
    {
        if (!Schema::hasTable('store_operating_histories')) {
            return ['items' => [], 'total' => 0];
        }

        $baseQuery = StoreOperatingHistory::query()->where('store_id', $storeId);
        $total = (int) (clone $baseQuery)->count();
        $offset = max(0, ($page - 1) * $pageSize);

        $items = $baseQuery
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($pageSize)
            ->get()
            ->map(function (StoreOperatingHistory $row) {
                return [
                    'id' => (string) $row->id,
                    'action_code' => (string) $row->action_code,
                    'action_label' => (string) $row->action_label,
                    'status_after' => (string) $row->status_after,
                    'reason' => (string) ($row->reason ?? ''),
                    'changed_by' => (string) ($row->changed_by ?? ''),
                    'changed_at' => optional($row->created_at)?->toIso8601String(),
                    'meta' => is_array($row->meta) ? $row->meta : [],
                ];
            })
            ->values()
            ->all();

        return ['items' => $items, 'total' => $total];
    }

    private function resolveStoreAdminContext(Request $request): array
    {
        [$user, $store, $error] = $this->access->resolveStoreContext($request);

        return [$user, $store, $error];
    }

    private function authorizeSettingsUpdate(?User $user, ?Store $store, array $inputSettings, array $inputNotifications): ?JsonResponse
    {
        if (!$user || !$store) {
            return null;
        }

        if ($this->hasStoreAdminOverride($user, $store)) {
            return null;
        }

        $requiredPermissions = [];

        if (!empty($inputNotifications)) {
            $requiredPermissions['UPDATE_NOTIFICATION_SETTINGS'] = array_keys($inputNotifications);
        }

        if (array_key_exists('language', $inputSettings)) {
            $requiredPermissions['UPDATE_LANGUAGE_SETTINGS'][] = 'language';
        }

        if (array_key_exists('theme', $inputSettings)) {
            $requiredPermissions['UPDATE_SECURITY_SETTINGS'][] = 'theme';
        }

        if (array_key_exists('hide_review_banner', $inputSettings)) {
            $requiredPermissions['UPDATE_SECURITY_SETTINGS'][] = 'hide_review_banner';
        }

        foreach ($requiredPermissions as $permissionCode => $fields) {
            if ($this->access->hasAnyPermission((int) $user->id, (string) $store->id, [$permissionCode])) {
                continue;
            }

            return $this->access->errorResponse(
                'FORBIDDEN_PERMISSION',
                'Ban khong co quyen cap nhat cai dat nay trong cua hang hien tai.',
                403,
                true,
                true,
                [
                    'permission_code' => $permissionCode,
                    'fields' => array_values(array_unique($fields)),
                    'store_id' => (string) $store->id,
                ]
            );
        }

        return null;
    }

    private function hasStoreAdminOverride(User $user, Store $store): bool
    {
        if ((string) $store->owner_id === (string) $user->id) {
            return true;
        }

        return in_array((string) $user->role, ['admin', 'super_admin'], true);
    }

    private function resolveSettings($userId): array
    {
        $defaultSettings = [
            'notif_order' => true,
            'notif_via_zalo_oa' => true,
            'notif_via_zalo_miniapp' => true,
            'language' => 'vi',
            'theme' => 'light',
            'hide_review_banner' => false,
        ];

        $setting = UserSetting::query()->where('user_id', $userId)->first();

        return [
            'notif_order' => $setting?->notif_order ?? $defaultSettings['notif_order'],
            'notif_via_zalo_oa' => $setting?->notif_via_zalo_oa ?? $defaultSettings['notif_via_zalo_oa'],
            'notif_via_zalo_miniapp' => $setting?->notif_via_zalo_miniapp ?? $defaultSettings['notif_via_zalo_miniapp'],
            'language' => $setting?->language ?: $defaultSettings['language'],
            'theme' => $setting?->theme ?: $defaultSettings['theme'],
            'hide_review_banner' => $setting?->hide_review_banner ?? $defaultSettings['hide_review_banner'],
        ];
    }

    private function resolveStoreSlaTimeoutMinutes(Store $store): int
    {
        $customMinutes = $this->resolveStoreCustomSlaMinutes($store);
        if ($customMinutes !== null) {
            return $customMinutes;
        }

        return $this->resolveDefaultSlaMinutes();
    }

    private function resolveStoreCustomSlaMinutes(Store $store): ?int
    {
        if (!Schema::hasColumn('stores', 'custom_sla_minutes')) {
            return null;
        }

        $minutes = $store->custom_sla_minutes;
        if ($minutes === null) {
            return null;
        }

        $minutes = (int) $minutes;

        return $minutes > 0 ? $minutes : null;
    }

    private function resolveDefaultSlaMinutes(): int
    {
        try {
            $dbSeconds = (int) optional(appSetting::first())->order_sla_timeout_seconds;
            if ($dbSeconds > 0) {
                return max(1, (int) ceil($dbSeconds / 60));
            }
        } catch (\Throwable $e) {
            // Ignore and fall back to config.
        }

        $configuredSeconds = (int) config('ecoluck.order_sla_timeout_seconds', 0);
        if ($configuredSeconds > 0) {
            return max(1, (int) ceil($configuredSeconds / 60));
        }

        return max(1, (int) config('ecoluck.order_sla_timeout_minutes', 15));
    }

    private function serializeSettings(array $resolved): array
    {
        return [
            'notifications' => [
                'notif_order' => (bool) $resolved['notif_order'],
                'notif_via_zalo_oa' => (bool) $resolved['notif_via_zalo_oa'],
                'notif_via_zalo_miniapp' => (bool) $resolved['notif_via_zalo_miniapp'],
            ],
            'language' => (string) $resolved['language'],
            'interface_mode' => (string) $resolved['theme'],
            'theme' => (string) $resolved['theme'],
            'hide_review_banner' => (bool) $resolved['hide_review_banner'],
        ];
    }
}

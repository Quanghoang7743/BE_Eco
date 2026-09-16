<?php

namespace App\Http\Controllers\Api;

use App\Events\StoreStaffInvitationResponse;
use App\Models\Notification;
use App\Models\StoreAdminNotification;
use App\Models\StoreStaffPermission;
use App\Models\User;
use App\Models\UserRoleBinding;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ClientNotificationController extends ClientController
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    public function notifications(Request $request)
    {
        $user = $this->resolveNotificationUser($request);

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $query = Notification::query()
            ->where('user_id', (string) $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyClientAudienceScope($query);

        $rows = $query->limit(200)->get();

        return response()->json($rows->map(function (Notification $notification) {
            return $this->serializeNotificationPayload($notification);
        })->values());
    }

    public function markNotificationRead(string $id, Request $request)
    {
        $user = $this->resolveNotificationUser($request);

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $query = Notification::query()
            ->where('id', $id)
            ->where('user_id', (int) $user->id);

        $this->applyClientAudienceScope($query);

        $notification = $query->first();

        if (!$notification) {
            return response()->json(['message' => 'Không tìm thấy thông báo'], 404);
        }

        $this->notificationService->markAsRead($notification);
        $notification->refresh();

        return response()->json($this->serializeNotificationPayload($notification));
    }

    public function markAllNotificationsRead(Request $request)
    {
        $user = $this->resolveNotificationUser($request);

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $updateQuery = Notification::query()->where('user_id', (string) $user->id);
        $this->applyClientAudienceScope($updateQuery);
        $this->notificationService->markAllAsRead($updateQuery);

        return response()->json([
            'success' => true,
        ]);
    }

    public function acceptStoreInvitation(string $inviteId, Request $request)
    {
        $user = $this->resolveNotificationUser($request);

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $binding = $this->pendingStoreInvitationBinding($inviteId, $user);
        if (!$binding) {
            return response()->json(['message' => 'Không tìm thấy lời mời cửa hàng đang chờ xác nhận.'], 404);
        }

        $adminNotification = null;

        DB::transaction(function () use ($binding, $user, &$adminNotification): void {
            if (Schema::hasColumn('user_role_bindings', 'status')) {
                UserRoleBinding::query()
                    ->where('id', $binding->id)
                    ->where('user_id', $user->id)
                    ->update(['status' => 'active']);
            }

            $this->markStoreInvitationNotificationsRead((int) $user->id, (string) $binding->id);
            $adminNotification = $this->createStoreStaffInvitationResponseNotification($binding, $user, true);
        });

        try {
            $title = (string) ($adminNotification?->title ?? 'Nhân viên đồng ý tham gia');
            $message = (string) ($adminNotification?->message ?? '');
            $data = is_array($adminNotification?->data ?? null) ? $adminNotification->data : [];

            event(new StoreStaffInvitationResponse(
                (string) $binding->entity_id,
                (string) $binding->id,
                (string) $user->id,
                (string) ($user->name ?: $user->display_name ?: $user->phone ?: 'Nhân viên'),
                true,
                $adminNotification ? (string) $adminNotification->id : null,
                $title,
                $message,
                isset($data['action_url']) ? (string) $data['action_url'] : null
            ));
        } catch (\Throwable $e) {
            Log::warning('Unable to broadcast store staff invitation acceptance.', [
                'invite_id' => (string) $binding->id,
                'store_id' => (string) $binding->entity_id,
                'user_id' => (string) $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => 'active',
        ]);
    }

    public function declineStoreInvitation(string $inviteId, Request $request)
    {
        $user = $this->resolveNotificationUser($request);

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $binding = $this->pendingStoreInvitationBinding($inviteId, $user);
        if (!$binding) {
            return response()->json(['message' => 'Không tìm thấy lời mời cửa hàng đang chờ xác nhận.'], 404);
        }

        $adminNotification = null;

        DB::transaction(function () use ($binding, $user, &$adminNotification): void {
            StoreStaffPermission::query()
                ->where('user_id', $user->id)
                ->where('store_id', (string) $binding->entity_id)
                ->delete();

            UserRoleBinding::query()
                ->where('id', $binding->id)
                ->where('user_id', $user->id)
                ->delete();

            $this->markStoreInvitationNotificationsRead((int) $user->id, (string) $binding->id);
            $adminNotification = $this->createStoreStaffInvitationResponseNotification($binding, $user, false);
        });

        try {
            $title = (string) ($adminNotification?->title ?? 'Nhân viên từ chối lời mời');
            $message = (string) ($adminNotification?->message ?? '');
            $data = is_array($adminNotification?->data ?? null) ? $adminNotification->data : [];

            event(new StoreStaffInvitationResponse(
                (string) $binding->entity_id,
                (string) $binding->id,
                (string) $user->id,
                (string) ($user->name ?: $user->display_name ?: $user->phone ?: 'Nhân viên'),
                false,
                $adminNotification ? (string) $adminNotification->id : null,
                $title,
                $message,
                isset($data['action_url']) ? (string) $data['action_url'] : null
            ));
        } catch (\Throwable $e) {
            Log::warning('Unable to broadcast store staff invitation decline.', [
                'invite_id' => (string) $binding->id,
                'store_id' => (string) $binding->entity_id,
                'user_id' => (string) $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => 'declined',
        ]);
    }

    private function createStoreStaffInvitationResponseNotification(
        UserRoleBinding $binding,
        User $user,
        bool $accepted
    ): ?StoreAdminNotification {
        if (!Schema::hasTable('store_admin_notifications')) {
            return null;
        }

        $storeId = trim((string) $binding->entity_id);
        if ($storeId === '') {
            return null;
        }

        $userName = $this->resolveDisplayName($user);
        $type = $accepted ? 'staff_invitation_accepted' : 'staff_invitation_declined';
        $title = $accepted ? 'Nhân viên đồng ý tham gia' : 'Nhân viên từ chối lời mời';
        $message = sprintf(
            '%s đã %s lời mời tham gia cửa hàng.',
            $userName,
            $accepted ? 'đồng ý' : 'từ chối'
        );
        $actionUrl = '/admin_store/settings/employees';

        try {
            return StoreAdminNotification::query()->create([
                'id' => (string) Str::uuid(),
                'store_id' => $storeId,
                'order_id' => null,
                'order_code' => null,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => [
                    'event' => $type,
                    'invite_id' => (string) $binding->id,
                    'user_id' => (string) $user->id,
                    'user_name' => $userName,
                    'accepted' => $accepted,
                    'action_url' => $actionUrl,
                ],
                'is_read' => false,
                'read_at' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unable to persist store staff invitation response notification.', [
                'invite_id' => (string) $binding->id,
                'store_id' => $storeId,
                'user_id' => (string) $user->id,
                'accepted' => $accepted,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveDisplayName(User $user): string
    {
        foreach (['full_name', 'name', 'display_name', 'phone', 'email'] as $key) {
            $value = trim((string) ($user->{$key} ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'Nhân viên';
    }

    private function applyClientAudienceScope(Builder $query): void
    {
        $query->where(function (Builder $audienceQuery): void {
            $audienceQuery
                ->whereNull('audience_scope')
                ->orWhere('audience_scope', '')
                ->orWhere('audience_scope', 'user_private');
        });
    }

    private function resolveNotificationUser(Request $request): ?User
    {
        $requestUser = $request->user();
        if ($requestUser) {
            return $requestUser;
        }

        // Backward-compatible fallback for environments that have not enabled Sanctum yet.
        return $this->resolveApiUser($request);
    }

    private function pendingStoreInvitationBinding(string $inviteId, User $user): ?UserRoleBinding
    {
        if (!Schema::hasTable('user_role_bindings') || !Schema::hasTable('roles')) {
            return null;
        }

        $bindingId = $this->resolveStoreInvitationBindingId($inviteId, $user);
        if ($bindingId === null) {
            return null;
        }

        $query = $this->storeInvitationBindingQuery($bindingId, $user);

        if (Schema::hasColumn('user_role_bindings', 'status')) {
            $query->where('user_role_bindings.status', 'pending');
        }

        return $query->first();
    }

    private function storeInvitationBindingQuery(int $bindingId, User $user): Builder
    {
        return UserRoleBinding::query()
            ->join('roles', 'roles.id', '=', 'user_role_bindings.role_id')
            ->where('user_role_bindings.id', $bindingId)
            ->where('user_role_bindings.user_id', $user->id)
            ->where('user_role_bindings.entity_type', 'store')
            ->whereIn('roles.name', ['store_staff', 'store_manager'])
            ->select('user_role_bindings.*');
    }

    private function resolveStoreInvitationBindingId(string $inviteId, User $user): ?int
    {
        $id = trim($inviteId);
        if ($id === '' || !ctype_digit($id)) {
            return null;
        }

        $candidateId = (int) $id;
        if ($this->storeInvitationBindingQuery($candidateId, $user)->exists()) {
            return $candidateId;
        }

        if (!Schema::hasTable('notifications') || !Schema::hasColumn('notifications', 'action_url')) {
            return null;
        }

        $notification = Notification::query()
            ->where('id', $candidateId)
            ->where('user_id', (string) $user->id)
            ->when(Schema::hasColumn('notifications', 'type'), fn (Builder $query) => $query->where('type', 'store_invitation'))
            ->first();

        $actionUrl = (string) ($notification?->action_url ?? '');
        if ($actionUrl === '') {
            return null;
        }

        $queryString = (string) (parse_url($actionUrl, PHP_URL_QUERY) ?? '');
        parse_str($queryString, $params);
        $resolvedId = trim((string) ($params['inviteId'] ?? $params['invite_id'] ?? ''));

        if ($resolvedId === '' || !ctype_digit($resolvedId)) {
            return null;
        }

        $resolvedBindingId = (int) $resolvedId;
        return $this->storeInvitationBindingQuery($resolvedBindingId, $user)->exists()
            ? $resolvedBindingId
            : null;
    }

    private function markStoreInvitationNotificationsRead(int $userId, string $inviteId): void
    {
        if (!Schema::hasTable('notifications') || !Schema::hasColumn('notifications', 'is_read')) {
            return;
        }

        Notification::query()
            ->where('user_id', (string) $userId)
            ->when(Schema::hasColumn('notifications', 'type'), fn (Builder $query) => $query->where('type', 'store_invitation'))
            ->when(Schema::hasColumn('notifications', 'action_url'), function (Builder $query) use ($inviteId): void {
                $query->where(function (Builder $actionQuery) use ($inviteId): void {
                    $actionQuery
                        ->where('action_url', 'like', '%inviteId=' . $inviteId . '&%')
                        ->orWhere('action_url', 'like', '%inviteId=' . $inviteId);
                });
            })
            ->update([
                'is_read' => true,
                ...(Schema::hasColumn('notifications', 'read_at') ? ['read_at' => now()] : []),
            ]);
    }
}

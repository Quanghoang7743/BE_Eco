<?php

namespace App\Services;

use App\Events\Customer\NotificationCreatedEvent;
use App\Models\Notification;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

class NotificationService
{
    private const AUDIENCE_SCOPES = [
        'admin_shared',
        'admin_private',
        'user_private',
        'store_private',
    ];

    public function create(array $attributes): Notification
    {
        $payload = $this->normalizePayload($attributes);

        return Notification::query()->create($payload);
    }

    public function notifyStoreAccountRemoved(User $user, Store $store): void
    {
        if (!Schema::hasTable('notifications')) {
            return;
        }

        $userId = trim((string) $user->id);
        if ($userId === '') {
            return;
        }

        $storeId = trim((string) $store->id);
        $storeName = trim((string) ($store->name ?? 'cửa hàng'));
        if ($storeName === '') {
            $storeName = 'cửa hàng';
        }

        $title = 'Bạn đã bị gỡ khỏi cửa hàng';
        $body = sprintf(
            'Tài khoản của bạn đã bị gỡ khỏi cửa hàng %s. Bạn sẽ không còn quyền quản lý cửa hàng này.',
            $storeName
        );
        $actionUrl = '/notifications';

        try {
            $notification = $this->create([
                'audience_scope' => 'user_private',
                'user_id' => $userId,
                'store_id' => $storeId !== '' ? $storeId : null,
                'type' => 'store_account_removed',
                'title' => $title,
                'message' => $body,
                'body' => $body,
                'action_url' => $actionUrl,
                'is_read' => false,
            ]);

            event(new NotificationCreatedEvent($userId, [
                'notification_id' => (string) ($notification->id ?? ''),
                'id' => (string) ($notification->id ?? ''),
                'user_id' => $userId,
                'type' => 'store_account_removed',
                'title' => $title,
                'body' => $body,
                'message' => $body,
                'display' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'store' => [
                    'id' => $storeId,
                    'name' => $storeName,
                    'logo_url' => $store->logo_url ?? null,
                ],
                'deep_link' => $actionUrl,
                'action_url' => $actionUrl,
            ]));
        } catch (Throwable $e) {
            Log::warning('Failed to notify user after store account removal.', [
                'user_id' => $userId,
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function markAsRead(Notification $notification): void
    {
        if ((bool) $notification->is_read) {
            return;
        }

        $notification->forceFill([
            'is_read' => true,
            'read_at' => now(),
        ])->save();
    }

    public function markAsUnread(Notification $notification): void
    {
        if (!(bool) $notification->is_read && $notification->read_at === null) {
            return;
        }

        $notification->forceFill([
            'is_read' => false,
            'read_at' => null,
        ])->save();
    }

    public function markAllAsRead(Builder $query): int
    {
        return (clone $query)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    private function normalizePayload(array $attributes): array
    {
        $message = trim((string) ($attributes['message'] ?? ''));
        if ($message === '') {
            throw new InvalidArgumentException('Notification message is required.');
        }

        $userId = $this->toNullableString($attributes['user_id'] ?? null);
        $storeId = $this->toNullableString($attributes['store_id'] ?? null);

        $audienceScope = $this->resolveAudienceScope(
            $attributes['audience_scope'] ?? null,
            $userId,
            $storeId
        );

        if (in_array($audienceScope, ['admin_private', 'user_private'], true) && $userId === null) {
            throw new InvalidArgumentException('user_id is required for private user audiences.');
        }

        if ($audienceScope === 'store_private' && $storeId === null) {
            throw new InvalidArgumentException('store_id is required for store_private audience.');
        }

        if ($audienceScope === 'admin_shared') {
            $userId = null;
            $storeId = null;
        }

        $title = $this->toNullableString($attributes['title'] ?? null);
        $body = array_key_exists('body', $attributes)
            ? $this->toNullableString($attributes['body'])
            : null;

        if ($body === null) {
            $body = $message;
        }

        $rawOrderId = $attributes['order_id'] ?? null;
        $actionUrl = $this->toNullableString($attributes['action_url'] ?? null);

        if ($rawOrderId !== null && !ctype_digit((string) $rawOrderId) && strlen((string) $rawOrderId) === 36) {
            if ($actionUrl === null) {
                if (in_array($audienceScope, ['admin_shared', 'admin_private'], true)) {
                    $actionUrl = '/admin/orders/' . $rawOrderId;
                } else {
                    $actionUrl = '/pages/order-detail?id=' . $rawOrderId;
                }
            }
        }

        $payload = [
            'message' => $message,
            'title' => $title,
            'body' => $body,
            'type' => $this->toNullableString($attributes['type'] ?? null) ?? 'system',
            'audience_scope' => $audienceScope,
            'user_id' => $userId,
            'store_id' => $storeId,
            'order_id' => $this->normalizeOrderId($rawOrderId),
            'action_url' => $actionUrl,
            'is_read' => (bool) ($attributes['is_read'] ?? false),
            'read_at' => $attributes['read_at'] ?? null,
        ];

        if (array_key_exists('created_at', $attributes)) {
            $payload['created_at'] = $attributes['created_at'];
        }

        if (array_key_exists('updated_at', $attributes)) {
            $payload['updated_at'] = $attributes['updated_at'];
        }

        return $payload;
    }

    private function resolveAudienceScope(?string $audienceScope, ?string $userId, ?string $storeId): string
    {
        $resolved = strtolower(trim((string) $audienceScope));
        if ($resolved !== '') {
            if (!in_array($resolved, self::AUDIENCE_SCOPES, true)) {
                throw new InvalidArgumentException('Unsupported notification audience scope: ' . $resolved);
            }

            return $resolved;
        }

        if ($userId !== null) {
            return 'user_private';
        }

        if ($storeId !== null) {
            return 'store_private';
        }

        return 'admin_shared';
    }

    private function normalizeOrderId(mixed $orderId): ?int
    {
        if ($orderId === null) {
            return null;
        }

        if (is_int($orderId)) {
            return $orderId;
        }

        $value = trim((string) $orderId);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    private function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}

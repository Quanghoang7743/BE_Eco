<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\UserStorePreference;
use App\Models\Voucher;
use Illuminate\Support\Carbon;

class VoucherFollowerNotificationService
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    public function dispatchForVoucher(Voucher $voucher): void
    {
        $voucher->loadMissing('store');

        if (! $this->isEligibleVoucher($voucher) || ! $voucher->store) {
            return;
        }

        $storeName = trim((string) $voucher->store->name);
        $title = 'Voucher mới từ ' . ($storeName !== '' ? $storeName : 'cửa hàng bạn theo dõi');
        $body = sprintf(
            'Cửa hàng %s vừa có voucher %s. Mở cửa hàng để xem chi tiết và sử dụng khi thanh toán.',
            $storeName !== '' ? $storeName : 'bạn theo dõi',
            (string) $voucher->code
        );
        $actionUrl = '/store/' . $voucher->store_id;

        $followerUserIds = UserStorePreference::query()
            ->where('store_id', (string) $voucher->store_id)
            ->where('is_following', true)
            ->pluck('user_id');

        if ($followerUserIds->isEmpty()) {
            return;
        }

        // Pre-load user ids that already received this exact notification in one
        // query instead of running an exists() check per follower (fixes N+1).
        $existingUserIds = Notification::query()
            ->whereIn('user_id', $followerUserIds->map(fn ($id) => (string) $id)->all())
            ->where('type', 'promotion')
            ->where('title', $title)
            ->where('action_url', $actionUrl)
            ->where('body', $body)
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->flip();

        foreach ($followerUserIds as $userId) {
            if ($existingUserIds->has((string) $userId)) {
                continue;
            }

            $this->notificationService->create([
                'title' => $title,
                'body' => $body,
                'message' => $body,
                'type' => 'promotion',
                'audience_scope' => 'user_private',
                'user_id' => (string) $userId,
                'store_id' => (string) $voucher->store_id,
                'action_url' => $actionUrl,
            ]);
        }
    }

    private function isEligibleVoucher(Voucher $voucher): bool
    {
        if (! $voucher->store_id || ! $voucher->is_active) {
            return false;
        }

        $now = Carbon::now();

        if ($voucher->quantity <= $voucher->used) {
            return false;
        }

        if ($voucher->start_date === null || $voucher->end_date === null) {
            return false;
        }

        return $voucher->start_date <= $now && $voucher->end_date >= $now;
    }
}

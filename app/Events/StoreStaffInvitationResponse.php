<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StoreStaffInvitationResponse implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $storeId,
        public readonly string $inviteId,
        public readonly string $userId,
        public readonly string $userName,
        public readonly bool $accepted,
        public readonly ?string $notificationId = null,
        public readonly ?string $title = null,
        public readonly ?string $message = null,
        public readonly ?string $actionUrl = null,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('store.' . $this->storeId . '.staff');
    }

    public function broadcastAs(): string
    {
        return $this->accepted ? 'staff.invitation.accepted' : 'staff.invitation.declined';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => $this->accepted ? 'staff.invitation.accepted' : 'staff.invitation.declined',
            'notification_id' => $this->notificationId,
            'store_id' => $this->storeId,
            'invite_id' => $this->inviteId,
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'accepted' => $this->accepted,
            'title' => $this->title,
            'message' => $this->message,
            'action_url' => $this->actionUrl,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}

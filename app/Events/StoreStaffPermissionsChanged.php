<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StoreStaffPermissionsChanged implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $storeId,
        public readonly int $actorId,
        public readonly string $roleName,
        public readonly array $permissionCodes,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.' . $this->userId);
    }

    public function broadcastAs(): string
    {
        return 'store-staff.permissions.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'store-staff.permissions.changed',
            'user_id' => (string) $this->userId,
            'store_id' => $this->storeId,
            'actor_id' => (string) $this->actorId,
            'role_name' => $this->roleName,
            'permission_codes' => array_values($this->permissionCodes),
            'changed_at' => now()->toIso8601String(),
        ];
    }
}

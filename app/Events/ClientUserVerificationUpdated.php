<?php

namespace App\Events;

use App\Models\ClientUserVerification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClientUserVerificationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ClientUserVerification $verification,
        public readonly string $action,
        public readonly int $pendingCount,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('client-user-verifications'),
            new PrivateChannel('App.Models.User.' . (string) $this->verification->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'client.user.verification.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'client.user.verification.updated',
            'action' => $this->action,
            'pending_count' => $this->pendingCount,
            'verification' => [
                'id' => $this->verification->id,
                'user_id' => $this->verification->user_id,
                'full_name' => $this->verification->full_name,
                'id_number' => $this->verification->id_number,
                'birth_date' => optional($this->verification->birth_date)->toDateString(),
                'address' => $this->verification->address,
                'id_front_image' => $this->verification->id_front_image,
                'id_back_image' => $this->verification->id_back_image,
                'id_selfie_image' => $this->verification->id_selfie_image,
                'status' => $this->verification->status,
                'rejection_reason' => $this->verification->rejection_reason,
                'submitted_at' => optional($this->verification->submitted_at)->toISOString(),
                'reviewed_at' => optional($this->verification->reviewed_at)->toISOString(),
            ],
        ];
    }
}

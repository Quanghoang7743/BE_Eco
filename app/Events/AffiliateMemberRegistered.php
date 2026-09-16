<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AffiliateMemberRegistered implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public int $referrerId;

    public array $member;

    public function __construct(int $referrerId, array $member)
    {
        $this->referrerId = $referrerId;
        $this->member = $member;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('affiliate.members.' . $this->referrerId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'affiliate.member.registered';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'affiliate.member.registered',
            'referrer_id' => $this->referrerId,
            'member' => $this->member,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
<?php

namespace App\Events;

use App\Models\MoneyWalletAccount;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MoneyWalletUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $action,
        public readonly array $payload,
    ) {
    }

    public static function fromAccount(MoneyWalletAccount $account, string $action, array $extra = []): self
    {
        return new self($action, array_merge([
            'event' => 'money-wallet.updated',
            'action' => $action,
            'wallet_account_id' => (string) $account->id,
            'owner_type' => (string) $account->owner_type,
            'owner_id' => (string) $account->owner_id,
            'available_balance' => (float) $account->available_balance,
            'locked_balance' => (float) $account->locked_balance,
            'currency' => (string) $account->currency,
            'updated_at' => now()->toIso8601String(),
        ], $extra));
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.' . (string) ($this->payload['owner_id'] ?? ''));
    }

    public function broadcastAs(): string
    {
        return 'money-wallet.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}

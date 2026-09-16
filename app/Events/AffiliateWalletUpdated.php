<?php

namespace App\Events;

use App\Models\AffiliateWalletLog;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AffiliateWalletUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $action,
        public readonly array $payload,
    ) {
    }

    public static function fromLog(int $userId, string $action, array $summary, ?AffiliateWalletLog $log = null, array $extra = []): self
    {
        return new self($userId, $action, array_merge([
            'event' => 'affiliate.wallet.updated',
            'action' => $action,
            'user_id' => $userId,
            'summary' => $summary,
            'ledger_entry' => $log ? self::formatLog($log) : null,
            'updated_at' => now()->toIso8601String(),
        ], $extra));
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('affiliate.wallet.' . $this->userId);
    }

    public function broadcastAs(): string
    {
        return 'affiliate.wallet.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }

    private static function formatLog(AffiliateWalletLog $log): array
    {
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $sourceType = (string) ($metadata['source_type'] ?? '');

        return [
            'id' => (string) $log->id,
            'user_id' => (int) $log->user_id,
            'action_type' => (string) $log->action_type,
            'amount_change' => (float) $log->amount_change,
            'balance_before' => (float) $log->balance_before,
            'balance_after' => (float) $log->balance_after,
            'reference_id' => $log->reference_id,
            'reference_type' => $log->reference_type,
            'source_type' => $sourceType !== '' ? $sourceType : null,
            'source_id' => $metadata['order_id']
                ?? $metadata['order_item_id']
                ?? $metadata['source_assignment_id']
                ?? $log->reference_id,
            'source_label' => $metadata['source_label'] ?? null,
            'metadata' => $metadata ?: null,
            'created_at' => optional($log->created_at)->toIso8601String(),
        ];
    }
}

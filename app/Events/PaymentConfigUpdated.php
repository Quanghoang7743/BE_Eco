<?php

namespace App\Events;

use App\Models\appSetting;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentConfigUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    private array $paymentConfig;

    public function __construct(appSetting $setting)
    {
        $this->paymentConfig = [
            'vietqr_bank_id' => (string) ($setting->vietqr_bank_id ?? ''),
            'vietqr_account_no' => (string) ($setting->vietqr_account_no ?? ''),
            'vietqr_account_name' => (string) ($setting->vietqr_account_name ?? ''),
            'updated_at' => $setting->updated_at?->toIso8601String(),
        ];
    }

    public function broadcastOn(): Channel
    {
        return new Channel('public-settings-channel');
    }

    public function broadcastAs(): string
    {
        return 'PaymentConfigUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'PaymentConfigUpdated',
            ...$this->paymentConfig,
        ];
    }
}

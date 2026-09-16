<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserNotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Notification $notification
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.' . (string) $this->notification->getAttribute('user_id'));
    }

    public function broadcastAs(): string
    {
        return 'user.notification.created';
    }

    public function broadcastWith(): array
    {
        $createdAt = $this->notification->created_at
            ? $this->notification->created_at->toIso8601String()
            : now()->toIso8601String();

        $title = (string) ($this->notification->getAttribute('title') ?: 'Thông báo');
        $body = (string) ($this->notification->getAttribute('body') ?: $this->notification->getAttribute('message') ?: '');

        return [
            'event' => 'user.notification.created',
            'notification_id' => (string) $this->notification->id,
            'id' => (string) $this->notification->id,
            'user_id' => (string) $this->notification->getAttribute('user_id'),
            'type' => (string) ($this->notification->getAttribute('type') ?: 'system'),
            'title' => $title,
            'body' => $body,
            'message' => (string) ($this->notification->getAttribute('message') ?: $body),
            'action_url' => $this->notification->getAttribute('action_url'),
            'created_at' => $createdAt,
            'display' => [
                'title' => $title,
                'body' => $body,
            ],
        ];
    }
}

<?php

namespace App\Jobs;

use App\Events\DrawRoomUpdated;
use App\Events\RoomDrawResult;
use App\Http\Controllers\Api\ClientTicketController;
use App\Models\Room;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RunRoomDrawJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $roomId;

    public int $tries = 3;

    public function __construct(string $roomId)
    {
        $this->roomId = $roomId;
    }

    public function handle(ClientTicketController $controller): void
    {
        [$room, $drawPayload] = DB::transaction(function () use ($controller) {
            $lockedRoom = Room::query()->whereKey($this->roomId)->lockForUpdate()->first();

            if (! $lockedRoom) {
                return [null, null];
            }

            return $controller->persistRoomDrawResult($lockedRoom);
        });

        if (! $room instanceof Room) {
            return;
        }

        $freshRoom = $room->fresh();

        if (! $freshRoom) {
            return;
        }

        $serializedRoom = $controller->serializeDrawRoomPayload($freshRoom);
        event(new DrawRoomUpdated($serializedRoom));
        $controller->publishDrawRoomRealtimeUpdate($serializedRoom);

        if (is_array($drawPayload)) {
            $drawPayload['room'] = $serializedRoom;
            $drawPayload['drawResult'] = $serializedRoom['drawResult'] ?? null;
            event(new RoomDrawResult($drawPayload));
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RunRoomDrawJob failed.', [
            'room_id' => $this->roomId,
            'error' => $e->getMessage(),
        ]);
    }
}

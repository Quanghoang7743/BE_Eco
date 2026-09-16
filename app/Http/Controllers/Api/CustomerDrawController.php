<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\appSetting;
use App\Models\Room;
use App\Models\RoomSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

class CustomerDrawController extends Controller
{
    /**
     * GET /api/customer/draws/configuration
     * K18 - Lay cau hinh phong quay cho khach hang.
     */
    public function getConfig(): JsonResponse
    {
        $schedule = null;
        if (Schema::hasTable('room_schedules')) {
            $schedule = RoomSchedule::query()
                ->where('schedule_type', RoomSchedule::DAILY_ROOM_GENERATION)
                ->where('is_active', true)
                ->orderByDesc('updated_at')
                ->first();
        }

        if (!$schedule) {
            $schedule = RoomSchedule::getOrCreateDailyConfig();
        }

        $today = now('Asia/Ho_Chi_Minh')->toDateString();

        $todayRoom = null;
        if (Schema::hasTable('rooms')) {
            $todayRoom = Room::query()
                ->whereDate('room_date', $today)
                ->orderBy('room_number')
                ->first();
        }

        $ticketExpiryDays = appSetting::DEFAULT_TICKET_EXPIRY_DAYS;
        if (Schema::hasTable('app_settings') && Schema::hasColumn('app_settings', 'ticket_expiry_days')) {
            $appConfig = appSetting::query()->first();
            if ($appConfig) {
                $ticketExpiryDays = (int) $appConfig->ticket_expiry_days;
            }
        }

        $defaultPrizeDescription = $schedule->default_prize_description;
        if (!$defaultPrizeDescription && $todayRoom && $todayRoom->prize_description) {
            $defaultPrizeDescription = (string) $todayRoom->prize_description;
        }

        $defaultPrizeImageUrl = $schedule->default_prize_image_url;
        if (!$defaultPrizeImageUrl && $todayRoom && $todayRoom->prize_image_url) {
            $defaultPrizeImageUrl = (string) $todayRoom->prize_image_url;
        }

        $defaultPrizeValue = $schedule->default_prize_value;
        if ($defaultPrizeValue === null && $todayRoom && $todayRoom->prize_value !== null) {
            $defaultPrizeValue = (float) $todayRoom->prize_value;
        }

        return response()->json([
            'data' => [
                'schedule_type' => RoomSchedule::DAILY_ROOM_GENERATION,
                'is_active' => (bool) $schedule->is_active,
                'rooms_per_day' => (int) $schedule->rooms_per_day,
                'required_votes' => (int) $schedule->required_votes,
                'default_prize' => [
                    'type' => (string) $schedule->default_prize_type,
                    'value' => $defaultPrizeValue,
                    'description' => $defaultPrizeDescription,
                    'image_url' => $defaultPrizeImageUrl,
                ],
                'ticket_expiry_days' => $ticketExpiryDays,
                'effective_date' => $today,
            ],
            'meta' => [
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }
}

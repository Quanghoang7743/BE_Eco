<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestOrdersSeeder extends Seeder
{
    public function run(): void
    {
        $stores = DB::table('stores')->select('id')->limit(10)->pluck('id')->values()->all();
        $users = DB::table('users')->select('id')->limit(50)->pluck('id')->values()->all();

        $statuses = ['pending', 'confirmed', 'preparing', 'ready', 'delivered', 'cancelled'];
        $rows = [];
        $insertCount = 60;

        for ($i = 0; $i < $insertCount; $i++) {
            $status = $statuses[array_rand($statuses)];
            $baseTime = now()->subDays(rand(0, 15))->subMinutes(rand(0, 1440));
            $totalAmount = rand(50000, 350000);
            $discountAmount = rand(0, 30000);
            $finalAmount = max(0, $totalAmount - $discountAmount);

            $confirmedAt = null;
            $preparingAt = null;
            $readyAt = null;
            $deliveredAt = null;
            $cancelledAt = null;
            $cancelReason = null;

            if (in_array($status, ['confirmed', 'preparing', 'ready', 'delivered'], true)) {
                $confirmedAt = $baseTime->copy()->addMinutes(rand(1, 10));
            }

            if (in_array($status, ['preparing', 'ready', 'delivered'], true)) {
                $preparingAt = $confirmedAt ? $confirmedAt->copy()->addMinutes(rand(5, 20)) : $baseTime->copy()->addMinutes(rand(10, 30));
            }

            if (in_array($status, ['ready', 'delivered'], true)) {
                $readyAt = $preparingAt ? $preparingAt->copy()->addMinutes(rand(10, 25)) : $baseTime->copy()->addMinutes(rand(25, 45));
            }

            if ($status === 'delivered') {
                $deliveredAt = $readyAt ? $readyAt->copy()->addMinutes(rand(5, 30)) : $baseTime->copy()->addMinutes(rand(40, 80));
            }

            if ($status === 'cancelled') {
                $cancelledAt = $baseTime->copy()->addMinutes(rand(3, 20));
                $cancelReason = 'Khach hang huy don test';
            }

            $rows[] = [
                'id' => (string) Str::uuid(),
                'order_code' => 'TEST-' . now()->format('ymd') . '-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT) . '-' . strtoupper(Str::random(3)),
                'user_id' => !empty($users) ? (string) $users[array_rand($users)] : null,
                'store_id' => !empty($stores) ? (string) $stores[array_rand($stores)] : null,
                'status' => $status,
                'total_tickets_used' => rand(0, 12),
                'total_amount' => $totalAmount,
                'discount_amount' => $discountAmount,
                'final_amount' => $finalAmount,
                'delivery_address' => 'Dia chi test #' . ($i + 1),
                'delivery_location' => json_encode([
                    'lat' => 10.75 + (rand(-100, 100) / 1000),
                    'lng' => 106.66 + (rand(-100, 100) / 1000),
                ]),
                'delivery_phone' => '09' . rand(10000000, 99999999),
                'delivery_note' => 'Don test sinh tu dong de kiem thu',
                'confirmed_at' => $confirmedAt,
                'preparing_at' => $preparingAt,
                'ready_at' => $readyAt,
                'delivered_at' => $deliveredAt,
                'cancelled_at' => $cancelledAt,
                'timeout_at' => null,
                'cancel_reason' => $cancelReason,
                'cancelled_by' => null,
                'sla_timeout_minutes' => 15,
                'sla_warning_sent' => (bool) rand(0, 1),
                'zalo_notification_sent' => (bool) rand(0, 1),
                'created_at' => $baseTime,
                'updated_at' => now(),
            ];
        }

        DB::table('orders')->insert($rows);

        $this->command?->info('Inserted ' . $insertCount . ' fake orders successfully.');
    }
}

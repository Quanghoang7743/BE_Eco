<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\Room;
use Faker\Factory as Faker;

class FakeWinnersSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('vi_VN');

        $count = 500;
        $today = now()->format('Y-m-d');

        $userId = \DB::table('users')->value('id') ?: 1;

        for ($i = 1; $i <= $count; $i++) {
            $roomNumber = 10000 + $i; // large number to avoid collisions
            $roomCode = Room::makeRoomCode($today, $roomNumber);

            Room::create([
                'room_code' => $roomCode,
                'room_date' => $today,
                'room_number' => $roomNumber,
                'status' => 'COMPLETED',
                'current_votes' => $faker->numberBetween(1000, 5000),
                'required_votes' => $faker->numberBetween(1000, 5000),
                'ticket_type_code' => 'ALL_TYPES',
                'prize_type' => $faker->randomElement(['Kazuki Canely-S', 'Dibao Pansy S', 'VinFast Evo200']),
                'prize_value' => $faker->numberBetween(1000000, 20000000),
                'prize_description' => $faker->paragraphs(2, true),
                'prize_image_url' => null,
                'winner_id' => (int) $userId,
                'winner_name' => $faker->name,
                'winner_ticket_id' => null,
                'draw_started_at' => now()->subMinutes($faker->numberBetween(10, 120)),
                'draw_completed_at' => now()->subMinutes($faker->numberBetween(0, 9)),
                'tx_hash' => '0x' . bin2hex(random_bytes(32)),
                'block_hash' => '0x' . bin2hex(random_bytes(32)),
            ]);
        }
    }
}

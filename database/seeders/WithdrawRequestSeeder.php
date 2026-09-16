<?php

namespace Database\Seeders;

use App\Models\WithdrawRequest;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class WithdrawRequestSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create();

        $userIds = \App\Models\User::query()->pluck('id')->all();
        if (empty($userIds)) {
            $this->command->info('No users found. Skipping WithdrawRequest seeding.');
            return;
        }

        $statusChoices = [
            'pending',
            'approved',
            'rejected',
        ];

        $records = [];
        $now = now();

        foreach (range(1, 5000) as $index) {
            $status = Arr::random($statusChoices, 1)[0];
            $rejectionReason = null;

            if ($status === 'rejected') {
                $rejectionReason = $faker->sentence(8);
            }

            $records[] = [
                'user_id' => Arr::random($userIds),
                'amount' => $faker->numberBetween(100000, 5000000),
                'note' => $faker->sentence(6),
                'status' => $status,
                'rejection_reason' => $rejectionReason,
                'image' => null,
                'created_at' => $faker->dateTimeBetween('-180 days', 'now'),
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, 500) as $chunk) {
            WithdrawRequest::insert($chunk);
        }

        $this->command->info('Inserted 5000 fake withdraw request records.');
    }
}

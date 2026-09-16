<?php

namespace Database\Seeders;

use App\Models\AffiliateWithdrawalRequest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AffiliateWithdrawalTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('affiliate_withdrawal_requests')) {
            $this->command?->warn('Skipped: missing users or affiliate_withdrawal_requests table.');
            return;
        }

        $now = now();

        $targetUser = User::query()->where('name', 'user 150')->first() ?? User::query()->find(96);

        if (! $targetUser) {
            $this->command?->warn('Skipped: could not find user 150 / user id 96.');
            return;
        }

        $targetReferrerId = (int) $targetUser->id;
        $existingCount = User::where('referrer_id', $targetReferrerId)->count();
        $needed = max(0, 100 - $existingCount);

        $created = 0;
        if ($needed > 0) {
            $rows = [];

            for ($i = 1; $i <= $needed; $i++) {
                $suffix = str_pad((string) ($existingCount + $i), 3, '0', STR_PAD_LEFT);
                $email = "user150-ref-{$suffix}@example.com";
                $phone = '150' . str_pad((string) ($existingCount + $i), 7, '0', STR_PAD_LEFT);

                $rows[] = [
                    'name' => "user 150 ref {$suffix}",
                    'full_name' => "User 150 Referral {$suffix}",
                    'email' => $email,
                    'phone' => $phone,
                    'role' => 'user',
                    'password' => bcrypt('password'),
                    'referrer_id' => $targetReferrerId,
                    'created_at' => $now->copy()->subMinutes($needed - $i),
                    'updated_at' => $now,
                ];
            }

            DB::table('users')->insert($rows);
            $created = count($rows);
        }

        $finalCount = User::where('referrer_id', $targetReferrerId)->count();

        AffiliateWithdrawalRequest::updateOrCreate(
            ['user_id' => $targetReferrerId],
            [
                'referrals_count' => $finalCount,
                'status' => 'pending',
                'approved_by' => null,
                'approved_at' => null,
            ]
        );

        $this->command?->info("Target user: {$targetUser->id} ({$targetUser->name})");
        $this->command?->info("Created {$created} referral users.");
        $this->command?->info("Final referral count: {$finalCount}");
        $this->command?->info('Created/updated pending affiliate withdrawal request.');
    }
}

<?php

namespace App\Console\Commands;

use App\Models\AffiliateWithdrawalRequest;
use App\Models\User;
use Illuminate\Console\Command;

class GenerateAffiliateWithdrawalRequests extends Command
{
    protected $signature = 'ecoluck:generate-affiliate-withdrawals';

    protected $description = 'Generate daily affiliate withdrawal requests for users with >=100 activated referrals';

    public function handle()
    {
        $this->info('Scanning users for affiliate eligibility...');

        // Count referrals grouped by referrer in a single query instead of
        // running one COUNT per user (fixes N+1).
        $referralCounts = User::query()
            ->whereNotNull('referrer_id')
            ->groupBy('referrer_id')
            ->selectRaw('referrer_id, COUNT(*) as referrals_count')
            ->having('referrals_count', '>=', 100)
            ->pluck('referrals_count', 'referrer_id');

        if ($referralCounts->isEmpty()) {
            $this->info('Created 0 affiliate withdrawal requests.');

            return 0;
        }

        $eligibleUserIds = $referralCounts->keys()->all();

        // Fetch all users that already have an open request in one query.
        $existingUserIds = AffiliateWithdrawalRequest::query()
            ->whereIn('user_id', $eligibleUserIds)
            ->whereIn('status', ['pending', 'approved'])
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->flip();

        $created = 0;

        foreach ($referralCounts as $userId => $count) {
            if ($existingUserIds->has((string) $userId)) {
                continue;
            }

            AffiliateWithdrawalRequest::create([
                'user_id' => $userId,
                'referrals_count' => (int) $count,
                'status' => 'pending',
            ]);
            $created++;
        }

        $this->info("Created {$created} affiliate withdrawal requests.");

        return 0;
    }
}

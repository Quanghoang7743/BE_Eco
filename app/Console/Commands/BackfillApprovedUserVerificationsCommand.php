<?php

namespace App\Console\Commands;

use App\Models\ClientUserVerification;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillApprovedUserVerificationsCommand extends Command
{
    protected $signature = 'users:backfill-approved-verifications
        {--apply : Persist the backfill. Without this option the command only reports what would change.}
        {--chunk=200 : Number of approved verification records to process per chunk.}';

    protected $description = 'Backfill empty users profile fields from approved client user verifications.';

    public function handle(): int
    {
        if (! $this->schemaReady()) {
            $this->error('Required users/client_user_verifications schema is not ready.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $approvedCount = ClientUserVerification::query()
            ->where('status', ClientUserVerification::STATUS_APPROVED)
            ->count();

        $candidates = $this->candidateQuery()->get();

        $this->info('Approved verifications: ' . $approvedCount);
        $this->info('Users needing profile backfill: ' . $candidates->count());

        if ($candidates->isEmpty()) {
            $this->info('No profile fields need backfilling.');

            return self::SUCCESS;
        }

        $this->table(
            ['user_id', 'verification_id', 'fields'],
            $candidates
                ->map(fn (ClientUserVerification $verification): array => [
                    $verification->user_id,
                    $verification->id,
                    implode(', ', $this->fieldsToBackfill($verification)),
                ])
                ->all()
        );

        if (! $apply) {
            $this->warn('Dry-run only. Re-run with --apply to persist these updates.');

            return self::SUCCESS;
        }

        $updatedUsers = 0;
        DB::transaction(function () use ($chunkSize, &$updatedUsers): void {
            $this->candidateQuery()
                ->chunkById($chunkSize, function ($verifications) use (&$updatedUsers): void {
                    foreach ($verifications as $verification) {
                        $updatedUsers += $this->backfillUser($verification) ? 1 : 0;
                    }
                }, 'client_user_verifications.id', 'id');
        });

        $this->info('Backfilled users: ' . $updatedUsers);

        return self::SUCCESS;
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('users')
            && Schema::hasTable('client_user_verifications')
            && Schema::hasColumn('users', 'id')
            && Schema::hasColumn('users', 'full_name')
            && Schema::hasColumn('users', 'personal_full_name')
            && Schema::hasColumn('users', 'personal_identity_number')
            && Schema::hasColumn('users', 'personal_address')
            && Schema::hasColumn('users', 'birthday')
            && Schema::hasColumn('client_user_verifications', 'user_id')
            && Schema::hasColumn('client_user_verifications', 'full_name')
            && Schema::hasColumn('client_user_verifications', 'id_number')
            && Schema::hasColumn('client_user_verifications', 'birth_date')
            && Schema::hasColumn('client_user_verifications', 'address')
            && Schema::hasColumn('client_user_verifications', 'status');
    }

    private function candidateQuery()
    {
        return ClientUserVerification::query()
            ->select('client_user_verifications.*')
            ->join('users', 'users.id', '=', 'client_user_verifications.user_id')
            ->where('client_user_verifications.status', ClientUserVerification::STATUS_APPROVED)
            ->where(function ($query): void {
                $query
                    ->whereNull('users.personal_full_name')
                    ->orWhere('users.personal_full_name', '')
                    ->orWhereNull('users.personal_identity_number')
                    ->orWhere('users.personal_identity_number', '')
                    ->orWhereNull('users.personal_address')
                    ->orWhere('users.personal_address', '')
                    ->orWhereNull('users.birthday')
                    ->orWhereNull('users.full_name')
                    ->orWhere('users.full_name', '');
            })
            ->with('user:id,full_name,personal_full_name,personal_identity_number,personal_address,birthday')
            ->orderBy('client_user_verifications.id');
    }

    private function backfillUser(ClientUserVerification $verification): bool
    {
        $user = User::query()->find($verification->user_id);
        if (! $user) {
            return false;
        }

        $payload = $this->backfillPayload($user, $verification);
        if ($payload === []) {
            return false;
        }

        $user->forceFill($payload)->save();

        return true;
    }

    private function fieldsToBackfill(ClientUserVerification $verification): array
    {
        $user = $verification->user;
        if (! $user) {
            return [];
        }

        return array_keys($this->backfillPayload($user, $verification));
    }

    private function backfillPayload(User $user, ClientUserVerification $verification): array
    {
        $payload = [];

        if ($this->isBlank($user->personal_full_name) && ! $this->isBlank($verification->full_name)) {
            $payload['personal_full_name'] = $verification->full_name;
        }

        if ($this->isBlank($user->personal_identity_number) && ! $this->isBlank($verification->id_number)) {
            $payload['personal_identity_number'] = $verification->id_number;
        }

        if ($this->isBlank($user->personal_address) && ! $this->isBlank($verification->address)) {
            $payload['personal_address'] = $verification->address;
        }

        if ($this->isBlank($user->birthday) && $verification->birth_date) {
            $payload['birthday'] = $verification->birth_date->toDateString();
        }

        if ($this->isBlank($user->full_name) && ! $this->isBlank($verification->full_name)) {
            $payload['full_name'] = $verification->full_name;
        }

        return $payload;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}

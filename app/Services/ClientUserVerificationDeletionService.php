<?php

namespace App\Services;

use App\Events\ClientUserVerificationUpdated;
use App\Models\AffiliateProfile;
use App\Models\ClientUserVerification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ClientUserVerificationDeletionService
{
    private const IMAGE_FIELDS = [
        'id_front_image',
        'id_back_image',
        'id_selfie_image',
    ];

    /**
     * @param  Collection<int, ClientUserVerification>  $verifications
     */
    public function delete(Collection $verifications): int
    {
        $verifications = $verifications
            ->filter(fn ($verification) => $verification instanceof ClientUserVerification)
            ->unique('id')
            ->values();

        if ($verifications->isEmpty()) {
            return 0;
        }

        $candidatePaths = $this->storagePathsFromVerifications($verifications);
        $deletedVerifications = collect();

        DB::transaction(function () use ($verifications, $deletedVerifications): void {
            foreach ($verifications as $verification) {
                $lockedVerification = ClientUserVerification::query()
                    ->lockForUpdate()
                    ->find($verification->id);

                if (! $lockedVerification) {
                    continue;
                }

                $this->clearVerificationFields((int) $lockedVerification->user_id);
                $lockedVerification->delete();
                $deletedVerifications->push($lockedVerification);
            }
        });

        $this->deleteUnreferencedFiles($candidatePaths);

        $pendingCount = ClientUserVerification::query()
            ->where('status', ClientUserVerification::STATUS_PENDING)
            ->count();

        foreach ($deletedVerifications as $verification) {
            ClientUserVerificationUpdated::dispatch($verification, 'deleted', $pendingCount);
        }

        return $deletedVerifications->count();
    }

    private function clearVerificationFields(int $userId): void
    {
        $payload = [];

        foreach (['personal_full_name', 'personal_identity_number', 'personal_address'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                $payload[$column] = null;
            }
        }

        if ($payload !== []) {
            User::query()->whereKey($userId)->update($payload);
        }
    }

    /**
     * @param  Collection<int, ClientUserVerification>  $verifications
     * @return array<int, string>
     */
    private function storagePathsFromVerifications(Collection $verifications): array
    {
        return $verifications
            ->flatMap(fn (ClientUserVerification $verification) => array_map(
                fn (string $field) => $this->storagePathFromUrl($verification->{$field}),
                self::IMAGE_FIELDS
            ))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $candidatePaths
     */
    private function deleteUnreferencedFiles(array $candidatePaths): void
    {
        if ($candidatePaths === []) {
            return;
        }

        $referencedPaths = $this->referencedStoragePaths();
        $disk = Storage::disk('public');

        foreach ($candidatePaths as $path) {
            if (! isset($referencedPaths[$path])) {
                $disk->delete($path);
            }
        }
    }

    /**
     * @return array<string, true>
     */
    private function referencedStoragePaths(): array
    {
        $paths = [];

        $this->collectReferencedPaths(ClientUserVerification::class, $paths);

        if (Schema::hasTable('affiliate_profiles')) {
            $this->collectReferencedPaths(AffiliateProfile::class, $paths);
        }

        return $paths;
    }

    /**
     * @param  class-string<ClientUserVerification|AffiliateProfile>  $modelClass
     * @param  array<string, true>  $paths
     */
    private function collectReferencedPaths(string $modelClass, array &$paths): void
    {
        $model = new $modelClass();
        $table = $model->getTable();
        $fields = array_values(array_filter(
            self::IMAGE_FIELDS,
            fn (string $field) => Schema::hasColumn($table, $field)
        ));

        if ($fields === []) {
            return;
        }

        $modelClass::query()
            ->select(array_merge(['id'], $fields))
            ->chunkById(200, function ($records) use ($fields, &$paths): void {
                foreach ($records as $record) {
                    foreach ($fields as $field) {
                        $path = $this->storagePathFromUrl($record->{$field});
                        if ($path) {
                            $paths[$path] = true;
                        }
                    }
                }
            });
    }

    private function storagePathFromUrl(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false) {
            return null;
        }

        if (isset($parts['host'])) {
            $allowedHosts = collect([
                parse_url((string) config('app.url'), PHP_URL_HOST),
                parse_url((string) config('app.asset_url'), PHP_URL_HOST),
            ])->filter()->unique();

            if (! $allowedHosts->contains($parts['host'])) {
                return null;
            }
        }

        $urlPath = rawurldecode((string) ($parts['path'] ?? $value));
        if (! Str::startsWith($urlPath, ['/storage/', 'storage/'])) {
            return null;
        }

        $storagePath = ltrim(Str::after($urlPath, 'storage/'), '/');
        if ($storagePath === '' || str_contains($storagePath, '..')) {
            return null;
        }

        return $storagePath;
    }
}

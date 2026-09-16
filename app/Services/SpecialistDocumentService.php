<?php

namespace App\Services;

use App\Models\Specialist;
use App\Models\SpecialistAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SpecialistDocumentService
{
    private const SINGLE_DOCUMENT_FIELDS = [
        'contract_file' => 'contract_file_url',
        'id_card_front' => 'id_card_front_url',
        'id_card_back' => 'id_card_back_url',
    ];

    public function syncFromRequest(User $user, Request $request, ?User $actor = null): Specialist
    {
        $profile = Specialist::query()->firstOrNew(['user_id' => $user->id]);
        $profileWasPersisted = $profile->exists;
        $before = $this->documentSnapshot($profile);
        $pathsToDelete = [];

        foreach (self::SINGLE_DOCUMENT_FIELDS as $input => $column) {
            if ($request->hasFile($input)) {
                if ($profile->{$column}) {
                    $pathsToDelete[] = (string) $profile->{$column};
                }

                $profile->{$column} = $this->store($user, $request->file($input));
                continue;
            }

            if ($request->boolean('remove_' . $input) && $profile->{$column}) {
                $pathsToDelete[] = (string) $profile->{$column};
                $profile->{$column} = null;
            }
        }

        $otherDocuments = array_values(array_filter(
            (array) ($profile->other_documents_json ?? []),
            static fn ($path): bool => is_string($path) && trim($path) !== ''
        ));

        $removeIndexes = array_map(
            'intval',
            array_keys(array_filter((array) $request->input('remove_other_documents', [])))
        );

        foreach ($removeIndexes as $index) {
            if (array_key_exists($index, $otherDocuments)) {
                $pathsToDelete[] = $otherDocuments[$index];
                unset($otherDocuments[$index]);
            }
        }

        foreach ((array) $request->file('other_documents', []) as $file) {
            if ($file instanceof UploadedFile) {
                $otherDocuments[] = $this->store($user, $file);
            }
        }

        $profile->other_documents_json = array_values($otherDocuments);
        $profile->save();

        foreach (array_unique($pathsToDelete) as $path) {
            $this->deleteOwnedPath($user, $path);
        }

        $after = $this->documentSnapshot($profile);
        $changes = $this->changes($before, $after);

        if ($changes !== []) {
            $this->audit(
                $user,
                $actor,
                $request,
                $profileWasPersisted ? 'specialist_documents_updated' : 'specialist_documents_created',
                $changes
            );
        }

        return $profile;
    }

    public function documentPath(Specialist $profile, string $type, ?int $index = null): ?string
    {
        return match ($type) {
            'contract' => $this->nullablePath($profile->contract_file_url),
            'id-card-front' => $this->nullablePath($profile->id_card_front_url),
            'id-card-back' => $this->nullablePath($profile->id_card_back_url),
            'other' => $this->nullablePath(($profile->other_documents_json ?? [])[$index ?? -1] ?? null),
            default => null,
        };
    }

    public function isOwnedPath(User $user, string $path): bool
    {
        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        return Str::startsWith($normalized, "uploads/specialists/{$user->id}/documents/");
    }

    private function store(User $user, UploadedFile $file): string
    {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));
        $baseName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $baseName = $baseName !== '' ? Str::limit($baseName, 80, '') : 'document';
        $fileName = $baseName . '-' . Str::lower(Str::random(16));

        if ($extension !== '') {
            $fileName .= '.' . $extension;
        }

        return $file->storeAs(
            "uploads/specialists/{$user->id}/documents",
            $fileName,
            'local'
        );
    }

    private function deleteOwnedPath(User $user, string $path): void
    {
        if ($this->isOwnedPath($user, $path)) {
            Storage::disk('local')->delete($path);
        }
    }

    private function nullablePath(mixed $path): ?string
    {
        $path = trim((string) $path);

        return $path !== '' ? $path : null;
    }

    private function documentSnapshot(Specialist $profile): array
    {
        return [
            'contract_file_url' => $this->nullablePath($profile->contract_file_url),
            'id_card_front_url' => $this->nullablePath($profile->id_card_front_url),
            'id_card_back_url' => $this->nullablePath($profile->id_card_back_url),
            'other_documents_json' => array_values((array) ($profile->other_documents_json ?? [])),
        ];
    }

    private function changes(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $changes[$field] = [
                    'before' => $before[$field] ?? null,
                    'after' => $value,
                ];
            }
        }

        return $changes;
    }

    private function audit(
        User $specialist,
        ?User $actor,
        Request $request,
        string $action,
        array $changes
    ): void {
        if (! Schema::hasTable('specialist_audit_logs')) {
            return;
        }

        SpecialistAuditLog::query()->create([
            'specialist_id' => $specialist->id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'metadata' => ['changes' => $changes],
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
        ]);
    }
}

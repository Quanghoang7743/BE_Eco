<?php

namespace App\Services\Rbac;

use App\Models\Role;
use App\Models\UserRoleBinding;
use Illuminate\Validation\ValidationException;

class RoleBindingService
{
    public function assignRoleToUser(int|string $userId, string $roleName, string $entityType, int|string|null $entityId = null): UserRoleBinding
    {
        $role = Role::query()->where('name', $roleName)->first();

        if (!$role) {
            throw ValidationException::withMessages([
                'role' => ['Role does not exist.'],
            ]);
        }

        if ($entityType === 'system' && $entityId !== null) {
            throw ValidationException::withMessages([
                'entity_id' => ['entity_id must be null when entity_type is system.'],
            ]);
        }

        if ($entityType !== 'system' && $entityId === null) {
            throw ValidationException::withMessages([
                'entity_id' => ['entity_id is required for non-system entity types.'],
            ]);
        }

        return UserRoleBinding::query()->firstOrCreate([
            'user_id' => $userId,
            'role_id' => $role->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId !== null ? (string) $entityId : null,
        ]);
    }
}

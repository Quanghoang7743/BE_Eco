<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PlatformAdminAccess
{
    private const ADMIN_ROLES = ['admin', 'super_admin'];

    public static function allows(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $directRole = strtolower(trim((string) ($user->role ?? '')));
        if (in_array($directRole, self::ADMIN_ROLES, true)) {
            return true;
        }

        if (method_exists($user, 'hasAnyRole')) {
            try {
                if ($user->hasAnyRole(self::ADMIN_ROLES)) {
                    return true;
                }
            } catch (Throwable) {
            }
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('user_role_bindings')) {
            return false;
        }

        try {
            return $user->legacyRoles()
                ->whereIn('name', self::ADMIN_ROLES)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }
}

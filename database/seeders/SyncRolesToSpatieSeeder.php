<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SyncRolesToSpatieSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]
            ->forgetCachedPermissions();

        User::whereNotNull('role')
            ->chunk(100, function ($users) {

                foreach ($users as $user) {

                    $roleName = trim($user->role);

                    if (!$roleName) {
                        continue;
                    }

                    Role::firstOrCreate([
                        'name' => $roleName
                    ]);

                    if (!$user->hasRole($roleName)) {
                        $user->assignRole($roleName);

                        echo "Assigned {$roleName} -> User {$user->id}\n";
                    }
                }

            });

        app()[PermissionRegistrar::class]
            ->forgetCachedPermissions();
    }
}
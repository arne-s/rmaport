<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use App\Models\Permission;
use App\Models\Role;

class UserSetPassword extends Command
{
    protected $signature = 'user:set-password
                            {email : The user email address}
                            {password : The new password (will be hashed)}';

    protected $description = 'Set a user password (hashed) and ensure panel access (manager role + baseline permissions)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->argument('password');

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("Geen gebruiker gevonden met e-mail: {$email}");

            return self::FAILURE;
        }

        $user->password = Hash::make($password);
        $user->save();

        if (! $user->can('access filament panel')) {
            $this->ensureManagerRoleHasPanelPermissions();
            $user->assignRole('manager');
            $user->forgetCachedPermissions();
            $this->info('Rol "manager" toegevoegd met panel-permissies (toegang tot /beheer).');
        }

        $this->info("Wachtwoord voor {$email} is bijgewerkt. Je kunt nu inloggen op " . config('filament.domain') . '/beheer');

        return self::SUCCESS;
    }

    private function ensureManagerRoleHasPanelPermissions(): void
    {
        $accessPanel = Permission::findOrCreate('access filament panel', 'web');
        $manageUsers = Permission::findOrCreate('manage users', 'web');

        $manager = Role::findOrCreate('manager', 'web');
        $manager->givePermissionTo([$accessPanel, $manageUsers]);
    }
}

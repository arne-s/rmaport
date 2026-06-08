<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage users');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('manage users');
    }

    public function create(User $user): bool
    {
        return $user->can('manage users');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('manage users');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('manage users');
    }

    public function restore(User $user, Role $role): bool
    {
        return $user->can('manage users');
    }

    public function forceDelete(User $user, Role $role): bool
    {
        return $user->can('manage users');
    }
}

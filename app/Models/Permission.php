<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    protected $fillable = [
        'name',
        'guard_name',
        'display_name',
    ];

    public function getDisplayName(): string
    {
        return filled($this->display_name) ? (string) $this->display_name : (string) $this->name;
    }
}

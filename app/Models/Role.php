<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
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

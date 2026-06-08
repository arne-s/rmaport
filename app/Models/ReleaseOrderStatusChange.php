<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class ReleaseOrderStatusChange extends StatusChange
{
    protected $table = 'status_changes';

    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope('release_order_id', fn (Builder $builder) => $builder->whereNotNull('release_order_id'));
    }
}

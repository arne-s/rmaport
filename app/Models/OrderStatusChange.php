<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderStatusChange extends StatusChange
{
    protected $table = 'status_changes';

    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope('order_id', fn (Builder $builder) => $builder->whereNotNull('order_id'));
    }
}

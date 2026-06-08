<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderProductStatusChange extends StatusChange
{
    protected $table = 'status_changes';

    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope('order_product_id', function (Builder $builder): void {
            $builder->whereNotNull($builder->qualifyColumn('order_product_id'));
        });
    }
}

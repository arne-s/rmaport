<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait ActiveTrait
{
    /**
     * Scope a query to only include active products.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

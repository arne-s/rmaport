<?php

namespace App\Casts;

use App\Enums\OrderStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<OrderStatus|null, string|null> */
class OrderStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?OrderStatus
    {
        if ($value === null || $value === '') {
            return null;
        }

        return OrderStatus::normalizeLegacyStatus((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof OrderStatus ? $value->value : (string) $value;
    }
}

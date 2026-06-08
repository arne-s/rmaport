<?php

namespace App\Casts;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps legacy DB value {@see self::LEGACY_PURCHASED_VALUE} to {@see PurchaseOrderStatus::Purchased} (backing: purchasing).
 */
class PurchaseOrderStatusCast implements CastsAttributes
{
    private const string LEGACY_PURCHASED_VALUE = 'purchased';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?PurchaseOrderStatus
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = (string) $value;
        if ($raw === self::LEGACY_PURCHASED_VALUE) {
            return PurchaseOrderStatus::Purchased;
        }

        return PurchaseOrderStatus::tryFrom($raw);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if ($value instanceof PurchaseOrderStatus) {
            return [$key => $value->value];
        }

        $raw = (string) $value;
        if ($raw === self::LEGACY_PURCHASED_VALUE) {
            return [$key => PurchaseOrderStatus::Purchased->value];
        }

        return [$key => $raw];
    }
}

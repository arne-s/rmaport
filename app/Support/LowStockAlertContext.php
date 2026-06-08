<?php

namespace App\Support;

use App\Models\OrderProduct;

class LowStockAlertContext
{
    private static ?OrderProduct $orderProduct = null;

    public static function set(?OrderProduct $orderProduct): void
    {
        self::$orderProduct = $orderProduct;
    }

    public static function get(): ?OrderProduct
    {
        return self::$orderProduct;
    }

    public static function clear(): void
    {
        self::$orderProduct = null;
    }
}

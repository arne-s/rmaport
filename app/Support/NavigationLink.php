<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

class NavigationLink
{
    public const CSS_CLASS = 'main-request-number-link';

    public static function render(?string $url, ?string $label, string $fallback = '-'): HtmlString|string
    {
        if ($label === null || $label === '' || $label === '-') {
            return $fallback;
        }

        if ($url === null) {
            return e($label);
        }

        return new HtmlString(
            '<a class="'.self::CSS_CLASS.' hover:underline" href="'.e($url).'">'.e($label).'</a>'
        );
    }

    public static function main(?int $mainId, ?string $label, string $fallback = '-'): HtmlString|string
    {
        if ($mainId === null) {
            return $fallback;
        }

        return self::render(
            route('filament.app.resources.mains.view', ['record' => $mainId]),
            $label,
            $fallback,
        );
    }

    public static function purchaseOrder(?int $purchaseOrderId, ?string $label, string $fallback = '-'): HtmlString|string
    {
        if ($purchaseOrderId === null) {
            return $fallback;
        }

        return self::render(
            route('filament.app.resources.purchase-orders.view', ['record' => $purchaseOrderId]),
            $label,
            $fallback,
        );
    }

    public static function releaseOrder(?int $releaseOrderId, ?string $label, string $fallback = '-'): HtmlString|string
    {
        if ($releaseOrderId === null) {
            return $fallback;
        }

        return self::render(
            route('filament.app.resources.release-orders.view', ['record' => $releaseOrderId]),
            $label,
            $fallback,
        );
    }

    public static function orderEdit(?int $orderId, ?string $label, string $fallback = '-'): HtmlString|string
    {
        if ($orderId === null) {
            return $fallback;
        }

        return self::render(
            route('filament.app.resources.orders.edit', ['record' => $orderId]),
            $label,
            $fallback,
        );
    }
}

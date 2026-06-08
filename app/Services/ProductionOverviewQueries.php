<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Enums\OrderSubtype;
use App\Filament\Resources\ProductionResource;
use App\Models\Order\Main;
use Illuminate\Database\Eloquent\Builder;

final class ProductionOverviewQueries
{
    public static function base(): Builder
    {
        return ProductionResource::getEloquentQuery();
    }

    public static function fitting(): Builder
    {
        return self::base()
            ->whereIn('order_status', OrderStatus::orderStatusColumnValuesForPhase(OrderStatus::Fitting))
            ->whereNot(function (Builder $q): void {
                $q->where('subtype', OrderSubtype::Unit->value)
                    ->whereHas('billingCustomer', function (Builder $b): void {
                        $b->whereIn('type', array_map(
                            static fn (CustomerType $t): string => $t->value,
                            Main::billingTypesForUnitSimplifiedSalesFlow(),
                        ));
                    });
            });
    }

    public static function quote(): Builder
    {
        return self::base()
            ->whereIn('order_status', OrderStatus::orderStatusColumnValuesForPhase(OrderStatus::Quote));
    }

    public static function ordered(): Builder
    {
        return self::base()
            ->whereIn('order_status', OrderStatus::orderStatusColumnValuesForPhase(OrderStatus::Order));
    }

    public static function purchased(): Builder
    {
        return self::base()
            ->whereIn('order_status', OrderStatus::orderStatusColumnValuesForPhase(OrderStatus::Purchase));
    }

    public static function assembled(): Builder
    {
        return self::base()
            ->whereIn('order_status', OrderStatus::orderStatusColumnValuesForPhase(OrderStatus::Assembly))
            ->where('is_completed', false)
            ->whereNot(function (Builder $q): void {
                $q->where('subtype', OrderSubtype::Unit->value)
                    ->whereHas('billingCustomer', function (Builder $b): void {
                        $b->whereIn('type', array_map(
                            static fn (CustomerType $t): string => $t->value,
                            Main::billingTypesForUnitSimplifiedSalesFlow(),
                        ));
                    });
            });
    }

    /**
     * Counts like the tab buttons in {@see \App\Filament\Resources\ProductionResource\Pages\ListProduction::getHeaderActions} (main records only).
     */
    public static function delivered(): Builder
    {
        return self::base()
            ->whereIn('order_status', OrderStatus::orderStatusColumnValuesForPhase(OrderStatus::Delivery))
            ->where('is_completed', false)
            ->where('type', 'main');
    }
}

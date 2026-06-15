<?php

namespace App\Filament\Resources\RmaResource\Support;

use App\Enums\OrderType;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\Rma;

final class RmaRelatedMainResolver
{
    public static function resolve(Rma $rma): ?Main
    {
        foreach (self::lookupValues($rma) as $value) {
            $main = Main::query()
                ->withoutGlobalScopes()
                ->where('type', OrderType::Main->value)
                ->where('uid', $value)
                ->first();

            if ($main instanceof Main) {
                return $main;
            }
        }

        foreach (self::lookupValues($rma) as $value) {
            $main = Main::query()
                ->withoutGlobalScopes()
                ->where('type', OrderType::Main->value)
                ->where('reference_internal', $value)
                ->first();

            if ($main instanceof Main) {
                return $main;
            }
        }

        foreach (self::lookupValues($rma) as $value) {
            $relatedOrder = BaseOrder::query()
                ->withoutGlobalScopes()
                ->where('uid', $value)
                ->whereNotNull('main_id')
                ->first();

            if ($relatedOrder === null) {
                continue;
            }

            $main = Main::query()
                ->withoutGlobalScopes()
                ->find($relatedOrder->getMainId());

            if ($main instanceof Main) {
                return $main;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function lookupValues(Rma $rma): array
    {
        $values = [];

        foreach ([
            $rma->importRow?->reference,
            $rma->importRow?->assignment_nr,
            $rma->importRow?->customer_order_id,
        ] as $value) {
            $value = trim((string) ($value ?? ''));

            if ($value === '' || in_array($value, $values, true)) {
                continue;
            }

            $values[] = $value;
        }

        return $values;
    }
}

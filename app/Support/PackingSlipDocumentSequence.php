<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DeliveryNote;
use App\Models\PackingSlip;

final class PackingSlipDocumentSequence
{
    /**
     * Next numeric document id shared by {@see PackingSlip} and {@see DeliveryNote}.
     */
    public static function next(): string
    {
        $start = (int) config('document_uids.packing_slip.start', 1000);

        $maxPacking = self::toIntMax(PackingSlip::query()->max('uid'));
        $maxDelivery = self::toIntMax(DeliveryNote::query()->max('uid'));

        $maxUid = max($maxPacking, $maxDelivery);

        return (string) max($start, $maxUid + 1);
    }

    private static function toIntMax(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (int) $value;
    }
}

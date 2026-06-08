<?php

namespace App\Support;

use App\Enums\RecurringInvoiceFrequency;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use RuntimeException;

final class RecurringInvoiceSchedule
{
    public static function billingCalendarDay(CarbonInterface $inMonth, int $startDay): int
    {
        if ($startDay < 1 || $startDay > 31) {
            throw new InvalidArgumentException('start_day must be between 1 and 31.');
        }

        return min($startDay, (int) $inMonth->daysInMonth);
    }

    public static function matchesBillingDay(CarbonInterface $d, int $startDay): bool
    {
        return (int) $d->day === self::billingCalendarDay($d, $startDay);
    }

    /**
     * Smallest calendar date on or after {@code $from} that matches the billing day rule.
     */
    public static function firstNextRunDateOnOrAfter(CarbonInterface $from, int $startDay): Carbon
    {
        $cursor = Carbon::parse($from)->startOfDay();
        for ($i = 0; $i < 400; $i++) {
            if (self::matchesBillingDay($cursor, $startDay)) {
                return $cursor->copy();
            }
            $cursor->addDay();
        }

        throw new RuntimeException('Could not resolve next run date within the search window.');
    }

    public static function advanceNextRunDate(
        CarbonInterface $anchor,
        int $startDay,
        RecurringInvoiceFrequency $frequency,
    ): Carbon {
        $c = Carbon::parse($anchor)->startOfDay();
        $c = match ($frequency) {
            RecurringInvoiceFrequency::Month => $c->copy()->addMonthNoOverflow(),
            RecurringInvoiceFrequency::Quarter => $c->copy()->addMonthsNoOverflow(3),
            RecurringInvoiceFrequency::SixMonth => $c->copy()->addMonthsNoOverflow(6),
            RecurringInvoiceFrequency::Year => $c->copy()->addYearNoOverflow(),
        };
        $targetDay = self::billingCalendarDay($c, $startDay);
        if ((int) $c->day !== $targetDay) {
            $c->day($targetDay);
        }

        return $c;
    }
}

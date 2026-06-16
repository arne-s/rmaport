<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Rma;
use RuntimeException;

final class RmaNumberSequence
{
    public static function next(): string
    {
        $start = (int) config('document_uids.rma.start', 1);
        $digits = (int) config('document_uids.rma.digits', 8);
        $pattern = '^[0-9]{'.$digits.'}$';

        $currentMax = self::maxNumericUid($pattern);
        $next = max($start, $currentMax + 1);
        $maximum = (int) str_repeat('9', $digits);

        if ($next > $maximum) {
            throw new RuntimeException('Maximum aantal RMA-nummers bereikt.');
        }

        return str_pad((string) $next, $digits, '0', STR_PAD_LEFT);
    }

    private static function maxNumericUid(string $pattern): int
    {
        $query = Rma::query()->whereRaw('uid REGEXP ?', [$pattern]);

        if (Rma::query()->getConnection()->transactionLevel() > 0) {
            $latest = (clone $query)
                ->orderByDesc('uid')
                ->lockForUpdate()
                ->value('uid');

            return $latest !== null ? (int) $latest : 0;
        }

        $driver = Rma::query()->getConnection()->getDriverName();

        if ($driver === 'mysql') {
            return (int) ($query
                ->selectRaw('MAX(CAST(uid AS UNSIGNED)) as max_uid')
                ->value('max_uid') ?? 0);
        }

        return Rma::query()
            ->pluck('uid')
            ->filter(fn (mixed $uid): bool => is_string($uid) && preg_match('/'.$pattern.'/', $uid) === 1)
            ->map(fn (string $uid): int => (int) $uid)
            ->max() ?? 0;
    }
}

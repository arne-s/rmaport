<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ImportExport;
use RuntimeException;

final class ImportExportNumberSequence
{
    public static function next(): string
    {
        $prefix = (string) config('document_uids.export.prefix', 'EX-');
        $start = (int) config('document_uids.export.start', 1);
        $digits = (int) config('document_uids.export.digits', 7);
        $pattern = '^'.preg_quote($prefix, '/').'[0-9]{'.$digits.'}$';

        $currentMax = self::maxNumericSuffix($prefix, $pattern, $digits);
        $next = max($start, $currentMax + 1);
        $maximum = (int) str_repeat('9', $digits);

        if ($next > $maximum) {
            throw new RuntimeException('Maximum aantal export-nummers bereikt.');
        }

        return $prefix.str_pad((string) $next, $digits, '0', STR_PAD_LEFT);
    }

    private static function maxNumericSuffix(string $prefix, string $pattern, int $digits): int
    {
        $query = ImportExport::query()->whereRaw('uid REGEXP ?', [$pattern]);

        if (ImportExport::query()->getConnection()->transactionLevel() > 0) {
            $latest = (clone $query)
                ->orderByDesc('uid')
                ->lockForUpdate()
                ->value('uid');

            return self::extractSuffix($latest, $prefix, $digits);
        }

        $driver = ImportExport::query()->getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $prefixLength = strlen($prefix);

            return (int) (ImportExport::query()
                ->whereRaw('uid REGEXP ?', [$pattern])
                ->selectRaw('MAX(CAST(SUBSTRING(uid, ? + 1) AS UNSIGNED)) as max_suffix', [$prefixLength])
                ->value('max_suffix') ?? 0);
        }

        return ImportExport::query()
            ->pluck('uid')
            ->filter(fn (mixed $uid): bool => is_string($uid) && preg_match('/'.$pattern.'/', $uid) === 1)
            ->map(fn (string $uid): int => self::extractSuffix($uid, $prefix, $digits))
            ->max() ?? 0;
    }

    private static function extractSuffix(?string $uid, string $prefix, int $digits): int
    {
        if ($uid === null || ! str_starts_with($uid, $prefix)) {
            return 0;
        }

        $suffix = substr($uid, strlen($prefix));

        if (! is_numeric($suffix) || strlen($suffix) !== $digits) {
            return 0;
        }

        return (int) $suffix;
    }
}

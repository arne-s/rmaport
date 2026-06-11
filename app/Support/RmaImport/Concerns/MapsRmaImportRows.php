<?php

namespace App\Support\RmaImport\Concerns;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

trait MapsRmaImportRows
{
    protected function resolveUid(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $value = $this->nullableString($candidate);

            if ($value !== null) {
                return mb_substr($value, 0, 20);
            }
        }

        return null;
    }

    protected function parseBoolean(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return in_array((string) $value, ['1', 'true', 'yes'], true);
    }

    protected function parseQuantity(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 1;
        }

        return max(1, (int) $value);
    }

    protected function normalizeBarcode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return ltrim(trim($value), "'");
    }

    protected function parseDate(?string $value, string $format): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::createFromFormat($format, $value)->toDateString();
        } catch (InvalidFormatException) {
            try {
                return Carbon::parse($value)->toDateString();
            } catch (InvalidFormatException) {
                return null;
            }
        }
    }

    protected function parseDateTime(?string $value, string $format): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::createFromFormat($format, $value)->startOfDay()->toDateTimeString();
        } catch (InvalidFormatException) {
            try {
                return Carbon::parse($value)->startOfDay()->toDateTimeString();
            } catch (InvalidFormatException) {
                return null;
            }
        }
    }

    protected function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

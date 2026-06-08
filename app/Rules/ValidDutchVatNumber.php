<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Dutch VAT (btw-identificatienummer) as required by Exact:
 * exactly 14 characters: NL + 9 digits + B + 2 digits (e.g. NL000000000B00).
 */
class ValidDutchVatNumber implements ValidationRule
{
    /**
     * Exact-friendly normalized value, or null/empty.
     */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        $compact = strtoupper(preg_replace('/[\s.\-]/', '', $s) ?? '');

        return $compact !== '' ? $compact : null;
    }

    public static function isValidFormat(?string $normalized): bool
    {
        if ($normalized === null || $normalized === '') {
            return true;
        }

        return (bool) preg_match('/^NL\d{9}B\d{2}$/', $normalized);
    }

    /**
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return;
        }

        if (! self::isValidFormat($normalized)) {
            $fail('Ongeldig BTW-nummer.');
        }
    }
}

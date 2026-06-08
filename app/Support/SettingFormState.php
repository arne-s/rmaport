<?php

namespace App\Support;

final class SettingFormState
{
    /**
     * @param  array<string, mixed>  $flat
     * @return array<string, mixed>
     */
    public static function toNested(array $flat): array
    {
        $nested = [];

        foreach ($flat as $uid => $value) {
            if (! is_string($uid)) {
                continue;
            }

            data_set($nested, $uid, $value);
        }

        return $nested;
    }

    /**
     * @param  array<string, mixed>  $nested
     * @return array<string, mixed>
     */
    public static function toFlat(array $nested, string $prefix = ''): array
    {
        $flat = [];

        foreach ($nested as $key => $value) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }

            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat = array_merge($flat, self::toFlat($value, $path));

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }
}

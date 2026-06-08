<?php

namespace App\Support;

final class OutlookEventIds
{
    /**
     * @return list<string>
     */
    public static function collect(mixed $storedIds, mixed $legacyId = null): array
    {
        $ids = [];

        if (is_array($storedIds)) {
            $ids = $storedIds;
        } elseif (is_string($storedIds) && $storedIds !== '') {
            $decoded = json_decode($storedIds, true);
            if (is_array($decoded)) {
                $ids = $decoded;
            }
        }

        if (is_string($legacyId) && $legacyId !== '') {
            $ids[] = $legacyId;
        }

        return array_values(array_unique(array_filter(
            $ids,
            fn (mixed $id): bool => is_string($id) && $id !== '',
        )));
    }

    /**
     * @param  list<string>  $existing
     * @return list<string>
     */
    public static function append(array $existing, string $eventId): array
    {
        $existing[] = $eventId;

        return self::collect($existing);
    }
}

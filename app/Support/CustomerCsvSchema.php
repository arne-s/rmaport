<?php

namespace App\Support;

use App\Models\Address;

final class CustomerCsvSchema
{
    public const DELIMITER = ';';

    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return array_merge(
            self::coreHeaders(),
            self::addressHeaders('Contactadres'),
            self::addressHeaders('Factuuradres'),
            self::addressHeaders('Leveradres', includeLocationName: true),
            [
                'Interne opmerking',
                'Nieuwsbrief',
            ],
        );
    }

    /**
     * @return list<string>
     */
    public static function coreHeaders(): array
    {
        return [
            'ID',
            'Type',
            'Status',
            'Bedrijfsnaam',
            'Aanhef',
            'Voornaam',
            'Tussenvoegsel',
            'Achternaam',
            'Geboortedatum',
            'E-mail',
            'Telefoon',
            'Mobiel',
            'BTW-nummer',
            'KvK',
            'IBAN',
            'Debiteurennummer',
            'Betalingstermijn',
            'Kortingspercentage (%)',
            'Leveradres-type',
        ];
    }

    /**
     * @return list<string>
     */
    public static function addressHeaders(string $labelPrefix, bool $includeLocationName = false): array
    {
        $headers = [
            "{$labelPrefix} straat",
            "{$labelPrefix} huisnummer",
            "{$labelPrefix} toevoeging",
            "{$labelPrefix} postcode",
            "{$labelPrefix} plaats",
            "{$labelPrefix} land",
        ];

        if ($includeLocationName) {
            $headers[] = 'Leveradres locatienaam';
        }

        return $headers;
    }

    /**
     * @return list<string|int|float|null>
     */
    public static function addressValues(?Address $address, bool $includeLocationName = false): array
    {
        $values = [
            $address?->street ?? '',
            $address?->house_number ?? '',
            $address?->house_number_addition ?? '',
            $address?->postcode ?? '',
            $address?->city ?? '',
            $address?->country?->name ?? '',
        ];

        if ($includeLocationName) {
            $values[] = $address?->location_name ?? '';
        }

        return $values;
    }

    public static function parseDeliveryAddressType(?string $state): ?string
    {
        if ($state === null || trim($state) === '') {
            return null;
        }

        $normalized = strtolower(trim($state));

        return match ($normalized) {
            'contact', 'factuuradres' => 'contact',
            'custom', 'afwijkend' => 'custom',
            default => trim($state),
        };
    }

    public static function formatDeliveryAddressTypeForExport(?string $type): string
    {
        return match ($type) {
            'contact' => 'factuuradres',
            'custom' => 'afwijkend',
            default => '',
        };
    }
}

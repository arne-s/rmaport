<?php

namespace App\Support\RmaImport\MediaMarkt;

use App\Enums\RmaStatus;
use App\Support\RmaImport\Concerns\MapsRmaImportRows;

final class MediaMarktImportMapper
{
    use MapsRmaImportRows;

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>
     */
    public function map(array $row): array
    {
        return array_filter([
            'uid' => $this->resolveUid(
                $this->nullableString($row['RMA-nummer'] ?? null),
                $this->nullableString($row['Opdrachtnummer'] ?? null),
            ),
            'ean' => $this->nullableString($row['EAN'] ?? null),
            'complaint' => $this->nullableString($row['Klachtomschrijving'] ?? null),
            'accessories' => $this->nullableString($row['Accessoires'] ?? null),
            'is_refurbish' => $this->parseBoolean($row['Refurbish'] ?? null),
            'is_invoiced' => $this->parseBoolean($row['Gefactureerd'] ?? null),
            'status' => RmaStatus::Open->value,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  list<string>  $rawHeaders
     * @return list<string>
     */
    public function normalizeHeaders(array $rawHeaders): array
    {
        $referentieCount = 0;
        $vestigingCount = 0;

        return array_map(function (string $header) use (&$referentieCount, &$vestigingCount): string {
            $header = trim($header);

            if ($header === 'Referentie') {
                $referentieCount++;

                return $referentieCount === 1 ? 'Referentie' : '_skip';
            }

            if ($header === 'Vestiging') {
                $vestigingCount++;

                return match ($vestigingCount) {
                    1 => 'Vestiging',
                    2 => 'Vestigingcode',
                    default => '_skip',
                };
            }

            return $header;
        }, $rawHeaders);
    }

    /**
     * @param  list<string>  $headers
     */
    public function supportsHeaders(array $headers): bool
    {
        foreach ($headers as $header) {
            if (trim($header) === 'Opdrachtnummer') {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace App\Support;

use App\Enums\RmaCsvFormat;

final class RmaCsvSchema
{
    public const DELIMITER = ';';

    /**
     * @param  list<string>  $headers
     */
    public static function detectFormat(array $headers): RmaCsvFormat
    {
        $normalized = array_map(fn (string $header): string => trim($header), $headers);

        foreach ($normalized as $header) {
            if ($header === 'RETURN ID (RMA)' || $header === 'DEFECT ID') {
                return RmaCsvFormat::ConsumerReturns;
            }
        }

        return RmaCsvFormat::MediaMarkt;
    }

    /**
     * @param  list<string>  $rawHeaders
     * @return list<string>
     */
    public static function normalizeHeaders(array $rawHeaders, RmaCsvFormat $format): array
    {
        if ($format === RmaCsvFormat::ConsumerReturns) {
            return array_map(fn (string $header): string => trim($header), $rawHeaders);
        }

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
     * @param  list<string>  $values
     * @return array<string, string|null>
     */
    public static function combineHeadersWithValues(array $headers, array $values): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            if ($header === '_skip') {
                continue;
            }

            $row[$header] = $values[$index] ?? null;
        }

        return $row;
    }
}

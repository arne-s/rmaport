<?php

namespace App\Support\RmaImport\ConsumerReturns;

use App\Enums\RmaStatus;
use App\Support\RmaImport\Concerns\MapsRmaImportRows;

final class ConsumerReturnsImportMapper
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
                $this->nullableString($row['RETURN ID (RMA)'] ?? null),
                $this->nullableString($row['SHOP ORDER ID'] ?? null),
                $this->nullableString($row['CUSTOMER ORDER ID'] ?? null),
                $this->nullableString($row['DEFECT ID'] ?? null),
            ),
            'quantity' => $this->parseQuantity($row['QUANTITY'] ?? null),
            'ean' => $this->nullableString($row['EAN'] ?? null),
            'received_at' => $this->parseDateTime($this->nullableString($row['RETURN DATE'] ?? null), 'd-M-Y'),
            'return_reason' => $this->nullableString($row['RETURN REASON'] ?? null),
            'complaint' => $this->nullableString($row['CONSUMER COMMENT'] ?? null),
            'status' => RmaStatus::Open->value,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  list<string>  $rawHeaders
     * @return list<string>
     */
    public function normalizeHeaders(array $rawHeaders): array
    {
        return array_map(fn (string $header): string => trim($header), $rawHeaders);
    }

    /**
     * @param  list<string>  $headers
     */
    public function supportsHeaders(array $headers): bool
    {
        foreach ($headers as $header) {
            $header = trim($header);

            if ($header === 'RETURN ID (RMA)' || $header === 'DEFECT ID') {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace App\Services;

use App\Enums\ProductBrand;
use App\Enums\RmaStatus;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

class RmaCsvRowMapper
{
    /**
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>
     */
    public function mapMediaMarktRow(array $row): array
    {
        $productName = $this->nullableString($row['Type'] ?? null);
        $brand = ProductBrand::resolveImportValue($this->nullableString($row['Merk'] ?? null));

        return array_filter([
            'uid' => $this->resolveUid(
                $this->nullableString($row['RMA-nummer'] ?? null),
                $this->nullableString($row['Opdrachtnummer'] ?? null),
            ),
            'order_nr' => $this->nullableString($row['Opdrachtnummer'] ?? null),
            'reference' => $this->nullableString($row['Referentie'] ?? null),
            'ean' => $this->nullableString($row['EAN'] ?? null),
            'article_number' => $this->nullableString($row['Artikelnummer'] ?? null),
            'brand' => $brand?->value,
            'product_group' => $this->nullableString($row['Artikelgroep'] ?? null),
            'product_name' => $productName,
            'serial_number' => $this->nullableString($row['Serienummer'] ?? null),
            'language' => $this->nullableString($row['Taal'] ?? null),
            'purchased_at' => $this->parseDate($this->nullableString($row['Aankoopdatum'] ?? null), 'Y-m-d'),
            'complaint' => $this->nullableString($row['Klachtomschrijving'] ?? null),
            'location_name' => $this->nullableString($row['Vestiging'] ?? null),
            'location_code' => $this->nullableString($row['Vestigingcode'] ?? null),
            'external_location_id' => $this->nullableString($row['Vestiging-ID'] ?? null),
            'barcode' => $this->normalizeBarcode($this->nullableString($row['Barcode'] ?? null)),
            'product_condition' => $this->nullableString($row['Staat van product'] ?? null),
            'accessories' => $this->nullableString($row['Accessoires'] ?? null),
            'is_refurbish' => $this->parseBoolean($row['Refurbish'] ?? null),
            'is_doa' => $this->parseBoolean($row['DOA'] ?? null),
            'is_invoiced' => $this->parseBoolean($row['Gefactureerd'] ?? null),
            'status' => RmaStatus::Open->value,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>
     */
    public function mapConsumerReturnsRow(array $row): array
    {
        $productName = $this->nullableString($row['PRODUCT DESCRIPTION'] ?? null);
        $brand = ProductBrand::resolveFromProductDescription($productName);

        return array_filter([
            'uid' => $this->resolveUid(
                $this->nullableString($row['RETURN ID (RMA)'] ?? null),
                $this->nullableString($row['SHOP ORDER ID'] ?? null),
                $this->nullableString($row['CUSTOMER ORDER ID'] ?? null),
                $this->nullableString($row['DEFECT ID'] ?? null),
            ),
            'quantity' => $this->parseQuantity($row['QUANTITY'] ?? null),
            'reference' => $this->nullableString($row['CUSTOMER ORDER ID'] ?? null),
            'defect_id' => $this->nullableString($row['DEFECT ID'] ?? null),
            'ean' => $this->nullableString($row['EAN'] ?? null),
            'global_id' => $this->nullableString($row['GLOBAL ID'] ?? null),
            'product_name' => $productName,
            'graded_type' => $this->nullableString($row['GRADED TYPE'] ?? null),
            'serial_number' => $this->nullableString($row['SERIAL NUMBER'] ?? null),
            'imei' => $this->nullableString($row['IMEI'] ?? null),
            'order_nr' => $this->nullableString($row['SHOP ORDER ID'] ?? null),
            'purchased_at' => $this->parseDate($this->nullableString($row['SHOP ORDER DATE'] ?? null), 'd-M-Y'),
            'received_at' => $this->parseDateTime($this->nullableString($row['RETURN DATE'] ?? null), 'd-M-Y'),
            'return_reason' => $this->nullableString($row['RETURN REASON'] ?? null),
            'return_sub_reason' => $this->nullableString($row['RETURN SUB REASON'] ?? null),
            'complaint' => $this->nullableString($row['CONSUMER COMMENT'] ?? null),
            'brand' => $brand?->value,
            'status' => RmaStatus::Open->value,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function resolveUid(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $value = $this->nullableString($candidate);

            if ($value !== null) {
                return mb_substr($value, 0, 20);
            }
        }

        return null;
    }

    public function parseBoolean(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return in_array((string) $value, ['1', 'true', 'yes'], true);
    }

    public function parseQuantity(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 1;
        }

        return max(1, (int) $value);
    }

    public function normalizeBarcode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return ltrim(trim($value), "'");
    }

    public function parseDate(?string $value, string $format): ?string
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

    public function parseDateTime(?string $value, string $format): ?string
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

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

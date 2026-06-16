<?php

namespace App\Services\Import;

use App\Models\ImportTemplate;
use App\Support\RmaImport\Concerns\MapsRmaImportRows;
use Illuminate\Support\Str;

final class ImportRowTransformer
{
    use MapsRmaImportRows;

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>
     */
    public function transform(ImportTemplate $template, array $row): array
    {
        if (Str::contains($template->class, 'MediaMarktImportParser')) {
            return $this->transformMediaMarkt($row);
        }

        if (Str::contains($template->class, 'ConsumerReturnsShipmentImportParser')) {
            return $this->transformBol($row);
        }

        if (Str::contains($template->class, 'UniversalImportParser')) {
            return $this->transformUniversal($row);
        }

        return [];
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>
     */
    private function transformMediaMarkt(array $row): array
    {
        $vestiging = $this->nullableString($row['Vestiging'] ?? null);
        $vestigingId = $this->nullableString($row['Vestiging-ID'] ?? null);
        $sourceDescription = collect([$vestiging, $vestigingId])->filter()->implode(' ');

        return array_filter([
            'assignment_nr' => $this->nullableString($row['Opdrachtnummer'] ?? null),
            'reference' => $this->nullableString($row['Referentie'] ?? null),
            'ean_nr' => $this->nullableString($row['EAN'] ?? null),
            'product_name' => $this->joinProductNameParts(
                $this->nullableString($row['Merk'] ?? null),
                $this->nullableString($row['Artikelgroep'] ?? null),
            ),
            'purchase_date' => $this->parseDate($this->nullableString($row['Aankoopdatum'] ?? null), 'Y-m-d'),
            'return_reason' => $this->nullableString($row['Klachtomschrijving'] ?? null),
            'is_doa' => $this->parseBoolean($row['DOA'] ?? null) ?? false,
            'accessories' => $this->nullableString($row['Accessoires'] ?? null),
            'source_description' => $sourceDescription !== '' ? $sourceDescription : null,
        ], fn (mixed $value): bool => $value !== null && $value !== false);
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>
     */
    private function transformBol(array $row): array
    {
        $returnReason = collect([
            $this->nullableString($row['RETURN REASON'] ?? null),
            $this->nullableString($row['RETURN SUB REASON'] ?? null),
            $this->nullableString($row['CONSUMER COMMENT'] ?? null),
        ])->filter()->implode(' | ');

        return array_filter([
            'customer_order_id' => $this->nullableString($row['CUSTOMER ORDER ID'] ?? null),
            'reference' => $this->nullableString($row['DEFECT ID'] ?? null),
            'ean_nr' => $this->nullableString($row['EAN'] ?? null),
            'product_name' => $this->nullableString($row['PRODUCT DESCRIPTION'] ?? null),
            'purchase_date' => $this->parseDate($this->nullableString($row['SHOP ORDER DATE'] ?? null), 'd-M-Y'),
            'return_date' => $this->parseDate($this->nullableString($row['RETURN DATE'] ?? null), 'd-M-Y'),
            'return_reason' => $returnReason !== '' ? $returnReason : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>
     */
    private function transformUniversal(array $row): array
    {
        return array_filter([
            'reference' => $this->nullableString($row['UW RMA Referentie'] ?? null),
            'ean_nr' => $this->nullableString($row['EAN NUMMER'] ?? null),
            'product_name' => $this->nullableString($row['Artikel Omschrijving'] ?? null),
            'purchase_date' => $this->parseDate($this->nullableString($row['Aankoopdatum'] ?? null), 'd.m.y'),
            'return_reason' => $this->nullableString($row['Klachtomschrijving'] ?? null),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  list<string|null>  $parts
     */
    private function joinProductNameParts(?string ...$parts): ?string
    {
        $name = collect($parts)->filter(fn (?string $part): bool => filled($part))->implode(' ');

        return $name !== '' ? $name : null;
    }
}

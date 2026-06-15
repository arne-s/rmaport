<?php

namespace App\Support\RmaImport\Universal;

use App\Enums\ProductBrand;
use App\Enums\RmaStatus;
use App\Support\RmaImport\Concerns\MapsRmaImportRows;

final class UniversalImportMapper
{
    use MapsRmaImportRows;

    private const FOOTER_LABELS = [
        'Gecrediteerd',
        'Afgehandeld',
        'Reparatie',
    ];

    /**
     * @param  array<string, string|null>  $metadata
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>
     */
    public function map(array $metadata, array $row): array
    {
        $productName = $this->nullableString($row['Artikel Omschrijving'] ?? null);
        $brand = ProductBrand::resolveImportValue($this->nullableString($row['Merk'] ?? null))
            ?? ProductBrand::resolveFromProductDescription($productName);

        return array_filter([
            'uid' => $this->resolveUid(
                $this->nullableString($row['RMA NUMMER AUTOVISION'] ?? null),
                $this->nullableString($row['RMA NUMMER AUTOVISION '] ?? null),
                $this->nullableString($row['UW RMA Referentie'] ?? null),
            ),
            'reference' => $this->nullableString($row['UW RMA Referentie'] ?? null),
            'ean' => $this->nullableString($row['EAN NUMMER'] ?? null),
            'brand' => $brand?->value,
            'article_number' => $this->nullableString($row['Artikel nummer Autovision'] ?? null),
            'product_name' => $productName,
            'serial_number' => $this->nullableString($row['Serienummer'] ?? null),
            'purchased_at' => $this->parseDate($this->nullableString($row['Aankoopdatum'] ?? null), 'd.m.y'),
            'complaint' => $this->nullableString($row['Klachtomschrijving'] ?? null),
            'packing_slip_number' => $this->nullableString($metadata['Zending referentie'] ?? null),
            'received_at' => $this->parseDateTime($this->nullableString($metadata['Datum'] ?? null), 'Y-m-d'),
            'external_location_id' => $this->nullableString($metadata['Klantnummer Autovision'] ?? null),
            'location_name' => $this->nullableString($metadata['Bedrijfsnaam'] ?? null),
            'notes' => $this->buildNotes($metadata),
            'status' => RmaStatus::Open->value,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  list<list<string|null>>  $rows
     */
    public function supportsRows(array $rows): bool
    {
        foreach ($rows as $row) {
            $label = trim((string) ($row[0] ?? ''));

            if ($label === 'Zending referentie' || $label === 'Klantnummer Autovision') {
                return true;
            }
        }

        $title = trim((string) ($rows[0][0] ?? ''));

        return str_contains(mb_strtoupper($title), 'BULK RMA AANMELDFORMULIER');
    }

    /**
     * @param  list<list<string|null>>  $rows
     * @return array{
     *     metadata: array<string, string|null>,
     *     headers: list<string>,
     *     dataRows: list<list<string|null>>
     * }
     */
    public function extractSections(array $rows): array
    {
        $metadata = [];
        $headers = [];
        $dataRows = [];
        $headerRowIndex = null;

        foreach ($rows as $index => $row) {
            $label = trim((string) ($row[0] ?? ''));

            if ($label === '') {
                continue;
            }

            if ($this->isFooterRow($label)) {
                break;
            }

            if ($this->isDataHeaderRow($row)) {
                $headerRowIndex = $index;
                $headers = array_map(
                    fn (?string $header): string => trim((string) $header),
                    $row,
                );

                break;
            }

            if ($headerRowIndex === null && isset($row[1])) {
                $value = $row[1];

                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d');
                }

                $metadata[$label] = $this->nullableString(is_scalar($value) ? (string) $value : null);
            }
        }

        if ($headers === []) {
            return [
                'metadata' => $metadata,
                'headers' => [],
                'dataRows' => [],
            ];
        }

        for ($index = $headerRowIndex + 1; $index < count($rows); $index++) {
            $row = $rows[$index];
            $label = trim((string) ($row[0] ?? ''));

            if ($this->isFooterRow($label) || $this->readerIsEmptyRow($row)) {
                break;
            }

            if ($this->isDataHeaderRow($row)) {
                continue;
            }

            $dataRows[] = $row;
        }

        return [
            'metadata' => $metadata,
            'headers' => $headers,
            'dataRows' => $dataRows,
        ];
    }

    /**
     * @param  array<string, string|null>  $metadata
     */
    private function buildNotes(array $metadata): ?string
    {
        $parts = array_filter([
            $this->formatNoteLine('Adres', $metadata['Adres'] ?? null),
            $this->formatNoteLine('Huisnummer', $metadata['Huisnummer'] ?? null),
            $this->formatNoteLine('Postcode', isset($metadata['Postcode']) ? (string) $metadata['Postcode'] : null),
            $this->formatNoteLine('Plaats', $metadata['Plaats'] ?? null),
            $this->formatNoteLine('Land', $metadata['Land'] ?? null),
            $this->formatNoteLine('Telefoon', $metadata['Telefoon'] ?? null),
            $this->formatNoteLine('Email', $metadata['Email'] ?? $metadata['Email '] ?? null),
            $this->formatNoteLine('Contactpersoon', $metadata['Naam Contactpersoon'] ?? null),
        ]);

        if ($parts === []) {
            return null;
        }

        return implode("\n", $parts);
    }

    private function formatNoteLine(string $label, ?string $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        return "{$label}: {$value}";
    }

    private function isFooterRow(string $label): bool
    {
        return in_array($label, self::FOOTER_LABELS, true);
    }

    /**
     * @param  list<string|null>  $row
     */
    private function isDataHeaderRow(array $row): bool
    {
        $normalized = array_map(
            fn (?string $value): string => trim((string) $value),
            $row,
        );

        return in_array('UW RMA Referentie', $normalized, true)
            && (
                in_array('RMA NUMMER AUTOVISION', $normalized, true)
                || in_array('RMA NUMMER AUTOVISION ', $normalized, true)
            );
    }

    /**
     * @param  list<string|null>  $row
     */
    private function readerIsEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) ($value ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}

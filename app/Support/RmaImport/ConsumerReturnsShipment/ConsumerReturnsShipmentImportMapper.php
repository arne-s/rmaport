<?php

namespace App\Support\RmaImport\ConsumerReturnsShipment;

use App\Support\RmaImport\Concerns\MapsRmaImportRows;
use App\Support\RmaImport\ConsumerReturns\ConsumerReturnsImportMapper;

final class ConsumerReturnsShipmentImportMapper
{
    use MapsRmaImportRows;

    private const SECTION_HEADERS = [
        'ORDER DETAILS',
        'RECIPIENT DETAILS',
        'SHIPMENT DETAILS',
        'PRODUCT ITEM DETAILS',
    ];

    public function __construct(
        private readonly ConsumerReturnsImportMapper $consumerReturnsMapper = new ConsumerReturnsImportMapper,
    ) {}

    /**
     * @param  array<string, string|null>  $metadata
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>
     */
    public function map(array $metadata, array $row): array
    {
        $mapped = $this->consumerReturnsMapper->map($row);

        return array_filter(array_merge($mapped, [
            'packing_slip_number' => $this->nullableString($metadata['Shipment Reference'] ?? null),
            'notes' => $this->buildNotes($metadata),
        ]), fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  list<list<string|null>>  $rows
     */
    public function supportsRows(array $rows): bool
    {
        $hasOrderDetails = false;
        $hasConsumerHeader = false;

        foreach ($rows as $row) {
            $label = trim((string) ($row[0] ?? ''));

            if ($label === 'ORDER DETAILS') {
                $hasOrderDetails = true;
            }

            if ($this->consumerReturnsMapper->supportsHeaders($this->normalizeHeaderRow($row))) {
                $hasConsumerHeader = true;
            }
        }

        return $hasOrderDetails && $hasConsumerHeader;
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

            if ($this->isDataHeaderRow($row)) {
                $headerRowIndex = $index;
                $headers = $this->normalizeHeaderRow($row);

                break;
            }

            if ($headerRowIndex === null && $this->isMetadataRow($row)) {
                $metadataKey = $this->normalizeMetadataLabel($label);
                $value = $row[1] ?? null;

                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d');
                }

                $metadata[$metadataKey] = $this->nullableString(is_scalar($value) ? (string) $value : null);
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

            if ($this->isEmptyRow($row) || $this->isDataHeaderRow($row)) {
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
            $this->formatNoteLine('Bedrijfsnaam', $metadata['Company name'] ?? null),
            $this->formatNoteLine('Bol.com referentie', $metadata['Reference number (bol.com)'] ?? null),
            $this->formatNoteLine('Ontvanger referentie', $metadata['Reference number (recipient)'] ?? null),
            $this->formatNoteLine('Contact', $metadata['Contact'] ?? null),
            $this->formatNoteLine('Adres', $metadata['Address'] ?? null),
            $this->formatNoteLine('Vervoerder', $metadata['Shipment Carrier'] ?? null),
            $this->formatNoteLine('Track & Trace', $metadata['Track & Trace number'] ?? null),
            $this->formatNoteLine('Verzenddatum', $metadata['Shipment date'] ?? null),
            $this->formatNoteLine('Aantal verzonden', isset($metadata['Quantity shipped']) ? (string) $metadata['Quantity shipped'] : null),
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

    private function normalizeMetadataLabel(string $label): string
    {
        return rtrim(trim($label), ':');
    }

    /**
     * @param  list<string|null>  $row
     */
    private function isMetadataRow(array $row): bool
    {
        $label = trim((string) ($row[0] ?? ''));

        if ($label === '' || in_array($label, self::SECTION_HEADERS, true)) {
            return false;
        }

        return isset($row[1]) && trim((string) ($row[1] ?? '')) !== '';
    }

    /**
     * @param  list<string|null>  $row
     * @return list<string>
     */
    private function normalizeHeaderRow(array $row): array
    {
        return array_map(
            fn (?string $header): string => trim((string) $header),
            $row,
        );
    }

    /**
     * @param  list<string|null>  $row
     */
    private function isDataHeaderRow(array $row): bool
    {
        return $this->consumerReturnsMapper->supportsHeaders($this->normalizeHeaderRow($row));
    }

    /**
     * @param  list<string|null>  $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) ($value ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}

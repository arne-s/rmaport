<?php

namespace App\Support\RmaExport\Concerns;

use App\Models\ImportBatch;
use App\Models\ImportExport;
use App\Support\RmaImport\Concerns\MapsRmaImportRows;

trait BuildsImportRowRmaLookup
{
    use MapsRmaImportRows;

    /**
     * @return array<string, string>
     */
    protected function buildRmaUidLookup(ImportBatch $batch): array
    {
        $lookup = [];

        foreach ($batch->importRows as $row) {
            if ($row->rma === null) {
                continue;
            }

            $lookup[$this->importRowKey($row->reference, $row->ean_nr)] = $row->rma->uid;
        }

        return $lookup;
    }

    protected function importRowKey(?string $reference, ?string $ean): string
    {
        return ($reference ?? '').'|'.($this->normalizeEan($ean) ?? '');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function lookupKeyFromAttributes(array $attributes): string
    {
        return $this->importRowKey(
            $attributes['reference'] ?? null,
            $attributes['ean_nr'] ?? null,
        );
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $candidates
     */
    protected function findColumnIndex(array $headers, array $candidates): ?int
    {
        $normalizedHeaders = array_map(
            fn (string $header): string => trim($header),
            $headers,
        );

        foreach ($candidates as $candidate) {
            $index = array_search($candidate, $normalizedHeaders, true);

            if ($index !== false) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<string|null>  $row
     */
    protected function setRowValue(array &$row, int $columnIndex, string $value): void
    {
        while (count($row) <= $columnIndex) {
            $row[] = null;
        }

        $row[$columnIndex] = $value;
    }

    protected function sourceFilePath(ImportBatch $batch): string
    {
        return storage_path('app/'.$batch->file_path);
    }

    protected function fileExtension(ImportBatch $batch): string
    {
        return strtolower(pathinfo((string) $batch->file_name, PATHINFO_EXTENSION));
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function exportPaths(ImportBatch $batch, ImportExport $export): array
    {
        $relativePath = "exports/{$batch->id}/{$export->uid}.xlsx";
        $absolutePath = storage_path('app/'.$relativePath);

        return [$relativePath, $absolutePath];
    }
}

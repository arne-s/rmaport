<?php

namespace App\Support\RmaExport;

use App\Filament\Resources\ImportTasks\Support\ImportBatchMailRecipients;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Support\FormatDisplayDate;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

final class SpreadsheetPlaceholderExporter
{
    private const DATA_TEMPLATE_ROW = 14;

    private const LAST_DATA_COLUMN = 'F';

    /**
     * @param  array<int, string>  $rowComments  keyed by import row id
     */
    public function export(ImportBatch $batch, string $destinationPath, array $rowComments = []): void
    {
        $templatePath = resource_path('templates/return_universal.xlsx');

        if (! is_file($templatePath)) {
            throw new RuntimeException("Exporttemplate niet gevonden: {$templatePath}");
        }

        if (! is_dir(dirname($destinationPath))) {
            mkdir(dirname($destinationPath), 0755, true);
        }

        $batch->loadMissing(['importRows.rma', 'importRows.customer', 'importRows.source.customer']);

        $rowsWithRma = $batch->importRows
            ->filter(fn (ImportRow $row): bool => $row->rma !== null)
            ->sortBy('id')
            ->values();

        if ($rowsWithRma->isEmpty()) {
            throw new RuntimeException('Er zijn geen importrijen met een RMA om te exporteren.');
        }

        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        $this->replaceHeaderPlaceholders($sheet, $batch);

        foreach ($rowsWithRma as $index => $row) {
            $targetRow = self::DATA_TEMPLATE_ROW + $index;

            if ($index > 0) {
                $sheet->insertNewRowBefore($targetRow);
            }

            $this->replaceRowPlaceholders($sheet, $targetRow, $row, $rowComments);
        }

        $this->trimWorksheetToUsedRange($sheet, self::DATA_TEMPLATE_ROW + $rowsWithRma->count() - 1);

        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($destinationPath);
    }

    private function replaceHeaderPlaceholders(Worksheet $sheet, ImportBatch $batch): void
    {
        $vars = $this->headerVars($batch);

        foreach (['B2', 'B3', 'B4'] as $cellAddress) {
            $cell = $sheet->getCell($cellAddress);
            $value = $cell->getValue();

            if (! is_string($value) || ! str_contains($value, '[')) {
                continue;
            }

            $cell->setValue($this->replacePlaceholders($value, $vars));
        }
    }

    /**
     * @param  array<int, string>  $rowComments
     */
    private function replaceRowPlaceholders(
        Worksheet $sheet,
        int $targetRow,
        ImportRow $row,
        array $rowComments,
    ): void {
        $vars = $this->rowVars($row, $rowComments);

        $columns = [
            'A' => 'import_row.reference',
            'B' => 'import_row.ean',
            'C' => 'import_row.product_name',
            'D' => 'import_row.return_reason',
            'E' => 'rma.uid',
            'F' => 'comment',
        ];

        foreach ($columns as $column => $key) {
            $cell = $sheet->getCell("{$column}{$targetRow}");
            $value = $cell->getValue();

            if (is_string($value) && str_contains($value, '[')) {
                $cell->setValue($this->replacePlaceholders($value, $vars));

                continue;
            }

            $cell->setValue($vars[$key]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function headerVars(ImportBatch $batch): array
    {
        $customer = ImportBatchMailRecipients::resolveCustomer($batch);

        return [
            'customer.name' => $customer instanceof Customer ? (string) $customer->getName() : '',
            'import.reference' => (string) ($batch->reference ?? ''),
            'import.import_date' => $this->formatBatchDate($batch->import_date),
        ];
    }

    /**
     * @param  array<int, string>  $rowComments
     * @return array<string, string>
     */
    private function rowVars(ImportRow $row, array $rowComments): array
    {
        return [
            'import_row.reference' => (string) ($row->reference ?? ''),
            'import_row.ean' => (string) ($row->ean_nr ?? ''),
            'import_row.product_name' => (string) ($row->product_name ?? ''),
            'import_row.return_reason' => (string) ($row->return_reason ?? $row->rma?->return_reason ?? ''),
            'rma.uid' => (string) ($row->rma?->uid ?? ''),
            'comment' => (string) ($rowComments[$row->id] ?? $rowComments[(string) $row->id] ?? ''),
        ];
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function replacePlaceholders(string $value, array $vars): string
    {
        foreach ($vars as $key => $replacement) {
            $value = str_replace('['.$key.']', $replacement, $value);
        }

        return $value;
    }

    private function trimWorksheetToUsedRange(Worksheet $sheet, int $lastDataRow): void
    {
        $sheet->setSelectedCells('A1:'.self::LAST_DATA_COLUMN.$lastDataRow);
        $sheet->garbageCollect();
    }

    private function formatBatchDate(mixed $date): string
    {
        if ($date === null) {
            return '';
        }

        if ($date instanceof Carbon) {
            return FormatDisplayDate::longDate($date);
        }

        try {
            return FormatDisplayDate::longDate(Carbon::parse($date));
        } catch (\Throwable) {
            return '';
        }
    }
}

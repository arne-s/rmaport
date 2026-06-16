<?php

namespace App\Support\RmaExport\MediaMarkt;

use App\Models\ImportBatch;
use App\Models\ImportExport;
use App\Models\ImportTemplate;
use App\Services\Import\ImportRowTransformer;
use App\Support\RmaExport\Concerns\BuildsImportRowRmaLookup;
use App\Support\RmaExport\Contracts\RmaExportGenerator;
use App\Support\RmaImport\MediaMarkt\MediaMarktImportMapper;
use App\Support\RmaImport\SpreadsheetPhpReader;
use App\Support\RmaImport\SpreadsheetTableEditor;
use App\Support\RmaImport\SpreadsheetTableReader;
use App\Support\RmaImport\SpreadsheetTableWriter;
use RuntimeException;

final class MediaMarktExportGenerator implements RmaExportGenerator
{
    use BuildsImportRowRmaLookup;

    public function __construct(
        private readonly SpreadsheetPhpReader $phpReader = new SpreadsheetPhpReader,
        private readonly SpreadsheetTableReader $reader = new SpreadsheetTableReader,
        private readonly SpreadsheetTableEditor $editor = new SpreadsheetTableEditor,
        private readonly SpreadsheetTableWriter $writer = new SpreadsheetTableWriter,
        private readonly MediaMarktImportMapper $mapper = new MediaMarktImportMapper,
        private readonly ImportRowTransformer $transformer = new ImportRowTransformer,
    ) {}

    public function generate(ImportBatch $batch, ImportExport $export): string
    {
        $path = $this->sourceFilePath($batch);
        $extension = $this->fileExtension($batch);

        if ($extension !== 'xlsx') {
            return $this->generateFromFlatTable($batch, $export, $path, $extension);
        }

        $sheetRows = $this->phpReader->readRows($path);
        $allRows = array_map(
            fn (array $row): array => $row['values'],
            $sheetRows,
        );
        $headerIndex = $this->findHeaderRowIndex($allRows);

        if ($headerIndex === null) {
            throw new RuntimeException('Datakopregel niet gevonden in het importbestand.');
        }

        $headers = $this->mapper->normalizeHeaders(array_map(
            fn (?string $header): string => trim((string) $header),
            $allRows[$headerIndex],
        ));

        $rmaColumnIndex = $this->findColumnIndex($headers, ['RMA-nummer']);

        if ($rmaColumnIndex === null) {
            throw new RuntimeException('RMA-kolom niet gevonden in het importbestand.');
        }

        $template = $batch->importTemplate;

        if (! $template instanceof ImportTemplate) {
            throw new RuntimeException('Importtemplate ontbreekt.');
        }

        $lookup = $this->buildRmaUidLookup($batch);
        $cellUpdates = [];

        for ($index = $headerIndex + 1; $index < count($sheetRows); $index++) {
            $row = $sheetRows[$index]['values'];

            if ($this->reader->isEmptyRow($row)) {
                continue;
            }

            $assoc = $this->reader->combineHeadersWithValues($headers, $row);
            $attributes = $this->transformer->transform($template, $assoc);

            if ($attributes === []) {
                continue;
            }

            $key = $this->lookupKeyFromAttributes($attributes);

            if (! isset($lookup[$key])) {
                continue;
            }

            $cellUpdates[] = [
                'row' => $sheetRows[$index]['row'],
                'column' => $rmaColumnIndex,
                'value' => $lookup[$key],
            ];
        }

        [$relativePath, $absolutePath] = $this->exportPaths($batch, $export);

        $this->editor->copyAndUpdateCells($path, $absolutePath, $cellUpdates);

        return $relativePath;
    }

    private function generateFromFlatTable(
        ImportBatch $batch,
        ImportExport $export,
        string $path,
        string $extension,
    ): string {
        $table = $this->reader->readFlatTable($path, $extension);
        $headers = $this->mapper->normalizeHeaders($table['headers']);

        $rmaColumnIndex = $this->findColumnIndex($headers, ['RMA-nummer']);

        if ($rmaColumnIndex === null) {
            throw new RuntimeException('RMA-kolom niet gevonden in het importbestand.');
        }

        $template = $batch->importTemplate;

        if (! $template instanceof ImportTemplate) {
            throw new RuntimeException('Importtemplate ontbreekt.');
        }

        $lookup = $this->buildRmaUidLookup($batch);
        $rows = [];

        foreach ($table['rows'] as $values) {
            $assoc = $this->reader->combineHeadersWithValues($headers, $values);
            $attributes = $this->transformer->transform($template, $assoc);
            $key = $attributes !== [] ? $this->lookupKeyFromAttributes($attributes) : '';

            if ($key !== '' && isset($lookup[$key])) {
                $this->setRowValue($values, $rmaColumnIndex, $lookup[$key]);
            }

            $rows[] = $values;
        }

        [$relativePath, $absolutePath] = $this->exportPaths($batch, $export);

        $this->writer->writeAllRows($absolutePath, array_merge([$headers], $rows));

        return $relativePath;
    }

    /**
     * @param  list<list<string|null>>  $rows
     */
    private function findHeaderRowIndex(array $rows): ?int
    {
        foreach ($rows as $index => $row) {
            $normalized = array_map(
                fn (?string $value): string => trim((string) $value),
                $row,
            );

            if ($this->mapper->supportsHeaders($normalized)) {
                return $index;
            }
        }

        return null;
    }
}

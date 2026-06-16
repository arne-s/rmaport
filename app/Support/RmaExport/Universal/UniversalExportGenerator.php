<?php

namespace App\Support\RmaExport\Universal;

use App\Models\ImportBatch;
use App\Models\ImportExport;
use App\Models\ImportTemplate;
use App\Services\Import\ImportRowTransformer;
use App\Support\RmaExport\Concerns\BuildsImportRowRmaLookup;
use App\Support\RmaExport\Contracts\RmaExportGenerator;
use App\Support\RmaImport\SpreadsheetPhpReader;
use App\Support\RmaImport\SpreadsheetTableEditor;
use App\Support\RmaImport\SpreadsheetTableReader;
use App\Support\RmaImport\Universal\UniversalImportMapper;
use RuntimeException;

final class UniversalExportGenerator implements RmaExportGenerator
{
    use BuildsImportRowRmaLookup;

    private const FOOTER_LABELS = [
        'Gecrediteerd',
        'Afgehandeld',
        'Reparatie',
    ];

    public function __construct(
        private readonly SpreadsheetPhpReader $phpReader = new SpreadsheetPhpReader,
        private readonly SpreadsheetTableReader $reader = new SpreadsheetTableReader,
        private readonly SpreadsheetTableEditor $editor = new SpreadsheetTableEditor,
        private readonly UniversalImportMapper $mapper = new UniversalImportMapper,
        private readonly ImportRowTransformer $transformer = new ImportRowTransformer,
    ) {}

    public function generate(ImportBatch $batch, ImportExport $export): string
    {
        $path = $this->sourceFilePath($batch);
        $sheetRows = $this->phpReader->readRows($path);
        $allRows = array_map(
            fn (array $row): array => $row['values'],
            $sheetRows,
        );
        $sections = $this->mapper->extractSections($allRows);

        if ($sections['headers'] === []) {
            throw new RuntimeException('Geen datakoppen gevonden in het importbestand.');
        }

        $headerIndex = $this->findHeaderRowIndex($allRows);

        if ($headerIndex === null) {
            throw new RuntimeException('Datakopregel niet gevonden in het importbestand.');
        }

        $rmaColumnIndex = $this->findColumnIndex($sections['headers'], [
            'RMA NUMMER AUTOVISION',
            'RMA NUMMER AUTOVISION ',
        ]);

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
            $label = trim((string) ($row[0] ?? ''));

            if ($this->isFooterRow($label) || $this->reader->isEmptyRow($row)) {
                break;
            }

            if ($this->isDataHeaderRow($row)) {
                continue;
            }

            $assoc = $this->reader->combineHeadersWithValues($sections['headers'], $row);
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

    /**
     * @param  list<list<string|null>>  $rows
     */
    private function findHeaderRowIndex(array $rows): ?int
    {
        foreach ($rows as $index => $row) {
            if ($this->isDataHeaderRow($row)) {
                return $index;
            }
        }

        return null;
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

    private function isFooterRow(string $label): bool
    {
        return in_array($label, self::FOOTER_LABELS, true);
    }
}

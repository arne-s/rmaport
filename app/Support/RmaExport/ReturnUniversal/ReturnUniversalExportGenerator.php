<?php

namespace App\Support\RmaExport\ReturnUniversal;

use App\Models\ImportBatch;
use App\Models\ImportExport;
use App\Support\RmaExport\Concerns\BuildsImportRowRmaLookup;
use App\Support\RmaExport\Contracts\RmaExportGenerator;
use App\Support\RmaExport\SpreadsheetPlaceholderExporter;

final class ReturnUniversalExportGenerator implements RmaExportGenerator
{
    use BuildsImportRowRmaLookup;

    public function __construct(
        private readonly SpreadsheetPlaceholderExporter $exporter = new SpreadsheetPlaceholderExporter,
    ) {}

    /**
     * @param  array<int, string>  $rowComments  keyed by import row id
     */
    public function generate(ImportBatch $batch, ImportExport $export, array $rowComments = []): string
    {
        [$relativePath, $absolutePath] = $this->exportPaths($batch, $export);

        $this->exporter->export($batch, $absolutePath, $rowComments);

        return $relativePath;
    }
}

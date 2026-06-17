<?php

namespace App\Support\RmaExport\Contracts;

use App\Models\ImportBatch;
use App\Models\ImportExport;

interface RmaExportGenerator
{
    /**
     * Generate export file and return the storage-relative path.
     *
     * @param  array<int, string>  $rowComments  Opmerkingen uit sheet retour modal, keyed by import row id
     */
    public function generate(ImportBatch $batch, ImportExport $export, array $rowComments = []): string;
}

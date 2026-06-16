<?php

namespace App\Support\RmaExport\Contracts;

use App\Models\ImportBatch;
use App\Models\ImportExport;

interface RmaExportGenerator
{
    /**
     * Generate export file and return the storage-relative path.
     */
    public function generate(ImportBatch $batch, ImportExport $export): string;
}

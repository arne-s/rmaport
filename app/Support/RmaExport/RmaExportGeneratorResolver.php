<?php

namespace App\Support\RmaExport;

use App\Models\ExportTemplate;
use App\Support\RmaExport\Contracts\RmaExportGenerator;
use InvalidArgumentException;

final class RmaExportGeneratorResolver
{
    public function resolve(ExportTemplate $template): RmaExportGenerator
    {
        $class = $template->class;

        if (! class_exists($class)) {
            throw new InvalidArgumentException("Export generator class [{$class}] bestaat niet.");
        }

        $generator = app($class);

        if (! $generator instanceof RmaExportGenerator) {
            throw new InvalidArgumentException("Export generator [{$class}] moet RmaExportGenerator implementeren.");
        }

        return $generator;
    }
}

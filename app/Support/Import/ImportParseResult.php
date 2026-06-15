<?php

namespace App\Support\Import;

use App\Models\ImportTemplate;

final class ImportParseResult
{
    /**
     * @param  array<string, string|null>  $metadata
     * @param  list<array<string, string|null>>  $rows
     */
    public function __construct(
        public ImportTemplate $template,
        public array $metadata,
        public array $rows,
        public ?int $detectedCustomerId = null,
        public ?string $reference = null,
        public ?string $trackTraceNr = null,
        public ?string $shipmentDate = null,
    ) {}

    public function rowCount(): int
    {
        return count($this->rows);
    }
}

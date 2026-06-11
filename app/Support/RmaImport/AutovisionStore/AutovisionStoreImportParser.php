<?php

namespace App\Support\RmaImport\AutovisionStore;

use App\Enums\RmaImportTemplate;
use App\Support\RmaImport\Contracts\RmaImportParser;
use App\Support\RmaImport\SpreadsheetTableReader;

final class AutovisionStoreImportParser implements RmaImportParser
{
    public function __construct(
        private readonly SpreadsheetTableReader $reader = new SpreadsheetTableReader,
        private readonly AutovisionStoreImportMapper $mapper = new AutovisionStoreImportMapper,
    ) {}

    public function template(): RmaImportTemplate
    {
        return RmaImportTemplate::AutovisionStore;
    }

    public function supports(string $path, string $extension): bool
    {
        if (strtolower($extension) !== 'xlsx') {
            return false;
        }

        $rows = $this->reader->readAllRows($path);

        return $this->mapper->supportsRows($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $path, string $extension): array
    {
        $rows = $this->reader->readAllRows($path);
        $sections = $this->mapper->extractSections($rows);
        $mappedRows = [];

        foreach ($sections['dataRows'] as $values) {
            $row = $this->reader->combineHeadersWithValues($sections['headers'], $values);
            $mapped = $this->mapper->map($sections['metadata'], $row);

            if (filled($mapped['uid'] ?? null)) {
                $mappedRows[] = $mapped;
            }
        }

        return $mappedRows;
    }
}

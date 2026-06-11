<?php

namespace App\Support\RmaImport\MediaMarkt;

use App\Enums\RmaImportTemplate;
use App\Support\RmaImport\Contracts\RmaImportParser;
use App\Support\RmaImport\SpreadsheetTableReader;

final class MediaMarktImportParser implements RmaImportParser
{
    public function __construct(
        private readonly SpreadsheetTableReader $reader = new SpreadsheetTableReader,
        private readonly MediaMarktImportMapper $mapper = new MediaMarktImportMapper,
    ) {}

    public function template(): RmaImportTemplate
    {
        return RmaImportTemplate::MediaMarkt;
    }

    public function supports(string $path, string $extension): bool
    {
        if (! in_array(strtolower($extension), ['csv', 'txt', 'xlsx'], true)) {
            return false;
        }

        $table = $this->reader->readFlatTable($path, $extension);

        return $this->mapper->supportsHeaders($table['headers']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $path, string $extension): array
    {
        $table = $this->reader->readFlatTable($path, $extension);
        $headers = $this->mapper->normalizeHeaders($table['headers']);
        $mappedRows = [];

        foreach ($table['rows'] as $values) {
            $row = $this->reader->combineHeadersWithValues($headers, $values);
            $mappedRows[] = $this->mapper->map($row);
        }

        return $mappedRows;
    }
}

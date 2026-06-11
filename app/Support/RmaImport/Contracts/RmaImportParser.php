<?php

namespace App\Support\RmaImport\Contracts;

use App\Enums\RmaImportTemplate;

interface RmaImportParser
{
    public function template(): RmaImportTemplate;

    public function supports(string $path, string $extension): bool;

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $path, string $extension): array;
}

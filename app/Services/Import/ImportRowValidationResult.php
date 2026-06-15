<?php

namespace App\Services\Import;

final class ImportRowValidationResult
{
    /**
     * @param  list<ImportRowValidationIssue>  $issues
     */
    public function __construct(
        public readonly int $total,
        public readonly int $newCount,
        public readonly int $existingCount,
        public readonly int $invalidCount,
        public readonly array $issues,
    ) {}

    public function summaryLabel(): string
    {
        return sprintf(
            '%d rijen totaal, %d nieuw, %d bestaand, %d ongeldig.',
            $this->total,
            $this->newCount,
            $this->existingCount,
            $this->invalidCount,
        );
    }

    /**
     * @return list<ImportRowValidationIssue>
     */
    public function invalidIssues(): array
    {
        return array_values(array_filter(
            $this->issues,
            fn (ImportRowValidationIssue $issue): bool => $issue->isInvalid(),
        ));
    }

    /**
     * @return list<ImportRowValidationIssue>
     */
    public function overviewIssues(): array
    {
        return array_values(array_filter(
            $this->issues,
            fn (ImportRowValidationIssue $issue): bool => $issue->isInvalid() || $issue->isExisting(),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function newRowAttributes(): array
    {
        return array_map(
            fn (ImportRowValidationIssue $issue): array => $issue->attributes,
            array_values(array_filter(
                $this->issues,
                fn (ImportRowValidationIssue $issue): bool => $issue->isNew(),
            )),
        );
    }
}

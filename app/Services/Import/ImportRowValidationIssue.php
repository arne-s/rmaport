<?php

namespace App\Services\Import;

use App\Enums\Import\ImportRowValidationStatus;

final class ImportRowValidationIssue
{
    /**
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly ImportRowValidationStatus $status,
        public readonly ?string $reference,
        public readonly ?string $eanNr,
        public readonly array $reasons = [],
        public readonly array $attributes = [],
    ) {}

    public function isInvalid(): bool
    {
        return $this->status === ImportRowValidationStatus::Invalid;
    }

    public function isNew(): bool
    {
        return $this->status === ImportRowValidationStatus::New;
    }

    public function isExisting(): bool
    {
        return $this->status === ImportRowValidationStatus::Existing;
    }

    public function reasonLabel(): string
    {
        return implode(', ', $this->reasons);
    }

    public function overviewLabel(): string
    {
        if ($this->isExisting()) {
            return 'Bestaat al';
        }

        return $this->reasonLabel();
    }
}

<?php

namespace App\Filament\Imports;

use App\Enums\RmaCsvFormat;
use App\Enums\RmaStatus;
use App\Models\Rma;
use App\Services\RmaCsvRowMapper;
use App\Support\RmaCsvSchema;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\ValidationException;

class RmaImporter extends Importer
{
    protected static ?string $model = Rma::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('uid')
                ->label('RMA Nummer')
                ->exampleHeader('RMA-nummer')
                ->rules(['nullable', 'max:20']),

            ImportColumn::make('order_nr')
                ->label('Ordernummer')
                ->exampleHeader('Opdrachtnummer')
                ->rules(['nullable', 'max:50']),
        ];
    }

    public function resolveRecord(): Rma
    {
        $this->data = $this->mapImportRow($this->originalData);

        $uid = $this->data['uid'] ?? null;

        if (! filled($uid)) {
            throw ValidationException::withMessages([
                'uid' => 'Geen RMA-nummer of fallback-id gevonden in deze rij.',
            ]);
        }

        return Rma::query()->firstOrNew(['uid' => $uid]);
    }

    protected function beforeValidate(): void
    {
        $this->data = $this->mapImportRow($this->originalData);

        if (blank($this->data['uid'] ?? null)) {
            throw ValidationException::withMessages([
                'uid' => 'Geen RMA-nummer of fallback-id gevonden in deze rij.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapImportRow(array $row): array
    {
        $format = RmaCsvSchema::detectFormat(array_keys($row));

        $mapper = app(RmaCsvRowMapper::class);

        return match ($format) {
            RmaCsvFormat::MediaMarkt => $mapper->mapMediaMarktRow($row),
            RmaCsvFormat::ConsumerReturns => $mapper->mapConsumerReturnsRow($row),
        };
    }

    protected function beforeCreate(): void
    {
        if (blank($this->record->status)) {
            $this->record->status = RmaStatus::Open;
        }

        $this->record->is_draft = false;
    }

    public function fillRecord(): void
    {
        $this->record->fill($this->data);
        $this->record->is_draft = false;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function getValidationRules(): array
    {
        return [
            'uid' => ['required', 'string', 'max:20'],
        ];
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'De RMA-import is voltooid. ' . $import->successful_rows . ' ' . ($import->successful_rows === 1 ? 'rij' : 'rijen') . ' geïmporteerd.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . $failedRowsCount . ' ' . ($failedRowsCount === 1 ? 'rij' : 'rijen') . ' mislukt.';
        }

        return $body;
    }

    public function getJobConnection(): ?string
    {
        return 'sync';
    }
}

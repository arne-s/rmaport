<?php

namespace App\Support\FormImport;

use App\Models\FormImport;
use App\Models\FormImportEntryLog;
use App\Models\FormImportState;
use App\Models\Concerns\ResolvesRmaProductFromEan;
use App\Models\Rma;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportFormEntriesAction
{
    use ResolvesRmaProductFromEan;

    public function __construct(
        private readonly FormImportApiClient $client,
        private readonly ConfigurableFormImportEntryMapper $mapper,
    ) {}

    /**
     * @return array{imported: int, skipped: int, failed: int}
     */
    public function sync(FormImport $formImport, bool $full = false): array
    {
        $formImport->loadMissing(['connection', 'fieldMappings', 'state']);

        $connection = $formImport->connection;

        if ($connection === null || ! $connection->is_active || ! $formImport->is_active) {
            return ['imported' => 0, 'skipped' => 0, 'failed' => 0];
        }

        if ($formImport->fieldMappings->isEmpty()) {
            throw new RuntimeException('Geen veld-koppelingen geconfigureerd voor dit formulier.');
        }

        $sinceEntryId = $full ? null : ($formImport->state?->last_entry_id ?? 0);
        $page = 1;
        $pageSize = config('form-import.page_size', 100);
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $maxEntryId = $sinceEntryId ?? 0;

        do {
            $result = $this->client->getEntries(
                $connection,
                $formImport->source_form_id,
                $sinceEntryId,
                $page,
                $pageSize,
            );

            $entries = $result['entries'];

            foreach ($entries as $entry) {
                $entryId = (int) ($entry['id'] ?? 0);

                if ($entryId === 0) {
                    $failed++;

                    continue;
                }

                $maxEntryId = max($maxEntryId, $entryId);

                if (FormImportEntryLog::query()
                    ->where('source_form_id', $formImport->source_form_id)
                    ->where('source_entry_id', $entryId)
                    ->exists()) {
                    $skipped++;

                    continue;
                }

                try {
                    $this->importEntry($formImport, $entry);
                    $imported++;
                } catch (\Throwable) {
                    $failed++;
                }
            }

            $page++;
        } while (count($entries) === $pageSize);

        if ($maxEntryId > ($formImport->state?->last_entry_id ?? 0)) {
            FormImportState::query()->updateOrCreate(
                ['form_import_id' => $formImport->id],
                ['last_entry_id' => $maxEntryId],
            );
        }

        if ($imported > 0) {
            $formImport->update([
                'last_imported_at' => now(),
                'last_imported_count' => $imported,
            ]);
        }

        return compact('imported', 'skipped', 'failed');
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function importEntry(FormImport $formImport, array $entry): void
    {
        $attributes = $this->mapper->map($formImport, $entry);

        if (blank($attributes['uid'] ?? null)) {
            throw new RuntimeException('Geen RMA-nummer beschikbaar voor inzending.');
        }

        DB::transaction(function () use ($formImport, $entry, $attributes): void {
            $rma = Rma::query()->firstOrNew(['uid' => $attributes['uid']]);
            $this->applyRmaImportData($rma, $attributes);
            $rma->is_draft = false;
            $rma->save();

            FormImportEntryLog::query()->create([
                'form_import_id' => $formImport->id,
                'source_form_id' => $formImport->source_form_id,
                'source_entry_id' => (int) $entry['id'],
                'rma_id' => $rma->id,
                'imported_at' => now(),
                'payload' => $entry,
            ]);

            $rma->rmaEvents()->create([
                'type' => 'Geïmporteerd via formulier-import',
                'data' => [
                    'source_entry_id' => (int) $entry['id'],
                    'source_form_id' => $formImport->source_form_id,
                    'source_url' => $entry['source_url'] ?? null,
                    'connection' => $formImport->connection?->name,
                ],
                'user_id' => null,
            ]);
        });
    }
}

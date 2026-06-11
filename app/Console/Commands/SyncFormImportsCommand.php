<?php

namespace App\Console\Commands;

use App\Models\FormImport;
use App\Support\FormImport\ImportFormEntriesAction;
use Illuminate\Console\Command;

class SyncFormImportsCommand extends Command
{
    protected $signature = 'form-import:sync
                            {--import= : Sync a single form import by ID}
                            {--full : Import all entries, ignoring the last synced entry ID}';

    protected $description = 'Import form submissions from configured sources as RMA records';

    public function handle(ImportFormEntriesAction $action): int
    {
        $query = FormImport::query()
            ->with(['connection', 'fieldMappings', 'state'])
            ->where('is_active', true)
            ->whereHas('connection', fn ($builder) => $builder->where('is_active', true));

        if ($importId = $this->option('import')) {
            $query->whereKey($importId);
        }

        $imports = $query->get();

        if ($imports->isEmpty()) {
            $this->info('Geen actieve formulier-imports gevonden.');

            return self::SUCCESS;
        }

        $full = (bool) $this->option('full');
        $totalImported = 0;
        $totalSkipped = 0;
        $totalFailed = 0;

        foreach ($imports as $import) {
            try {
                $result = $action->sync($import, $full);
                $totalImported += $result['imported'];
                $totalSkipped += $result['skipped'];
                $totalFailed += $result['failed'];

                $this->line(sprintf(
                    '%s (#%d): %d geïmporteerd, %d overgeslagen, %d mislukt.',
                    $import->source_form_title,
                    $import->source_form_id,
                    $result['imported'],
                    $result['skipped'],
                    $result['failed'],
                ));
            } catch (\Throwable $exception) {
                $this->error("{$import->source_form_title}: {$exception->getMessage()}");
            }
        }

        $this->info("Totaal: {$totalImported} geïmporteerd, {$totalSkipped} overgeslagen, {$totalFailed} mislukt.");

        return self::SUCCESS;
    }
}

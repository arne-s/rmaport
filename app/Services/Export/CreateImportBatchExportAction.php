<?php

namespace App\Services\Export;

use App\Models\ImportBatch;
use App\Models\ImportExport;
use App\Models\User;
use App\Support\RmaExport\RmaExportGeneratorResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CreateImportBatchExportAction
{
    public function __construct(
        private readonly RmaExportGeneratorResolver $generatorResolver = new RmaExportGeneratorResolver,
    ) {}

    public function __invoke(ImportBatch $batch, User $user): ?ImportExport
    {
        $batch->loadMissing([
            'export',
            'importTemplate.exportTemplate',
            'importRows.rma',
        ]);

        if ($batch->export !== null) {
            return null;
        }

        $exportTemplate = $batch->importTemplate?->exportTemplate;

        if ($exportTemplate === null) {
            throw new RuntimeException('Er is geen exporttemplate gekoppeld aan dit importtemplate.');
        }

        $rowsWithRma = $batch->importRows->filter(
            fn ($row): bool => $row->rma !== null,
        );

        if ($rowsWithRma->isEmpty()) {
            throw new RuntimeException('Er zijn geen importrijen met een RMA om te exporteren.');
        }

        return DB::transaction(function () use ($batch, $user, $exportTemplate): ImportExport {
            /** @var ImportExport $export */
            $export = ImportExport::query()->create([
                'import_id' => $batch->id,
                'file_disk' => 'local',
                'file_name' => 'pending.xlsx',
                'user_id' => $user->id,
            ]);

            $relativePath = $this->generatorResolver
                ->resolve($exportTemplate)
                ->generate($batch, $export);

            $export->update([
                'file_name' => "{$export->uid}.xlsx",
            ]);

            return $export->fresh();
        });
    }
}

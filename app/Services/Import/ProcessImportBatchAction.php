<?php

namespace App\Services\Import;

use App\Actions\Import\CreateRmaFromImportRowAction;
use App\Filament\Imports\RmaStagingImporter;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\ImportTemplate;
use App\Models\Source;
use App\Models\User;
use App\Support\Import\ImportParseResult;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ProcessImportBatchAction
{
    public function __construct(
        private readonly ImportRowValidator $validator = new ImportRowValidator,
        private readonly CreateRmaFromImportRowAction $createRmaFromImportRow = new CreateRmaFromImportRowAction,
    ) {}

    /**
     * @param  array{
     *     customer_id: int,
     *     track_trace_nr?: string|null,
     *     reference?: string|null,
     *     shipment_date?: string|null,
     * }  $batchData
     * @return array{batch: ImportBatch, validation: ImportRowValidationResult}
     */
    public function __invoke(
        ImportParseResult $parseResult,
        array $batchData,
        UploadedFile $file,
        User $user,
    ): array {
        $customer = Customer::query()->findOrFail($batchData['customer_id']);

        $validation = $this->validator->validate(
            $parseResult->template,
            $customer->id,
            $parseResult->rows,
        );

        $source = Source::query()->firstOrCreate(
            [
                'customer_id' => $customer->id,
                'import_template_id' => $parseResult->template->id,
            ],
            [
                'name' => $customer->name ?? trim($customer->full_name) ?: 'Bron '.$customer->id,
            ],
        );

        $batch = DB::transaction(function () use ($parseResult, $batchData, $file, $user, $source, $validation): ImportBatch {
            $createRmaFromImportRow = $this->createRmaFromImportRow;
            /** @var ImportBatch $batch */
            $batch = app(Import::class);
            $batch->user()->associate($user);
            $batch->file_name = $file->getClientOriginalName();
            $batch->file_path = 'pending';
            $batch->importer = RmaStagingImporter::class;
            $batch->import_template_id = $parseResult->template->id;
            $batch->track_trace_nr = $batchData['track_trace_nr'] ?? null;
            $batch->reference = $batchData['reference'] ?? null;
            $batch->shipment_date = filled($batchData['shipment_date'] ?? null)
                ? Carbon::parse($batchData['shipment_date'])
                : null;
            $batch->total_rows = $validation->total;
            $batch->save();

            $storedPath = Storage::disk('local')->putFileAs(
                "imports/{$batch->id}",
                $file,
                $file->getClientOriginalName(),
            );
            $batch->update(['file_path' => $storedPath]);

            foreach ($validation->newRowAttributes() as $attributes) {
                $importRow = ImportRow::query()->create([
                    ...$attributes,
                    'import_id' => $batch->id,
                    'customer_id' => $source->customer_id,
                    'source_id' => $source->id,
                ]);

                $createRmaFromImportRow($importRow);
            }

            $batch->update([
                'processed_rows' => $validation->total,
                'successful_rows' => $validation->newCount,
                'completed_at' => now(),
            ]);

            return $batch->fresh(['importTemplate', 'importRows']);
        });

        return [
            'batch' => $batch,
            'validation' => $validation,
        ];
    }

    public function resolveTemplate(?int $templateId): ?ImportTemplate
    {
        if ($templateId === null) {
            return null;
        }

        return ImportTemplate::query()->with('source.customer')->find($templateId);
    }
}

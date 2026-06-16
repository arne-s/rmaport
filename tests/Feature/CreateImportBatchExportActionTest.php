<?php

use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportExport;
use App\Models\ImportRow;
use App\Models\ImportTemplate;
use App\Models\User;
use App\Services\Export\CreateImportBatchExportAction;
use App\Services\Import\ParseImportFileAction;
use App\Services\Import\ProcessImportBatchAction;
use App\Support\RmaImport\MediaMarkt\MediaMarktImportParser;
use Database\Seeders\ImportTemplateSeeder;
use Database\Seeders\RmaImportTestProductsSeeder;
use Illuminate\Http\UploadedFile;

beforeEach(function (): void {
    $this->seed(ImportTemplateSeeder::class);
    $this->seed(RmaImportTestProductsSeeder::class);
});

it('creates an export for an import batch with rows that have rmas', function (): void {
    $fixture = base_path('tests/fixtures/rma/media-markt-export.xlsx');
    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();
    $parseResult = app(ParseImportFileAction::class)($fixture, 'xlsx', $template);

    $customer = Customer::query()->findOrFail($parseResult->detectedCustomerId);
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Export',
        'last_name' => 'Tester',
    ]);

    $uploadedFile = new UploadedFile(
        $fixture,
        'media-markt-export.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    );

    $result = app(ProcessImportBatchAction::class)(
        parseResult: $parseResult,
        batchData: [
            'customer_id' => $customer->id,
            'track_trace_nr' => 'TT123',
            'reference' => 'REF-001',
            'shipment_date' => '2026-06-01',
        ],
        file: $uploadedFile,
        user: $user,
    );

    /** @var ImportBatch $batch */
    $batch = $result['batch'];

    $export = app(CreateImportBatchExportAction::class)($batch->fresh(['export', 'importTemplate.exportTemplate', 'importRows.rma']), $user);

    expect($export)->toBeInstanceOf(ImportExport::class)
        ->and($export->uid)->toMatch('/^EX-\d{7}$/')
        ->and($export->import_id)->toBe($batch->id)
        ->and($export->file_name)->toBe("{$export->uid}.xlsx")
        ->and(file_exists(storage_path("app/exports/{$batch->id}/{$export->uid}.xlsx")))->toBeTrue();

    expect($batch->fresh()->export?->id)->toBe($export->id);
});

it('does not create a duplicate export when one already exists', function (): void {
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Export',
        'last_name' => 'Tester',
    ]);

    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();

    $batch = ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'import_template_id' => $template->id,
    ]);

    ImportExport::query()->create([
        'import_id' => $batch->id,
        'uid' => 'EX-0000001',
        'file_disk' => 'local',
        'file_name' => 'export.xlsx',
        'user_id' => $user->id,
    ]);

    $result = app(CreateImportBatchExportAction::class)($batch->fresh(['export', 'importTemplate.exportTemplate', 'importRows.rma']), $user);

    expect($result)->toBeNull()
        ->and(ImportExport::query()->where('import_id', $batch->id)->count())->toBe(1);
});

it('throws when import template has no export template', function (): void {
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Export',
        'last_name' => 'Tester',
    ]);

    $template = ImportTemplate::query()->create([
        'name' => 'Zonder export',
        'filename' => 'none.xlsx',
        'class' => 'App\\Support\\RmaImport\\NoneImportParser',
        'type' => \App\Enums\ImportTemplateType::File,
        'export_template_id' => null,
    ]);

    $batch = ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'import_template_id' => $template->id,
    ]);

    app(CreateImportBatchExportAction::class)($batch->fresh(['export', 'importTemplate.exportTemplate', 'importRows.rma']), $user);
})->throws(RuntimeException::class, 'Er is geen exporttemplate gekoppeld aan dit importtemplate.');

it('throws when no import rows have an rma', function (): void {
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Export',
        'last_name' => 'Tester',
    ]);

    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();

    $batch = ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'import_template_id' => $template->id,
    ]);

    ImportRow::query()->create([
        'import_id' => $batch->id,
        'reference' => 'REF-001',
        'ean_nr' => '8715465017075',
    ]);

    app(CreateImportBatchExportAction::class)($batch->fresh(['export', 'importTemplate.exportTemplate', 'importRows.rma']), $user);
})->throws(RuntimeException::class, 'Er zijn geen importrijen met een RMA om te exporteren.');

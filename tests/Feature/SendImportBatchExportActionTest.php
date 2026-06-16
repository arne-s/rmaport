<?php

use App\Enums\CustomerStatus;
use App\Enums\RmaStatus;
use App\Filament\Resources\ImportTasks\Pages\ListImportTasks;
use App\Mail\ImportBatchExportMail;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportExport;
use App\Models\ImportRow;
use App\Models\ImportTemplate;
use App\Models\Rma;
use App\Models\Source;
use App\Models\User;
use App\Services\Import\ParseImportFileAction;
use App\Services\Import\ProcessImportBatchAction;
use App\Support\RmaImport\MediaMarkt\MediaMarktImportParser;
use Database\Seeders\ImportTemplateSeeder;
use Database\Seeders\RmaImportTestProductsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    Permission::findOrCreate('manage sales', 'web');
    Permission::findOrCreate('access filament panel', 'web');
    $this->seed(ImportTemplateSeeder::class);
    $this->seed(RmaImportTestProductsSeeder::class);
});

it('creates export and sends email when sendExport action is submitted', function (): void {
    Mail::fake();

    $fixture = base_path('tests/fixtures/rma/media-markt-export.xlsx');
    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();
    $parseResult = app(ParseImportFileAction::class)($fixture, 'xlsx', $template);

    $customer = Customer::query()->findOrFail($parseResult->detectedCustomerId);
    $customer->update(['email' => 'klant@import-test.example']);

    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Export',
        'last_name' => 'Sender',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage sales']);

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
    $batch = $result['batch']->fresh(['export', 'importTemplate.exportTemplate', 'importRows.rma']);

    $this->actingAs($user);

    Livewire::test(ListImportTasks::class)
        ->callAction(TestAction::make('sendExport')->table($batch), [
            'from' => 'orders@example.com',
            'to' => ['customer'],
            'cc' => [],
            'bcc' => [],
            'subject' => 'Sheet retour MediaMarkt',
            'message' => '<p>Retour sheet bijgevoegd.</p>',
        ])
        ->assertNotified();

    $export = ImportExport::query()->where('import_id', $batch->id)->first();

    expect($export)->not->toBeNull()
        ->and($export->sent_at)->not->toBeNull()
        ->and(file_exists(storage_path("app/exports/{$batch->id}/{$export->uid}.xlsx")))->toBeTrue();

    Mail::assertSent(ImportBatchExportMail::class, function (ImportBatchExportMail $mail) use ($customer): bool {
        return $mail->subject === 'Sheet retour MediaMarkt'
            && in_array($customer->getEmail(), (array) $mail->toAddress, true);
    });
});

it('attaches selected import batch documents to sheet retour email', function (): void {
    Mail::fake();

    $fixture = base_path('tests/fixtures/rma/media-markt-export.xlsx');
    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();
    $parseResult = app(ParseImportFileAction::class)($fixture, 'xlsx', $template);

    $customer = Customer::query()->findOrFail($parseResult->detectedCustomerId);
    $customer->update(['email' => 'klant@import-test.example']);

    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Export',
        'last_name' => 'Attachments',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage sales']);

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
    $batch = $result['batch']->fresh(['export', 'importTemplate.exportTemplate', 'importRows.rma']);

    $document = UploadedFile::fake()->create('retour-notitie.pdf', 100, 'application/pdf');
    $media = $batch->addMedia($document->getRealPath())
        ->usingFileName('retour-notitie.pdf')
        ->toMediaCollection('documents');

    $this->actingAs($user);

    Livewire::test(ListImportTasks::class)
        ->callAction(TestAction::make('sendExport')->table($batch), [
            'from' => 'orders@example.com',
            'to' => ['customer'],
            'cc' => [],
            'bcc' => [],
            'subject' => 'Sheet retour MediaMarkt',
            'message' => '<p>Retour sheet bijgevoegd.</p>',
            'uploaded_attachments' => [(string) $media->id],
        ])
        ->assertNotified();

    expect(ImportExport::query()->where('import_id', $batch->id)->exists())->toBeTrue();

    $export = ImportExport::query()->where('import_id', $batch->id)->first();
    expect($export?->sent_at)->not->toBeNull();

    Mail::assertSent(ImportBatchExportMail::class);
    Mail::assertSent(ImportBatchExportMail::class, function (ImportBatchExportMail $mail) use ($media): bool {
        return collect($mail->attachmentMediaIds)
            ->map(fn ($id): int => (int) $id)
            ->contains((int) $media->id);
    });
});

it('mounts sendExport modal with sheet retour sturen heading', function (): void {
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Export',
        'last_name' => 'Modal',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();

    $batch = ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'successful_rows' => 1,
        'import_template_id' => $template->id,
    ]);

    $this->actingAs($user);

    Livewire::test(ListImportTasks::class)
        ->mountAction(TestAction::make('sendExport')->table($batch))
        ->assertActionMounted(TestAction::make('sendExport')->table($batch));
});

it('shows editable opmerkingen field per rma row in sendExport modal', function (): void {
    $customer = Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'MediaMarkt',
    ]);

    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();

    $source = Source::query()->create([
        'name' => 'MediaMarkt',
        'import_template_id' => $template->id,
        'customer_id' => $customer->id,
    ]);

    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Export',
        'last_name' => 'Comments',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    $batch = ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'successful_rows' => 1,
        'import_template_id' => $template->id,
    ]);

    $row = ImportRow::query()->create([
        'import_id' => $batch->id,
        'customer_id' => $customer->id,
        'source_id' => $source->id,
        'reference' => 'REF-COMMENT-001',
        'ean_nr' => '0846885011362',
    ]);

    Rma::query()->create([
        'import_row_id' => $row->id,
        'customer_id' => $customer->id,
        'uid' => 'RMA-COM-001',
        'status' => RmaStatus::Open,
    ]);

    $row->load('rma');

    $html = view('filament.resources.import-tasks.partials.send-export-rmas-table', [
        'rows' => collect([$row]),
    ])->render();

    expect($html)
        ->toContain('Opmerkingen')
        ->toContain('REF-COMMENT-001')
        ->toContain('send-export-rmas-comment-input')
        ->toContain('wire:model.defer="exportRowComments.'.$row->id.'"');

    $this->actingAs($user);

    Livewire::test(ListImportTasks::class)
        ->set('exportRowComments.'.$row->id, 'Defect aan linker wiel')
        ->assertSet('exportRowComments.'.$row->id, 'Defect aan linker wiel');
});

<?php

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\ImportTemplate;
use App\Models\User;
use App\Services\Export\CreateImportBatchExportAction;
use App\Services\Import\ParseImportFileAction;
use App\Services\Import\ProcessImportBatchAction;
use App\Support\RmaImport\SpreadsheetTableReader;
use Database\Seeders\ImportTemplateSeeder;
use Database\Seeders\RmaImportTestProductsSeeder;
use Illuminate\Http\UploadedFile;

beforeEach(function (): void {
    $this->seed(ImportTemplateSeeder::class);
    $this->seed(RmaImportTestProductsSeeder::class);
});

it('generates universal export with rma uids in the rma column', function (): void {
    Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'Vanden Borre N.V.',
        'debtor_number' => '19551',
    ]);

    $fixture = base_path('tests/fixtures/rma/autovision-vanden-borre.xlsx');
    $template = ImportTemplate::query()->where('name', 'Universeel')->firstOrFail();
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
        'autovision-vanden-borre.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    );

    $result = app(ProcessImportBatchAction::class)(
        parseResult: $parseResult,
        batchData: [
            'customer_id' => $customer->id,
            'track_trace_nr' => 'TT-UNI',
            'reference' => '760647648',
            'shipment_date' => '2026-06-01',
        ],
        file: $uploadedFile,
        user: $user,
    );

    /** @var ImportBatch $batch */
    $batch = $result['batch'];

    expect(ImportRow::query()->where('import_id', $batch->id)->whereDoesntHave('rma')->count())->toBe(0);

    $export = app(CreateImportBatchExportAction::class)($batch, $user);

    expect($export)->not->toBeNull()
        ->and($export->uid)->toMatch('/^EX-\d{7}$/');

    $exportPath = storage_path("app/exports/{$batch->id}/{$export->uid}.xlsx");
    expect($exportPath)->toBeReadableFile();

    $reader = new SpreadsheetTableReader;
    $rows = $reader->readAllRows($exportPath);
    $headerIndex = collect($rows)->search(fn (array $row): bool => in_array('UW RMA Referentie', array_map(
        fn (?string $value): string => trim((string) $value),
        $row,
    ), true));

    expect($headerIndex)->not->toBeFalse();

    $headers = array_map(
        fn (?string $header): string => trim((string) $header),
        $rows[$headerIndex],
    );

    $rmaColumnIndex = array_search('RMA NUMMER AUTOVISION', $headers, true);

    if ($rmaColumnIndex === false) {
        $rmaColumnIndex = array_search('RMA NUMMER AUTOVISION ', $headers, true);
    }

    expect($rmaColumnIndex)->not->toBeFalse();

    $rmaUids = ImportRow::query()
        ->where('import_id', $batch->id)
        ->with('rma')
        ->get()
        ->map(fn (ImportRow $row): string => $row->rma?->uid)
        ->filter()
        ->values()
        ->all();

    expect($rmaUids)->not->toBeEmpty();

    $filledValues = collect($rows)
        ->slice($headerIndex + 1)
        ->takeUntil(fn (array $row): bool => trim((string) ($row[0] ?? '')) === 'Gecrediteerd')
        ->map(fn (array $row): string => trim((string) ($row[$rmaColumnIndex] ?? '')))
        ->filter()
        ->values()
        ->all();

    foreach ($rmaUids as $uid) {
        expect($filledValues)->toContain($uid);
    }

    expect($export->file_name)->toBe("{$export->uid}.xlsx");
});

<?php

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\ImportRow;
use App\Models\ImportTemplate;
use App\Models\User;
use App\Services\Export\CreateImportBatchExportAction;
use App\Services\Import\ParseImportFileAction;
use App\Services\Import\ProcessImportBatchAction;
use App\Support\RmaImport\Universal\UniversalImportParser;
use Database\Seeders\ImportTemplateSeeder;
use Database\Seeders\RmaImportTestProductsSeeder;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function (): void {
    $this->seed(ImportTemplateSeeder::class);
    $this->seed(RmaImportTestProductsSeeder::class);
});

it('generates universal import export using return universal template', function (): void {
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

    /** @var \App\Models\ImportBatch $batch */
    $batch = $result['batch'];

    expect(ImportRow::query()->where('import_id', $batch->id)->whereDoesntHave('rma')->count())->toBe(0);

    $export = app(CreateImportBatchExportAction::class)($batch, $user);

    expect($export)->not->toBeNull()
        ->and($export->uid)->toMatch('/^EX-\d{7}$/');

    $exportPath = storage_path("app/exports/{$batch->id}/{$export->uid}.xlsx");
    expect($exportPath)->toBeReadableFile();

    $sheet = IOFactory::load($exportPath)->getActiveSheet();

    $rmaUids = ImportRow::query()
        ->where('import_id', $batch->id)
        ->with('rma')
        ->orderBy('id')
        ->get()
        ->map(fn (ImportRow $row): string => (string) $row->rma?->uid)
        ->filter()
        ->values()
        ->all();

    expect($rmaUids)->not->toBeEmpty();

    $filledRmaValues = collect($rmaUids)
        ->map(fn (string $uid, int $index): string => trim((string) $sheet->getCell('E'.(14 + $index))->getCalculatedValue()))
        ->all();

    foreach ($rmaUids as $uid) {
        expect($filledRmaValues)->toContain($uid);
    }

    expect($export->file_name)->toBe("{$export->uid}.xlsx");
});

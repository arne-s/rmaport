<?php

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\ImportTemplate;
use App\Models\Rma;
use App\Models\User;
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

it('parses mediamarkt fixture and detects customer from template source', function (): void {
    $fixture = base_path('tests/fixtures/rma/media-markt-export.xlsx');
    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();

    $result = app(ParseImportFileAction::class)($fixture, 'xlsx', $template);

    expect($result->rowCount())->toBe(8)
        ->and($result->detectedCustomerId)->not->toBeNull();
});

it('creates rmas when importing new rows', function (): void {
    $fixture = base_path('tests/fixtures/rma/media-markt-export.xlsx');
    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();
    $parseResult = app(ParseImportFileAction::class)($fixture, 'xlsx', $template);

    $customer = Customer::query()->findOrFail($parseResult->detectedCustomerId);
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Import',
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
            'shipment_reference' => 'SHIP-REF-001',
            'shipment_date' => '2026-06-01',
        ],
        file: $uploadedFile,
        user: $user,
    );

    $batch = $result['batch'];
    $validation = $result['validation'];

    expect($batch)->toBeInstanceOf(ImportBatch::class)
        ->and($batch->uid)->toMatch('/^IM-\d{7}$/')
        ->and($validation->summaryLabel())->toBe('8 rijen totaal, 8 nieuw, 0 bestaand, 0 ongeldig.')
        ->and($batch->successful_rows)->toBe(8)
        ->and($batch->track_trace_nr)->toBe('TT123')
        ->and($batch->reference)->toBe('REF-001')
        ->and($batch->shipment_reference)->toBe('SHIP-REF-001')
        ->and(ImportRow::query()->where('import_id', $batch->id)->count())->toBe(8)
        ->and(ImportRow::query()->where('import_id', $batch->id)->where('customer_id', $customer->id)->count())->toBe(8)
        ->and(Rma::query()->count())->toBe(8)
        ->and(ImportRow::query()->where('import_id', $batch->id)->whereDoesntHave('rma')->count())->toBe(0)
        ->and(ImportRow::query()->where('import_id', $batch->id)->whereNotNull('product_name')->count())->toBeGreaterThan(0);
});

it('extracts bol shipment reference from consumer returns shipment metadata', function (): void {
    $fixture = base_path('tests/fixtures/rma/consumer-returns-shipment.xlsx');
    $template = ImportTemplate::query()->where('name', 'bol.com zending')->firstOrFail();

    $result = app(ParseImportFileAction::class)($fixture, 'xlsx', $template);

    expect($result->reference)->toBe('NCKI26077751')
        ->and($result->shipmentReference)->toBe('SMT-1941121')
        ->and($result->trackTraceNr)->toBe('JVGL06160816001129545183')
        ->and($result->shipmentDate)->toBe('2026-05-08')
        ->and($result->importDate)->toBeNull();
});

it('detects universal customer via debtor number', function (): void {
    Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'Vanden Borre N.V.',
        'debtor_number' => '19551',
    ]);

    $fixture = base_path('tests/fixtures/rma/autovision-vanden-borre.xlsx');
    $template = ImportTemplate::query()->where('name', 'Universeel')->firstOrFail();

    $result = app(ParseImportFileAction::class)($fixture, 'xlsx', $template);

    expect($result->reference)->toBe('760647648')
        ->and($result->detectedCustomerId)->not->toBeNull()
        ->and(Customer::query()->find($result->detectedCustomerId)?->debtor_number)->toBe('19551')
        ->and($result->importDate)->toBe('2026-02-05')
        ->and($result->shipmentDate)->toBeNull();
});

<?php

use App\Enums\CustomerStatus;
use App\Enums\ProductUnit;
use App\Enums\RmaStatus;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\RmaResource\Support\RmaViewPresenter;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Rma;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(Tests\TestCase::class, DatabaseTransactions::class);

it('orders combined general fields without rma number and status', function (): void {
    $customer = Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'Test Klant',
    ]);

    $product = Product::query()->create([
        'uid' => 'PRESENTER-1',
        'name' => 'Test Scootmobiel',
        'unit' => ProductUnit::Pieces,
        'ean_1' => '0846885011362',
        'company_purchase_price' => 10,
        'company_sales_price' => 20,
        'company_margin' => 50,
    ]);

    $rma = Rma::query()->create([
        'uid' => 'RMA-PRESENTER-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'customer_id' => $customer->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $labels = array_column(RmaViewPresenter::combinedGeneralFields($rma), 'label');

    expect($labels)->toBe([
        'Klant',
        'E-mail',
        'Telefoonnummer',
        'Invoerdatum en tijd',
        'Referentie',
        'Opdrachtnummer',
        'Zending-datum',
        'Zending-referentie',
        'Track & Trace',
        'Artikelnaam',
        'Accessoires',
        'DOA',
    ])->and($labels)->not->toContain('RMA Nummer', 'Status', 'Aantal', 'Ordernummer', 'Klacht', 'Pakbon', 'Artikelnummer', 'Merk', 'EAN', 'Aankoopdatum', 'Betalingsmethode');
});

it('links customer and product fields in the presenter', function (): void {
    $customer = Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'Test Klant',
    ]);

    $product = Product::query()->create([
        'uid' => 'PRESENTER-LINK-1',
        'name' => 'Test Scootmobiel',
        'unit' => ProductUnit::Pieces,
        'ean_1' => '0846885011362',
        'company_purchase_price' => 10,
        'company_sales_price' => 20,
        'company_margin' => 50,
    ]);

    $rma = Rma::query()->create([
        'uid' => 'RMA-PRESENTER-LINK-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'customer_id' => $customer->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $fields = RmaViewPresenter::combinedGeneralFields($rma);

    $customerField = collect($fields)->firstWhere('label', 'Klant');
    $productField = collect($fields)->firstWhere('label', 'Artikelnaam');

    expect($customerField['url'] ?? null)->toBe(CustomerResource::getUrl('edit', ['record' => $customer]))
        ->and($productField['url'] ?? null)->toBe(ProductResource::getUrl('edit', ['record' => $product]));
});

it('truncates long product names to one hundred fifty characters', function (): void {
    $longName = str_repeat('A', 160);

    $product = Product::query()->create([
        'uid' => 'PRESENTER-LONG-1',
        'name' => $longName,
        'unit' => ProductUnit::Pieces,
        'ean_1' => '0846885011362',
        'company_purchase_price' => 10,
        'company_sales_price' => 20,
        'company_margin' => 50,
    ]);

    $rma = Rma::query()->create([
        'uid' => 'RMA-PRESENTER-LONG-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $productField = collect(RmaViewPresenter::productFields($rma))->firstWhere('label', 'Artikelnaam');

    expect($productField['value'])->toBe(Str::limit($longName, 150))
        ->and($productField['truncate'] ?? false)->toBeTrue()
        ->and($productField['title'] ?? null)->toBe($longName);
});

it('shows import shipment fields in the footer section', function (): void {
    $user = \App\Models\User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Import',
        'last_name' => 'Tester',
    ]);

    $batch = \App\Models\ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'successful_rows' => 1,
        'reference' => 'SHIP-REF-001',
        'track_trace_nr' => 'TT-12345',
        'shipment_date' => '2026-06-10',
    ]);

    $importRow = \App\Models\ImportRow::query()->create([
        'import_id' => $batch->id,
        'assignment_nr' => 'AD999888',
        'is_doa' => true,
    ]);

    $rma = Rma::query()->create([
        'uid' => 'RMA-SHIPMENT-FIELDS-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'import_row_id' => $importRow->id,
    ]);

    $rma->load(['importRow.importBatch']);

    $middleFields = RmaViewPresenter::combinedGeneralMiddleFields($rma);
    $footerFields = RmaViewPresenter::combinedGeneralFooterFields($rma);

    expect(array_column($middleFields, 'label'))->toBe([
        'Opdrachtnummer',
        'Zending-datum',
        'Zending-referentie',
        'Track & Trace',
    ])->and(array_column($footerFields, 'label'))->toBe([
        'Artikelnaam',
        'Accessoires',
        'DOA',
    ])->and($footerFields[2]['value'])->toBe('Ja')
        ->and($middleFields[0]['value'])->toBe('AD999888')
        ->and($middleFields[1]['value'])->toBe('10-06-2026')
        ->and($middleFields[2]['value'])->toBe('SHIP-REF-001')
        ->and($middleFields[3]['value'])->toBe('TT-12345');
});

it('shows unknown doa when import row is missing', function (): void {
    $rma = Rma::query()->create([
        'uid' => 'RMA-DOA-UNKNOWN-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
    ]);

    $doaField = collect(RmaViewPresenter::combinedGeneralFooterFields($rma))
        ->firstWhere('label', 'DOA');

    expect($doaField['value'])->toBe('(onbekend)');
});

it('formats invoerdatum en tijd from created_at', function (): void {
    Carbon::setTestNow('2026-06-11 12:34:00');

    $rma = Rma::query()->create([
        'uid' => 'RMA-PRESENTER-002',
        'status' => RmaStatus::Open,
        'is_draft' => false,
    ]);

    $headerFields = RmaViewPresenter::combinedGeneralHeaderFields($rma);

    expect($headerFields[3])->toBe([
        'label' => 'Invoerdatum en tijd',
        'value' => '11/06/26 - 12:34',
    ]);

    Carbon::setTestNow();
});

it('orders return fields with purchase date before return date and reason last', function (): void {
    $labels = array_column(RmaViewPresenter::returnReadOnlyFields(Rma::query()->make()), 'label');

    expect($labels)->toBe([
        'Aankoopdatum',
        'Retourdatum',
        'Retourreden',
    ]);
});

it('shows unknown return date when not available', function (): void {
    $rma = Rma::query()->create([
        'uid' => 'RMA-PRESENTER-RETURN-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
    ]);

    $returnDateField = collect(RmaViewPresenter::returnReadOnlyFields($rma))
        ->firstWhere('label', 'Retourdatum');

    expect($returnDateField['value'])->toBe('(onbekend)');
});

it('formats return date with days since purchase date', function (): void {
    $user = \App\Models\User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Import',
        'last_name' => 'Tester',
    ]);

    $batch = \App\Models\ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'successful_rows' => 1,
    ]);

    $importRow = \App\Models\ImportRow::query()->create([
        'import_id' => $batch->id,
        'purchase_date' => '2026-05-14',
        'return_date' => '2026-06-24',
    ]);

    $rma = Rma::query()->create([
        'uid' => 'RMA-PRESENTER-RETURN-002',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'import_row_id' => $importRow->id,
    ]);

    $returnDateField = collect(RmaViewPresenter::returnReadOnlyFields($rma->load('importRow')))
        ->firstWhere('label', 'Retourdatum');

    expect($returnDateField['value'])->toBe('24 jun. 2026 (41 dagen na aankoop)');
});

it('shows customer email and phone under klant in the header fields', function (): void {
    $customer = Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'Test Klant',
        'email' => 'klant@example.com',
        'phone_number' => '0612345678',
    ]);

    $rma = Rma::query()->create([
        'uid' => 'RMA-PRESENTER-CONTACT-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'customer_id' => $customer->id,
    ]);

    $fields = collect(RmaViewPresenter::combinedGeneralHeaderFields($rma));

    expect($fields->firstWhere('label', 'E-mail')['value'])->toBe('klant@example.com')
        ->and($fields->firstWhere('label', 'Telefoonnummer')['value'])->toBe('0612345678');
});

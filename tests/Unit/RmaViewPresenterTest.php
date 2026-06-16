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
        'Ontvangstdatum',
        'Referentie',
        'Opdrachtnummer',
        'Aanvraagdatum',
        'Verzenddatum',
        'Referentie',
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
        ->and($customerField['title'] ?? null)->toBeNull()
        ->and($productField['url'] ?? null)->toBe(ProductResource::getUrl('edit', ['record' => $product]));
});

it('shows source description as customer link title in the main view', function (): void {
    $customer = Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'Test Klant',
    ]);

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
        'customer_id' => $customer->id,
        'source_description' => 'Mediamarkt Apeldoorn 520',
    ]);

    $rma = Rma::query()->create([
        'uid' => 'RMA-PRESENTER-SOURCE-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'customer_id' => $customer->id,
        'import_row_id' => $importRow->id,
    ]);

    $customerField = collect(RmaViewPresenter::combinedGeneralHeaderFields($rma->load('importRow')))
        ->firstWhere('label', 'Klant');

    expect($customerField['title'] ?? null)->toBe('Mediamarkt Apeldoorn 520');
});

it('includes customer internal note on klant field when comment is present', function (): void {
    $customer = Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'Test Klant',
        'comment' => 'Altijd bellen voor retour',
    ]);

    $rma = Rma::query()->create([
        'uid' => 'RMA-NOTE-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'customer_id' => $customer->id,
    ]);

    $customerField = collect(RmaViewPresenter::combinedGeneralHeaderFields($rma))
        ->firstWhere('label', 'Klant');

    expect($customerField['internalNote'] ?? null)->toBe('Altijd bellen voor retour');
});

it('omits customer internal note on klant field when comment is empty', function (): void {
    $customer = Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'Test Klant',
        'comment' => null,
    ]);

    $rma = Rma::query()->create([
        'uid' => 'RMA-NOTE-002',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'customer_id' => $customer->id,
    ]);

    $customerField = collect(RmaViewPresenter::combinedGeneralHeaderFields($rma))
        ->firstWhere('label', 'Klant');

    expect($customerField)->not->toHaveKey('internalNote');
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

it('shows imported product name in parentheses when fallback product is used', function (): void {
    $fallbackProduct = Product::query()->updateOrCreate(
        ['uid' => \App\Services\Import\ImportRowProductResolver::FALLBACK_ARTICLE_NUMBER],
        [
            'name' => 'Onbekend product',
            'unit' => ProductUnit::Pieces,
            'company_purchase_price' => 10,
            'company_sales_price' => 20,
            'company_margin' => 50,
        ],
    );

    $importRow = \App\Models\ImportRow::query()->create([
        'import_id' => \App\Models\ImportBatch::query()->create([
            'user_id' => \App\Models\User::query()->create([
                'email' => fake()->unique()->safeEmail(),
                'password' => bcrypt('password'),
                'first_name' => 'Import',
                'last_name' => 'Tester',
            ])->id,
            'file_name' => 'test.xlsx',
            'file_path' => 'imports/test.xlsx',
            'importer' => \App\Filament\Imports\RmaStagingImporter::class,
            'total_rows' => 1,
        ])->id,
        'reference' => 'REF-FALLBACK-1',
        'ean_nr' => '9990000000001',
        'product_name' => 'House of Marley HIFI PLATENSPELER',
    ]);

    $rma = Rma::query()->create([
        'uid' => 'RMA-FB-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'product_id' => $fallbackProduct->id,
        'import_row_id' => $importRow->id,
        'quantity' => 1,
    ]);

    $productField = collect(RmaViewPresenter::productFields($rma->load('importRow')))->firstWhere('label', 'Artikelnaam');

    expect($productField['value'])->toBe('Onbekend product (House of Marley HIFI PLATENSPELER)')
        ->and($productField['url'] ?? null)->toBe(ProductResource::getUrl('edit', ['record' => $fallbackProduct]));
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
        'import_date' => '2026-06-10',
        'shipment_date' => '2026-06-11',
    ]);

    $importRow = \App\Models\ImportRow::query()->create([
        'import_id' => $batch->id,
        'assignment_nr' => 'AD999888',
        'is_doa' => true,
    ]);

    $rma = Rma::query()->create([
        'uid' => 'RMA-SHIP-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'import_row_id' => $importRow->id,
    ]);

    $rma->load(['importRow.importBatch']);

    $middleFields = RmaViewPresenter::combinedGeneralMiddleFields($rma);
    $footerFields = RmaViewPresenter::combinedGeneralFooterFields($rma);

    expect(array_column($middleFields, 'label'))->toBe([
        'Opdrachtnummer',
        'Aanvraagdatum',
        'Verzenddatum',
        'Referentie',
    ])->and(array_column($footerFields, 'label'))->toBe([
        'Artikelnaam',
        'Accessoires',
        'DOA',
    ])->and($footerFields[2]['value'])->toBe('Ja')
        ->and($middleFields[0]['value'])->toBe('AD999888')
        ->and($middleFields[1]['value'])->toBe('10-06-2026')
        ->and($middleFields[2]['value'])->toBe('11-06-2026')
        ->and($middleFields[3]['value'])->toBe('SHIP-REF-001');
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
        'value' => '11 jun. 2026 - 12:34',
    ]);

    Carbon::setTestNow();
});

it('shows not yet received when received_at is empty', function (): void {
    $rma = Rma::query()->create([
        'uid' => 'RMA-PRE-RECV-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
    ]);

    $receivedDateField = collect(RmaViewPresenter::combinedGeneralHeaderFields($rma))
        ->firstWhere('label', 'Ontvangstdatum');

    expect($receivedDateField['value'])->toBe('(nog niet ontvangen)');
});

it('formats ontvangstdatum from received_at without time', function (): void {
    $rma = Rma::query()->create([
        'uid' => 'RMA-PRE-RECV-002',
        'status' => RmaStatus::Received,
        'is_draft' => false,
        'received_at' => '2026-06-14 15:30:00',
    ]);

    $receivedDateField = collect(RmaViewPresenter::combinedGeneralHeaderFields($rma))
        ->firstWhere('label', 'Ontvangstdatum');

    expect($receivedDateField['value'])->toBe('14 jun. 2026');
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

    $purchaseDateField = collect(RmaViewPresenter::returnReadOnlyPrimaryFields($rma->load('importRow')))
        ->firstWhere('label', 'Aankoopdatum');

    expect($purchaseDateField['value'])->toBe('14 mei 2026');
});

it('shows zending-referentie under referentie when shipment reference is present', function (): void {
    $importRow = \App\Models\ImportRow::query()->create([
        'import_id' => \App\Models\ImportBatch::query()->create([
            'user_id' => \App\Models\User::query()->create([
                'email' => fake()->unique()->safeEmail(),
                'password' => bcrypt('password'),
                'first_name' => 'Import',
                'last_name' => 'Tester',
            ])->id,
            'file_name' => 'test.xlsx',
            'file_path' => 'imports/test.xlsx',
            'importer' => \App\Filament\Imports\RmaStagingImporter::class,
            'total_rows' => 1,
            'shipment_reference' => 'SMT-1941121',
        ])->id,
        'reference' => 'REF-SHIP-1',
    ]);

    $rma = Rma::query()->create([
        'uid' => 'RMA-SREF-1',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'import_row_id' => $importRow->id,
    ]);

    $fields = RmaViewPresenter::generalDetailFields($rma->load(['importRow.importBatch']));

    expect(array_column($fields, 'label'))->toBe(['Referentie', 'Zending-referentie'])
        ->and($fields[1]['value'])->toBe('SMT-1941121');
});

it('links customer order id to bol.com under referentie when present', function (): void {
    $importRow = \App\Models\ImportRow::query()->create([
        'import_id' => \App\Models\ImportBatch::query()->create([
            'user_id' => \App\Models\User::query()->create([
                'email' => fake()->unique()->safeEmail(),
                'password' => bcrypt('password'),
                'first_name' => 'Import',
                'last_name' => 'Tester',
            ])->id,
            'file_name' => 'test.xlsx',
            'file_path' => 'imports/test.xlsx',
            'importer' => \App\Filament\Imports\RmaStagingImporter::class,
            'total_rows' => 1,
            'track_trace_nr' => 'JVGL06160816001129545183',
            'shipment_reference' => 'SMT-1941121',
        ])->id,
        'reference' => 'REF-BOL-1',
        'customer_order_id' => 'C000397X67',
    ]);

    $rma = Rma::query()->create([
        'uid' => 'RMA-BOL-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'import_row_id' => $importRow->id,
    ]);

    $fields = RmaViewPresenter::generalDetailFields($rma->load(['importRow.importBatch']));

    expect(array_column($fields, 'label'))->toBe(['Referentie', 'Zending-referentie', 'Klantorder'])
        ->and($fields[0]['value'])->toBe('REF-BOL-1')
        ->and($fields[1]['value'])->toBe('SMT-1941121')
        ->and($fields[2]['value'])->toBe('C000397X67')
        ->and($fields[2]['url'] ?? null)->toBe('https://login.bol.com/wsp/login')
        ->and($fields[2]['newTab'] ?? false)->toBeTrue();
});

it('does not show track and trace number in general detail fields', function (): void {
    $importRow = \App\Models\ImportRow::query()->create([
        'import_id' => \App\Models\ImportBatch::query()->create([
            'user_id' => \App\Models\User::query()->create([
                'email' => fake()->unique()->safeEmail(),
                'password' => bcrypt('password'),
                'first_name' => 'Import',
                'last_name' => 'Tester',
            ])->id,
            'file_name' => 'test.xlsx',
            'file_path' => 'imports/test.xlsx',
            'importer' => \App\Filament\Imports\RmaStagingImporter::class,
            'total_rows' => 1,
            'track_trace_nr' => 'JVGL06160816001129545183',
        ])->id,
        'reference' => 'REF-TT-1',
    ]);

    $rma = Rma::query()->create([
        'uid' => 'RMA-TT-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'import_row_id' => $importRow->id,
    ]);

    $fields = RmaViewPresenter::generalDetailFields($rma->load(['importRow.importBatch']));

    expect(array_column($fields, 'label'))->toBe(['Referentie'])
        ->and(array_column($fields, 'label'))->not->toContain('Track & Trace number');
});

it('does not show klantorder when customer order id is missing', function (): void {
    $importRow = \App\Models\ImportRow::query()->create([
        'import_id' => \App\Models\ImportBatch::query()->create([
            'user_id' => \App\Models\User::query()->create([
                'email' => fake()->unique()->safeEmail(),
                'password' => bcrypt('password'),
                'first_name' => 'Import',
                'last_name' => 'Tester',
            ])->id,
            'file_name' => 'test.xlsx',
            'file_path' => 'imports/test.xlsx',
            'importer' => \App\Filament\Imports\RmaStagingImporter::class,
            'total_rows' => 1,
        ])->id,
        'reference' => 'REF-NO-BOL',
    ]);

    $rma = Rma::query()->create([
        'uid' => 'RMA-NOBOL-1',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'import_row_id' => $importRow->id,
    ]);

    $fields = RmaViewPresenter::generalDetailFields($rma->load('importRow'));

    expect(array_column($fields, 'label'))->toBe(['Referentie']);
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

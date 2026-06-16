<?php

use App\Actions\Import\CreateRmaFromImportRowAction;
use App\Enums\CustomerStatus;
use App\Enums\ProductUnit;
use App\Enums\RmaStatus;
use App\Filament\Resources\ImportRows\Pages\ListImportRows;
use App\Filament\Resources\RmaResource;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\ImportTemplate;
use App\Models\Product;
use App\Models\Rma;
use App\Models\Source;
use App\Models\User;
use App\Support\RmaImport\MediaMarkt\MediaMarktImportParser;
use Database\Seeders\ImportTemplateSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    Permission::findOrCreate('manage sales', 'web');
    Permission::findOrCreate('access filament panel', 'web');
    $this->seed(ImportTemplateSeeder::class);
});

function createImportRowForRmaCreation(): array
{
    $customer = Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'MediaMarkt',
    ]);

    $product = Product::query()->create([
        'uid' => 'RMA-FROM-ROW-1',
        'name' => 'Testproduct',
        'unit' => ProductUnit::Pieces,
        'ean_1' => '0846885011362',
        'company_purchase_price' => 10,
        'company_sales_price' => 20,
        'company_margin' => 50,
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
        'first_name' => 'Import',
        'last_name' => 'Tester',
    ]);

    $batch = ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'successful_rows' => 1,
        'import_template_id' => $template->id,
        'reference' => 'BATCH-REF-001',
        'track_trace_nr' => 'TT-999',
    ]);

    $row = ImportRow::query()->create([
        'import_id' => $batch->id,
        'customer_id' => $customer->id,
        'source_id' => $source->id,
        'reference' => 'REF-456',
        'assignment_nr' => 'AD999888',
        'ean_nr' => '0846885011362',
        'return_reason' => 'Defect product',
        'accessories' => 'Kabel',
        'source_description' => 'Mediamarkt Apeldoorn',
    ]);

    return compact('customer', 'product', 'batch', 'row', 'user');
}

it('creates an rma from an import row', function (): void {
    ['row' => $row, 'customer' => $customer, 'product' => $product, 'batch' => $batch] = createImportRowForRmaCreation();

    $rma = app(CreateRmaFromImportRowAction::class)($row);

    expect($rma->uid)->toMatch('/^\d{8}$/')
        ->and($rma->status)->toBe(RmaStatus::Open)
        ->and($rma->is_draft)->toBeFalse()
        ->and($rma->customer_id)->toBe($customer->id)
        ->and($rma->import_row_id)->toBe($row->id)
        ->and($rma->product_id)->toBe($product->id)
        ->and($rma->return_reason)->toBe('Defect product')
        ->and($rma->accessories)->toBe('Kabel')
        ->and($rma->packing_slip_number)->toBe('BATCH-REF-001')
        ->and($rma->notes)->toBeNull()
        ->and($row->fresh()->rma?->is($rma))->toBeTrue()
        ->and($rma->rmaEvents()->where('type', 'Aangemaakt vanuit importregel')->exists())->toBeTrue();
});

it('prevents creating a second rma for the same import row', function (): void {
    ['row' => $row] = createImportRowForRmaCreation();

    app(CreateRmaFromImportRowAction::class)($row);

    expect(fn () => app(CreateRmaFromImportRowAction::class)($row->fresh()))
        ->toThrow(RuntimeException::class, 'Er bestaat al een RMA voor deze importregel.');
});

it('creates an rma from the import rows table action', function (): void {
    ['row' => $row, 'user' => $user] = createImportRowForRmaCreation();

    $this->actingAs($user);

    Livewire::test(ListImportRows::class)
        ->callAction(TestAction::make('createRmaFromImportRow')->table($row))
        ->assertNotified()
        ->assertRedirect(RmaResource::getUrl('view', ['record' => Rma::query()->where('import_row_id', $row->id)->first()]));

    expect(Rma::query()->where('import_row_id', $row->id)->value('uid'))->toMatch('/^\d{8}$/');
});

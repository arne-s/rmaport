<?php

use App\Enums\CustomerStatus;
use App\Enums\ProductUnit;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\ImportRows\ImportRowResource;
use App\Filament\Resources\ImportTasks\ImportTaskResource;
use App\Filament\Resources\ImportRows\Pages\ListImportRows;
use App\Filament\Resources\ImportRows\Pages\ViewImportRow;
use App\Filament\Resources\ProductResource;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\ImportTemplate;
use App\Models\Product;
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

it('denies import row resource without manage sales permission', function (): void {
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Import',
        'last_name' => 'Viewer',
    ]);
    $user->givePermissionTo('access filament panel');

    expect(ImportRowResource::canViewAny())->toBeFalse();

    $this->actingAs($user)
        ->get(ImportRowResource::getUrl('index'))
        ->assertForbidden();
});

it('opens import modal from import row list', function (): void {
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Import',
        'last_name' => 'Modal',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    $this->actingAs($user);

    Livewire::test(ListImportRows::class)
        ->mountAction(TestAction::make('import_rma')->table())
        ->assertActionMounted(TestAction::make('import_rma')->table());
});

it('lists import rows for sales users', function (): void {
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Import',
        'last_name' => 'Lister',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    $customer = Customer::query()->create([
        'status' => CustomerStatus::Active,
        'name' => 'MediaMarkt',
    ]);

    $product = Product::query()->create([
        'uid' => 'IMPORT-OVERVIEW-1',
        'name' => 'Ninebot Kickscooter',
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
        'reference' => 'REF-123',
        'assignment_nr' => 'AD123',
        'ean_nr' => '0846885011362',
    ]);

    $this->actingAs($user);

    Livewire::test(ListImportRows::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$row])
        ->assertSee('Terug naar Dashboard')
        ->assertSee('REF-123')
        ->assertSee('0846885011362')
        ->assertSee('MediaMarkt')
        ->assertSee('Ninebot Kickscooter')
        ->assertSeeHtml(CustomerResource::getUrl('edit', ['record' => $customer]))
        ->assertSeeHtml(ProductResource::getUrl('edit', ['record' => $product]))
        ->assertSee('Aanmaken')
        ->assertSee($batch->uid);
});

it('links import task uid to import tasks list filtered by search', function (): void {
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Import',
        'last_name' => 'Linker',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();
    $source = Source::query()->firstOrFail();

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
        'customer_id' => $source->customer_id,
        'source_id' => $source->id,
        'reference' => 'REF-LINK',
    ]);

    $this->actingAs($user);

    Livewire::test(ListImportRows::class)
        ->assertSuccessful()
        ->assertSeeHtml('href="'.e(ImportTaskResource::indexUrlForImportTask($batch)).'"');
});

it('filters import rows by import task uid via native search query parameter', function (): void {
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Import',
        'last_name' => 'Scope',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();

    $source = Source::query()->firstOrFail();

    $batchA = ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'a.xlsx',
        'file_path' => 'imports/a.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'successful_rows' => 1,
        'import_template_id' => $template->id,
    ]);

    $batchB = ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'b.xlsx',
        'file_path' => 'imports/b.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'successful_rows' => 1,
        'import_template_id' => $template->id,
    ]);

    $rowA = ImportRow::query()->create([
        'import_id' => $batchA->id,
        'customer_id' => $source->customer_id,
        'source_id' => $source->id,
        'reference' => 'ROW-A',
    ]);

    ImportRow::query()->create([
        'import_id' => $batchB->id,
        'customer_id' => $source->customer_id,
        'source_id' => $source->id,
        'reference' => 'ROW-B',
    ]);

    $this->actingAs($user);

    Livewire::withQueryParams(['search' => $batchA->uid])
        ->test(ListImportRows::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$rowA])
        ->assertDontSee('ROW-B')
        ->assertDontSee('Toon alle importrijen');
});

it('searches import rows by import task uid', function (): void {
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Import',
        'last_name' => 'Search',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();
    $source = Source::query()->firstOrFail();

    $batch = ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'search.xlsx',
        'file_path' => 'imports/search.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'successful_rows' => 1,
        'import_template_id' => $template->id,
        'uid' => 'IM-0999999',
    ]);

    $row = ImportRow::query()->create([
        'import_id' => $batch->id,
        'customer_id' => $source->customer_id,
        'source_id' => $source->id,
        'reference' => 'SEARCH-ROW',
    ]);

    $otherBatch = ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'other.xlsx',
        'file_path' => 'imports/other.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'successful_rows' => 1,
        'import_template_id' => $template->id,
    ]);

    ImportRow::query()->create([
        'import_id' => $otherBatch->id,
        'customer_id' => $source->customer_id,
        'source_id' => $source->id,
        'reference' => 'UNRELATED',
    ]);

    $this->actingAs($user);

    Livewire::test(ListImportRows::class)
        ->searchTable($batch->uid)
        ->assertCanSeeTableRecords([$row])
        ->assertDontSee('UNRELATED');
});

it('shows import row view page', function (): void {
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Import',
        'last_name' => 'Lister',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();

    $source = Source::query()->firstOrFail();

    $batch = ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'successful_rows' => 1,
        'import_template_id' => $template->id,
        'reference' => 'BATCH-REF',
    ]);

    $row = ImportRow::query()->create([
        'import_id' => $batch->id,
        'customer_id' => $source->customer_id,
        'source_id' => $source->id,
        'reference' => 'ROW-REF',
        'return_reason' => 'Defect',
    ]);

    $this->actingAs($user);

    Livewire::test(ViewImportRow::class, ['record' => $row->getKey()])
        ->assertSuccessful()
        ->assertSee('ROW-REF')
        ->assertSee('Defect')
        ->assertSee('BATCH-REF');
});

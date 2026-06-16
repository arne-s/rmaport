<?php

use App\Enums\CustomerStatus;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\ImportTasks\ImportTaskResource;
use App\Filament\Resources\ImportTasks\Pages\ListImportTasks;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportExport;
use App\Models\ImportRow;
use App\Models\ImportTemplate;
use App\Models\Source;
use App\Models\User;
use App\Support\RmaImport\MediaMarkt\MediaMarktImportParser;
use Database\Seeders\ImportTemplateSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    Permission::findOrCreate('manage sales', 'web');
    Permission::findOrCreate('access filament panel', 'web');
    $this->seed(ImportTemplateSeeder::class);
});

it('denies import task resource without manage sales permission', function (): void {
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Import',
        'last_name' => 'Viewer',
    ]);
    $user->givePermissionTo('access filament panel');

    expect(ImportTaskResource::canViewAny())->toBeFalse();

    $this->actingAs($user)
        ->get(ImportTaskResource::getUrl('index'))
        ->assertForbidden();
});

it('opens import modal from import task list', function (): void {
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Import',
        'last_name' => 'Modal',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    $this->actingAs($user);

    Livewire::test(ListImportTasks::class)
        ->mountAction(TestAction::make('import_rma')->table())
        ->assertActionMounted(TestAction::make('import_rma')->table());
});

it('lists import tasks for sales users', function (): void {
    Carbon::setTestNow('2026-06-10 13:22:00');

    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Jan',
        'last_name' => 'Importeur',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage sales']);

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

    $batch = ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 30,
        'successful_rows' => 28,
        'import_template_id' => $template->id,
        'reference' => 'ZEND-REF-1',
        'shipment_reference' => 'SMT-1941121',
        'import_date' => '2026-06-08',
        'track_trace_nr' => '3SABC123',
        'shipment_date' => '2026-06-09',
    ]);

    ImportRow::query()->create([
        'import_id' => $batch->id,
        'customer_id' => $customer->id,
        'source_id' => $source->id,
        'reference' => 'REF-123',
    ]);

    $this->actingAs($user);

    Livewire::test(ListImportTasks::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$batch])
        ->assertSee('Terug naar Dashboard')
        ->assertSee('ZEND-REF-1')
        ->assertSee('SMT-1941121')
        ->assertSee('8 jun. 2026')
        ->assertSee('9 jun. 2026')
        ->assertSee('28 / 30')
        ->assertSee($batch->uid)
        ->assertSee('10 jun. 2026 13:22')
        ->assertSee('Jan Importeur')
        ->assertSee('test.xlsx')
        ->assertSeeHtml('href="'.e(CustomerResource::getUrl('edit', ['record' => $customer])).'"')
        ->assertSeeHtml('href="'.e(route('import-batches.download', $batch)).'"')
        ->assertSeeHtml('href="'.e(\App\Filament\Resources\ImportRows\ImportRowResource::indexUrlForImportTask($batch)).'"');
});

it('shows aanmaken button when import task has no export', function (): void {
    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Export',
        'last_name' => 'Viewer',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage sales']);

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

    $batch = ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'successful_rows' => 1,
        'import_template_id' => $template->id,
    ]);

    ImportRow::query()->create([
        'import_id' => $batch->id,
        'customer_id' => $customer->id,
        'source_id' => $source->id,
        'reference' => 'REF-123',
    ]);

    $this->actingAs($user);

    Livewire::test(ListImportTasks::class)
        ->assertSuccessful()
        ->assertSee('Aanmaken')
        ->assertSee('Sheet retour')
        ->assertSeeHtml('wire:click.prevent.stop="mountTableAction(\'sendExport\'')
        ->mountAction(TestAction::make('sendExport')->table($batch))
        ->assertActionMounted(TestAction::make('sendExport')->table($batch));
});

it('shows sheet retour date and download link when import task has export', function (): void {
    Carbon::setTestNow('2026-06-12 14:30:00');

    $user = User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Export',
        'last_name' => 'Viewer',
    ]);
    $user->givePermissionTo(['access filament panel', 'manage sales']);

    $template = ImportTemplate::query()->where('class', MediaMarktImportParser::class)->firstOrFail();

    $batch = ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'import_template_id' => $template->id,
    ]);

    $export = ImportExport::query()->create([
        'import_id' => $batch->id,
        'uid' => 'EX-0000099',
        'file_disk' => 'local',
        'file_name' => 'export.xlsx',
        'user_id' => $user->id,
    ]);

    $this->actingAs($user);

    Livewire::test(ListImportTasks::class)
        ->assertSuccessful()
        ->assertSee('12 jun. 2026 14:30')
        ->assertSeeHtml('href="'.e(route('import-exports.download', $export)).'"')
        ->assertSeeHtml('class="downloadDocument"')
        ->assertDontSee('wire:click.prevent.stop="mountTableAction(\'sendExport\'')
        ->assertDontSee('Verzonden');

    Carbon::setTestNow();
});

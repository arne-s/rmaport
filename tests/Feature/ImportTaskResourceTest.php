<?php

use App\Enums\CustomerStatus;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\ImportTasks\ImportTaskResource;
use App\Filament\Resources\ImportTasks\Pages\ListImportTasks;
use App\Models\Customer;
use App\Models\ImportBatch;
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
        ->assertSee('3SABC123')
        ->assertSee('28 / 30')
        ->assertSee($batch->uid)
        ->assertSee('10 jun. 2026 13:22')
        ->assertSee('Jan Importeur')
        ->assertSee('test.xlsx')
        ->assertSeeHtml('href="'.e(CustomerResource::getUrl('edit', ['record' => $customer])).'"')
        ->assertSeeHtml('href="'.e(route('import-batches.download', $batch)).'"')
        ->assertSeeHtml('href="'.e(\App\Filament\Resources\ImportRows\ImportRowResource::indexUrlForImportTask($batch)).'"');
});

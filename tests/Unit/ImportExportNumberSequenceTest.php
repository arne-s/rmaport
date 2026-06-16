<?php

use App\Models\ImportExport;
use App\Support\ImportExportNumberSequence;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(Tests\TestCase::class, DatabaseTransactions::class);

it('generates the first export uid with prefix and seven digits', function (): void {
    expect(ImportExportNumberSequence::next())->toBe('EX-0000001');
});

it('increments export uids sequentially', function (): void {
    $user = \App\Models\User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Export',
        'last_name' => 'Tester',
    ]);

    $batch = \App\Models\ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
    ]);

    ImportExport::query()->create([
        'import_id' => $batch->id,
        'uid' => 'EX-0000001',
        'file_disk' => 'local',
        'file_name' => 'export.xlsx',
        'user_id' => $user->id,
    ]);

    expect(ImportExportNumberSequence::next())->toBe('EX-0000002');
});

it('assigns uid automatically when creating an import export', function (): void {
    $user = \App\Models\User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Export',
        'last_name' => 'Tester',
    ]);

    $batch = \App\Models\ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
    ]);

    $export = ImportExport::query()->create([
        'import_id' => $batch->id,
        'file_disk' => 'local',
        'file_name' => 'export.xlsx',
        'user_id' => $user->id,
    ]);

    expect($export->uid)->toMatch('/^EX-\d{7}$/');
});

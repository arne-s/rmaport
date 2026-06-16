<?php

use App\Models\ImportBatch;
use App\Support\ImportBatchNumberSequence;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(Tests\TestCase::class, DatabaseTransactions::class);

it('generates the first import batch uid with prefix and seven digits', function (): void {
    expect(ImportBatchNumberSequence::next())->toBe('IM-0000001');
});

it('increments import batch uids sequentially', function (): void {
    $user = \App\Models\User::query()->create([
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'first_name' => 'Import',
        'last_name' => 'Tester',
    ]);

    ImportBatch::query()->create([
        'user_id' => $user->id,
        'file_name' => 'test.xlsx',
        'file_path' => 'imports/test.xlsx',
        'importer' => \App\Filament\Imports\RmaStagingImporter::class,
        'total_rows' => 1,
        'uid' => 'IM-0000001',
    ]);

    expect(ImportBatchNumberSequence::next())->toBe('IM-0000002');
});

it('assigns uid automatically when creating an import batch', function (): void {
    $user = \App\Models\User::query()->create([
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
    ]);

    expect($batch->uid)->toMatch('/^IM-\d{7}$/');
});

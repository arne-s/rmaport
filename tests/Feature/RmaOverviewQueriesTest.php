<?php

use App\Enums\RmaStatus;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Rma;
use App\Models\User;
use App\Services\RmaOverviewQueries;
use Illuminate\Support\Carbon;

it('counts rmas per status excluding drafts', function (): void {
    Rma::query()->create(['uid' => 'OPEN-1', 'status' => RmaStatus::Open, 'is_draft' => false]);
    Rma::query()->create(['uid' => 'OPEN-2', 'status' => RmaStatus::Open, 'is_draft' => false]);
    Rma::query()->create(['uid' => 'CLOSED-1', 'status' => RmaStatus::Closed, 'is_draft' => false]);
    Rma::query()->create(['uid' => 'DRAFT-1', 'status' => RmaStatus::Open, 'is_draft' => true]);

    expect(RmaOverviewQueries::forStatus(RmaStatus::Open)->count())->toBe(2)
        ->and(RmaOverviewQueries::forStatus(RmaStatus::Closed)->count())->toBe(1)
        ->and(RmaOverviewQueries::forStatus(RmaStatus::Received)->count())->toBe(0);
});

it('builds rma status sub-page urls', function (): void {
    expect(RmaOverviewQueries::urlForStatus(RmaStatus::InProgress))
        ->toContain('/rmas/in_progress')
        ->not->toContain('tableFilters');

    expect(RmaOverviewQueries::urlForStatus(RmaStatus::Draft))
        ->toContain('/rmas/draft');

    expect(RmaOverviewQueries::urlForStatus(RmaStatus::Open))
        ->toContain('/rmas/open');

    expect(RmaOverviewQueries::urlForStatus(RmaStatus::WaitingSupplier))
        ->toContain('/rmas/waiting_supplier');
});

it('returns the latest return days with rma counts excluding drafts', function (): void {
    Carbon::setTestNow('2026-06-03 12:00:00');

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
        'total_rows' => 3,
        'successful_rows' => 3,
    ]);

    $rowA1 = ImportRow::query()->create([
        'import_id' => $batch->id,
        'return_date' => '2026-06-01',
    ]);
    $rowA2 = ImportRow::query()->create([
        'import_id' => $batch->id,
        'return_date' => '2026-06-01',
    ]);
    $rowB1 = ImportRow::query()->create([
        'import_id' => $batch->id,
        'return_date' => '2026-06-03',
    ]);

    Rma::query()->create([
        'uid' => 'DAY-A-1',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'import_row_id' => $rowA1->id,
        'created_at' => '2026-06-10 10:00:00',
    ]);
    Rma::query()->create([
        'uid' => 'DAY-A-2',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'import_row_id' => $rowA2->id,
        'created_at' => '2026-06-10 11:00:00',
    ]);
    Rma::query()->create([
        'uid' => 'DAY-B-1',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'import_row_id' => $rowB1->id,
        'created_at' => '2026-06-10 09:00:00',
    ]);
    Rma::query()->create([
        'uid' => 'DRAFT-DAY',
        'status' => RmaStatus::Open,
        'is_draft' => true,
        'import_row_id' => ImportRow::query()->create([
            'import_id' => $batch->id,
            'return_date' => '2026-06-04',
        ])->id,
        'created_at' => '2026-06-04 09:00:00',
    ]);

    $days = RmaOverviewQueries::returnDateDayCounts();

    expect($days)->toHaveCount(31)
        ->and($days->first()['date'])->toBe('2026-05-04')
        ->and($days->last()['date'])->toBe('2026-06-03')
        ->and($days->firstWhere('date', '2026-06-01')['value'])->toBe(2)
        ->and($days->firstWhere('date', '2026-06-03')['value'])->toBe(1)
        ->and($days->firstWhere('date', '2026-06-02')['value'])->toBe(0);

    Carbon::setTestNow();
});

it('falls back to rma return date when import row return date is missing', function (): void {
    Carbon::setTestNow('2026-06-05 12:00:00');

    Rma::query()->create([
        'uid' => 'RETURN-DATE-1',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'return_date' => '2026-06-04',
    ]);

    $days = RmaOverviewQueries::returnDateDayCounts();

    expect($days->firstWhere('date', '2026-06-04')['value'])->toBe(1);

    Carbon::setTestNow();
});

it('limits return day counts to the latest thirty-one calendar days', function (): void {
    Carbon::setTestNow('2026-06-16 12:00:00');

    Rma::query()->create([
        'uid' => 'LMT-BEFORE',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'return_date' => '2026-05-16',
    ]);
    Rma::query()->create([
        'uid' => 'LMT-START',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'return_date' => '2026-05-17',
    ]);
    Rma::query()->create([
        'uid' => 'LMT-END',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'return_date' => '2026-06-16',
    ]);
    Rma::query()->create([
        'uid' => 'LMT-AFTER',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'return_date' => '2026-06-17',
    ]);

    $days = RmaOverviewQueries::returnDateDayCounts();

    expect($days)->toHaveCount(31)
        ->and($days->first()['date'])->toBe('2026-05-17')
        ->and($days->last()['date'])->toBe('2026-06-16')
        ->and($days->firstWhere('date', '2026-05-17')['value'])->toBeGreaterThanOrEqual(1)
        ->and($days->firstWhere('date', '2026-06-16')['value'])->toBeGreaterThanOrEqual(1);

    Carbon::setTestNow();
});

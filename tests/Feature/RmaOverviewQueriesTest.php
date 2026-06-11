<?php

use App\Enums\RmaStatus;
use App\Models\Rma;
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

it('builds rma index urls filtered by status', function (): void {
    $url = RmaOverviewQueries::indexUrlForStatus(RmaStatus::InProgress);

    expect($url)->toContain('tableFilters')
        ->and($url)->toContain('in_progress');
});

it('returns the latest purchase days with rma counts excluding drafts', function (): void {
    Carbon::setTestNow('2026-06-03 12:00:00');

    Rma::query()->create([
        'uid' => 'DAY-A-1',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'purchased_at' => '2026-06-01',
    ]);
    Rma::query()->create([
        'uid' => 'DAY-A-2',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'purchased_at' => '2026-06-01',
    ]);
    Rma::query()->create([
        'uid' => 'DAY-B-1',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'purchased_at' => '2026-06-03',
    ]);
    Rma::query()->create([
        'uid' => 'NO-DATE',
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'purchased_at' => null,
    ]);
    Rma::query()->create([
        'uid' => 'DRAFT-DAY',
        'status' => RmaStatus::Open,
        'is_draft' => true,
        'purchased_at' => '2026-06-04',
    ]);

    $days = RmaOverviewQueries::purchasedAtDayCounts();

    expect($days)->toHaveCount(31)
        ->and($days->first()['date'])->toBe('2026-05-04')
        ->and($days->last()['date'])->toBe('2026-06-03')
        ->and($days->firstWhere('date', '2026-06-01')['value'])->toBe(2)
        ->and($days->firstWhere('date', '2026-06-03')['value'])->toBe(1)
        ->and($days->firstWhere('date', '2026-06-02')['value'])->toBe(0);
});

it('limits purchase day counts to the latest thirty-one calendar days', function (): void {
    Carbon::setTestNow('2026-06-16 12:00:00');

    for ($day = 1; $day <= 40; $day++) {
        Rma::query()->create([
            'uid' => 'LIMIT-'.$day,
            'status' => RmaStatus::Open,
            'is_draft' => false,
            'purchased_at' => sprintf('2026-06-%02d', $day),
        ]);
    }

    $days = RmaOverviewQueries::purchasedAtDayCounts();

    expect($days)->toHaveCount(31)
        ->and($days->first()['date'])->toBe('2026-05-17')
        ->and($days->last()['date'])->toBe('2026-06-16')
        ->and($days->firstWhere('date', '2026-06-16')['value'])->toBe(1)
        ->and($days->firstWhere('date', '2026-06-01')['value'])->toBe(0);
});

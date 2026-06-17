<?php

use App\Enums\RmaStatus;

it('provides dutch labels for all rma statuses', function (): void {
    foreach (RmaStatus::cases() as $status) {
        expect($status->getLabel())->not->toBeEmpty();
        expect($status->getColor())->not->toBeEmpty();
        expect(RmaStatus::labels())->toHaveKey($status->value);
    }
});

it('defaults to open status value', function (): void {
    expect(RmaStatus::Open->value)->toBe('open');
});

it('provides overview slugs and statuses', function (): void {
    expect(RmaStatus::Draft->overviewSlug())->toBe('draft')
        ->and(RmaStatus::Open->overviewSlug())->toBe('open')
        ->and(RmaStatus::WaitingSupplier->overviewSlug())->toBe('waiting_supplier')
        ->and(RmaStatus::InProgress->overviewSlug())->toBe('in_progress')
        ->and(RmaStatus::fromOverviewSlug('waiting_supplier'))->toBe(RmaStatus::WaitingSupplier)
        ->and(RmaStatus::fromOverviewSlug('draft'))->toBe(RmaStatus::Draft)
        ->and(RmaStatus::fromOverviewSlug('unknown'))->toBeNull();

    expect(RmaStatus::overviewStatuses())->toHaveCount(8)
        ->and(RmaStatus::overviewStatuses())->not->toContain(RmaStatus::Closed);
});

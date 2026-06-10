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

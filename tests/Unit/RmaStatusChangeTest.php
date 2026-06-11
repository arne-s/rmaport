<?php

use App\Enums\RmaStatus;
use App\Models\Rma;
use App\Models\RmaEvent;
use App\Models\RmaStatusChange;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(Tests\TestCase::class, DatabaseTransactions::class);

it('records status change and event when rma status changes', function (): void {
    $rma = Rma::query()->create([
        'uid' => 'RMA-CHANGE-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
    ]);

    $rma->changeStatus(RmaStatus::InProgress);

    $rma->refresh();

    expect($rma->status)->toBe(RmaStatus::InProgress);

    $change = RmaStatusChange::query()->where('rma_id', $rma->getKey())->first();

    expect($change)->not->toBeNull()
        ->and($change->from_status)->toBe(RmaStatus::Open->value)
        ->and($change->to_status)->toBe(RmaStatus::InProgress->value);

    expect(RmaEvent::query()
        ->where('rma_id', $rma->getKey())
        ->where('type', 'RMA-status gewijzigd: Open → In behandeling')
        ->exists())->toBeTrue();
});

it('does nothing when changing to the same status', function (): void {
    $rma = Rma::query()->create([
        'uid' => 'RMA-SAME-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
    ]);

    $rma->changeStatus(RmaStatus::Open);

    expect(RmaStatusChange::query()->where('rma_id', $rma->getKey())->count())->toBe(0)
        ->and(RmaEvent::query()->where('rma_id', $rma->getKey())->count())->toBe(0);
});

it('creates a standalone rma event via logEvent', function (): void {
    $rma = Rma::query()->create([
        'uid' => 'RMA-LOG-001',
        'status' => RmaStatus::Open,
        'is_draft' => false,
    ]);

    $event = $rma->logEvent('Testgebeurtenis', ['foo' => 'bar']);

    expect($event->type)->toBe('Testgebeurtenis')
        ->and($event->data)->toBe(['foo' => 'bar']);
});

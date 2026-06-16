<?php

use App\Models\Rma;
use App\Support\RmaNumberSequence;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(Tests\TestCase::class, DatabaseTransactions::class);

it('generates the first rma number padded to eight digits', function (): void {
    expect(RmaNumberSequence::next())->toBe('00000001');
});

it('increments rma numbers sequentially', function (): void {
    Rma::query()->create([
        'uid' => '00000001',
        'is_draft' => false,
    ]);

    expect(RmaNumberSequence::next())->toBe('00000002');
});

it('ignores non numeric legacy uids when determining the next number', function (): void {
    Rma::query()->create([
        'uid' => 'AD999888',
        'is_draft' => false,
    ]);

    Rma::query()->create([
        'uid' => '00000005',
        'is_draft' => false,
    ]);

    expect(RmaNumberSequence::next())->toBe('00000006');
});

it('generates unique draft uids as eight digit numbers', function (): void {
    $first = Rma::createDraft();
    $second = Rma::createDraft();

    expect($first->uid)->toMatch('/^\d{8}$/')
        ->and($second->uid)->toMatch('/^\d{8}$/')
        ->and((int) $second->uid)->toBe((int) $first->uid + 1);
});

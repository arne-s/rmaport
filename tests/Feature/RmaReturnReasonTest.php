<?php

use App\Enums\RmaStatus;
use App\Models\Rma;
use Illuminate\Support\Facades\Schema;

it('stores long return reasons on rmas', function (): void {
    $returnReason = str_repeat('Retourreden ', 40);

    $rma = Rma::query()->create([
        'uid' => 'RMA-LONG-RETURN-'.uniqid(),
        'status' => RmaStatus::Open,
        'is_draft' => false,
        'return_reason' => $returnReason,
    ]);

    expect($rma->fresh()->return_reason)->toBe($returnReason)
        ->and(strlen($returnReason))->toBeGreaterThan(100);
});

it('uses a text column for rmas return_reason', function (): void {
    $column = collect(Schema::getColumns('rmas'))
        ->firstWhere('name', 'return_reason');

    expect($column)->not->toBeNull()
        ->and($column['type_name'] ?? null)->toBe('text');
});

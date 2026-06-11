<?php

use App\Filament\Resources\RmaResource\Support\RmaRelatedMainResolver;
use App\Models\Rma;

it('returns null when no related main exists', function (): void {
    $rma = Rma::query()->create([
        'uid' => 'RMA-MAIN-002',
        'order_nr' => 'UNKNOWN-999',
        'status' => 'open',
        'is_draft' => false,
    ]);

    expect(RmaRelatedMainResolver::resolve($rma))->toBeNull();
});

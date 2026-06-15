<?php

use App\Filament\Resources\RmaResource\Support\RmaRelatedMainResolver;
use App\Models\ImportRow;
use App\Models\Rma;

it('returns null when no related main exists', function (): void {
    $rma = Rma::query()->create([
        'uid' => 'RMA-MAIN-002',
        'status' => 'open',
        'is_draft' => false,
    ]);

    $rma->setRelation('importRow', ImportRow::make(['reference' => 'UNKNOWN-999']));

    expect(RmaRelatedMainResolver::resolve($rma))->toBeNull();
});

<?php

namespace App\Models\Concerns;

use App\Models\Rma;
use App\Services\Import\ImportRowProductResolver;

trait ResolvesRmaProductFromEan
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function applyRmaImportData(Rma $rma, array &$data): void
    {
        $ean = $data['ean'] ?? null;

        unset(
            $data['ean'],
            $data['order_nr'],
            $data['location_name'],
            $data['purchased_at'],
            $data['returned_at'],
            $data['reference'],
            $data['is_doa'],
        );

        $rma->fill($data);

        if ($rma->product_id !== null || blank($ean)) {
            return;
        }

        $rma->product_id = app(ImportRowProductResolver::class)
            ->findByEan($ean)
            ?->id;
    }
}

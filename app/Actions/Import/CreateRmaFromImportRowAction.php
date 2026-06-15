<?php

namespace App\Actions\Import;

use App\Enums\RmaStatus;
use App\Models\ImportRow;
use App\Models\Rma;
use App\Services\Import\ImportRowProductResolver;
use App\Support\RmaImport\Concerns\MapsRmaImportRows;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CreateRmaFromImportRowAction
{
    use MapsRmaImportRows;

    public function __construct(
        private readonly ImportRowProductResolver $productResolver = new ImportRowProductResolver,
    ) {}

    public function __invoke(ImportRow $importRow): Rma
    {
        $importRow->loadMissing(['importBatch', 'source', 'rma']);

        if ($importRow->rma !== null) {
            throw new RuntimeException('Er bestaat al een RMA voor deze importregel.');
        }

        $uid = $this->resolveUid(
            $importRow->assignment_nr,
            $importRow->reference,
            $importRow->customer_order_id,
            'IR'.$importRow->getKey(),
        );

        if ($uid === null) {
            throw new RuntimeException('Geen RMA-nummer af te leiden uit deze importregel.');
        }

        if (Rma::query()->where('uid', $uid)->exists()) {
            throw new RuntimeException("RMA-nummer {$uid} bestaat al.");
        }

        $product = $this->productResolver->findByEan($importRow->ean_nr);

        if ($product === null) {
            throw new RuntimeException('Geen product gevonden voor de EAN van deze importregel.');
        }

        $customerId = $importRow->customer_id ?? $importRow->source?->customer_id;

        if ($customerId === null) {
            throw new RuntimeException('Geen klant gekoppeld aan deze importregel.');
        }

        return DB::transaction(function () use ($importRow, $uid, $product, $customerId): Rma {
            $batch = $importRow->importBatch;

            /** @var Rma $rma */
            $rma = Rma::query()->create([
                'uid' => $uid,
                'customer_id' => $customerId,
                'import_row_id' => $importRow->getKey(),
                'product_id' => $product->id,
                'quantity' => 1,
                'accessories' => $importRow->accessories,
                'return_reason' => $importRow->return_reason,
                'packing_slip_number' => $batch?->reference,
                'received_at' => $importRow->received_at
                    ?? $importRow->return_date?->startOfDay()
                    ?? $batch?->shipment_date?->startOfDay(),
                'status' => RmaStatus::Open,
                'is_draft' => false,
            ]);

            $rma->logEvent('Aangemaakt vanuit importregel', [
                'import_row_id' => $importRow->getKey(),
                'import_id' => $importRow->import_id,
            ]);

            return $rma;
        });
    }
}

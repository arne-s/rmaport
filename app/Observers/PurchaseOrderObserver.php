<?php

namespace App\Observers;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderStatusChange;

class PurchaseOrderObserver
{
    /**
     * Handle the PurchaseOrder "created" event.
     */
    public function created(PurchaseOrder $purchaseOrder): void
    {
        //
    }

    /**
     * Handle the PurchaseOrder "updated" event.
     */
    public function updated(PurchaseOrder $purchaseOrder): void
    {
        if (! $purchaseOrder->wasChanged('status')) {
            return;
        }

        $to = $purchaseOrder->status;
        $from = $purchaseOrder->getOriginal('status');

        $fromStr = $from instanceof PurchaseOrderStatus ? $from->value : $from;
        $toStr = $to instanceof PurchaseOrderStatus ? $to->value : $to;

        PurchaseOrderStatusChange::create([
            'purchase_order_id' => $purchaseOrder->id,
            'from_status' => $fromStr,
            'to_status' => $toStr,
            'changed_by' => auth()?->id(),
            'meta' => null,
        ]);

        $purchaseOrder->loadMissing('orderProducts');
        if ($purchaseOrder->orderProductsAreAllInPickState()) {
            return;
        }

        $purchaseOrder->applyStatusToOrderProducts();
    }

    /**
     * Handle the PurchaseOrder "deleted" event.
     */
    public function deleted(PurchaseOrder $purchaseOrder): void
    {
        //
    }

    /**
     * Handle the PurchaseOrder "restored" event.
     */
    public function restored(PurchaseOrder $purchaseOrder): void
    {
        //
    }

    /**
     * Handle the PurchaseOrder "force deleted" event.
     */
    public function forceDeleted(PurchaseOrder $purchaseOrder): void
    {
        //
    }
}

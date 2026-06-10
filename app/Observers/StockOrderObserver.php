<?php

namespace App\Observers;

use App\Enums\OrderStatus;
use App\Models\Order\Order;
use App\Models\Order\StockOrder;
use App\Models\OrderStatusChange;

class StockOrderObserver
{
    public function creating(StockOrder $stockOrder): void
    {
        if ($stockOrder->getAuthorId() === null && auth()->id() !== null) {
            $stockOrder->setAuthorId((int) auth()->id());
        }
    }

    /**
     * Handle the StockOrder "created" event.
     */
    public function created(StockOrder $stockOrder): void
    {

    }

    /**
     * Handle the StockOrder "updated" event.
     */
    public function updated(StockOrder $stockOrder): void
    {
        if ($stockOrder->getOrderStatus()?->value === 'verified' && !$stockOrder->getIsVerified()) {
            $stockOrder->setIsVerified(true);
            $stockOrder->saveQuietly();
        }

        $to = $stockOrder->order_status;
        if ($stockOrder->isDirty('order_status') || $to === OrderStatus::Order) {
            // Original/current values may be strings or enum instances depending on casting
            $from = $stockOrder->getOriginal('order_status');

            // Normalize to strings
            $fromStr = $from instanceof OrderStatus ? $from->value : $from;
            $toStr   = $to   instanceof OrderStatus ? $to->value   : $to;

            OrderStatusChange::create([
                'order_id'    => $stockOrder->id,
                'from_status' => $fromStr,
                'to_status'   => $toStr,
                'changed_by'  => auth()?->id(),
                'meta'        => null,
            ]);
        }
    }

    /**
     * Handle the StockOrder "deleted" event.
     */
    public function deleted(StockOrder $stockOrder): void
    {
        //
    }

    /**
     * Handle the StockOrder "restored" event.
     */
    public function restored(StockOrder $stockOrder): void
    {
        //
    }

    /**
     * Handle the StockOrder "force deleted" event.
     */
    public function forceDeleted(StockOrder $stockOrder): void
    {
        //
    }
}

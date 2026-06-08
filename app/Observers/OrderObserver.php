<?php

namespace App\Observers;

use App\Enums\OrderGeneralStatus;
use App\Enums\OrderStatus;
use App\Models\Order\Order;
use App\Models\OrderStatusChange;

class OrderObserver
{
    /**
     * Handle the Order "created" event.
     */
    public function created(Order $order): void
    {
        $this->syncMainAdvisorWhenMissing($order);
        $this->syncMainOrderStatusWhenPending($order);
    }

    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        $this->syncMainAdvisorWhenMissing($order);
        $this->syncMainOrderStatusWhenPending($order);

        $orderStatus = $order->getOrderStatus();
        if ($orderStatus === null) {
            return;
        }

        if ($orderStatus->value === 'verified' && ! $order->getIsVerified()) {
            $order->setIsVerified(true);
            $order->saveQuietly();
        }

        $to = $order->order_status;
        if ($order->isDirty('order_status') || $to === OrderStatus::Order) {
            // Original/current values may be strings or enum instances depending on casting
            $from = $order->getOriginal('order_status');

            // Normalize to strings
            $fromStr = $from instanceof OrderStatus ? $from->value : $from;
            $toStr = $to instanceof OrderStatus ? $to->value : $to;

            OrderStatusChange::create([
                'order_id' => $order->id,
                'from_status' => $fromStr,
                'to_status' => $toStr,
                'changed_by' => auth()?->id(),
                'meta' => null,
            ]);
        }
    }

    private function syncMainOrderStatusWhenPending(Order $order): void
    {
        if ($order->main_id === null) {
            return;
        }
        $status = $order->getStatus();
        if (! in_array($status, [OrderGeneralStatus::Pending, OrderGeneralStatus::Sent], true)) {
            return;
        }
        $main = $order->main;
        if ($main === null) {
            return;
        }
        $mainOrderStatus = $main->getOrderStatus();
        $allowTransition = [
            OrderStatus::QuoteConcept,
            OrderStatus::QuoteSent,
            OrderStatus::Quote,
            OrderStatus::Fitting,
            OrderStatus::FittingPlanned,
            OrderStatus::FittingReady,
        ];
        if ($mainOrderStatus !== null && ! in_array($mainOrderStatus, $allowTransition, true)) {
            return;
        }
        $main->changeOrderStatus(OrderStatus::OrderAudit);
    }

    private function syncMainAdvisorWhenMissing(Order $order): void
    {
        if ($order->main_id === null) {
            return;
        }

        $advisorId = $order->advisor_id;
        if ($advisorId === null) {
            return;
        }

        $main = $order->main;
        if ($main === null || $main->advisor_id !== null) {
            return;
        }

        $main->advisor_id = $advisorId;
        $main->saveQuietly();
    }

    /**
     * Handle the Order "deleted" event.
     */
    public function deleted(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "restored" event.
     */
    public function restored(Order $order): void
    {
        //
    }

    /**
     * Handle the Order "force deleted" event.
     */
    public function forceDeleted(Order $order): void
    {
        //
    }
}

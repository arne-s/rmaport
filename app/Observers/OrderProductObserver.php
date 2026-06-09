<?php

namespace App\Observers;

use App\Enums\OrderProductStatus;
use App\Enums\OrderType;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\OrderProduct;
use App\Models\OrderProductStatusChange;

class OrderProductObserver
{
    public function updating(OrderProduct $orderProduct): void
    {
        $to = $orderProduct->status;
        if ($orderProduct->isDirty('status')) {
            $toStatus = $to instanceof OrderProductStatus ? $to : OrderProductStatus::tryFrom((string) $to);
            if (
                $toStatus !== null
                && in_array($toStatus, [
                    OrderProductStatus::Delivered,
                    OrderProductStatus::PickedReceived,
                    OrderProductStatus::PickedStock,
                ], true)
                && $orderProduct->getAttribute('delivered_at') === null
            ) {
                $orderProduct->setAttribute('delivered_at', now());
            }

            // Original/current values may be strings or enum instances depending on casting
            $from = $orderProduct->getOriginal('status');

            // Normalize to strings
            $fromStr = $from instanceof OrderProductStatus ? $from->value : $from;
            $toStr   = $to   instanceof OrderProductStatus ? $to->value   : $to;

            OrderProductStatusChange::create([
                'order_product_id'    => $orderProduct->id,
                'from_status' => $fromStr,
                'to_status'   => $toStr,
                'changed_by'  => auth()?->id(),
                'meta'        => null,
            ]);
        }
    }

    public function saved(OrderProduct $orderProduct): void
    {
        if (
            ! $orderProduct->wasRecentlyCreated
            && ! $orderProduct->wasChanged([
                'status',
                'purchase_order_id',
                'release_order_id',
                'order_id',
                'fulfillment_type',
            ])
        ) {
            return;
        }

        $this->recalculateMainSummariesForOrderIds([
            $orderProduct->order_id,
            $orderProduct->getOriginal('order_id'),
        ]);
    }

    public function deleted(OrderProduct $orderProduct): void
    {
        $this->recalculateMainSummariesForOrderIds([
            $orderProduct->order_id,
            $orderProduct->getOriginal('order_id'),
        ]);
    }

    /**
     * @param  array<int, int|string|null>  $orderIds
     */
    protected function recalculateMainSummariesForOrderIds(array $orderIds): void
    {
        $normalizedOrderIds = collect($orderIds)
            ->filter(fn ($value): bool => $value !== null && (int) $value > 0)
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->values();

        if ($normalizedOrderIds->isEmpty()) {
            return;
        }

        $mainIds = BaseOrder::withoutGlobalScopes()
            ->whereIn('id', $normalizedOrderIds->all())
            ->get(['id', 'main_id', 'type'])
            ->map(function (BaseOrder $order): ?int {
                if ($order->type === OrderType::Main) {
                    return (int) $order->id;
                }

                return $order->main_id !== null ? (int) $order->main_id : null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($mainIds->isEmpty()) {
            return;
        }

        Main::withoutGlobalScopes()
            ->whereIn('id', $mainIds->all())
            ->get()
            ->each(function (Main $main): void {
                $main->recalculateProductSummary();
            });
    }
}

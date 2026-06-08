<?php

namespace App\Mail\Concerns;

use App\Models\Order\BaseOrder;
use App\Models\PurchaseOrder;
use Carbon\Carbon;

trait BuildsDealerExpectedDeliveryTemplateVars
{
    public static function preview(): static
    {
        $purchaseOrder = PurchaseOrder::query()
            ->with([
                'orderProducts.product',
                'main.billingCustomer',
                'main.customer',
                'order.billingCustomer',
                'order.customer',
            ])
            ->whereHas('orderProducts')
            ->latest()
            ->first()
            ?? PurchaseOrder::query()
                ->with(['main', 'order', 'orderProducts.product'])
                ->latest()
                ->first();

        return new static(
            $purchaseOrder ?? new PurchaseOrder,
            now()->addWeeks(2)->toDateString(),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function buildDealerExpectedDeliveryTemplateVars(
        PurchaseOrder $purchaseOrder,
        string $deliveryDate,
    ): array {
        $order = $this->resolvePurchaseOrderMailOrder($purchaseOrder);

        $products = $purchaseOrder->orderProducts()
            ->with('product')
            ->get()
            ->map(fn ($orderProduct) => $orderProduct->product?->getName() ?? $orderProduct->getValue())
            ->filter()
            ->values();

        $listItems = $products
            ->map(fn (string $item) => '<li>' . e($item) . '</li>')
            ->join('');

        $productsList = '<div style="text-align: center"><ul style="display: inline-block; text-align: left; margin: 0; padding-left: 10px;">'
            . $listItems
            . '</ul></div>';

        return [
            'customer_name' => $this->resolveDealerCustomerName($order),
            'order_customer_name' => $this->resolveOrderEndCustomerName($order),
            'order_number' => $order?->getUid() ?? $purchaseOrder->getReferenceNumber(),
            'delivery_date' => static::formatDeliveryWeek($deliveryDate),
            'products' => $productsList,
        ];
    }

    protected function resolvePurchaseOrderMailOrder(PurchaseOrder $purchaseOrder): ?BaseOrder
    {
        $purchaseOrder->loadMissing(['main', 'order']);

        if ($purchaseOrder->main_id !== null && $purchaseOrder->main !== null) {
            return $purchaseOrder->main;
        }

        return $purchaseOrder->order;
    }

    protected function resolveDealerCustomerName(?BaseOrder $order): string
    {
        if ($order === null) {
            return '';
        }

        $order->loadMissing('billingCustomer');

        return trim((string) ($order->billingCustomer?->getName() ?? ''));
    }

    protected function resolveOrderEndCustomerName(?BaseOrder $order): string
    {
        if ($order === null) {
            return '';
        }

        $order->loadMissing('customer');

        if ($order->customer !== null) {
            $name = trim((string) ($order->customer->getName() ?? ''));

            return $name !== '' ? $name : (string) $order->getCustomerAddressDisplayName();
        }

        return (string) $order->getCustomerAddressDisplayName();
    }

    public static function formatDeliveryWeek(string $date): string
    {
        return Carbon::parse($date)->translatedFormat('\W\e\e\k W, Y');
    }
}

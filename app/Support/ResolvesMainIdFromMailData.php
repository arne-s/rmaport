<?php

namespace App\Support;

use App\Enums\OrderType;
use App\Models\Order\BaseOrder;
use App\Models\Order\Invoice;
use App\Models\Order\Main;
use App\Models\Order\Quote;
use App\Models\OrderProduct;

class ResolvesMainIdFromMailData
{
    /**
     * Resolve a main (aanvraag) id from Laravel MessageSending view data (public mailable properties).
     *
     * @param  array<string, mixed>  $data
     */
    public static function resolve(array $data): ?int
    {
        if (isset($data['main']) && $data['main'] instanceof Main) {
            return $data['main']->getId();
        }

        if (isset($data['order'])) {
            $mainId = self::resolveFromOrder($data['order']);

            if ($mainId !== null) {
                return $mainId;
            }
        }

        if (isset($data['invoice']) && $data['invoice'] instanceof Invoice) {
            $mainId = self::resolveFromInvoice($data['invoice']);

            if ($mainId !== null) {
                return $mainId;
            }
        }

        if (isset($data['quote']) && $data['quote'] instanceof Quote) {
            $mainId = $data['quote']->getMainId() ?? $data['quote']->getMain()?->getId();

            if ($mainId !== null) {
                return $mainId;
            }
        }

        if (isset($data['orderProduct']) && $data['orderProduct'] instanceof OrderProduct) {
            $mainId = self::resolveFromOrderProduct($data['orderProduct']);

            if ($mainId !== null) {
                return $mainId;
            }
        }

        if (isset($data['orderId']) && is_numeric($data['orderId'])) {
            $order = BaseOrder::query()->find((int) $data['orderId']);

            if ($order !== null) {
                return self::resolveFromOrder($order);
            }
        }

        return null;
    }

    protected static function resolveFromOrder(mixed $order): ?int
    {
        if ($order instanceof Main) {
            return $order->getId();
        }

        if ($order instanceof BaseOrder) {
            if ($order->getType() === OrderType::Main) {
                return $order->getId();
            }

            return $order->getMain()?->getId();
        }

        return null;
    }

    protected static function resolveFromInvoice(Invoice $invoice): ?int
    {
        if ($invoice->main_id !== null) {
            return (int) $invoice->main_id;
        }

        $main = $invoice->getMain() ?? $invoice->main;

        if ($main instanceof Main) {
            return $main->getId();
        }

        return $invoice->order?->getMain()?->getId();
    }

    protected static function resolveFromOrderProduct(OrderProduct $orderProduct): ?int
    {
        $order = $orderProduct->order;

        if ($order === null) {
            return null;
        }

        return self::resolveFromOrder($order);
    }
}

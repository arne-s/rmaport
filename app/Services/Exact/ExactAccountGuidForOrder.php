<?php

namespace App\Services\Exact;

use App\Models\Order\BaseOrder;
use App\Services\ExactOnlineService;
use Exception;

class ExactAccountGuidForOrder
{
    /**
     * Resolve the Exact Account GUID for an order (quote/order/invoice) from the billing party.
     *
     * @throws Exception When no Exact account is configured for the billing party.
     */
    public static function resolve(BaseOrder $order, ExactOnlineService $exact): string
    {
        if ($exact->testmode) {
            return (string) config('exact.testdata.company_id');
        }

        $exactId = $order->billingCustomer?->getExactId()
            ?? $order->customer?->getExactId();

        if (! $exactId) {
            throw new Exception(
                "No Exact Account ID found for order {$order->getId()} (billing_customer_id: {$order->billing_customer_id})"
            );
        }

        return $exactId;
    }
}

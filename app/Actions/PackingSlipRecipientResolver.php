<?php

namespace App\Actions;

use App\Helpers\EmailHelper;
use App\Models\Order\Order;

/**
 * Resolves first name for [first_name] in packing-slip mail templates (shipping or selected To keys).
 */
final class PackingSlipRecipientResolver
{
    /**
     * First name from order shipping (same rules as the legacy packing-slip mail flow).
     */
    public static function recipientFirstNameFromShipping(Order $order): string
    {
        $shippingCustomer = $order->shippingCustomer;
        if ($shippingCustomer !== null) {
            return (string) ($shippingCustomer->getFirstName() ?? '');
        }

        return (string) ($order->customer?->getFirstName() ?? '');
    }

    /**
     * First name matching selected To keys in the mail modal (dealer vs customer).
     * For multiple or other keys: fall back to shipping-based logic.
     *
     * @param  array<int, string>  $toKeys
     */
    public static function recipientFirstNameForToKeys(Order $order, array $toKeys): string
    {
        $hasDealer = in_array('dealer', $toKeys, true);
        $hasCustomer = in_array('customer', $toKeys, true);

        if ($hasDealer && ! $hasCustomer) {
            return (string) ($order->billingCustomer?->getFirstName() ?? '');
        }

        if ($hasCustomer && ! $hasDealer) {
            return (string) ($order->customer?->getFirstName() ?? '');
        }

        return self::recipientFirstNameFromShipping($order);
    }
}

<?php

namespace App\Observers;

use App\Models\Address;
use App\Models\Customer;
use App\Services\NewsletterSubscriptionWriter;

/**
 * After save: billing/shipping FK normalization, newsletter sync, display names on addresses.
 */
class CustomerObserver
{
    public function saved(Customer $customer): void
    {
        $customer->ensureBillingAndShippingAddressLinks();
        $customer->refresh();

        foreach (array_unique(array_filter([
            $customer->billing_address_id,
            $customer->shipping_address_id,
            $customer->address_id,
        ], static fn ($id): bool => $id !== null && (int) $id !== 0)) as $addressId) {
            $address = Address::query()->find((int) $addressId);
            if ($address !== null) {
                if ((int) $address->customer_id !== (int) $customer->getKey()) {
                    $address->forceFill(['customer_id' => $customer->getKey()])->saveQuietly();
                }
                $address->syncInferredTypeFromCustomerLinks();
            }
        }

        NewsletterSubscriptionWriter::syncFromCustomer($customer);
        $customer->syncPersonDisplayNameOntoAddresses();
    }
}

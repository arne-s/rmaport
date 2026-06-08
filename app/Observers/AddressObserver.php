<?php

namespace App\Observers;

use App\Models\Address;
use App\Models\Customer;
use App\Services\NewsletterSubscriptionWriter;

class AddressObserver
{
    public function saved(Address $address): void
    {
        if ($address->type === null) {
            $address->syncInferredTypeFromCustomerLinks();
        }

        $customer = Customer::query()
            ->where(function ($query) use ($address): void {
                $query->where('billing_address_id', $address->id)
                    ->orWhere('shipping_address_id', $address->id);
            })
            ->first();

        if ($customer !== null) {
            NewsletterSubscriptionWriter::syncFromCustomer($customer);
        }
    }
}

<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Models\Address;
use App\Models\Country;
use App\Models\Customer;
use App\Support\CustomerCsvSchema;

class CustomerCsvAddressImporter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function sync(Customer $customer, array $data): void
    {
        $customer->loadMissing(['address', 'billingAddress', 'shippingAddress']);

        $type = $customer->getType();
        $deliveryAddressType = $this->resolveDeliveryAddressType($customer, $data, $type);

        if ($deliveryAddressType !== null) {
            $customer->delivery_address_type = $deliveryAddressType;
        }

        $contactFields = $this->extractAddressFields($data, 'contact');
        if (! $this->isAddressEmpty($contactFields)) {
            if ($type !== null && $type->isBusiness()) {
                $address = $this->upsertAddress($customer->address, $contactFields);
                $customer->address_id = $address->getKey();
            } else {
                $address = $this->upsertAddress($customer->billingAddress, $contactFields);
                $customer->billing_address_id = $address->getKey();
                $customer->address_id = $address->getKey();
            }
        }

        $billingFields = $this->extractAddressFields($data, 'billing');
        if (! $this->isAddressEmpty($billingFields)) {
            $address = $this->upsertAddress($customer->billingAddress, $billingFields);
            $customer->billing_address_id = $address->getKey();
        }

        if ($deliveryAddressType === 'custom') {
            $locationName = $this->nullableString($data['shipping_location_name'] ?? null);
            $shippingFields = $this->extractAddressFields($data, 'shipping', $locationName);

            if (! $this->isAddressEmpty($shippingFields)) {
                $address = $this->upsertAddress($customer->shippingAddress, $shippingFields);
                $customer->shipping_address_id = $address->getKey();
            }
        } elseif ($deliveryAddressType === 'contact') {
            $shippingFields = $this->extractAddressFields($data, 'shipping');

            if (! $this->isAddressEmpty($shippingFields)) {
                $locationName = $this->nullableString($data['shipping_location_name'] ?? null);
                if ($locationName !== null) {
                    $shippingFields['location_name'] = $locationName;
                }

                $address = $this->upsertAddress($customer->shippingAddress, $shippingFields);
                $customer->shipping_address_id = $address->getKey();
            } else {
                $sourceFields = $type !== null && $type->isBusiness()
                    ? ($this->isAddressEmpty($billingFields) ? null : $billingFields)
                    : ($this->isAddressEmpty($contactFields) ? null : $contactFields);

                if ($sourceFields !== null) {
                    $address = $this->upsertAddress($customer->shippingAddress, $sourceFields);
                    $customer->shipping_address_id = $address->getKey();
                }
            }
        }

        $this->applyNewsletterSubscriptionFromImport($customer, $data, $type);

        if ($customer->isDirty(['address_id', 'billing_address_id', 'shipping_address_id', 'delivery_address_type'])) {
            $customer->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyNewsletterSubscriptionFromImport(Customer $customer, array $data, ?CustomerType $type): void
    {
        $subscribed = $this->parseBoolean($data['newsletter_subscribed'] ?? null);

        if ($subscribed === null) {
            return;
        }

        if ($type?->usesNewsletterDealerSegments() === true) {
            $customer->loadMissing(['billingAddress', 'shippingAddress']);

            if ($customer->billingAddress !== null) {
                $customer->billingAddress->newsletter_subscribed = $subscribed;
                $customer->billingAddress->saveQuietly();
            }

            if (($customer->delivery_address_type ?? 'contact') === 'custom' && $customer->shippingAddress !== null) {
                $customer->shippingAddress->newsletter_subscribed = $subscribed;
                $customer->shippingAddress->saveQuietly();
            }

            return;
        }

        $customer->newsletter_subscribed = $subscribed;
        $customer->saveQuietly();
    }

    private function parseBoolean(mixed $state): ?bool
    {
        if ($state === null || $state === '') {
            return null;
        }

        return match (strtolower(trim((string) $state))) {
            'ja', 'yes', '1', 'true' => true,
            'nee', 'no', '0', 'false' => false,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveDeliveryAddressType(Customer $customer, array $data, ?CustomerType $type): ?string
    {
        $value = CustomerCsvSchema::parseDeliveryAddressType(
            $this->nullableString($data['delivery_address_type'] ?? null),
        );

        if ($value !== null && in_array($value, ['contact', 'custom'], true)) {
            return $value;
        }

        if ($customer->delivery_address_type !== null) {
            return $customer->delivery_address_type;
        }

        return 'contact';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     street: ?string,
     *     house_number: ?string,
     *     house_number_addition: ?string,
     *     postcode: ?string,
     *     city: ?string,
     *     country_id: ?int,
     *     location_name: ?string,
     * }
     */
    private function extractAddressFields(array $data, string $prefix, ?string $locationName = null): array
    {
        $hasAnyField = collect([
            "{$prefix}_street",
            "{$prefix}_house_number",
            "{$prefix}_house_number_addition",
            "{$prefix}_postcode",
            "{$prefix}_city",
            "{$prefix}_country",
        ])->contains(fn (string $key): bool => filled($data[$key] ?? null));

        return [
            'street' => $this->nullableString($data["{$prefix}_street"] ?? null),
            'house_number' => $this->nullableString($data["{$prefix}_house_number"] ?? null),
            'house_number_addition' => $this->nullableString($data["{$prefix}_house_number_addition"] ?? null),
            'postcode' => $this->nullableString($data["{$prefix}_postcode"] ?? null),
            'city' => $this->nullableString($data["{$prefix}_city"] ?? null),
            'country_id' => $hasAnyField
                ? $this->resolveCountryId($this->nullableString($data["{$prefix}_country"] ?? null))
                : null,
            'location_name' => $locationName,
        ];
    }

    /**
     * @param  array{
     *     street: ?string,
     *     house_number: ?string,
     *     house_number_addition: ?string,
     *     postcode: ?string,
     *     city: ?string,
     *     country_id: ?int,
     *     location_name: ?string,
     * }  $fields
     */
    private function isAddressEmpty(array $fields): bool
    {
        return blank($fields['street'])
            && blank($fields['house_number'])
            && blank($fields['house_number_addition'])
            && blank($fields['postcode'])
            && blank($fields['city']);
    }

    /**
     * @param  array{
     *     street: ?string,
     *     house_number: ?string,
     *     house_number_addition: ?string,
     *     postcode: ?string,
     *     city: ?string,
     *     country_id: ?int,
     *     location_name: ?string,
     * }  $fields
     */
    private function upsertAddress(?Address $existing, array $fields): Address
    {
        $address = $existing ?? new Address();

        $address->street = $fields['street'];
        $address->house_number = $fields['house_number'];
        $address->house_number_addition = $fields['house_number_addition'];
        $address->postcode = $fields['postcode'];
        $address->city = $fields['city'];

        if ($fields['country_id'] !== null) {
            $address->country_id = $fields['country_id'];
        }

        if ($fields['location_name'] !== null) {
            $address->location_name = $fields['location_name'];
        }

        $address->save();

        return $address;
    }

    private function resolveCountryId(?string $country): int
    {
        if ($country === null || $country === '') {
            return Country::NL_ID;
        }

        $resolved = Country::query()
            ->where('name', $country)
            ->orWhere('code', strtoupper($country))
            ->value('id');

        return $resolved ?? Country::NL_ID;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}

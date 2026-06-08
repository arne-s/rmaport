<?php

namespace App\Models\Concerns;

use App\Enums\PurchaseOrderType;
use App\Models\Customer;
use App\Models\Country;
use App\Models\Order\StockOrder;
use App\Models\PurchaseOrder;
use App\Models\ReleaseOrder;

trait FormatsDeliveryAddressLine
{
    public function getAddressFormatted(): string
    {
        $additional = $this->getAdditional() ?? [];
        $deliveryAddress = $this->resolveDeliveryAddressArray($additional['delivery_address'] ?? null);
        $shippingName = trim((string) ($additional['shipping_name'] ?? ''));
        $shippingName = $this->resolveShippingNameForFormattedLine($shippingName);

        if (! is_array($deliveryAddress)) {
            return '';
        }

        $street = trim((string) ($deliveryAddress['street'] ?? ''));
        $city = trim((string) ($deliveryAddress['city'] ?? ''));
        if ($street === '' && $city === '') {
            return '';
        }

        $houseNumber = trim((string) ($deliveryAddress['house_number'] ?? ''));
        $addition = trim((string) ($deliveryAddress['house_number_addition'] ?? ''));
        $line1First = trim($street . ' ' . $houseNumber);
        $line1 = trim($line1First . ($addition !== '' ? ', ' . $addition : ''));
        $postcodeRaw = trim((string) ($deliveryAddress['postcode'] ?? ''));
        $postcode = $this->formatDutchPostcodeForDisplay($postcodeRaw);
        $line2 = $postcode !== '' && $city !== ''
            ? $postcode . ', ' . $city
            : trim($postcode . ' ' . $city);

        $countryName = '';
        $countryId = $deliveryAddress['country_id'] ?? null;
        if ($countryId !== null && (int) $countryId !== Country::NL_ID) {
            $countryName = Country::query()->find((int) $countryId)?->name ?? '';
        }

        return implode(', ', array_filter([
            $shippingName !== '' ? $shippingName : null,
            $line1 !== '' ? $line1 : null,
            $line2 !== '' ? $line2 : null,
            $countryName !== '' ? $countryName : null,
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $deliveryAddress
     * @return array<string, mixed>|null
     */
    protected function resolveDeliveryAddressArray(mixed $deliveryAddress): ?array
    {
        $merged = is_array($deliveryAddress) ? $deliveryAddress : [];

        $street = trim((string) ($merged['street'] ?? ''));
        $city = trim((string) ($merged['city'] ?? ''));
        if ($street !== '' || $city !== '') {
            return $merged;
        }

        if (! method_exists($this, 'shippingAddress')) {
            return null;
        }

        $shipping = $this->shippingAddress;
        if ($shipping === null) {
            return null;
        }

        return array_merge($merged, [
            'street' => $shipping->street ?? '',
            'house_number' => $shipping->house_number ?? '',
            'house_number_addition' => $shipping->house_number_addition ?? '',
            'postcode' => $shipping->postcode ?? '',
            'city' => $shipping->city ?? '',
            'country_id' => $shipping->country_id ?? ($merged['country_id'] ?? null),
        ]);
    }

    protected function resolveShippingNameForFormattedLine(string $shippingName): string
    {
        if ($shippingName !== '') {
            return $shippingName;
        }

        if ($this instanceof PurchaseOrder) {
            $fromCustomer = trim((string) ($this->order?->customer?->getName() ?? ''));
            if ($fromCustomer !== '') {
                return $fromCustomer;
            }

            if ($this->getType() === PurchaseOrderType::Stock) {
                return trim((string) (Customer::getRdMobilityCustomer()?->getName() ?? ''));
            }

            return '';
        }

        if ($this instanceof StockOrder) {
            $dealer = trim((string) ($this->billingCustomer?->getName() ?? ''));
            if ($dealer !== '') {
                return $dealer;
            }

            return trim((string) (Customer::getRdMobilityCustomer()?->getName() ?? ''));
        }

        if ($this instanceof ReleaseOrder) {
            $fromDealer = trim((string) ($this->dealer?->getName() ?? ''));
            if ($fromDealer !== '') {
                return $fromDealer;
            }

            return trim((string) ($this->order?->customer?->getName() ?? ''));
        }

        return '';
    }

    protected function formatDutchPostcodeForDisplay(string $postcode): string
    {
        $pc = strtoupper(trim(str_replace(' ', '', $postcode)));
        if (preg_match('/^(\d{4})([A-Z]{2})$/', $pc, $matches)) {
            return $matches[1] . ' ' . $matches[2];
        }

        return trim($postcode);
    }
}

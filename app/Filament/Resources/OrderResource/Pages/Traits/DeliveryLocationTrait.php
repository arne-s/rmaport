<?php

namespace App\Filament\Resources\OrderResource\Pages\Traits;

use App\Models\Country;
use App\Models\Customer;
use App\Models\Order\Main;

/**
 * Manages delivery location fields in the ViewOrder delivery tab.
 */
trait DeliveryLocationTrait
{
    public string $deliveryLocationType = '';
    public string $deliveryLocationCustomName = '';
    public string $deliveryLocationCustomPostcode = '';
    public string $deliveryLocationCustomStreet = '';
    public string $deliveryLocationCustomHouseNumber = '';
    public string $deliveryLocationCustomHouseNumberAddition = '';
    public string $deliveryLocationCustomCity = '';
    public ?int $deliveryLocationCustomCountryId = Country::NL_ID;

    public function loadDeliveryFields(): void
    {
        $note = $this->record->getDeliveryNote();
        if (is_array($note)) {
            $this->deliveryNoteAttendees = (string)($note['attendees'] ?? '');
            $this->deliveryNoteGeneralNotes = (string)($note['general_notes'] ?? '');
        }

        $shippingCustomerId = $this->record->shipping_customer_id;
        $this->deliveryLocationType = $shippingCustomerId !== null ? 'customer-' . $shippingCustomerId : '';
    }

    public function updatedDeliveryLocationType(?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $this->persistDeliveryLocationFromFittingTab();
    }

    public function updatedDeliveryLocationCustomPostcode(): void
    {
        $this->performDeliveryPostcodeLookup();
    }

    public function updatedDeliveryLocationCustomHouseNumber(): void
    {
        $this->performDeliveryPostcodeLookup();
    }

    public function updatedDeliveryLocationCustomHouseNumberAddition(): void
    {
        $this->performDeliveryPostcodeLookup();
    }

    public function updatedDeliveryLocationCustomCountryId(): void
    {
        $this->performDeliveryPostcodeLookup();
    }

    public function saveDeliveryLocationCustomAddress(): void
    {
        if ($this->deliveryLocationType !== 'custom') {
            return;
        }

        $this->persistDeliveryLocationFromFittingTab();
    }

    public function getDeliveryLocationOptionsForFittingTab(): array
    {
        $options = [];
        $record = $this->record;

        $customerId = $record->customer_id;
        if ($customerId !== null) {
            $customerName = $record->getCustomerAddressDisplayName();
            $customerEmail = $record->getCustomerContactEmail();
            $customerLabel = $customerName . ($customerEmail !== '' ? ', ' . $customerEmail : '');
            $options['customer-' . $customerId] = 'Klant' . ($customerLabel !== '' ? ' (' . $customerLabel . ')' : '');
        }

        $billingCustomerId = $record->billing_customer_id;
        if ($billingCustomerId !== null && $billingCustomerId !== $customerId) {
            $record->loadMissing('billingCustomer');
            $dealerName = $record->billingCustomer?->getName();
            $options['customer-' . $billingCustomerId] = 'Dealer' . (filled($dealerName) ? ' (' . $dealerName . ')' : '');
        }

        $shippingCustomerId = $record->shipping_customer_id;
        if ($shippingCustomerId !== null && $shippingCustomerId !== $customerId && $shippingCustomerId !== $billingCustomerId) {
            $record->loadMissing('shippingCustomer');
            $shippingName = $record->shippingCustomer?->getName();
            $options['customer-' . $shippingCustomerId] = 'Leveradres' . (filled($shippingName) ? ' (' . $shippingName . ')' : '');
        }

        $options['custom'] = 'Afwijkend adres';

        return $options;
    }

    public function getDeliveryLocationAddressTextForFittingTab(): string
    {
        $formValue = $this->deliveryLocationType;

        if (!is_string($formValue) || !str_starts_with($formValue, 'customer-')) {
            return '';
        }

        $customerId = (int) str_replace('customer-', '', $formValue);
        /** @var Main $main */
        $main = $this->record;

        if ($customerId === (int) $main->shipping_customer_id
            || ($main->shipping_customer_id === null && $customerId === (int) $main->customer_id)
        ) {
            return $main->shippingAddress?->getAddressTemplate() ?? '';
        }

        $customer = Customer::query()->find($customerId);

        if ($customer === null) {
            return '';
        }

        $address = $customer->getPhysicalDeliveryAddress();
        if ($address === null) {
            return '';
        }

        return implode(', ', array_filter([
            filled($address->street) && filled($address->house_number)
                ? trim($address->street . ' ' . $address->house_number . ' ' . ($address->house_number_addition ?? ''))
                : null,
            $address->postcode,
            $address->city,
        ]));
    }

    public function getDeliveryLocationPhoneTextForFittingTab(): string
    {
        return '';
    }

    public function getCountryOptionsForDeliveryCustomAddress(): array
    {
        return Country::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function persistDeliveryLocationFromFittingTab(): bool
    {
        return true;
    }

    private function performDeliveryPostcodeLookup(): void
    {
        if ($this->deliveryLocationType !== 'custom') {
            return;
        }

        $countryId = $this->deliveryLocationCustomCountryId ?? Country::NL_ID;
        if ((int)$countryId !== Country::NL_ID) {
            return;
        }

        if (strlen(trim($this->deliveryLocationCustomPostcode)) < 6 || trim($this->deliveryLocationCustomHouseNumber) === '') {
            return;
        }

        $postcodeService = app('postcode');
        $response = $postcodeService->fetchAddress(
            $this->deliveryLocationCustomPostcode,
            $this->deliveryLocationCustomHouseNumber,
            $this->deliveryLocationCustomHouseNumberAddition
        );

        if (!isset($response['street'])) {
            return;
        }

        $this->deliveryLocationCustomCity = (string)($response['city'] ?? $this->deliveryLocationCustomCity);
        $this->deliveryLocationCustomStreet = (string)($response['street'] ?? $this->deliveryLocationCustomStreet);
    }
}

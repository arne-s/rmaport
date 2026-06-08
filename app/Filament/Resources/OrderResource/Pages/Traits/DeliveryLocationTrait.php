<?php

namespace App\Filament\Resources\OrderResource\Pages\Traits;

use App\Models\Appointment;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Order\Main;

/**
 * Manages delivery location in the ViewOrder delivery tab.
 * Location is stored on the active delivery Appointment (location_type, location_customer_id, location_custom).
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

        $appointment = $this->record->getActiveDeliveryAppointment();
        $this->deliveryLocationType = $this->appointmentToDeliveryLocationFormValue($appointment);

        if ($this->deliveryLocationType === 'custom') {
            $custom = is_string($appointment?->location_custom)
                ? json_decode($appointment->location_custom, true)
                : null;
            if (is_array($custom)) {
                $this->deliveryLocationCustomName = (string)($custom['location'] ?? '');
                $this->deliveryLocationCustomPostcode = (string)($custom['postcode'] ?? '');
                $this->deliveryLocationCustomStreet = (string)($custom['street'] ?? '');
                $this->deliveryLocationCustomHouseNumber = (string)($custom['house_number'] ?? '');
                $this->deliveryLocationCustomHouseNumberAddition = (string)($custom['house_number_addition'] ?? '');
                $this->deliveryLocationCustomCity = (string)($custom['city'] ?? '');
                $this->deliveryLocationCustomCountryId = isset($custom['country_id']) ? (int)$custom['country_id'] : Country::NL_ID;
            }
        }
    }

    private function appointmentToDeliveryLocationFormValue(?Appointment $appointment): string
    {
        if ($appointment === null) {
            $shippingCustomerId = $this->record->shipping_customer_id;
            return $shippingCustomerId !== null ? 'customer-' . $shippingCustomerId : '';
        }

        $locationType = $appointment->location_type;

        if ($locationType === 'phone') {
            return 'phone';
        }

        if ($locationType === 'custom') {
            return 'custom';
        }

        if ($locationType === 'customer' && $appointment->location_customer_id !== null) {
            return 'customer-' . $appointment->location_customer_id;
        }

        $shippingCustomerId = $this->record->shipping_customer_id;
        return $shippingCustomerId !== null ? 'customer-' . $shippingCustomerId : '';
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
        if ($this->record === null) {
            return true;
        }

        $locationType = $this->deliveryLocationType !== '' ? $this->deliveryLocationType : null;
        if ($locationType === null) {
            return true;
        }

        $appointment = $this->record->getActiveDeliveryAppointment();
        if ($appointment === null) {
            return true;
        }

        if ($locationType === 'custom') {
            $customData = array_filter([
                'location'              => trim($this->deliveryLocationCustomName) !== '' ? trim($this->deliveryLocationCustomName) : null,
                'postcode'              => trim($this->deliveryLocationCustomPostcode) !== '' ? trim($this->deliveryLocationCustomPostcode) : null,
                'street'                => trim($this->deliveryLocationCustomStreet) !== '' ? trim($this->deliveryLocationCustomStreet) : null,
                'house_number'          => trim($this->deliveryLocationCustomHouseNumber) !== '' ? trim($this->deliveryLocationCustomHouseNumber) : null,
                'house_number_addition' => trim($this->deliveryLocationCustomHouseNumberAddition) !== '' ? trim($this->deliveryLocationCustomHouseNumberAddition) : null,
                'city'                  => trim($this->deliveryLocationCustomCity) !== '' ? trim($this->deliveryLocationCustomCity) : null,
                'country_id'            => $this->deliveryLocationCustomCountryId ?? Country::NL_ID,
            ]);

            $appointment->location_type = 'custom';
            $appointment->location_customer_id = null;
            $appointment->location_custom = $customData !== [] ? json_encode($customData) : null;
        } elseif ($locationType === 'phone') {
            $appointment->location_type = 'phone';
            $appointment->location_customer_id = null;
            $appointment->location_custom = null;
        } elseif (str_starts_with($locationType, 'customer-')) {
            $customerId = (int) str_replace('customer-', '', $locationType);
            $appointment->location_type = 'customer';
            $appointment->location_customer_id = $customerId;
            $appointment->location_custom = null;
        }

        $appointment->save();

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

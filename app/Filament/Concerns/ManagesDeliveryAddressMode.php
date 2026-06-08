<?php

namespace App\Filament\Concerns;

use App\Enums\CustomerType;
use App\Models\Country;
use App\Models\Customer;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Delivery column: native select for ship-to end customer vs ship-to invoice (billing) customer.
 */
trait ManagesDeliveryAddressMode
{
    public const string DELIVERY_ADDRESS_MODE_CUSTOMER = 'customer';

    public const string DELIVERY_ADDRESS_MODE_INVOICE = 'invoice';

    public const string DELIVERY_ADDRESS_MODE_DEALER = 'dealer';

    public const string DELIVERY_ADDRESS_MODE_CUSTOM = 'custom';

    protected function deriveDeliveryAddressModeFromRecord(): string
    {
        $billingId = $this->record->billing_customer_id;
        $shipId = $this->record->shipping_customer_id;
        $customerId = $this->record->customer_id;

        if ($billingId !== null && $shipId !== null && (int) $shipId === (int) $billingId) {
            $this->record->loadMissing('billingCustomer');

            return $this->record->billingCustomer?->getType() === CustomerType::Dealer
                ? self::DELIVERY_ADDRESS_MODE_DEALER
                : self::DELIVERY_ADDRESS_MODE_INVOICE;
        }

        if (
            $customerId !== null
            && $shipId !== null
            && (int) $shipId === (int) $customerId
            && ($billingId === null || (int) $billingId !== (int) $customerId)
        ) {
            return self::DELIVERY_ADDRESS_MODE_CUSTOMER;
        }

        return self::DELIVERY_ADDRESS_MODE_INVOICE;
    }

    protected function resolveShippingCustomerIdForDeliveryMode(?string $mode, ?int $billingCustomerId, ?int $endCustomerId): ?int
    {
        $mode = (($mode ?? '') !== '') ? $mode : self::DELIVERY_ADDRESS_MODE_INVOICE;

        if ($mode === self::DELIVERY_ADDRESS_MODE_INVOICE || $mode === self::DELIVERY_ADDRESS_MODE_DEALER) {
            return $billingCustomerId ?? $endCustomerId;
        }

        return $endCustomerId ?? $billingCustomerId;
    }

    /**
     * @return array<string, string>
     */
    protected function buildDeliveryAddressModeOptions(Get $get): array
    {
        $endCustomerRaw = $get('customer_id');
        $endCustomerId = is_numeric($endCustomerRaw) ? (int) $endCustomerRaw : $this->record->customer_id;

        $billingRaw = $get('billing_customer_id');
        $billingId = is_numeric($billingRaw)
            ? (int) $billingRaw
            : ($this->record->billing_customer_id !== null ? (int) $this->record->billing_customer_id : null);

        if ($endCustomerId !== null && $billingId !== null && $endCustomerId === $billingId) {
            return [
                self::DELIVERY_ADDRESS_MODE_CUSTOMER => 'Leveradres klant',
                self::DELIVERY_ADDRESS_MODE_CUSTOM => 'Zelf ingeven',
            ];
        }

        $isDealer = $billingId !== null
            && Customer::query()->where('id', $billingId)->value('type') === CustomerType::Dealer;

        $options = [];

        if ($isDealer) {
            $options[self::DELIVERY_ADDRESS_MODE_DEALER] = 'Levergegevens dealer';
        }

        $options[self::DELIVERY_ADDRESS_MODE_CUSTOMER] = 'Leveradres klant';
        $options[self::DELIVERY_ADDRESS_MODE_CUSTOM] = 'Zelf ingeven';

        return $options;
    }

    protected function resolveDeliveryAddressModeForForm(): string
    {
        if ($this->record->resolveShippingAddressTypeKey() === self::DELIVERY_ADDRESS_MODE_CUSTOM) {
            return self::DELIVERY_ADDRESS_MODE_CUSTOM;
        }

        if ((int) $this->record->customer_id === (int) $this->record->billing_customer_id) {
            return self::DELIVERY_ADDRESS_MODE_CUSTOMER;
        }

        return $this->deriveDeliveryAddressModeFromRecord();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mergeDeliveryAddressModeIntoSaveData(array $data): array
    {
        $mode = $data['delivery_address_mode'] ?? null;

        if ($mode === self::DELIVERY_ADDRESS_MODE_CUSTOM) {
            $additional = array_merge($this->record->getAdditional() ?? [], $data['additional'] ?? []);
            $additional['shipping_address_type_key'] = self::DELIVERY_ADDRESS_MODE_CUSTOM;
            $additional['delivery_address'] = $data['additional']['delivery_address'] ?? $additional['delivery_address'] ?? null;
            $additional['shipping_name'] = $data['additional']['shipping_name'] ?? $additional['shipping_name'] ?? null;
            $data['additional'] = $additional;
            $data['shipping_customer_id'] = null;
            unset($data['delivery_address_mode']);

            return $data;
        }

        $billingRaw = $data['billing_customer_id'] ?? null;
        $billingId = ($billingRaw !== null && $billingRaw !== '')
            ? (int) $billingRaw
            : null;
        $endCustomerId = isset($data['customer_id']) && $data['customer_id'] !== null && $data['customer_id'] !== ''
            ? (int) $data['customer_id']
            : $this->record->customer_id;
        $data['shipping_customer_id'] = $this->resolveShippingCustomerIdForDeliveryMode(
            is_string($mode) ? $mode : null,
            $billingId,
            $endCustomerId
        );
        unset($data['delivery_address_mode']);

        return $data;
    }

    /**
     * @param  callable(Set, int): void  $applyDeliverySnapshot
     */
    protected function applyDeliveryAddressModeToShippingState(Set $set, Get $get, mixed $state, callable $applyDeliverySnapshot): void
    {
        $mode = is_string($state) && $state !== '' ? $state : self::DELIVERY_ADDRESS_MODE_INVOICE;

        if ($mode === self::DELIVERY_ADDRESS_MODE_CUSTOM) {
            $set('shipping_customer_id', null);
            $set('additional.shipping_name', null);
            $set('additional.delivery_address', $this->emptyCustomDeliveryAddressFormState());

            return;
        }

        $billingRaw = $get('billing_customer_id');
        $billingId = is_numeric($billingRaw) ? (int) $billingRaw : null;
        $endRaw = $get('customer_id');
        $endCustomerId = is_numeric($endRaw) ? (int) $endRaw : $this->record->customer_id;

        $targetId = $this->resolveShippingCustomerIdForDeliveryMode($mode, $billingId, $endCustomerId);
        if ($targetId === null) {
            return;
        }

        $set('shipping_customer_id', $targetId);
        $applyDeliverySnapshot($set, $targetId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyCustomDeliveryAddressFormState(): array
    {
        return [
            'postcode' => null,
            'street' => null,
            'house_number' => null,
            'house_number_addition' => null,
            'city' => null,
            'country_id' => Country::NL_ID,
        ];
    }
}

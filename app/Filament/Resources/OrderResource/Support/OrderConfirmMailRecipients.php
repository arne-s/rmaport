<?php

namespace App\Filament\Resources\OrderResource\Support;

use App\Actions\SendInvoiceMailAction;
use App\Enums\CustomerType;
use App\Filament\Support\EmailRecipientResolver;
use App\Helpers\EmailHelper;
use App\Models\Customer;
use App\Models\Order\Order;

/**
 * Default To/CC for order confirmation mail ({@see \App\Filament\Resources\OrderResource\Actions\ApproveOrderEmailAction}).
 *
 * To = billing customer (invoice e-mail) when available, otherwise end customer.
 * CC = none by default (dealer location remains selectable manually in the modal).
 */
final class OrderConfirmMailRecipients
{
    public const string DEALER_LOCATION_KEY = 'dealer_location';

    public static function usesDealerBillingRecipientLayout(Order $order, mixed $livewire = null): bool
    {
        return self::resolveBillingCustomerRecord($order, $livewire)?->getType() === CustomerType::B2B;
    }

    /**
     * Invoice e-mail on the billing dealer record (billing address fallback).
     */
    public static function resolveDealerBillingInvoiceEmail(Order $order, mixed $livewire = null): ?string
    {
        $dealer = self::resolveBillingCustomerRecord($order, $livewire);
        if ($dealer?->getType() !== CustomerType::B2B) {
            return null;
        }

        return self::resolveDealerBillingInvoiceEmailForCustomer($dealer);
    }

    private static function resolveDealerBillingInvoiceEmailForCustomer(Customer $dealer): ?string
    {
        $dealer->loadMissing('billingAddress');

        $email = $dealer->getEmail();
        if (is_string($email) && $email !== '' && EmailHelper::isValid($email)) {
            return $email;
        }

        $billingAddressEmail = $dealer->billingAddress?->getEmail();
        if (is_string($billingAddressEmail)) {
            $billingAddressEmail = trim($billingAddressEmail);
            if ($billingAddressEmail !== '' && EmailHelper::isValid($billingAddressEmail)) {
                return $billingAddressEmail;
            }
        }

        return null;
    }

    private static function resolveBillingCustomerRecord(Order $order, mixed $livewire = null): ?Customer
    {
        $ids = self::resolveCustomerAndBillingIds($order, $livewire);
        if ($ids['billing_customer_id'] === null) {
            $order->loadMissing('billingCustomer');

            return $order->billingCustomer;
        }

        $billingId = $ids['billing_customer_id'];
        $order->loadMissing('billingCustomer');
        if ($order->billingCustomer !== null && (int) $order->billingCustomer->getKey() === $billingId) {
            return $order->billingCustomer;
        }

        return Customer::query()->find($billingId);
    }

    /**
     * Dealer location (shipping address) e-mail.
     */
    public static function resolveDealerLocationEmail(Customer $dealer): ?string
    {
        $dealer->loadMissing(['shippingAddress', 'billingAddress', 'address']);

        $shipping = $dealer->shippingAddress;
        $email = is_string($shipping?->email) ? trim($shipping->email) : '';
        if ($email !== '' && EmailHelper::isValid($email)) {
            return $email;
        }

        $fallback = $dealer->getEmail();
        if (is_string($fallback)) {
            $fallback = trim($fallback);
            if ($fallback !== '' && EmailHelper::isValid($fallback)) {
                return $fallback;
            }
        }

        return null;
    }

    public static function dealerLocationRecipientOptionLabel(Order $order, mixed $livewire = null): ?string
    {
        if (! self::usesDealerBillingRecipientLayout($order, $livewire)) {
            return null;
        }

        $dealer = self::resolveBillingCustomerRecord($order, $livewire);
        if ($dealer === null) {
            return null;
        }

        $email = self::resolveDealerLocationEmail($dealer);
        if ($email === null) {
            return null;
        }

        $dealer->loadMissing('shippingAddress');
        $displayName = trim((string) ($dealer->shippingAddress?->getLocationName() ?? ''));
        if ($displayName === '') {
            $displayName = trim((string) ($dealer->shippingAddress?->getName() ?? ''));
        }
        if ($displayName === '') {
            $displayName = (string) ($dealer->getName() ?? 'Locatie');
        }

        return 'Locatie: '.$displayName.' <'.$email.'>';
    }

    /**
     * Billing customer e-mail (dealer: invoice address; otherwise {@see Customer::getEmail()}).
     */
    public static function resolveBillingCustomerEmail(Order $order, mixed $livewire = null): ?string
    {
        $billing = self::resolveBillingCustomerRecord($order, $livewire);
        if ($billing === null) {
            return null;
        }

        if ($billing->getType() === CustomerType::B2B) {
            return self::resolveDealerBillingInvoiceEmailForCustomer($billing);
        }

        $email = $billing->getEmail();
        if (! is_string($email)) {
            return null;
        }

        $email = trim($email);

        return $email !== '' && EmailHelper::isValid($email) ? $email : null;
    }

    /**
     * @return array{customer_id: ?int, billing_customer_id: ?int}
     */
    private static function resolveCustomerAndBillingIds(Order $order, mixed $livewire = null): array
    {
        $customerId = $order->getCustomerId();
        $billingId = $order->billing_customer_id;

        if ($livewire !== null) {
            try {
                $form = $livewire->form->getState();
                if (array_key_exists('billing_customer_id', $form) && $form['billing_customer_id'] !== null && $form['billing_customer_id'] !== '') {
                    $billingId = (int) $form['billing_customer_id'];
                }
                if (array_key_exists('customer_id', $form) && $form['customer_id'] !== null && $form['customer_id'] !== '') {
                    $customerId = (int) $form['customer_id'];
                }
            } catch (\Throwable) {
                // e.g. no Filament form on this livewire
            }
        }

        return [
            'customer_id' => $customerId !== null ? (int) $customerId : null,
            'billing_customer_id' => $billingId !== null ? (int) $billingId : null,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function defaultToRecipientKeys(Order $order, mixed $livewire = null): array
    {
        if (EmailHelper::isValid(self::resolveBillingCustomerEmail($order, $livewire))) {
            return ['dealer'];
        }

        $customerEmail = OrderCustomerMailRecipients::resolveCustomerContactEmailForModal($order, $livewire);
        if (EmailHelper::isValid($customerEmail)) {
            return ['customer'];
        }

        return [];
    }

    /**
     * @param  array<int, string>  $toKeys
     * @return array<int, string>
     */
    public static function defaultCcRecipientKeys(Order $order, mixed $livewire = null, array $toKeys = []): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public static function recipientOptions(Order $order, mixed $livewire = null): array
    {
        $options = EmailRecipientResolver::getRecipientOptions();

        if ($order->customer !== null) {
            $customerEmail = OrderCustomerMailRecipients::resolveCustomerContactEmailForModal($order, $livewire);
            $options['customer'] = 'Klant: '.$order->getCustomerAddressDisplayName().' <'.($customerEmail ?: '—').'>';
        }

        $billingCustomer = self::resolveBillingCustomerRecord($order, $livewire);
        if ($billingCustomer !== null) {
            $invoiceEmail = self::resolveBillingCustomerEmail($order, $livewire);
            $options['dealer'] = self::usesDealerBillingRecipientLayout($order, $livewire)
                ? 'Factuur: '.$billingCustomer->getName().' <'.($invoiceEmail ?: '—').'>'
                : 'Factuurklant: '.$billingCustomer->getName().' <'.($invoiceEmail ?: '—').'>';
        }

        $locationLabel = self::dealerLocationRecipientOptionLabel($order, $livewire);
        if ($locationLabel !== null) {
            $options[self::DEALER_LOCATION_KEY] = $locationLabel;
        }

        return $options;
    }

    /**
     * @param  array<int, string>  $selectedKeys
     * @return array<int, string>
     */
    public static function resolveRecipientEmails(Order $order, mixed $livewire, array $selectedKeys): array
    {
        $emails = [];

        foreach ($selectedKeys as $key) {
            if ($key === 'customer') {
                $email = OrderCustomerMailRecipients::resolveCustomerContactEmailForModal($order, $livewire);
                if (EmailHelper::isValid($email)) {
                    $emails[] = $email;
                }

                continue;
            }

            if ($key === 'dealer') {
                $email = self::resolveBillingCustomerEmail($order, $livewire);
                if (EmailHelper::isValid($email)) {
                    $emails[] = $email;
                }

                continue;
            }

            if ($key === self::DEALER_LOCATION_KEY) {
                $dealer = self::resolveBillingCustomerRecord($order, $livewire);
                if ($dealer !== null) {
                    $email = self::resolveDealerLocationEmail($dealer);
                    if (EmailHelper::isValid($email)) {
                        $emails[] = $email;
                    }
                }

                continue;
            }

            $emails = array_merge($emails, EmailRecipientResolver::resolveRecipients([$key]));
        }

        return array_values(array_unique($emails));
    }
}

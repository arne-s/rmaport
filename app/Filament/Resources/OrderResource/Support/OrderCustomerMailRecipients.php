<?php

namespace App\Filament\Resources\OrderResource\Support;

use App\Filament\Support\EmailRecipientResolver;
use App\Models\Order\BaseOrder;

/**
 * Shared logic for customer mail modals on orders (To/CC/BCC keys → email addresses).
 */
final class OrderCustomerMailRecipients
{
    /**
     * Resolves the customer contact e-mail for modals and transactional mail:
     * optional form `additional.billing_email`, persisted order `additional`, then
     * {@see BaseOrder::getCustomerContactEmail()} (e.g. shipping address).
     */
    public static function resolveCustomerContactEmailForModal(?BaseOrder $order, mixed $livewire = null): ?string
    {
        if ($order === null) {
            return null;
        }

        if ($livewire !== null) {
            try {
                $formState = $livewire->form->getState();
                $billingEmail = $formState['additional']['billing_email'] ?? null;
                if (is_string($billingEmail) && $billingEmail !== '') {
                    return $billingEmail;
                }
            } catch (\Throwable) {
                // e.g. no Filament form on this livewire
            }
        }

        $additional = $order->getAdditional() ?? [];
        $billingEmail = $additional['billing_email'] ?? null;
        if (is_string($billingEmail) && $billingEmail !== '') {
            return $billingEmail;
        }

        $contact = $order->getCustomerContactEmail();

        return $contact !== '' ? $contact : null;
    }

    /**
     * Label for EmailRecipientSelect key `customer` when the order has a {@see BaseOrder::$customer}.
     */
    public static function customerRecipientOptionLabel(BaseOrder $order, mixed $livewire = null): ?string
    {
        if ($order->customer === null) {
            return null;
        }

        $email = self::resolveCustomerContactEmailForModal($order, $livewire);

        return 'Klant: '.$order->getCustomerAddressDisplayName().' <'.($email ?: '—').'>';
    }

    /**
     * @return array{name: string, email: string}|null
     */
    public static function customerMailRecipientNameAndEmail(BaseOrder $order): ?array
    {
        if ($order->customer === null) {
            return null;
        }

        $email = self::resolveCustomerContactEmailForModal($order, null);
        if ($email === null || $email === '') {
            return null;
        }

        $name = $order->getCustomerAddressDisplayName();
        if ($name === '') {
            $name = $order->customer->getName() ?? '';
        }

        return ['name' => $name, 'email' => $email];
    }

    /**
     * Owner of document collections (main when the record is a fitting child).
     */
    public static function documentOwnerForRecord(BaseOrder $record): BaseOrder
    {
        return $record->getMain() ?? $record;
    }

    /**
     * @param  array<int, string>  $selectedKeys
     * @return array<int, string>
     */
    public static function resolveEmails(?BaseOrder $record, array $selectedKeys): array
    {
        $emails = [];

        foreach ($selectedKeys as $key) {
            if (filter_var($key, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $key;
                continue;
            }

            if ($key === 'customer') {
                $email = self::resolveCustomerContactEmailForModal($record, null);
                if (filled($email)) {
                    $emails[] = $email;
                }

                continue;
            }

            if ($key === 'dealer' || $key === 'billing_company') {
                $email = $record?->billingCustomer?->getEmail();
                if (filled($email)) {
                    $emails[] = $email;
                }

                continue;
            }

            $emails = array_merge($emails, EmailRecipientResolver::resolveRecipients([$key]));
        }

        return array_values(array_unique(array_filter($emails, fn ($v): bool => is_string($v) && $v !== '')));
    }
}

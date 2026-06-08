<?php

namespace App\Filament\Resources\OrderResource\Support;

use App\Helpers\EmailHelper;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Order\BaseOrder;
use App\Models\Order\Main;
use App\Models\Order\Order;

/**
 * Default To for packing-slip mail: shipping-address e-mail on the invoice customer
 * ({@see Order::$billingCustomer}).
 */
final class PackingSlipMailRecipients
{
    public const string RECIPIENT_KEY = 'packing_slip_delivery';

    public const string INFO_CC_EMAIL = 'info@autovision.nl';

    /**
     * @return array{email: string, display_name: string}|null
     */
    public static function defaultMailRecipient(?Main $main, ?Order $order): ?array
    {
        $invoiceCustomer = self::resolveInvoiceCustomer($main, $order);
        if ($invoiceCustomer === null) {
            return null;
        }

        $email = self::resolveInvoiceCustomerShippingEmail($invoiceCustomer);
        if ($email === null || ! EmailHelper::isValid($email)) {
            return null;
        }

        $displayName = self::resolveInvoiceCustomerShippingDisplayName($invoiceCustomer);

        return [
            'email' => $email,
            'display_name' => $displayName !== '' ? $displayName : $email,
        ];
    }

    public static function recipientOptionLabel(?Main $main, ?Order $order): ?string
    {
        $recipient = self::defaultMailRecipient($main, $order);
        if ($recipient === null) {
            return null;
        }

        return 'Levergegevens: '.$recipient['display_name'].' <'.$recipient['email'].'>';
    }

    /**
     * @param  array<int, string>  $selectedKeys
     * @return array<int, string>
     */
    public static function resolveEmails(?Main $main, ?Order $order, array $selectedKeys): array
    {
        $record = $main ?? $order;
        $emails = [];

        foreach ($selectedKeys as $key) {
            if (filter_var($key, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $key;

                continue;
            }

            if ($key === self::RECIPIENT_KEY) {
                $delivery = self::defaultMailRecipient($main, $order);
                if ($delivery !== null) {
                    $emails[] = $delivery['email'];
                }

                continue;
            }

            $emails = array_merge(
                $emails,
                OrderCustomerMailRecipients::resolveEmails($record instanceof BaseOrder ? $record : null, [$key]),
            );
        }

        return array_values(array_unique(array_filter($emails, fn (string $v): bool => $v !== '')));
    }

    /**
     * BCC customer contact when it differs from the default To (invoice customer shipping e-mail).
     *
     * @return array<int, string>
     */
    public static function defaultBccRecipientKeys(?Main $main, ?Order $order, mixed $livewire = null): array
    {
        $to = self::defaultMailRecipient($main, $order);
        if ($to === null || $order === null) {
            return [];
        }

        $customerEmail = OrderCustomerMailRecipients::resolveCustomerContactEmailForModal($order, $livewire);
        if ($customerEmail === null || $customerEmail === '') {
            return [];
        }

        if (strcasecmp($customerEmail, $to['email']) === 0) {
            return [];
        }

        return ['customer'];
    }

    private static function resolveInvoiceCustomer(?Main $main, ?Order $order): ?Customer
    {
        if ($order !== null) {
            $order->loadMissing(['billingCustomer.shippingAddress', 'billingCustomer.billingAddress', 'billingCustomer.address', 'customer']);

            if ($order->billingCustomer !== null) {
                return $order->billingCustomer;
            }

            return $order->customer;
        }

        if ($main !== null) {
            $main->loadMissing(['billingCustomer.shippingAddress', 'billingCustomer.billingAddress', 'billingCustomer.address', 'customer']);

            if ($main->billingCustomer !== null) {
                return $main->billingCustomer;
            }

            return $main->customer;
        }

        return null;
    }

    private static function resolveInvoiceCustomerShippingEmail(Customer $invoiceCustomer): ?string
    {
        $shipping = self::resolveInvoiceCustomerShippingAddress($invoiceCustomer);
        $email = is_string($shipping?->email) ? trim($shipping->email) : '';
        if ($email !== '') {
            return $email;
        }

        $fallback = $invoiceCustomer->getEmail();

        return is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : null;
    }

    private static function resolveInvoiceCustomerShippingDisplayName(Customer $invoiceCustomer): string
    {
        $shipping = self::resolveInvoiceCustomerShippingAddress($invoiceCustomer);
        if ($shipping !== null) {
            $attention = trim((string) ($shipping->getName() ?? ''));
            if ($attention !== '') {
                return $attention;
            }

            $locationName = trim((string) ($shipping->getLocationName() ?? ''));
            if ($locationName !== '') {
                return $locationName;
            }
        }

        return (string) ($invoiceCustomer->getName() ?? '');
    }

    private static function resolveInvoiceCustomerShippingAddress(Customer $invoiceCustomer): ?Address
    {
        $invoiceCustomer->loadMissing(['shippingAddress', 'billingAddress', 'address']);

        return $invoiceCustomer->shippingAddress;
    }
}

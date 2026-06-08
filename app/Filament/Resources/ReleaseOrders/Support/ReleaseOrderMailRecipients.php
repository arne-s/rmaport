<?php

namespace App\Filament\Resources\ReleaseOrders\Support;

use App\Helpers\EmailHelper;
use App\Models\Address;
use App\Models\Customer;
use App\Models\ReleaseOrder;

/**
 * Default To recipient for release-request mail: always the dealer on the release order
 * ({@see ReleaseOrder::dealer}), using the e-mail on that dealer's shipping address (Levergegevens).
 * The delivery address selected on the form does not affect the mail recipient.
 */
final class ReleaseOrderMailRecipients
{
    public const string RECIPIENT_KEY = 'release_delivery';

    public const string INKOOP_CC_EMAIL = 'inkoop@rdmobility.com';

    /**
     * @return array{email: string, display_name: string}|null
     */
    public static function defaultMailRecipient(?ReleaseOrder $releaseOrder, mixed $livewire = null): ?array
    {
        if ($releaseOrder === null) {
            return null;
        }

        $releaseOrder->loadMissing(['dealer.shippingAddress', 'dealer.billingAddress', 'dealer.address']);

        $dealer = $releaseOrder->dealer;
        if ($dealer === null) {
            return null;
        }

        $email = self::resolveDealerShippingEmail($dealer);
        if ($email === null || ! EmailHelper::isValid($email)) {
            return null;
        }

        $displayName = self::resolveDealerShippingDisplayName($dealer);

        return [
            'email' => $email,
            'display_name' => $displayName !== '' ? $displayName : $email,
        ];
    }

    public static function recipientOptionLabel(?ReleaseOrder $releaseOrder, mixed $livewire = null): ?string
    {
        $recipient = self::defaultMailRecipient($releaseOrder, $livewire);
        if ($recipient === null) {
            return null;
        }

        return 'Levergegevens: '.$recipient['display_name'].' <'.$recipient['email'].'>';
    }

    private static function resolveDealerShippingAddress(Customer $dealer): ?Address
    {
        $dealer->loadMissing(['shippingAddress', 'billingAddress', 'address']);

        return $dealer->shippingAddress;
    }

    private static function resolveDealerShippingEmail(Customer $dealer): ?string
    {
        $shipping = self::resolveDealerShippingAddress($dealer);
        $email = is_string($shipping?->email) ? trim($shipping->email) : '';
        if ($email !== '') {
            return $email;
        }

        $fallback = $dealer->getEmail();

        return is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : null;
    }

    private static function resolveDealerShippingDisplayName(Customer $dealer): string
    {
        $shipping = self::resolveDealerShippingAddress($dealer);
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

        return (string) ($dealer->getName() ?? '');
    }
}

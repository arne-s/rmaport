<?php

namespace App\Enums;

enum NewsletterSubscriptionSegment: string
{
    case CustomerB2c = 'customer_b2c';
    case CustomerB2bBilling = 'customer_b2b_billing';
    case CustomerB2bShipping = 'customer_b2b_shipping';
    case DealerBilling = 'dealer_billing';
    case DealerShipping = 'dealer_shipping';
    case UniekSportenBilling = 'uniek_sporten_billing';
    case UniekSportenShipping = 'uniek_sporten_shipping';

    public function getLabel(): string
    {
        return match ($this) {
            self::CustomerB2c => 'Particulier',
            self::CustomerB2bBilling => 'B2B (factuur)',
            self::CustomerB2bShipping => 'B2B (locatie)',
            self::DealerBilling => 'Dealer (factuur)',
            self::DealerShipping => 'Dealer (locatie)',
            self::UniekSportenBilling => 'Uniek Sporten (factuur)',
            self::UniekSportenShipping => 'Uniek Sporten (locatie)',
        };
    }

    public function isBilling(): bool
    {
        return match ($this) {
            self::CustomerB2c,
            self::CustomerB2bBilling,
            self::DealerBilling,
            self::UniekSportenBilling => true,
            default => false,
        };
    }

    public function isShipping(): bool
    {
        return match ($this) {
            self::CustomerB2bShipping,
            self::DealerShipping,
            self::UniekSportenShipping => true,
            default => false,
        };
    }
}

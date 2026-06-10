<?php

namespace App\Enums;

enum NewsletterSubscriptionSegment: string
{
    case CustomerB2c = 'customer_b2c';
    case CustomerB2bBilling = 'customer_b2b_billing';
    case CustomerB2bShipping = 'customer_b2b_shipping';

    public function getLabel(): string
    {
        return match ($this) {
            self::CustomerB2c => 'Particulier',
            self::CustomerB2bBilling => 'B2B (factuur)',
            self::CustomerB2bShipping => 'B2B (locatie)',
        };
    }

    public function isBilling(): bool
    {
        return match ($this) {
            self::CustomerB2c,
            self::CustomerB2bBilling => true,
            default => false,
        };
    }

    public function isShipping(): bool
    {
        return match ($this) {
            self::CustomerB2bShipping => true,
            default => false,
        };
    }
}

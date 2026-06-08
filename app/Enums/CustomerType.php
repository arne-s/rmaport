<?php

namespace App\Enums;

enum CustomerType: string
{
    case B2B = 'b2b';
    case B2C = 'b2c';
    case Dealer = 'dealer';
    case UniekSporten = 'uniek_sporten';
    case AV = 'av';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::AV => 'AV',
            self::B2C => 'Particulier',
            self::Dealer => 'Dealer',
            self::B2B => 'B2B',
            self::UniekSporten => 'UniekSporten',
            default => null,
        };
    }

    public function isBusiness(): bool
    {
        return in_array($this, [self::B2B, self::AV, self::Dealer, self::UniekSporten]);
    }

    public function isVisible(): bool
    {
        return in_array($this, [self::B2C, self::B2B, self::Dealer, self::UniekSporten]);
    }

    public function usesNewsletterDealerSegments(): bool
    {
        return $this->isBusiness() && $this->isVisible();
    }

    public function billingNewsletterSegment(): ?NewsletterSubscriptionSegment
    {
        return match ($this) {
            self::B2C => NewsletterSubscriptionSegment::CustomerB2c,
            self::B2B => NewsletterSubscriptionSegment::CustomerB2bBilling,
            self::Dealer => NewsletterSubscriptionSegment::DealerBilling,
            self::UniekSporten => NewsletterSubscriptionSegment::UniekSportenBilling,
            default => null,
        };
    }

    public function shippingNewsletterSegment(): ?NewsletterSubscriptionSegment
    {
        return match ($this) {
            self::B2B => NewsletterSubscriptionSegment::CustomerB2bShipping,
            self::Dealer => NewsletterSubscriptionSegment::DealerShipping,
            self::UniekSporten => NewsletterSubscriptionSegment::UniekSportenShipping,
            default => null,
        };
    }

    public static function labels(): array
    {
        return array_reduce(self::cases(), function ($carry, $item) {
            $carry[$item->value] = $item->getLabel();
            return $carry;
        }, []);
    }

    public static function visibleLabels(): array
    {
        return array_reduce(self::cases(), function ($carry, $item) {
            if ($item->isVisible()) {
                $carry[$item->value] = $item->getLabel();
            }

            return $carry;
        }, []);
    }

    /**
     * Visible types in presentation order: Particulier, Dealer, B2B, UniekSporten.
     * Used for the customers list type filter and the create-customer type field.
     *
     * @return array<string, string>
     */
    public static function visibleLabelsInCustomerTableFilterOrder(): array
    {
        $labels = self::visibleLabels();
        $order = [
            self::B2C->value,
            self::Dealer->value,
            self::B2B->value,
            self::UniekSporten->value,
        ];

        $ordered = [];
        foreach ($order as $value) {
            if (isset($labels[$value])) {
                $ordered[$value] = $labels[$value];
            }
        }

        return $ordered;
    }
}

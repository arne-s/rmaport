<?php

namespace App\Enums;

enum CustomerType: string
{
    case B2B = 'b2b';
    case B2C = 'b2c';
    case AV = 'av';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::AV => 'AV',
            self::B2C => 'Particulier',
            self::B2B => 'B2B',
            default => null,
        };
    }

    public function isBusiness(): bool
    {
        return in_array($this, [self::B2B, self::AV]);
    }

    public function isVisible(): bool
    {
        return in_array($this, [self::B2C, self::B2B]);
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
            default => null,
        };
    }

    public function shippingNewsletterSegment(): ?NewsletterSubscriptionSegment
    {
        return match ($this) {
            self::B2B => NewsletterSubscriptionSegment::CustomerB2bShipping,
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
     * Types available when creating a new customer (Particulier only).
     *
     * @return array<string, string>
     */
    public static function visibleLabelsForCreate(): array
    {
        return [
            self::B2C->value => self::B2C->getLabel(),
        ];
    }

    /**
     * Visible types in presentation order: Particulier, B2B.
     * Used for the customers list type filter.
     *
     * @return array<string, string>
     */
    public static function visibleLabelsInCustomerTableFilterOrder(): array
    {
        $labels = self::visibleLabels();
        $order = [
            self::B2C->value,
            self::B2B->value,
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

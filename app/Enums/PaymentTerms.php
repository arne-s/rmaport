<?php

namespace App\Enums;

use App\Models\ExactPaymentCondition;

enum PaymentTerms: string
{
    case Split50_50      = 'split_50_50';
    case Advance100      = 'advance_100';
    case Postpay         = 'postpay';
    case PostpayShipping = 'postpay_shipping';
    case DirectService   = 'direct_service';
    case PostpayService  = 'postpay_service';

    public function getLabel(): string
    {
        return match ($this) {
            self::Split50_50      => '50% aanbetaling vooraf, restantbetaling vóór levering.',
            self::Advance100      => '100% vooraf aan uitlevering.',
            self::Postpay         => '100% achteraf. Na aflevering.',
            self::PostpayShipping => '100% achteraf. Na verzending.',
            self::DirectService   => 'Direct na service/onderhoud afspraak.',
            self::PostpayService  => '100% na service/onderhoud afspraak.',
        };
    }

    /** Percentage used for deposit / final-invoice line amounts (50 or 100). */
    public function getPaymentPercentage(): int
    {
        return match ($this) {
            self::Split50_50 => 50,
            default          => 100,
        };
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return array_reduce(self::cases(), function (array $carry, self $item): array {
            $carry[$item->value] = $item->getLabel();
            return $carry;
        }, []);
    }

    /**
     * Whether the deposit-invoice flow applies (50/50 split).
     */
    public static function requiresDepositInvoice(?self $terms): bool
    {
        return $terms === self::Split50_50;
    }

    /**
     * Exact payment condition code forced for this payment-terms case (e.g. 100% advance → not applicable).
     */
    public function forcedExactPaymentConditionCode(): ?string
    {
        return match ($this) {
            self::Advance100 => ExactPaymentCondition::NOT_APPLICABLE_CODE,
            default => null,
        };
    }

    public static function forcedExactPaymentConditionCodeFor(?self $terms): ?string
    {
        return $terms?->forcedExactPaymentConditionCode();
    }
}

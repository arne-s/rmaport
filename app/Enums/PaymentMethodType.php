<?php
namespace App\Enums;

enum PaymentMethodType: string
{
    case MollieIdeal = 'mollie_ideal';
    case ExactBank = 'exact_bank';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::MollieIdeal => 'Mollie',
            self::ExactBank => 'Bank',
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
}

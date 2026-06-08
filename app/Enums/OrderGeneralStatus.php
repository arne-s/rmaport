<?php

namespace App\Enums;

enum OrderGeneralStatus: string
{
    case Initial = 'initial';
    case Draft = 'draft';
    case Sent = 'sent';
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Expired = 'expired';
    case Changed = 'changed';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Initial => 'Niet opgeslagen',
            self::Draft => 'Concept',
            self::Pending => 'Openstaand',
            self::Sent => 'Verzonden',
            self::Processing => 'In behandeling',
            self::Completed => 'Akkoord',
            self::Paid => 'Betaald',
            self::Expired => 'Verlopen',
            self::Changed => 'Aangepast',
            self::Cancelled => 'Geannuleerd',
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

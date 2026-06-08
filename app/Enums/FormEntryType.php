<?php

namespace App\Enums;

enum FormEntryType: string
{
    case Contact = 'contact';
    case DemoRequest = 'demo_request';
    case DealerRequest = 'dealer_request';

    public function getLabel(): string
    {
        return match ($this) {
            self::Contact => 'Contactformulier',
            self::DemoRequest => 'Demo aanvraag',
            self::DealerRequest => 'Dealer aanvraag',
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
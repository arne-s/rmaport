<?php

namespace App\Enums;

enum NoteType: string
{
    case General = 'general';
    case Order = 'order';
    case Product = 'product';
    case Complaint = 'complaint';
    case Callback = 'callback';
    case Rma = 'rma';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::General => 'Algemeen',
            self::Order => 'Aanvraag-gerelateerd',
            self::Complaint => 'Klacht',
            self::Callback => 'Terugbelverzoek',
            self::Rma => 'RMA-gerelateerd',
            default => null,
        };
    }

    public static function labels(): array
    {
        return array_reduce(self::cases(), function (array $carry, self $item): array {
            $label = $item->getLabel();
            if ($label === null) {
                return $carry;
            }

            $carry[$item->value] = $label;

            return $carry;
        }, []);
    }
}

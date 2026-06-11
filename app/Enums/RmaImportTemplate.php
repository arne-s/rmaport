<?php

namespace App\Enums;

enum RmaImportTemplate: string
{
    case Auto = 'auto';
    case MediaMarkt = 'media_markt';
    case ConsumerReturns = 'consumer_returns';
    case ConsumerReturnsShipment = 'consumer_returns_shipment';
    case AutovisionStore = 'autovision_store';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Auto => 'Automatisch detecteren',
            self::MediaMarkt => 'MediaMarkt CSV/Excel',
            self::ConsumerReturns => 'Consumer returns Excel/CSV',
            self::ConsumerReturnsShipment => 'Consumer returns zending (bol.com)',
            self::AutovisionStore => 'Autovision winkel-sheet',
        };
    }
}

<?php

namespace App\Enums;

enum OrderSubtype: string
{
    case Unit = 'unit';
    case Service = 'service';
    case Part = 'part';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Unit => 'Unit',
            self::Part => 'Onderdeel',
            self::Service => 'Service / Onderhoud',
            default => null,
        };
    }

    /**
     * Value => label voor selects (vaste UI-volgorde: Unit, Onderdeel, Service / Onderhoud).
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Unit->value => self::Unit->getLabel(),
            self::Part->value => self::Part->getLabel(),
            self::Service->value => self::Service->getLabel(),
        ];
    }
}

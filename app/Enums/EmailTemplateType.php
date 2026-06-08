<?php

namespace App\Enums;

enum EmailTemplateType: string
{
    case Unit = 'unit';
    case Service = 'service';
    case Part = 'part';
    case General = 'general';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unit => 'Unit',
            self::Part => 'Onderdeel',
            self::Service => 'Service',
            self::General => 'Algemeen',
        };
    }

    /**
     * Value => label voor selects en filters (vaste volgorde: Unit, Onderdeel, Service, Algemeen).
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Unit->value => self::Unit->getLabel(),
            self::Part->value => self::Part->getLabel(),
            self::Service->value => self::Service->getLabel(),
            self::General->value => self::General->getLabel(),
        ];
    }
}

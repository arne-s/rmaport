<?php

namespace App\Enums;

enum ImportTemplateType: string
{
    case File = 'file';
    case Webform = 'webform';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::File => 'Bestand',
            self::Webform => 'Webformulier',
        };
    }
}

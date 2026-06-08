<?php

namespace App\Enums;

enum CreateMainMode: string
{
    case Fitting = 'fitting';
    case Quote = 'quote';
    case Order = 'order';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fitting => 'Passing',
            self::Quote => 'Offerte',
            self::Order => 'Order',
        };
    }
}


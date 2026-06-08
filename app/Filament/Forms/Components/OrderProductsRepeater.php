<?php

namespace App\Filament\Forms\Components;

use App\Filament\Support\OrderProductRepeaterConfiguration;
use Filament\Forms\Components\Repeater;

class OrderProductsRepeater extends Repeater
{
    public static function make(?string $name = null): static
    {
        return OrderProductRepeaterConfiguration::apply(parent::make($name))
            ->live(false);
    }
}

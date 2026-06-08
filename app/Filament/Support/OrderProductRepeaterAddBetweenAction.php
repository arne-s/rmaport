<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Support\Enums\Size;

final class OrderProductRepeaterAddBetweenAction
{
    public static function configure(Action $action): Action
    {
        return $action
            ->label('Product toevoegen')
            ->icon('heroicon-o-plus')
            ->iconButton()
            ->color('gray')
            ->size(Size::ExtraSmall)
            ->visible(fn (Repeater $component): bool => $component->isAddable());
    }
}

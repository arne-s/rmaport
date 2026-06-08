<?php

namespace App\Filament\Support;

use Filament\Actions\Action;

final class OrderProductRepeaterAddAction
{
    public static function configure(Action $action): Action
    {
        return $action
            ->button()
            ->label('Product')
            ->icon('heroicon-s-plus-circle');
    }
}

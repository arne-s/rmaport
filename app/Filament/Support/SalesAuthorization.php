<?php

namespace App\Filament\Support;

final class SalesAuthorization
{
    public static function canManage(): bool
    {
        return auth()->user()?->can('manage sales') ?? false;
    }
}

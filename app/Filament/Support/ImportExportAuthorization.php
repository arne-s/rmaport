<?php

namespace App\Filament\Support;

final class ImportExportAuthorization
{
    public static function canManage(): bool
    {
        return auth()->user()?->can('manage import exports') ?? false;
    }
}

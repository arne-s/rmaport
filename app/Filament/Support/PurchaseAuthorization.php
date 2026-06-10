<?php

namespace App\Filament\Support;

final class PurchaseAuthorization
{
    public static function canManage(): bool
    {
        return auth()->user()?->can('manage purchases') ?? false;
    }
}

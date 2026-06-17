<?php

namespace App\Filament\Resources\RmaResource\Pages;

use App\Enums\RmaStatus;

class ListRmasOpen extends ListRmasStatusPage
{
    public ?string $status = 'open';

    protected static function filteredStatus(): RmaStatus
    {
        return RmaStatus::Open;
    }
}

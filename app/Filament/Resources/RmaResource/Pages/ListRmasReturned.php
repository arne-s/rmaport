<?php

namespace App\Filament\Resources\RmaResource\Pages;

use App\Enums\RmaStatus;

class ListRmasReturned extends ListRmasStatusPage
{
    public ?string $status = 'returned';

    protected static function filteredStatus(): RmaStatus
    {
        return RmaStatus::Returned;
    }
}

<?php

namespace App\Filament\Resources\RmaResource\Pages;

use App\Enums\RmaStatus;

class ListRmasReceived extends ListRmasStatusPage
{
    public ?string $status = 'received';

    protected static function filteredStatus(): RmaStatus
    {
        return RmaStatus::Received;
    }
}

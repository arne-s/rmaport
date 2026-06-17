<?php

namespace App\Filament\Resources\RmaResource\Pages;

use App\Enums\RmaStatus;

class ListRmasInProgress extends ListRmasStatusPage
{
    public ?string $status = 'in_progress';

    protected static function filteredStatus(): RmaStatus
    {
        return RmaStatus::InProgress;
    }
}

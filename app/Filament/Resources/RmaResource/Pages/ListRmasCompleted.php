<?php

namespace App\Filament\Resources\RmaResource\Pages;

use App\Enums\RmaStatus;

class ListRmasCompleted extends ListRmasStatusPage
{
    public ?string $status = 'completed';

    protected static function filteredStatus(): RmaStatus
    {
        return RmaStatus::Completed;
    }
}

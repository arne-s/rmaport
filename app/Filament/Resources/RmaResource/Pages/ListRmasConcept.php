<?php

namespace App\Filament\Resources\RmaResource\Pages;

use App\Enums\RmaStatus;

class ListRmasConcept extends ListRmasStatusPage
{
    public ?string $status = 'draft';

    protected static function filteredStatus(): RmaStatus
    {
        return RmaStatus::Draft;
    }
}

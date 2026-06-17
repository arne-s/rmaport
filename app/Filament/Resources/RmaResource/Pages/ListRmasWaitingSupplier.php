<?php

namespace App\Filament\Resources\RmaResource\Pages;

use App\Enums\RmaStatus;

class ListRmasWaitingSupplier extends ListRmasStatusPage
{
    public ?string $status = 'waiting_supplier';

    protected static function filteredStatus(): RmaStatus
    {
        return RmaStatus::WaitingSupplier;
    }
}

<?php

namespace App\Filament\Resources\RmaResource\Pages;

use App\Enums\RmaStatus;

class ListRmasWaitingCustomer extends ListRmasStatusPage
{
    public ?string $status = 'waiting_customer';

    protected static function filteredStatus(): RmaStatus
    {
        return RmaStatus::WaitingCustomer;
    }
}

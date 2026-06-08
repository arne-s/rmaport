<?php

namespace App\Filament\Resources\Pages;

use Filament\Resources\Pages\ListRecords as FilamentListRecords;

class ListRecords extends FilamentListRecords
{
    public string|int|null $defaultTableRecordsPerPageSelectOption = 50;
}

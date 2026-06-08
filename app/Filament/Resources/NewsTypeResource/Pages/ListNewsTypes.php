<?php

namespace App\Filament\Resources\NewsTypeResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\NewsTypeResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNewsTypes extends ListRecords
{
    protected static string $resource = NewsTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

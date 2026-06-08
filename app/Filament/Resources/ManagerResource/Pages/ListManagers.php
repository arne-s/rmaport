<?php

namespace App\Filament\Resources\ManagerResource\Pages;

use App\Filament\Resources\ManagerResource;
use App\Filament\Resources\Pages\ListRecords;

class ListManagers extends ListRecords
{
    protected static string $resource = ManagerResource::class;
    protected static ?string $title = 'Gebruikers-overzicht';
    protected static ?string $breadcrumb = 'Gebruikers';
    protected function getHeaderActions(): array
    {
        return [

        ];
    }

}

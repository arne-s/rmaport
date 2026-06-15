<?php

namespace App\Filament\Resources\ImportRows\Pages;

use App\Filament\Resources\ImportRows\ImportRowResource;
use Filament\Resources\Pages\ViewRecord;

class ViewImportRow extends ViewRecord
{
    protected static string $resource = ImportRowResource::class;

    protected static ?string $title = 'Import bekijken';

    protected function getHeaderActions(): array
    {
        return [];
    }
}

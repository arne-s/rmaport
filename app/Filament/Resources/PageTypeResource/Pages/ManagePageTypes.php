<?php

namespace App\Filament\Resources\PageTypeResource\Pages;

use App\Filament\Resources\PageTypeResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePageTypes extends ManageRecords
{
    protected static string $resource = PageTypeResource::class;
    protected static ?string $title = 'Pagina types';

    public function getBreadcrumbs(): array
    {
        return [
            '/' => 'Contentbeheer',
            '/?' => "Website",
            'Pagina types',
        ];
    }


    public function getDefaultTableRecordsPerPageSelectOption(): int { return 50; }

    protected function getHeaderActions(): array
    {
        return [
            
        ];
    }
}

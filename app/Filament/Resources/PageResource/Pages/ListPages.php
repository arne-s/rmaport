<?php

namespace App\Filament\Resources\PageResource\Pages;

// use Filament\Actions\CreateAction;
use App\Filament\Resources\PageResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;
    protected static ?string $title = 'Pagina\'s';

    
    public function getDefaultTableRecordsPerPageSelectOption(): int { return 50; }

    public function getBreadcrumbs(): array
    {
        //parent return
            return ['/' => 'Contentbeheer', '/?' => "Website",
                'Pagina\'s'];
        
        return parent::getBreadcrumbs();
    }
}

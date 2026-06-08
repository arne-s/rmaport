<?php

namespace App\Filament\Resources\NewsResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Actions\Action;
use App\Filament\Resources\NewsResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNews extends ListRecords
{
    protected static string $resource = NewsResource::class;

    protected static ?string $title = 'Nieuwsitems';

    public function getBreadcrumbs(): array
    {
        //parent return
            return ['/' => 'Contentbeheer', '/?' => "Website",
                'Nieuwsitems'];
        
        return parent::getBreadcrumbs();
    }

    protected function getHeaderActions(): array
    {
        return [
            
        ];
    }
}

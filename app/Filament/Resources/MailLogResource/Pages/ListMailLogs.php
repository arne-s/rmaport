<?php

namespace App\Filament\Resources\MailLogResource\Pages;

use App\Filament\Resources\MailLogResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListMailLogs extends ListRecords
{
    protected static string $resource = MailLogResource::class;

    protected static ?string $title = 'E-mail logs';

    protected function getTableQuery(): Builder
    {
        // Filter out test records in production
        if (app()->environment('production')) {
            return parent::getTableQuery()->where('is_test', false);
        }
        return parent::getTableQuery();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getBreadcrumbs(): array
    {
        return ['/' => 'Contentbeheer', '/?' => "Portaal", 'E-mail logs'];

        return parent::getBreadcrumbs();
    }
}

<?php

namespace App\Filament\Resources\ReportingResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Actions\Action;
use App\Filament\Resources\ReportingResource;
use App\Filament\Support\SalesAuthorization;
use Filament\Pages\Actions;
use App\Filament\Resources\Pages\ListRecords;

class ListReportings extends ListRecords
{
    protected static string $resource = ReportingResource::class;
    protected static ?string $breadcrumb = '';
    protected static ?string $title = 'Administratie';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ...(SalesAuthorization::canManage() ? [
                Action::make('Offertes')->label('Verkoop | Offertes')->url(route('filament.app.resources.quotes.index'))->icon('heroicon-o-clipboard-document-check'),
                Action::make('Orders')->label('Verkoop | Orders')->url(route('filament.app.resources.orders.index'))->icon('heroicon-o-shopping-bag'),
            ] : []),
            Action::make('Facturen')->label('Verkoop | Facturen')->url(route('filament.app.resources.invoices.index'))->icon('heroicon-o-book-open'),
        ];
    }

}

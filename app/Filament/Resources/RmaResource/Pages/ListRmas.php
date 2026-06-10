<?php

namespace App\Filament\Resources\RmaResource\Pages;

use App\Filament\Actions\ImportRmaAction;
use App\Filament\Resources\RmaResource;
use App\Filament\Support\SalesAuthorization;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListRmas extends ListRecords
{
    protected static string $resource = RmaResource::class;

    protected static ?string $breadcrumb = 'RMA\'s';

    public function getDefaultTableRecordsPerPageSelectOption(): int
    {
        return 100;
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);
        $existingActions = $table->getHeaderActions();

        return $table
            ->headerActions(array_merge(
                $existingActions,
                [
                    Action::make('create')
                        ->label('RMA aanmaken')
                        ->icon('heroicon-s-plus-circle')
                        ->url(RmaResource::getUrl('create')),
                    ImportRmaAction::make()
                        ->visible(fn (): bool => SalesAuthorization::canManage()),
                ],
            ))
            ->paginationPageOptions([50, 100, 250])
            ->defaultPaginationPageOption(100);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}

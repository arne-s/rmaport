<?php

namespace App\Filament\Resources\ImportRows\Pages;

use App\Filament\Actions\ImportRmaAction;
use App\Filament\Resources\ImportRows\ImportRowResource;
use App\Filament\Support\SalesAuthorization;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListImportRows extends ListRecords
{
    protected static string $resource = ImportRowResource::class;

    protected static ?string $title = 'Import-overzicht';

    protected static ?string $breadcrumb = 'Import-overzicht';

    public function getDefaultTableRecordsPerPageSelectOption(): int
    {
        return 100;
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table
            ->headerActions([
                ImportRmaAction::make()
                    ->visible(fn (): bool => SalesAuthorization::canManage()),
            ])
            ->paginationPageOptions([50, 100, 250])
            ->defaultPaginationPageOption(100);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}

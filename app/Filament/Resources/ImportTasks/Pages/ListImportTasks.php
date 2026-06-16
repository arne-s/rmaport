<?php

namespace App\Filament\Resources\ImportTasks\Pages;

use App\Filament\Actions\ImportRmaAction;
use App\Filament\Resources\ImportTasks\ImportTaskResource;
use App\Filament\Support\SalesAuthorization;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListImportTasks extends ListRecords
{
    protected static string $resource = ImportTaskResource::class;

    protected static ?string $title = 'Importtaken';

    protected static ?string $breadcrumb = 'Importtaken';

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

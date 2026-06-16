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

    protected static ?string $title = 'Importrijen';

    protected static ?string $breadcrumb = 'Importrijen';

    public function getDefaultTableRecordsPerPageSelectOption(): int
    {
        return 100;
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back',
            ]))
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

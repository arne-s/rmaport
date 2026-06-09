<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Imports\ProductImporter;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Support\ImportExportAuthorization;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\ImportAction;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected static ?string $breadcrumb = 'Artikeloverzicht';

    protected static ?string $title = 'Artikeloverzicht';

    public function getDefaultTableRecordsPerPageSelectOption(): int
    {
        return 100;
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);
        $existingActions = $table->getHeaderActions();

        return $table
            ->paginationPageOptions([50, 100, 250])
            ->defaultPaginationPageOption(100)
            ->headerActions(array_merge(
                $existingActions,
                [
                    Action::make('export_csv')
                        ->label('Export')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->visible(fn (): bool => ImportExportAuthorization::canManage())
                        ->action(fn (): BinaryFileResponse => $this->exportProductsCsv()),
                    ImportAction::make('import_csv')
                        ->importer(ProductImporter::class)
                        ->label('Import')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->extraModalWindowAttributes(['class' => 'import-products-modal'])
                        ->visible(fn (): bool => ImportExportAuthorization::canManage())
                        ->csvDelimiter(';'),
                ]
            ));
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getSubheading(): string|Htmlable|null
    {
        return Product::query()->count() . ' artikelen';
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 3;
    }

    public function exportProductsCsv(): BinaryFileResponse
    {
        die('not implemented');


    }
}

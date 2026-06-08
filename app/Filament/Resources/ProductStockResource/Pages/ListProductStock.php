<?php

namespace App\Filament\Resources\ProductStockResource\Pages;

use App\Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductStockResource;
use App\Filament\Support\ImportExportAuthorization;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListProductStock extends ListRecords
{
    protected static string $resource = ProductStockResource::class;

    protected static ?string $breadcrumb = 'Voorraad';

    protected static ?string $title = 'Voorraad';

    protected function baseProductStockQuery(): Builder
    {
        return Product::query()
            ->select([
                'products.id',
                'products.name',
                'products.uid',
                DB::raw('COALESCE(product_stock.available_stock, 0) as available_stock'),
            ])
            ->leftJoin('product_stock', 'product_stock.product_id', '=', 'products.id')
            ->where('products.is_stock_enabled', 1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->baseProductStockQuery())
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back mt-1',
            ]))
            ->columns([
                TextColumn::make('name')
                    ->label('Artikelnaam')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Product $record): ?string => ProductResource::editUrlFor($record))
                    ->color(fn (Product $record): ?string => ProductResource::editUrlFor($record) ? 'primary' : null),

                TextColumn::make('uid')
                    ->label('Artikelnummer')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('available_stock')
                    ->label('Voorraadniveau')
                    ->numeric(0)
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->headerActions([
                Action::make('export_excel')
                    ->label('Excel export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => ImportExportAuthorization::canManage())
                    ->action(fn (): ?BinaryFileResponse => $this->exportProductStockSpreadsheet()),
            ])
            ->extraAttributes([
                'class' => '[&_td]:whitespace-nowrap',
            ]);
    }

    public function exportProductStockSpreadsheet(): ?BinaryFileResponse
    {
        abort_unless(ImportExportAuthorization::canManage(), 403);

        Storage::makeDirectory('exports');

        $basename = 'voorraad_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.xlsx';
        $filepath = storage_path('app/exports/' . $basename);

        $xlsxOptions = new XlsxOptions();
        $xlsxOptions->DEFAULT_COLUMN_WIDTH = 20;

        $writer = new XlsxWriter($xlsxOptions);
        $writer->openToFile($filepath);
        $writer->addRow(Row::fromValues([
            'Artikelnaam',
            'Artikelnummer',
            'Voorraadniveau',
        ]));

        $query = $this->getFilteredTableQuery()->orderBy('name');

        foreach ($query->cursor() as $record) {
            if (! $record instanceof Product) {
                continue;
            }

            $writer->addRow(Row::fromValues([
                (string) ($record->name ?? ''),
                (string) ($record->uid ?? ''),
                (int) round((float) ($record->available_stock ?? 0)),
            ]));
        }

        $writer->close();

        return response()->download($filepath)->deleteFileAfterSend(true);
    }
}

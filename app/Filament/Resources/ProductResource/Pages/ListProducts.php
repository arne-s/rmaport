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
        abort_unless(ImportExportAuthorization::canManage(), 403);

        Storage::makeDirectory('exports');

        $basename = 'artikelen_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.csv';
        $filepath = storage_path('app/exports/' . $basename);

        $handle = fopen($filepath, 'w');

        // UTF-8 BOM for correct display in Excel
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            'Artikelnaam RD Mobility',
            'Artikelnummer RD Mobility',
            'Type',
            'Type',
            'Eenheid',
            'Specificaties',
            'Interne opmerking',
            'Leverancier',
            'Artikelnaam leverancier',
            'Artikelnummer leverancier',
            'Inkoop-BTW',
            'Voorraad product',
            'Fysieke voorraad',
            'Gereserveerde voorraad',
            'Beschikbare voorraad',
            'Back-orders toestaan',
            'Minimumvoorraad',
            'Inkoop excl. BTW',
            'Verkoop excl. BTW',
            'Marge %',
            'Opslag %',
            'Deelbaar',
            'Inkoop',
            'Verkoop',
            'Ordergestuurd',
            'Artikelgroep (Exact)',
            'Verkoop-BTW (Exact)',
            'Exact Online ID',
            'Laatst gesynchroniseerd (Exact)',
        ], ';');

        $products = $this->getFilteredTableQuery()
            ->with(['supplier', 'exactPurchaseVatCode', 'exactSalesVatCode', 'exactArticleGroup', 'stock'])
            ->orderBy('name')
            ->cursor();

        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }

            $purchaseVat = $product->exactPurchaseVatCode;
            $salesVat = $product->exactSalesVatCode;

            fputcsv($handle, [
                $product->name ?? '',
                $product->uid ?? '',
                $product->type?->getLabel() ?? '',
                $product->chair_type ?? '',
                $product->unit?->getLabel() ?? '',
                $product->description ?? '',
                $product->comment ?? '',
                $product->supplier?->name ?? '',
                $product->supplier_product_name ?? '',
                $product->supplier_product_uid ?? '',
                $purchaseVat ? ($purchaseVat->code . ' : ' . $purchaseVat->name) : '',
                $product->is_stock_enabled ? 'Ja' : 'Nee',
                $product->stock?->physical_stock ?? 0,
                $product->stock?->reserved_stock ?? 0,
                $product->stock?->available_stock ?? 0,
                ($product->stock?->allow_backorder ?? false) ? 'Ja' : 'Nee',
                $product->stock?->min_threshold ?? 0,
                number_format((float) ($product->company_purchase_price ?? 0), 2, ',', '.'),
                number_format((float) ($product->company_sales_price ?? 0), 2, ',', '.'),
                number_format((float) ($product->company_margin ?? 0), 2, ',', '.'),
                number_format((float) ($product->company_markup ?? 0), 2, ',', '.'),
                $product->is_fraction_allowed_item ? 'Ja' : 'Nee',
                $product->is_purchase_item ? 'Ja' : 'Nee',
                $product->is_sales_item ? 'Ja' : 'Nee',
                $product->is_on_demand_item ? 'Ja' : 'Nee',
                $product->exactArticleGroup?->name ?? '',
                $salesVat ? ($salesVat->code . ' : ' . $salesVat->name) : '',
                $product->exact_id ?? '',
                $product->exact_synced_at?->format('d-m-Y H:i') ?? '',
            ], ';');
        }

        fclose($handle);

        return response()->download($filepath)->deleteFileAfterSend(true);
    }
}

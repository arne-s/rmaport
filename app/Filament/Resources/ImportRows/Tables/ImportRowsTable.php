<?php

namespace App\Filament\Resources\ImportRows\Tables;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\ImportRows\ImportRowResource;
use App\Filament\Resources\ProductResource;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Product;
use App\Services\Import\ImportRowProductResolver;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ImportRowsTable
{
    public static function makeCreateRmaAction(): Action
    {
        return Action::make('createRmaFromImportRow')
            ->label('Aanmaken')
            ->icon(Heroicon::PlusCircle)
            ->color('gray')
            ->extraAttributes(['onclick' => 'event.stopPropagation()'])
            ->action(fn (): null => null);
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back',
            ]))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'customer',
                'source.customer',
                'importBatch',
                'rma',
            ]))
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Klant')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state, ImportRow $record): string => $state
                        ?? $record->customer?->full_name
                        ?? $record->source?->customer?->name
                        ?? $record->source?->name
                        ?? '—')
                    ->url(fn (?string $state, ImportRow $record): ?string => ($customer = self::resolveCustomer($record)) !== null
                        ? CustomerResource::getUrl('edit', ['record' => $customer])
                        : null)
                    ->color('primary')
                    ->disabledClick()
                    ->placeholder('—'),
                TextColumn::make('reference')
                    ->label('Referentie')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('ean_nr')
                    ->label('Artikel')
                    ->formatStateUsing(fn (?string $state, ImportRowProductResolver $productResolver): string => $productResolver->findByEan($state)?->name ?? '—')
                    ->url(fn (?string $state, ImportRowProductResolver $productResolver): ?string => ($product = $productResolver->findByEan($state)) !== null
                        ? ProductResource::getUrl('edit', ['record' => $product])
                        : null)
                    ->color('primary')
                    ->disabledClick()
                    ->grow(false)
                    ->extraCellAttributes(['class' => 'import-row-artikel-column'])
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $eans = Product::query()
                            ->where('name', 'like', "%{$search}%")
                            ->get(['ean_1', 'ean_2'])
                            ->flatMap(fn (Product $product): array => [$product->ean_1, $product->ean_2])
                            ->filter()
                            ->unique()
                            ->values()
                            ->all();

                        return $query->where(function (Builder $inner) use ($search, $eans): void {
                            $inner->where('ean_nr', 'like', "%{$search}%");

                            foreach ($eans as $ean) {
                                $inner->orWhere('ean_nr', 'like', "%{$ean}%");
                            }
                        });
                    })
                    ->placeholder('—'),
                TextColumn::make('importBatch.created_at')
                    ->label('Geïmporteerd op')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy(
                            ImportBatch::query()
                                ->select('created_at')
                                ->whereColumn('imports.id', 'import_rows.import_id')
                                ->limit(1),
                            $direction,
                        );
                    }),
                TextColumn::make('importBatch.file_name')
                    ->label('Bestand')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('import_id')
                    ->label('Import ID')
                    ->sortable()
                    ->placeholder('—'),
                ViewColumn::make('rma')
                    ->label('RMA')
                    ->view('filament.tables.columns.import-row-rma')
                    ->action(static::makeCreateRmaAction())
                    ->disabledClick()
                    ->extraCellAttributes(['class' => 'import-row-rma-column']),
            ])
            ->defaultSort('importBatch.created_at', 'desc')
            ->recordUrl(fn (ImportRow $record): string => ImportRowResource::getUrl('view', ['record' => $record]))
            ->filters([
                ImportRowResource::createStatusFilter(
                    'customer_id',
                    'customer_id',
                    'Klant',
                    self::customerFilterOptions(),
                ),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->searchable()
            ->emptyStateHeading('Geen geimporteerde rijen')
            ->recordActions([])
            ->toolbarActions([]);
    }

    private static function resolveCustomer(ImportRow $record): ?Customer
    {
        return $record->customer ?? $record->source?->customer;
    }

    /**
     * @return array<int, string>
     */
    private static function customerFilterOptions(): array
    {
        return Customer::query()
            ->whereIn('id', ImportRow::query()->select('customer_id')->whereNotNull('customer_id')->distinct())
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Customer $customer): array => [
                $customer->id => $customer->name ?? $customer->full_name,
            ])
            ->all();
    }
}

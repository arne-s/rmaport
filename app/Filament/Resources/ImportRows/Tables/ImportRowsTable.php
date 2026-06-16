<?php

namespace App\Filament\Resources\ImportRows\Tables;

use App\Actions\Import\CreateRmaFromImportRowAction;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\ImportRows\ImportRowResource;
use App\Filament\Resources\ImportTasks\ImportTaskResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\RmaResource;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Product;
use App\Services\Import\ImportRowProductResolver;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class ImportRowsTable
{
    public static function makeCreateRmaAction(): Action
    {
        return Action::make('createRmaFromImportRow')
            ->label('Aanmaken')
            ->icon(Heroicon::PlusCircle)
            ->color('gray')
            ->extraAttributes(['onclick' => 'event.stopPropagation()'])
            ->hidden(fn (ImportRow $record): bool => $record->rma !== null)
            ->action(function (ImportRow $record, CreateRmaFromImportRowAction $createRma): void {
                try {
                    $rma = $createRma($record);
                } catch (RuntimeException $exception) {
                    Notification::make()
                        ->title('RMA aanmaken mislukt')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('RMA aangemaakt')
                    ->body($rma->uid)
                    ->success()
                    ->send();

                redirect(RmaResource::getUrl('view', ['record' => $rma]));
            });
    }

    public static function configure(Table $table): Table
    {
        return $table
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
                    ->label('EAN')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('product_name')
                    ->label('Artikel')
                    ->state(function (ImportRow $record, ImportRowProductResolver $productResolver): string {
                        $productName = $productResolver->findByEan($record->ean_nr)?->name;

                        if ($productName === null) {
                            return '—';
                        }

                        if ($productResolver->usedFallback($record->ean_nr) && filled($record->product_name)) {
                            return "{$productName} ({$record->product_name})";
                        }

                        return $productName;
                    })
                    ->limit(60)
                    ->url(fn (ImportRow $record, ImportRowProductResolver $productResolver): ?string => ($product = $productResolver->findByEan($record->ean_nr)) !== null
                        ? ProductResource::getUrl('edit', ['record' => $product])
                        : null)
                    ->color('primary')
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
                    ->extraCellAttributes(['class' => 'import-row-imported-at-column'])
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy(
                            ImportBatch::query()
                                ->select('created_at')
                                ->whereColumn('imports.id', 'import_rows.import_id')
                                ->limit(1),
                            $direction,
                        );
                    }),
                TextColumn::make('importBatch.uid')
                    ->label('Importtaak')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy(
                            ImportBatch::query()
                                ->select('uid')
                                ->whereColumn('imports.id', 'import_rows.import_id')
                                ->limit(1),
                            $direction,
                        );
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas(
                            'importBatch',
                            fn (Builder $batch): Builder => $batch->where('uid', 'like', "{$search}%"),
                        );
                    })
                    ->url(fn (ImportRow $record): ?string => $record->importBatch !== null
                        ? ImportTaskResource::indexUrlForImportTask($record->importBatch)
                        : null)
                    ->color('primary')
                    ->placeholder('—')
                    ->extraCellAttributes(['class' => 'import-row-import-id-column']),
                ViewColumn::make('rma')
                    ->label('RMA')
                    ->view('filament.tables.columns.import-row-rma')
                    ->action(static::makeCreateRmaAction())
                    ->disabledClick()
                    ->extraCellAttributes(['class' => 'import-row-rma-column']),
            ])
            ->defaultSort('importBatch.created_at', 'desc')
            ->recordUrl(null)
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

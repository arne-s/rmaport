<?php

namespace App\Filament\Resources\ImportTasks\Tables;

use App\Filament\Forms\Components\ToggleFilter;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\ImportRows\ImportRowResource;
use App\Filament\Resources\ImportTasks\Actions\SendImportBatchExportAction;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Support\FormatDisplayDate;
use Filament\Forms\Components\CheckboxList;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ImportTasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->header(view('filament.components.back-to-overview', [
                'title' => 'Dashboard',
                'url' => route('filament.app.pages.dashboard'),
                'class' => 'quote-overview-back',
            ]))
            ->columns([
                TextColumn::make('uid')
                    ->label('Importtaak')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('Klant')
                    ->state(fn (ImportBatch $record): string => self::resolveCustomer($record)?->name
                        ?? self::resolveCustomer($record)?->full_name
                        ?? '—')
                    ->url(fn (ImportBatch $record): ?string => ($customer = self::resolveCustomer($record)) !== null
                        ? CustomerResource::getUrl('edit', ['record' => $customer])
                        : null)
                    ->color('primary')
                    ->placeholder('—'),
                TextColumn::make('reference')
                    ->label('Referentie')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('shipment_reference')
                    ->label('Zending-referentie')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('import_date')
                    ->label('Aanvraagdatum')
                    ->formatStateUsing(fn ($state): ?string => $state !== null
                        ? FormatDisplayDate::longDate($state)
                        : null)
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('shipment_date')
                    ->label('Verzenddatum')
                    ->formatStateUsing(fn ($state): ?string => $state !== null
                        ? FormatDisplayDate::longDate($state)
                        : null)
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('successful_rows')
                    ->label('Aantal rijen')
                    ->formatStateUsing(fn (?int $state, ImportBatch $record): string => sprintf(
                        '%d / %d',
                        $state ?? 0,
                        $record->total_rows ?? 0,
                    ))
                    ->url(fn (ImportBatch $record): string => ImportRowResource::indexUrlForImportTask($record))
                    ->color('primary')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Geïmporteerd op')
                    ->formatStateUsing(fn ($state): ?string => $state !== null
                        ? FormatDisplayDate::longDateTime($state)
                        : null)
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Geïmporteerd door')
                    ->placeholder('—'),
                TextColumn::make('file_name')
                    ->label('Bestandsnaam')
                    ->searchable()
                    ->url(fn (ImportBatch $record): ?string => filled($record->file_path)
                        ? route('import-batches.download', $record)
                        : null)
                    ->color('primary')
                    ->placeholder('—'),
                ViewColumn::make('sheet_retour')
                    ->label('Sheet retour')
                    ->view('filament.tables.columns.import-batch-sheet-retour')
                    ->action(SendImportBatchExportAction::make())
                    ->disabledClick()
                    ->extraCellAttributes(['class' => 'import-batch-sheet-retour-column'])
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(null)
            ->filters([
                self::customerFilter(),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->searchable()
            ->emptyStateHeading('Geen importtaken')
            ->recordActions([])
            ->toolbarActions([]);
    }

    private static function customerFilter(): Filter
    {
        $name = 'customer_id';
        $options = self::customerFilterOptions();

        $checkboxlist = CheckboxList::make($name)
            ->searchable(false)
            ->label('')
            ->options($options);

        return Filter::make($name)
            ->label('Klant')
            ->schema([
                ToggleFilter::make()
                    ->label('Klant')
                    ->schema([
                        $checkboxlist,
                    ]),
            ])
            ->indicateUsing(function (array $data) use ($name, $options): ?string {
                if (! ($data[$name] ?? null)) {
                    return null;
                }

                $labels = array_map(
                    fn (mixed $id): string => $options[$id] ?? (string) $id,
                    $data[$name],
                );

                return 'Klant: '.implode(', ', $labels);
            })
            ->query(function (Builder $query, array $data) use ($name): Builder {
                return $query->when(
                    $data[$name] ?? null,
                    fn (Builder $query, array $ids): Builder => $query->whereHas(
                        'importRows',
                        fn (Builder $inner): Builder => $inner->whereIn('customer_id', $ids),
                    ),
                );
            });
    }

    public static function resolveCustomer(ImportBatch $record): ?Customer
    {
        $row = $record->importRows->first();

        return $row?->customer ?? $row?->source?->customer;
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

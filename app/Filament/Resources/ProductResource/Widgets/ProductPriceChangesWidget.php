<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Enums\PriceType;
use App\Models\PriceChangeLog;
use App\Models\Product;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ProductPriceChangesWidget extends TableWidget
{
    protected static ?string $heading = '';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    public static function canView(): bool
    {
        return true;
    }

    protected function getTableQuery(): Builder
    {
        if (! $this->record instanceof Product) {
            return PriceChangeLog::query()->whereRaw('1 = 0');
        }

        return PriceChangeLog::query()
            ->where('product_id', $this->record->getKey())
            ->with(['author']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                TextColumn::make('created_at')
                    ->label('Datum')
                    ->formatStateUsing(fn ($state) => $state instanceof Carbon ? $state->translatedFormat('d M Y H:i:s') : '-')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Type prijs')
                    ->formatStateUsing(fn ($state) => $state?->getLabel() ?? '-')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('type', 'like', "%{$search}%")),

                TextColumn::make('method')
                    ->label('Methode')
                    ->formatStateUsing(fn ($state) => $state?->getLabel() ?? '-')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('method', 'like', "%{$search}%")),

                TextColumn::make('value_change')
                    ->label('Waarde')
                    ->state(function (PriceChangeLog $record): string {
                        $from = $record->value_from !== null
                            ? $this->formatValueByType($record, (float) $record->value_from)
                            : '-';
                        $to = $record->value_to !== null
                            ? $this->formatValueByType($record, (float) $record->value_to)
                            : '-';

                        return $from . ' → ' . $to;
                    }),

                TextColumn::make('author.name')
                    ->label('Gebruiker')
                    ->formatStateUsing(fn (PriceChangeLog $record): string => $record->author?->name ?? '-')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('author', function (Builder $authorQuery) use ($search): void {
                            $authorQuery
                                ->where('first_name', 'like', "%{$search}%")
                                ->orWhere('middle_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                    }),

                TextColumn::make('comment')
                    ->label('Opmerking')
                    ->searchable()
                    ->extraCellAttributes([
                        'style' => 'padding-left: 0;',
                    ])
                    ->formatStateUsing(fn (?string $state): string => $state !== null && trim($state) !== '' ? $state : '-'),
            ])
            ->defaultSort('created_at', 'desc')
            ->searchable()
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Geen prijswijzigingen');
    }

    private function formatValueByType(PriceChangeLog $record, float $value): string
    {
        if ($record->type === PriceType::CompanyMargin || $record->type === PriceType::CompanyMarkup) {
            $formatted = number_format($value, 2, ',', '.');
            $formatted = rtrim(rtrim($formatted, '0'), ',');
            return $formatted . '%';
        }

        $formatted = number_format($value, 2, ',', '.');
        return '€ ' . $formatted;
    }
}


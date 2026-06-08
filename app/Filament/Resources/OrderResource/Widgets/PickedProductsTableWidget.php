<?php

namespace App\Filament\Resources\OrderResource\Widgets;

use App\Filament\Resources\OrderResource\Widgets\Concerns\ConfiguresClickableProductNameColumn;
use App\Enums\FulfillmentType;
use App\Enums\OrderProductStatus;
use App\Enums\ProductType;
use App\Models\OrderProduct;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PickedProductsTableWidget extends TableWidget
{
    use ConfiguresClickableProductNameColumn;

    protected static ?string $model = OrderProduct::class;

    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = '';

    public static function canView(): bool
    {
        return true;
    }


    protected function getTableQuery(): Builder
    {
        /* @var $this ->record Main */
        $query = $this->record?->getPurchasedPickedProducts()?->getQuery();

        return $query ?? OrderProduct::query()->whereRaw('0 = 1');
    }


    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                $this->configureProductNameColumn(
                    TextColumn::make('product.name')
                        ->label('Artikelnaam RD Mobility')
                        ->placeholder('-')
                        ->sortable(),
                ),

                TextColumn::make('product.type')
                    ->label('Type')
                    ->formatStateUsing(fn ($state): string => $state instanceof ProductType
                        ? ($state->getLabel() ?? '-')
                        : (ProductType::tryFrom((string) $state)?->getLabel() ?? '-'))
                    ->sortable(),

                $this->configureClickableProductUidColumn(
                    TextColumn::make('product.uid')
                        ->label('Artikelnummer RD Mobility')
                        ->placeholder('-')
                        ->sortable(),
                ),

                TextColumn::make('qty')
                    ->label('Aantal')
                    ->sortable(),

                TextColumn::make('picked_at')
                    ->label('Gepickt datum')
                    ->state(fn(OrderProduct $record): ?\Carbon\Carbon => $record->latestPickedStatusChange?->created_at)
                    ->date('d-m-Y')
                    ->placeholder('—'),

                TextColumn::make('picked_by')
                    ->label('Gepickt door')
                    ->state(fn(OrderProduct $record): string => (string)($record->latestPickedStatusChange?->changedBy?->getName() ?? ''))
                    ->formatStateUsing(fn(string $state): string => $state !== '' ? $state : '—'),

                SelectColumn::make('status')
                    ->label('Status')
                    ->options(function (SelectColumn $column): array {
                        $record = $column->getRecord();
                        $options = OrderProductStatus::getPickedStatusLabels();
                        if ($record instanceof OrderProduct
                            && $record->getFulfillmentType() === FulfillmentType::Release) {
                            $options[OrderProductStatus::PickedReceived->value] = 'Gepickt (afroep)';
                        }

                        return $options;
                    })
                    ->selectablePlaceholder(false)
                    ->disabled(),

            ])
            ->paginated(false)
            ->striped(false)
            ->headerActions([])
            ->bulkActions([])
            ->searchable(false)
            ->emptyStateHeading('Geen gepickte producten')
            ->recordUrl(null)
            ->extraAttributes(['class' => 'orderProductsTable']);
    }
}

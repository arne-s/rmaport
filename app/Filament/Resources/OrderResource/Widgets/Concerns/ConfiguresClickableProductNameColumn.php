<?php

namespace App\Filament\Resources\OrderResource\Widgets\Concerns;

use App\Filament\Resources\ProductResource;
use App\Models\OrderProduct;
use Filament\Tables\Columns\TextColumn;

trait ConfiguresClickableProductNameColumn
{
    protected function configureProductNameColumn(TextColumn $column): TextColumn
    {
        return $column;
    }

    protected function configureClickableProductUidColumn(TextColumn $column): TextColumn
    {
        return $column
            ->url(fn (OrderProduct $record): ?string => ProductResource::editUrlFor($record->product_id))
            ->openUrlInNewTab()
            ->color(fn (OrderProduct $record): ?string => ProductResource::editUrlFor($record->product_id) ? 'primary' : null);
    }
}

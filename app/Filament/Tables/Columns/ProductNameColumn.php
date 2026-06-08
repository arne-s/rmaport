<?php

namespace App\Filament\Tables\Columns;

use Filament\Tables\Columns\TextColumn;

class ProductNameColumn extends TextColumn
{
    /**
     * The view used to render the column.
     *
     * @var string
     */
    protected string $view = 'filament.tables.columns.product-name-column';

    /**
     * Get the product status.
     *
     * @return string
     */
    public function getProductName(): string
    {
        return $this->record->name;
    }

    /**
     * Get the product name.
     *
     * @return string
     */
    public function getProductStatus(): string
    {
        return (int)($this->record->is_visible_portal || $this->record->product?->is_visible_portal);
    }
}

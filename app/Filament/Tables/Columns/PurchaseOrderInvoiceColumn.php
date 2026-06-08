<?php

namespace App\Filament\Tables\Columns;

use App\Traits\Columns\CanBeEmpty;
use Filament\Tables\Columns\TextColumn;

class PurchaseOrderInvoiceColumn extends TextColumn
{
    use CanBeEmpty;

    /**
     * The view used to render the column.
     *
     * @var string
     */
    protected string $view = 'filament.tables.columns.purchase-order-invoice-column';
}
